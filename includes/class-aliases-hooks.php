<?php
defined( 'ABSPATH' ) || exit;

/**
 * CIA_Hooks
 *
 * Registers alias-resolution hooks for all FluxStore / WooCommerce search
 * pathways plus FiboSearch (optional).
 *
 * ## Authentication model
 *
 * A SINGLE shared WooCommerce API key (admin / service account) is used for
 * all FluxStore requests. Customer identity is resolved from three signals,
 * tried in priority order inside every REST handler:
 *
 *   1. User-Cookie header — present on MStore/FluxStore API calls that
 *      explicitly attach the auth cookie (scanner, orders, cart, etc.).
 *      NOT sent by wcConnector.getAsync() → absent on product searches.
 *
 *   2. ?customer_id= query param — optional explicit override, useful for
 *      Postman / API testing without a live app session.
 *
 *   3. user_id= query param — FluxStore passes the logged-in customer's
 *      WordPress user ID as 'user_id' on every product query. This is the
 *      primary identity signal for FluxStore product searches.
 *
 * ## Alias resolution strategy (exact-first with LIKE fallback)
 *
 * Resolution is attempted in two stages for every search:
 *
 *   Stage 1 — Exact match (alias_code = '$search')
 *     Fast indexed lookup. Returns immediately if any rows match.
 *
 *   Stage 2 — LIKE fallback (alias_code LIKE '%$search%')
 *     Only reached when Stage 1 returns empty. Handles partial code entry
 *     (e.g. user types "50123" instead of the full "5012345").
 *
 * Admin scope  : both stages query ALL customers (global).
 * Customer scope: both stages are scoped to that customer's user_id only.
 *
 * If both stages return empty, the original search term passes through to
 * WooCommerce unchanged (normal keyword / SKU search behaviour).
 *
 * ## FluxStore search pathways
 *
 * FluxStore issues TWO separate requests for every search:
 *   a) search=<term>  → woocommerce_rest_product_object_query with args['s']
 *   b) sku=<term>     → woocommerce_rest_product_object_query with args['sku']
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │  Search Pathway              Hook                     API Route   │
 * ├──────────────────────────────────────────────────────────────────┤
 * │  FluxStore text search       woocommerce_rest_        /wc/v3/    │
 * │  (search=<term>)             product_object_query      products   │
 * │  FluxStore SKU search        woocommerce_rest_        /wc/v3/    │
 * │  (sku=<term>)                product_object_query      products   │
 * │  WC REST v2                  woocommerce_rest_        /wc/v2/    │
 * │                              product_query             products   │
 * │  WooCommerce Store API       woocommerce_blocks_      /store/v1/ │
 * │                              product_query_args        products   │
 * │  FiboSearch Pro (optional)   dgwt/wcas/search_query  AJAX        │
 * └──────────────────────────────────────────────────────────────────┘
 */
class CIA_Hooks {

    /**
     * The meta key under which the EAN/GTIN is stored on the product post.
     */
    private const EAN_META_KEY = '_global_unique_id';

    public static function init(): void {
        add_filter( 'rest_pre_dispatch',                      [ __CLASS__, 'resolve_in_scanner' ],            10, 3 );
        add_filter( 'wp_rest_cache/skip_caching',             [ __CLASS__, 'skip_cache_for_product_search' ], 10, 3 );
        add_filter( 'wp_rest_cache/skip_cache',               [ __CLASS__, 'skip_cache_for_product_search' ], 10, 3 );
        add_filter( 'woocommerce_rest_product_query',         [ __CLASS__, 'resolve_in_rest_v2' ],            10, 2 );
        add_filter( 'woocommerce_rest_product_object_query',  [ __CLASS__, 'resolve_in_rest_v3' ],            10, 2 );
        add_filter( 'woocommerce_blocks_product_query_args',  [ __CLASS__, 'resolve_in_store_api' ],          10, 2 );
        add_action( 'init',                                   [ __CLASS__, 'register_fibosearch_hook' ],      20    );
    }

    // -------------------------------------------------------------------------
    // Customer identity resolution
    // -------------------------------------------------------------------------

    /**
     * Decode the FluxStore `User-Cookie` HTTP header into a WordPress user ID.
     *
     * @return int|null  Validated customer user ID, or null if absent / invalid.
     */
    private static function get_customer_id_from_user_cookie(): ?int {
        $raw_header = $_SERVER['HTTP_USER_COOKIE'] ?? '';
        if ( empty( $raw_header ) ) return null;

        $cookie = urldecode( base64_decode( $raw_header ) );
        if ( empty( $cookie ) ) return null;

        if ( ! wp_validate_auth_cookie( $cookie, 'logged_in' ) ) return null;

        $parsed = wp_parse_auth_cookie( $cookie, 'logged_in' );
        if ( empty( $parsed['username'] ) ) return null;

        $user = get_user_by( 'login', $parsed['username'] );
        return $user ? $user->ID : null;
    }

