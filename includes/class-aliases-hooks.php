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
 * ## Admin alias search
 *
 * Administrators and shop managers are NOT restricted to their own aliases.
 * When an admin searches for an alias code, ALL EAN/item codes mapped to
 * that alias across ALL customers are resolved via CIA_DB::resolve_aliases_global()
 * so the admin sees every product associated with the alias, regardless of
 * which customer owns the record.
 *
 * ## FluxStore search pathways
 *
 * FluxStore issues TWO separate requests for every search:
 *   a) search=<term>  → woocommerce_rest_product_object_query with args['s']
 *   b) sku=<term>     → woocommerce_rest_product_object_query with args['sku']
 *      (triggered by kSearchConfig.enableSkuSearch)
 *
 * Both paths are intercepted and alias-resolved so that results are returned
 * regardless of which FluxStore pathway is active.
 *
 * ## Multi-EAN support
 *
 * One alias_code may map to MULTIPLE ean8_code rows for the same customer.
 * All matching EAN codes are passed to WooCommerce as an IN meta_query so
 * every associated product is returned in a single request.
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
     *
     * Common values:
     *   '_global_unique_id'  — WooCommerce core v9.2+
     *   '_ean'               — Germanized / WP-Lister
     *   '_yith_ean'          — YITH WooCommerce EAN
     *   '_alg_ean'           — Ean for WooCommerce plugin
     */
    private const EAN_META_KEY = '_global_unique_id';

    public static function init(): void {

        // ── MStore API Scanner ─────────────────────────────────────────────────
        add_filter( 'rest_pre_dispatch', [ __CLASS__, 'resolve_in_scanner' ], 10, 3 );

        // ── wp-rest-cache: skip reading AND writing cached responses ───────────
        add_filter( 'wp_rest_cache/skip_caching', [ __CLASS__, 'skip_cache_for_product_search' ], 10, 3 );
        add_filter( 'wp_rest_cache/skip_cache',   [ __CLASS__, 'skip_cache_for_product_search' ], 10, 3 );

        // ── FluxStore / WC REST API v2 ──────────────────────────────────────
        add_filter( 'woocommerce_rest_product_query',        [ __CLASS__, 'resolve_in_rest_v2' ],   10, 2 );

        // ── FluxStore / WC REST API v3 ──────────────────────────────────────
        add_filter( 'woocommerce_rest_product_object_query', [ __CLASS__, 'resolve_in_rest_v3' ],   10, 2 );

        // ── WooCommerce Store API (Blocks) ────────────────────────────────────
        add_filter( 'woocommerce_blocks_product_query_args', [ __CLASS__, 'resolve_in_store_api' ], 10, 2 );

        // ── FiboSearch Pro (optional) ─────────────────────────────────────────
        add_action( 'init', [ __CLASS__, 'register_fibosearch_hook' ], 20 );
    }

    // ── Customer identity resolution ───────────────────────────────────────────

    /**
     * Decode the FluxStore `User-Cookie` HTTP header into a WordPress user ID.
     *
     * @return int|null  Validated customer user ID, or null if absent / invalid.
     */
    private static function get_customer_id_from_user_cookie(): ?int {
        $raw_header = $_SERVER['HTTP_USER_COOKIE'] ?? '';
        if ( empty( $raw_header ) ) {
            return null;
        }

        $cookie = urldecode( base64_decode( $raw_header ) );
        if ( empty( $cookie ) ) {
            return null;
        }

        if ( ! wp_validate_auth_cookie( $cookie, 'logged_in' ) ) {
            return null;
        }

        $parsed = wp_parse_auth_cookie( $cookie, 'logged_in' );
        if ( empty( $parsed['username'] ) ) {
            return null;
        }

        $user = get_user_by( 'login', $parsed['username'] );
        return $user ? $user->ID : null;
    }

    /**
     * Determine the customer ID for a WooCommerce REST product request.
     *
     * Priority:
     *   1. User-Cookie header — cryptographically validated WordPress cookie.
     *   2. ?customer_id= param — explicit override for Postman / API testing.
     *   3. ?user_id= param — FluxStore's standard product query identity signal.
     *
     * @param  WP_REST_Request $request
     * @return int|null
     */
    private static function customer_id_for_request( WP_REST_Request $request ): ?int {
        $from_cookie = self::get_customer_id_from_user_cookie();
        if ( $from_cookie ) {
            return $from_cookie;
        }

        $from_customer_id = absint( $request->get_param( 'customer_id' ) ?? 0 );
        if ( $from_customer_id ) {
            return $from_customer_id;
        }

        $from_user_id = absint( $request->get_param( 'user_id' ) ?? 0 );
        if ( $from_user_id ) {
            return $from_user_id;
        }

        return null;
    }

    // ── Core alias resolver ────────────────────────────────────────────────────

    /**
     * Resolves a search term to ALL matching EAN codes.
     *
     * Resolution strategy by user type:
     *
     *   Administrator / shop manager:
     *     → CIA_DB::resolve_aliases_global( $search )
     *     → Returns DISTINCT EAN codes across ALL customers for the alias.
     *     → Allows admins to search any customer alias and find all products.
     *
     *   Regular customer:
     *     → CIA_DB::resolve_aliases( $user_id, $search )
     *     → Returns EAN codes scoped to that customer only.
     *
     *   No identity resolved:
     *     → Returns [] so WooCommerce falls back to normal keyword search.
     *
     * @param  string   $search       Raw search / SKU term from the query args.
     * @param  int|null $customer_id  User ID from cookie, param, or user_id param.
     * @return string[]               All matching EAN codes; empty = no resolution.
     */
    private static function resolve( string $search, ?int $customer_id = null ): array {
        $user_id = $customer_id ?: get_current_user_id();

        if ( ! $user_id ) {
            return [];
        }

        // Admins and shop managers: global alias lookup across all customers.
        if ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) {
            return CIA_DB::resolve_aliases_global( $search );
        }

        // Regular customers: scoped to their own aliases only.
        return CIA_DB::resolve_aliases( $user_id, $search );
    }

    // ── Search term extraction ─────────────────────────────────────────────────

    /**
     * Extract the alias-candidate search term from WP_Query args.
     *
     * FluxStore issues two separate product search requests:
     *   • Text search : args['s']   (from ?search=<term>)
     *   • SKU search  : args['sku'] (from ?sku=<term>)
     */
    private static function extract_search_term( array $args ): string {
        return sanitize_text_field(
            $args['s'] ?? $args['search'] ?? $args['sku'] ?? ''
        );
    }

    /**
     * Remove all search / SKU keys from WP_Query args before injecting
     * the meta_query so WooCommerce does not also run a parallel text or
     * _sku match on the alias code.
     */
    private static function clear_search_args( array $args ): array {
        unset( $args['s'], $args['search'], $args['sku'] );
        return $args;
    }

    // ── Handler: WC REST API v2 ────────────────────────────────────────────────

    public static function resolve_in_rest_v2( array $args, WP_REST_Request $request ): array {
        $search      = self::extract_search_term( $args );
        $customer_id = self::customer_id_for_request( $request );

        if ( empty( $search ) ) {
            return $args;
        }

        $ean_codes = self::resolve( $search, $customer_id );

        if ( ! empty( $ean_codes ) ) {
            $args = self::clear_search_args( $args );
            $args = self::apply_exact_ean_match( $args, $ean_codes );
        }

        return $args;
    }

    // ── Handler: WC REST API v3 ────────────────────────────────────────────────

    public static function resolve_in_rest_v3( array $args, WP_REST_Request $request ): array {
        $search      = self::extract_search_term( $args );
        $customer_id = self::customer_id_for_request( $request );

        if ( empty( $search ) ) {
            return $args;
        }

        $ean_codes = self::resolve( $search, $customer_id );

        if ( ! empty( $ean_codes ) ) {
            $args = self::clear_search_args( $args );
            $args = self::apply_exact_ean_match( $args, $ean_codes );
        }

        return $args;
    }

    // ── Handler: WooCommerce Store API ─────────────────────────────────────────

    public static function resolve_in_store_api( array $args, WP_REST_Request $request ): array {
        $search      = self::extract_search_term( $args );
        $customer_id = self::customer_id_for_request( $request );

        if ( empty( $search ) ) {
            return $args;
        }

        $ean_codes = self::resolve( $search, $customer_id );

        if ( ! empty( $ean_codes ) ) {
            $args = self::clear_search_args( $args );
            $args = self::apply_exact_ean_match( $args, $ean_codes );
        }

        return $args;
    }

    // ── Handler: FiboSearch Pro ────────────────────────────────────────────────

    /**
     * dgwt/wcas/search_query
     * FiboSearch is browser-based; session user is available via get_current_user_id().
     * Returns only the first EAN — FiboSearch only accepts a single string.
     */
    public static function resolve_in_fibosearch( string $search_query ): string {
        $search = sanitize_text_field( $search_query );

        if ( empty( $search ) ) {
            return $search_query;
        }

        $customer_id = self::get_customer_id_from_user_cookie();
        $ean_codes   = self::resolve( $search, $customer_id );

        return $ean_codes[0] ?? $search_query;
    }

    // ── Handler: MStore API Scanner ────────────────────────────────────────────

    /**
     * rest_pre_dispatch — intercepts /api/flutter_woo/scanner.
     *
     * MStore validates User-Cookie and calls wp_set_current_user() before
     * dispatch, so get_current_user_id() already returns the correct user.
     *
     * Admin behaviour: resolves the alias globally across all customers so
     * the admin scanner can find any product by any customer alias.
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
        if ( ! $raw_data ) {
            return $result;
        }

        $user_id = get_current_user_id();
        $is_admin = $user_id && (
            user_can( $user_id, 'manage_woocommerce' ) ||
            user_can( $user_id, 'manage_options' )
        );

        // Resolve alias → EAN codes.
        // Admins get a global lookup; customers get their own aliases only.
        if ( $is_admin ) {
            $ean_codes = CIA_DB::resolve_aliases_global( $raw_data );
        } elseif ( $user_id ) {
            $ean_codes = CIA_DB::resolve_aliases( $user_id, $raw_data );
        } else {
            $ean_codes = [];
        }

        if ( empty( $ean_codes ) ) {
            $ean_codes = [ $raw_data ]; // no alias — try raw value as EAN directly
        }

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ean_codes ), '%s' ) );
        $post_id      = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = %s
                   AND meta_value IN ({$placeholders})
                 LIMIT 1",
                array_merge( [ self::EAN_META_KEY ], $ean_codes )
            )
        );

        if ( ! $post_id ) {
            return $result;
        }

        $controller = new CUSTOM_WC_REST_Products_Controller();
        $req        = new WP_REST_Request( 'GET' );
        $req->set_query_params( [
            'status'   => 'publish',
            'include'  => [ absint( $post_id ) ],
            'page'     => 1,
            'per_page' => 1,
        ] );

        $response = $controller->get_items( $req );

        return rest_ensure_response( [
            'type' => 'product',
            'data' => $response->get_data(),
        ] );
    }

    // ── wp-rest-cache exclusion ────────────────────────────────────────────────

    public static function skip_cache_for_product_search(
        bool $skip,
        WP_REST_Request $request,
        WP_REST_Response $response
    ): bool {

        if ( $skip ) {
            return true;
        }

        $route             = $request->get_route();
        $has_search        = (bool) $request->get_param( 'search' );
        $has_sku           = (bool) $request->get_param( 'sku' );
        $has_customer_id   = (bool) $request->get_param( 'customer_id' );
        $has_user_id       = (bool) $request->get_param( 'user_id' );
        $has_user_cookie   = ! empty( $_SERVER['HTTP_USER_COOKIE'] );
        $is_authed         = is_user_logged_in();
        $is_product_search = (bool) preg_match(
            '#^/wc/(v[23]|store/v1)/products$#',
            $route
        );

        return $is_product_search
            && ( $has_search || $has_sku )
            && ( $is_authed || $has_customer_id || $has_user_id || $has_user_cookie );
    }

    // ── FiboSearch: late registration ──────────────────────────────────────────

    public static function register_fibosearch_hook(): void {
        if ( has_filter( 'dgwt/wcas/search_query' ) || self::is_fibosearch_active() ) {
            add_filter( 'dgwt/wcas/search_query', [ __CLASS__, 'resolve_in_fibosearch' ], 10, 1 );
        }
    }

    private static function is_fibosearch_active(): bool {
        return defined( 'DGWT_WCAS_VERSION' ) ||
               class_exists( 'DgoraWcas\Engine\SearchEngine' ) ||
               function_exists( 'dgwt_wcas' );
    }

    // ── Shared utility ─────────────────────────────────────────────────────────

    /**
     * Injects an exact EAN meta_query into the WP_Query args.
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
