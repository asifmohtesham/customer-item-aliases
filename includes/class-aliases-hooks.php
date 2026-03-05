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
 * Rather than issuing one WC API key per customer (which does not scale),
 * the plugin uses a SINGLE shared API key (admin / service account) and
 * resolves the customer identity from an explicit `customer_id` request
 * parameter supplied by the FluxStore app at search time.
 *
 * Fallback: when `customer_id` is absent the current WordPress session user
 * is used instead (covers FiboSearch AJAX and other authenticated contexts).
 *
 * ## Multi-EAN support
 *
 * One alias_code may map to MULTIPLE ean8_code rows for the same customer.
 * All matching EAN codes are resolved and passed to WooCommerce as an IN
 * meta_query so every associated product is returned in a single request.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │  Search Pathway              Hook                     API Route   │
 * ├──────────────────────────────────────────────────────────────────┤
 * │  FluxStore (WC REST v2)      woocommerce_rest_        /wc/v2/    │
 * │                              product_query             products   │
 * │  FluxStore (WC REST v3)      woocommerce_rest_        /wc/v3/    │
 * │                              product_object_query      products   │
 * │  FluxStore (Store API)       woocommerce_blocks_      /store/v1/ │
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

        // ── wp-rest-cache: skip reading AND writing cached responses for ──────
        // ── authenticated product searches (alias resolution is user-specific) ─
        add_filter( 'wp_rest_cache/skip_caching', [ __CLASS__, 'skip_cache_for_product_search' ], 10, 3 );
        add_filter( 'wp_rest_cache/skip_cache',   [ __CLASS__, 'skip_cache_for_product_search' ], 10, 3 );

        // ── FluxStore: WooCommerce REST API v2 ───────────────────────────────
        add_filter( 'woocommerce_rest_product_query',        [ __CLASS__, 'resolve_in_rest_v2' ],   10, 2 );

        // ── FluxStore: WooCommerce REST API v3 ───────────────────────────────
        add_filter( 'woocommerce_rest_product_object_query', [ __CLASS__, 'resolve_in_rest_v3' ],   10, 2 );

        // ── FluxStore: WooCommerce Store API (Blocks) ─────────────────────────
        add_filter( 'woocommerce_blocks_product_query_args', [ __CLASS__, 'resolve_in_store_api' ], 10, 2 );

        // ── FiboSearch Pro (optional) ─────────────────────────────────────────
        add_action( 'init', [ __CLASS__, 'register_fibosearch_hook' ], 20 );
    }

    // ── Core alias resolver ───────────────────────────────────────────────────

    /**
     * Resolves a search term to ALL matching EAN codes for the given customer.
     *
     * Customer identity resolution order:
     *   1. Explicit $customer_id argument (from ?customer_id= request param)
     *   2. get_current_user_id() — fallback for authenticated sessions
     *      (FiboSearch AJAX, Store API with cookie auth, etc.)
     *
     * Returns an empty array when:
     *   - No customer identity can be determined
     *   - The resolved user is an administrator or shop manager (admin bypass)
     *   - No aliases exist for this customer + search term combination
     *
     * @param  string   $search       Raw search term from the query args.
     * @param  int|null $customer_id  Explicit customer ID from request param, or null.
     * @return string[]               All matching EAN codes; empty = no resolution.
     */
    private static function resolve( string $search, ?int $customer_id = null ): array {
        $user_id = $customer_id ?: get_current_user_id();

        if ( ! $user_id ) {
            return []; // unauthenticated and no explicit customer_id
        }

        // Administrators and shop managers search freely — no alias substitution.
        if ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) {
            return [];
        }

        return CIA_DB::resolve_aliases( $user_id, $search );
    }

    // ── Handler: WC REST API v2 ───────────────────────────────────────────────

    /**
     * woocommerce_rest_product_query
     * Fires for GET /wp-json/wc/v2/products?search=...&customer_id=...
     * WC v2 stores the search term under 's' inside $args.
     */
    public static function resolve_in_rest_v2( array $args, WP_REST_Request $request ): array {
        $search      = sanitize_text_field( $args['s'] ?? $args['search'] ?? '' );
        $customer_id = absint( $request->get_param( 'customer_id' ) ?? 0 ) ?: null;

        if ( empty( $search ) ) {
            return $args;
        }

        $ean_codes = self::resolve( $search, $customer_id );

        if ( ! empty( $ean_codes ) ) {
            unset( $args['s'], $args['search'] );
            $args = self::apply_exact_ean_match( $args, $ean_codes );
        }

        return $args;
    }

    // ── Handler: WC REST API v3 ───────────────────────────────────────────────

    /**
     * woocommerce_rest_product_object_query
     * Fires for GET /wp-json/wc/v3/products?search=...&customer_id=...
     * WC v3 also stores the search term under 's' inside $args
     * (despite the URL param being named 'search').
     */
    public static function resolve_in_rest_v3( array $args, WP_REST_Request $request ): array {
        $search      = sanitize_text_field( $args['s'] ?? $args['search'] ?? '' );
        $customer_id = absint( $request->get_param( 'customer_id' ) ?? 0 ) ?: null;

        if ( empty( $search ) ) {
            return $args;
        }

        $ean_codes = self::resolve( $search, $customer_id );

        if ( ! empty( $ean_codes ) ) {
            unset( $args['s'], $args['search'] );
            $args = self::apply_exact_ean_match( $args, $ean_codes );
        }

        return $args;
    }

    // ── Handler: WooCommerce Store API ────────────────────────────────────────

    /**
     * woocommerce_blocks_product_query_args
     * Fires for GET /wp-json/wc/store/v1/products?search=...&customer_id=...
     * Store API uses 's' (WP_Query convention) inside $args.
     */
    public static function resolve_in_store_api( array $args, WP_REST_Request $request ): array {
        $search      = sanitize_text_field( $args['s'] ?? $args['search'] ?? '' );
        $customer_id = absint( $request->get_param( 'customer_id' ) ?? 0 ) ?: null;

        if ( empty( $search ) ) {
            return $args;
        }

        $ean_codes = self::resolve( $search, $customer_id );

        if ( ! empty( $ean_codes ) ) {
            unset( $args['s'], $args['search'] );
            $args = self::apply_exact_ean_match( $args, $ean_codes );
        }

        return $args;
    }

    // ── Handler: FiboSearch Pro ───────────────────────────────────────────────

    /**
     * dgwt/wcas/search_query
     * FiboSearch Pro AJAX autocomplete. Only registered when FiboSearch
     * is active. Falls back to session user (no customer_id param available
     * in FiboSearch's filter signature).
     *
     * When multiple EANs are resolved, returns the first one only — FiboSearch
     * handles its own product query internally and only accepts a string here.
     */
    public static function resolve_in_fibosearch( string $search_query ): string {
        $search = sanitize_text_field( $search_query );

        if ( empty( $search ) ) {
            return $search_query;
        }

        // FiboSearch does not carry customer_id — use session user only.
        $ean_codes = self::resolve( $search );

        return $ean_codes[0] ?? $search_query;
    }

    // ── Handler: MStore API Scanner ───────────────────────────────────────────

    /**
     * rest_pre_dispatch — intercepts /api/flutter_woo/scanner before MStore
     * API handles it. MStore's scanner checks _ywbc_barcode_value, _alg_ean,
     * and _sku but never _global_unique_id. We short-circuit with a full
     * product response when a match is found via EAN.
     *
     * Scanner does not support customer_id param — identity comes from the
     * token (which sets current user via validateCookieLogin).
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

        // Admin bypass.
        if ( $user_id && (
            user_can( $user_id, 'manage_woocommerce' ) ||
            user_can( $user_id, 'manage_options' )
        ) ) {
            return $result;
        }

        // Step 1: resolve alias → EAN codes.
        $ean_codes = $user_id
            ? CIA_DB::resolve_aliases( $user_id, $raw_data )
            : [ $raw_data ];

        if ( empty( $ean_codes ) ) {
            $ean_codes = [ $raw_data ]; // no alias — try the raw value as-is
        }

        // Step 2: find a product by _global_unique_id.
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
            return $result; // no match — let MStore API handle normally
        }

        // Step 3: short-circuit with a response matching MStore API's shape.
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

    // ── wp-rest-cache exclusion ───────────────────────────────────────────────

    /**
     * Shared callback for both wp_rest_cache/skip_cache (reading) and
     * wp_rest_cache/skip_caching (writing).
     *
     * Skips the cache entirely for any authenticated product search so that
     * alias resolution always runs against live data.
     * Also skips when customer_id param is present, regardless of session.
     */
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
        $has_customer_id   = (bool) $request->get_param( 'customer_id' );
        $is_authed         = is_user_logged_in();
        $is_product_search = (bool) preg_match(
            '#^/wc/(v[23]|store/v1)/products$#',
            $route
        );

        return $is_product_search && $has_search && ( $is_authed || $has_customer_id );
    }

    // ── FiboSearch: late registration ─────────────────────────────────────────

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

    // ── Shared utility ────────────────────────────────────────────────────────

    /**
     * Injects an exact EAN meta_query into the WP_Query args.
     *
     * Uses '=' for a single EAN and 'IN' for multiple, so every product
     * associated with the alias is returned in one query.
     *
     * Callers must unset 's'/'search' from $args before invoking this.
     *
     * @param  array    $args      WP_Query args array.
     * @param  string[] $ean_codes One or more EAN/item code strings.
     * @return array               Modified args with meta_query injected.
     */
    private static function apply_exact_ean_match( array $args, array $ean_codes ): array {
        $args['meta_query']   = $args['meta_query'] ?? [];
        $args['meta_query'][] = [
            'key'     => self::EAN_META_KEY,
            'value'   => count( $ean_codes ) === 1 ? $ean_codes[0] : $ean_codes,
            'compare' => count( $ean_codes ) === 1 ? '='           : 'IN',
        ];
        return $args;
    }
}