    /**
     * Determine the customer ID for a WooCommerce REST product request.
     *
     * Priority:
     *   1. User-Cookie header  (scanner / cart / orders)
     *   2. ?customer_id= param (Postman / API testing)
     *   3. ?user_id= param     (FluxStore product searches)
     */
    private static function customer_id_for_request( WP_REST_Request $request ): ?int {
        $from_cookie = self::get_customer_id_from_user_cookie();
        if ( $from_cookie ) return $from_cookie;

        $from_customer_id = absint( $request->get_param( 'customer_id' ) ?? 0 );
        if ( $from_customer_id ) return $from_customer_id;

        $from_user_id = absint( $request->get_param( 'user_id' ) ?? 0 );
        if ( $from_user_id ) return $from_user_id;

        return null;
    }

    // -------------------------------------------------------------------------
    // Core alias resolver  (exact-first, LIKE fallback)
    // -------------------------------------------------------------------------

    /**
     * Resolve a search term to ALL matching EAN codes.
     *
     * Two-stage resolution:
     *   1. Exact match  (fast, indexed)  — returns immediately on hit.
     *   2. LIKE fallback (%search%)      — only if exact returns nothing.
     *
     * Scope:
     *   Admin / shop manager  → global (all customers)
     *   Regular customer      → scoped to their user_id
     *   No identity           → returns [] (WooCommerce normal search)
     *
     * @param  string   $search
     * @param  int|null $customer_id
     * @return string[]
     */
    private static function resolve( string $search, ?int $customer_id = null ): array {
        $user_id = $customer_id ?: get_current_user_id();

        if ( ! $user_id ) {
            $ean_codes = [];
        } else {
            $is_admin = user_can( $user_id, 'manage_woocommerce' )
                     || user_can( $user_id, 'manage_options' );

            if ( $is_admin ) {
                // Stage 1: exact, global
                $codes = CIA_DB::resolve_aliases_global( $search );
                // Stage 2: LIKE fallback, global
                $ean_codes = $codes ?: CIA_DB::resolve_aliases_global_like( $search );
            } else {
                // Stage 1: exact, scoped
                $codes = CIA_DB::resolve_aliases( $user_id, $search );
                // Stage 2: LIKE fallback, scoped
                $ean_codes = $codes ?: CIA_DB::resolve_aliases_like( $user_id, $search );
            }
        }

        /**
         * Fire after alias resolution for analytics / logging.
         *
         * @param string[] $ean_codes   Resolved EAN codes (empty if not found).
         * @param string   $search      The original search term.
         * @param int|null $customer_id The customer who searched (null if anonymous).
         */
        return apply_filters( 'cia_after_resolve', $ean_codes, $search, $customer_id );
    }

    // -------------------------------------------------------------------------
    // Search term extraction helpers
    // -------------------------------------------------------------------------

    private static function extract_search_term( array $args ): string {
        return sanitize_text_field(
            $args['s'] ?? $args['search'] ?? $args['sku'] ?? ''
        );
    }

    private static function clear_search_args( array $args ): array {
        unset( $args['s'], $args['search'], $args['sku'] );
        return $args;
    }

    // -------------------------------------------------------------------------
    // REST handlers
    // -------------------------------------------------------------------------

    public static function resolve_in_rest_v2( array $args, WP_REST_Request $request ): array {
        $search    = self::extract_search_term( $args );
        if ( empty( $search ) ) return $args;

        $ean_codes = self::resolve( $search, self::customer_id_for_request( $request ) );
        if ( ! empty( $ean_codes ) ) {
            $args = self::clear_search_args( $args );
            $args = self::apply_exact_ean_match( $args, $ean_codes );
        }
        return $args;
    }

    public static function resolve_in_rest_v3( array $args, WP_REST_Request $request ): array {
        $search    = self::extract_search_term( $args );
        if ( empty( $search ) ) return $args;

        $ean_codes = self::resolve( $search, self::customer_id_for_request( $request ) );
        if ( ! empty( $ean_codes ) ) {
            $args = self::clear_search_args( $args );
            $args = self::apply_exact_ean_match( $args, $ean_codes );
        }
        return $args;
    }

    public static function resolve_in_store_api( array $args, WP_REST_Request $request ): array {
        $search    = self::extract_search_term( $args );
        if ( empty( $search ) ) return $args;

        $ean_codes = self::resolve( $search, self::customer_id_for_request( $request ) );
        if ( ! empty( $ean_codes ) ) {
            $args = self::clear_search_args( $args );
            $args = self::apply_exact_ean_match( $args, $ean_codes );
        }
        return $args;
    }

