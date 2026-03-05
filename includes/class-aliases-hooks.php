<?php
defined( 'ABSPATH' ) || exit;

/**
 * CIA_Hooks
 *
 * Registers alias-resolution hooks for all three FluxStore/WooCommerce
 * search pathways, plus FiboSearch when available.
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
     * Change this constant to match your actual meta key.
     *
     * Common values:
     *   '_global_unique_id'  — WooCommerce core v9.2+
     *   '_ean'               — Germanized / WP-Lister
     *   '_yith_ean'          — YITH WooCommerce EAN
     *   '_alg_ean'           — Ean for WooCommerce plugin
     */
    private const EAN_META_KEY = '_global_unique_id';

    public static function init(): void {

        // ── MStore API Scanner: resolve alias then inject _global_unique_id lookup ──
        add_filter(
            'rest_pre_dispatch',
            [ __CLASS__, 'resolve_in_scanner' ],
            10, 3
        );

        // ── wp-rest-cache: prevent caching of authenticated product searches ──
        // wp-rest-cache fires 'wp_rest_cache_skip_caching' to allow plugins to
        // opt out specific requests. Must be registered before REST API boots.
        add_filter(
            'wp_rest_cache/skip_caching',
            [ __CLASS__, 'skip_cache_for_product_search' ],
            10, 3
        );

        // ── FluxStore: WooCommerce REST API v2 (default WooWorker.js version) ──
        // Fires for GET /wp-json/wc/v2/products?search=...
        add_filter(
            'woocommerce_rest_product_query',
            [ __CLASS__, 'resolve_in_rest_v2' ],
            10, 2
        );

        // ── FluxStore: WooCommerce REST API v3 ───────────────────────────────
        // Fires for GET /wp-json/wc/v3/products?search=...
        add_filter(
            'woocommerce_rest_product_object_query',
            [ __CLASS__, 'resolve_in_rest_v3' ],
            10, 2
        );

        // ── FluxStore: WooCommerce Store API (Blocks / newer FluxStore Pro) ──
        // Fires for GET /wp-json/wc/store/v1/products?search=...
        add_filter(
            'woocommerce_blocks_product_query_args',
            [ __CLASS__, 'resolve_in_store_api' ],
            10, 2
        );

        // ── FiboSearch Pro (optional) ─────────────────────────────────────────
        // Only register when the FiboSearch Pro filter actually exists,
        // making the CIA plugin fully functional without FiboSearch installed.
        add_action( 'init', [ __CLASS__, 'register_fibosearch_hook' ], 20 );
    }

    /**
     * Intercepts the MStore API scanner endpoint before it dispatches.
     *
     * The scanner queries _ywbc_barcode_value, _alg_ean, and _sku in sequence
     * but never checks _global_unique_id. We:
     *   1. Detect the scanner route.
     *   2. Resolve any alias for the current user.
     *   3. Overwrite the 'data' param with the resolved EAN8 so the scanner's
     *      own $wpdb query hits _global_unique_id as a last-resort fallback.
     *
     * Because MStore API falls back to get_post_id_from_meta('_sku', $raw_data),
     * we cannot inject a new meta key lookup into its chain without modifying the
     * plugin. Instead we add a WPDB lookup ourselves and short-circuit with a
     * fully-formed REST response if we find a match.
     *
     * @param  mixed           $result  null (not yet dispatched)
     * @param  WP_REST_Server  $server
     * @param  WP_REST_Request $request
     * @return mixed  null to continue normal dispatch, or WP_REST_Response to short-circuit
     */
    public static function resolve_in_scanner(
        $result,
        WP_REST_Server $server,
        WP_REST_Request $request
    ): mixed {

        // Only intercept the scanner route
        if ( $request->get_route() !== '/api/flutter_woo/scanner' ) {
            return $result;
        }

        $raw_data = sanitize_text_field( $request->get_param( 'data' ) ?? '' );
        if ( ! $raw_data ) {
            return $result;
        }

        $user_id  = get_current_user_id();
        $ean8     = $raw_data;

        // Step 1: resolve alias if user is logged in
        if ( $user_id ) {
            $resolved = CIA_DB::get_ean_for_user( $user_id, $raw_data );
            if ( $resolved ) {
                $ean8 = $resolved;
            }
        }

        // Step 2: look up post ID by _global_unique_id (what MStore API never checks)
        global $wpdb;
        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id
                FROM {$wpdb->postmeta}
                WHERE meta_key = %s
                  AND meta_value = %s
                LIMIT 1",
                '_global_unique_id',
                $ean8
            )
        );

        if ( ! $post_id ) {
            // No match via EAN — let MStore API's own chain handle it normally
            return $result;
        }

        // Step 3: short-circuit with the product response, matching MStore API's
        // own response shape: { type: 'product', data: [...] }
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

    /**
     * Instructs wp-rest-cache to skip caching for any product search request
     * made by an authenticated user.
     *
     * wp_rest_cache/skip_caching passes:
     *   $skip     bool             — current skip decision (false by default)
     *   $request  WP_REST_Request  — the current request object
     *   $response WP_REST_Response — the response about to be cached
     *
     * We skip caching when ALL three conditions are true:
     *   1. The route is a product listing/search endpoint
     *   2. A 'search' parameter is present (i.e. this is a search, not a browse)
     *   3. A user is authenticated (alias resolution is user-specific)
     */
    public static function skip_cache_for_product_search(
        bool $skip,
        WP_REST_Request $request,
        WP_REST_Response $response
    ): bool {

        if ( $skip ) {
            return true; // already skipped by something else — respect that
        }

        $route      = $request->get_route();
        $has_search = (bool) $request->get_param( 'search' );
        $is_authed  = is_user_logged_in();

        // Match /wc/v2/products, /wc/v3/products, /wc/store/v1/products
        $is_product_search = (bool) preg_match(
            '#^/wc/(v[23]|store/v1)/products$#',
            $route
        );

        return $is_product_search && $has_search && $is_authed;
    }

    // ── FiboSearch: late registration after all plugins are loaded ────────────

    public static function register_fibosearch_hook(): void {
        // dgwt/wcas/search_query is a FiboSearch Pro-only filter.
        // has_filter() returns false if FiboSearch is not active,
        // so CIA continues to work without it.
        if ( has_filter( 'dgwt/wcas/search_query' ) || self::is_fibosearch_active() ) {
            add_filter(
                'dgwt/wcas/search_query',
                [ __CLASS__, 'resolve_in_fibosearch' ],
                10, 1
            );
        }
    }

    /**
     * Check if FiboSearch is active without relying on its filter being
     * registered yet (useful on early hook timing).
     */
    private static function is_fibosearch_active(): bool {
        return defined( 'DGWT_WCAS_VERSION' ) ||
               class_exists( 'DgoraWcas\Engine\SearchEngine' ) ||
               function_exists( 'dgwt_wcas' );
    }

    // ── Core alias resolution ──────────────────────────────────────────────────

    /**
     * Core resolver called by all four hook callbacks.
     *
     * Resolution is intentionally skipped when:
     *   - No search term is present
     *   - The request is unauthenticated (guest)
     *   - The authenticated user has admin-level capabilities
     *     (administrators must search freely across all products)
     *
     * @param  array  $args     WP_Query / WC query args
     * @param  string $param    The key holding the search term (e.g. 'search', 's')
     * @return array            Modified args, or original args if resolution is skipped
     */
    private static function resolve( array $args, string $param ): array {
        $search = $args[ $param ] ?? '';
        if ( ! $search ) {
            return $args;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $args; // guest — no alias resolution
        }

        // ── Admin bypass ───────────────────────────────────────────────────────
        // Administrators (and shop managers) search all products freely.
        // manage_woocommerce covers both admin and shop_manager roles.
        if ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) {
            return $args;
        }

        // ── Customer alias resolution ──────────────────────────────────────────
        $ean8 = CIA_DB::get_ean_for_user( $user_id, $search );
        if ( ! $ean8 ) {
            return $args; // no alias found — let WooCommerce handle the search normally
        }

        return self::apply_exact_ean_match( $args, $ean8, $param );
    }


    // ── Handler: WC REST API v2 ───────────────────────────────────────────────

    /**
     * woocommerce_rest_product_query
     * Used by FluxStore's WooWorker.js (default wc/v2 version).
     * $args uses 's' key for search in v2.
     */
    public static function resolve_in_rest_v2( array $args, WP_REST_Request $request ): array {
        // v2 passes the search term via 's' in $args
        $search = sanitize_text_field( $args['s'] ?? $args['search'] ?? '' );

        if ( empty( $search ) ) {
            return $args;
        }

        $ean8 = self::resolve( $search );

        if ( $ean8 ) {
            // Unset both possible keys to avoid broad keyword fallback
            unset( $args['s'], $args['search'] );
            $args = self::apply_exact_sku_match( $args, $ean8 );
        }

        return $args;
    }

    // ── Handler: WC REST API v3 ───────────────────────────────────────────────

    /**
     * woocommerce_rest_product_object_query
     * Used by FluxStore when configured with wc/v3.
     * $args uses 'search' key.
     */
    public static function resolve_in_rest_v3( array $args, WP_REST_Request $request ): array {
        $search = sanitize_text_field( $args['search'] ?? '' );

        if ( empty( $search ) ) {
            return $args;
        }

        $ean8 = self::resolve( $search );

        if ( $ean8 ) {
            unset( $args['search'] );
            $args = self::apply_exact_sku_match( $args, $ean8 );
        }

        return $args;
    }

    // ── Handler: WooCommerce Store API ───────────────────────────────────────

    /**
     * woocommerce_blocks_product_query_args
     * Used by WooCommerce Blocks / Store API (/wp-json/wc/store/v1/products).
     * Newer FluxStore Pro versions may use this endpoint.
     * $args uses 's' key (WP_Query convention).
     */
    public static function resolve_in_store_api( array $args, \WP_REST_Request $request ): array {
        // Store API puts the search term in 's' (WP_Query standard)
        $search = sanitize_text_field( $args['s'] ?? '' );

        if ( empty( $search ) ) {
            return $args;
        }

        $ean8 = self::resolve( $search );

        if ( $ean8 ) {
            unset( $args['s'] );
            $args = self::apply_exact_sku_match( $args, $ean8 );
        }

        return $args;
    }

    // ── Handler: FiboSearch Pro ───────────────────────────────────────────────

    /**
     * dgwt/wcas/search_query
     * FiboSearch Pro AJAX autocomplete endpoint.
     * CIA works without this — the hook is only registered if
     * FiboSearch is detected as active.
     */
    public static function resolve_in_fibosearch( string $search_query ): string {
        $search = sanitize_text_field( $search_query );

        if ( empty( $search ) ) {
            return $search_query;
        }

        $ean8 = self::resolve( $search );

        // Return translated EAN8, or the original query unchanged
        return $ean8 ?? $search_query;
    }

    // ── Shared utility ────────────────────────────────────────────────────────

    /**
     * Injects an exact EAN meta_query into the WP_Query args array.
     * Replaces the broad keyword search with a precise meta value match.
     */
    private static function apply_exact_sku_match( array $args, string $ean8 ): array {
        $args['meta_query']   = $args['meta_query'] ?? [];
        $args['meta_query'][] = [
            'key'     => self::EAN_META_KEY,  // ← was '_sku', now targets EAN
            'value'   => $ean8,
            'compare' => '=',
        ];
        return $args;
    }
}