    // -------------------------------------------------------------------------
    // FiboSearch handler
    // -------------------------------------------------------------------------

    /**
     * Browser-based; session user available via get_current_user_id().
     * Returns only the first EAN — FiboSearch accepts a single string.
     */
    public static function resolve_in_fibosearch( string $search_query ): string {
        $search = sanitize_text_field( $search_query );
        if ( empty( $search ) ) return $search_query;

        $ean_codes = self::resolve( $search, self::get_customer_id_from_user_cookie() );
        return $ean_codes[0] ?? $search_query;
    }

    // -------------------------------------------------------------------------
    // MStore API Scanner handler
    // -------------------------------------------------------------------------

    /**
     * Intercepts /api/flutter_woo/scanner before MStore handles it.
     * MStore calls wp_set_current_user() before dispatch, so
     * get_current_user_id() already returns the correct user here.
     *
     * Admin: global alias lookup (any customer's alias resolves).
     * Customer: scoped to their own aliases only.
     * Both stages (exact then LIKE) apply via resolve().
     */
    public static function resolve_in_scanner(
        $result,
        WP_REST_Server $server,
        WP_REST_Request $request
    ): mixed {
        if ( $request->get_route() !== '/api/flutter_woo/scanner' ) {
            return $result;
        }

        $raw_data = sanitize_text_field( $request->get_param( 'data' ) ?? '' );
        if ( ! $raw_data ) return $result;

        $user_id   = get_current_user_id();
        $ean_codes = $user_id
            ? self::resolve( $raw_data, $user_id )
            : [];

        if ( empty( $ean_codes ) ) {
            $ean_codes = [ $raw_data ]; // no alias match — try raw value as EAN
        }

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ean_codes ), '%s' ) );
        $post_id      = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id
                 FROM {$wpdb->postmeta}
                 WHERE meta_key   = %s
                   AND meta_value IN ({$placeholders})
                 LIMIT 1",
                array_merge( [ self::EAN_META_KEY ], $ean_codes )
            )
        );

        if ( ! $post_id ) return $result;

        $controller = new CUSTOM_WC_REST_Products_Controller();
        $req        = new WP_REST_Request( 'GET' );
        $req->set_query_params( [
            'status'   => 'publish',
            'include'  => [ absint( $post_id ) ],
            'page'     => 1,
            'per_page' => 1,
        ] );

        return rest_ensure_response( [
            'type' => 'product',
            'data' => $controller->get_items( $req )->get_data(),
        ] );
    }

    // -------------------------------------------------------------------------
    // wp-rest-cache exclusion
    // -------------------------------------------------------------------------

    public static function skip_cache_for_product_search(
        bool $skip,
        WP_REST_Request $request,
        WP_REST_Response $response
    ): bool {
        if ( $skip ) return true;

        $route = $request->get_route();
        return (bool) preg_match( '#^/wc/(v[23]|store/v1)/products$#', $route )
            && ( $request->get_param('search') || $request->get_param('sku') )
            && (
                is_user_logged_in()
                || $request->get_param('customer_id')
                || $request->get_param('user_id')
                || ! empty( $_SERVER['HTTP_USER_COOKIE'] )
            );
    }

    // -------------------------------------------------------------------------
    // FiboSearch: late registration
    // -------------------------------------------------------------------------

    public static function register_fibosearch_hook(): void {
        if ( has_filter( 'dgwt/wcas/search_query' ) || self::is_fibosearch_active() ) {
            add_filter( 'dgwt/wcas/search_query', [ __CLASS__, 'resolve_in_fibosearch' ], 10, 1 );
        }
    }

    private static function is_fibosearch_active(): bool {
        return defined( 'DGWT_WCAS_VERSION' )
            || class_exists( 'DgoraWcas\Engine\SearchEngine' )
            || function_exists( 'dgwt_wcas' );
    }

    // -------------------------------------------------------------------------
    // Shared query builder
    // -------------------------------------------------------------------------

    /**
     * Injects an exact EAN meta_query into WP_Query args.
     * Uses '=' for a single EAN and 'IN' for multiple.
     */
    private static function apply_exact_ean_match( array $args, array $ean_codes ): array {
        $args['meta_query']   = $args['meta_query'] ?? [];
        $args['meta_query'][] = [
            'key'     => self::EAN_META_KEY,
            'value'   => count( $ean_codes ) === 1 ? $ean_codes[0] : $ean_codes,
            'compare' => count( $ean_codes ) === 1 ? '='           : 'IN',
            'type'    => 'CHAR',
        ];
        return $args;
    }
}
