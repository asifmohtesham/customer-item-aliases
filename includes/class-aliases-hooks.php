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
     *
     * Common values:
     *   '_global_unique_id'  — WooCommerce core v9.2+
     *   '_ean'               — Germanized / WP-Lister
     *   '_yith_ean'          — YITH WooCommerce EAN
     *   '_alg_ean'           — Ean for WooCommerce plugin
     */
    private const EAN_META_KEY = '_global_unique_id';

    public static function init(): void {

        // ── MStore API Scanner: resolve alias + _global_unique_id lookup ──────
        add_filter(
            'rest_pre_dispatch',
            [ __CLASS__, 'resolve_in_scanner' ],
            10, 3
        );

        // ── wp-rest-cache: skip caching authenticated product searches ─────────
        add_filter(
            'wp_rest_cache/skip_caching',
            [ __CLASS__, 'skip_cache_for_product_search' ],
            10, 3
        );

        // ── FluxStore: WooCommerce REST API v2 ───────────────────────────────
        add_filter(
            'woocommerce_rest_product_query',
            [ __CLASS__, 'resolve_in_rest_v2' ],
            10, 2
        );

        // ── FluxStore: WooCommerce REST API v3 ───────────────────────────────
        add_filter(
            'woocommerce_rest_product_object_query',
            [ __CLASS__, 'resolve_in_rest_v3' ],
            10, 2
        );

        // ── FluxStore: WooCommerce Store API (Blocks) ─────────────────────────
        add_filter(
            'woocommerce_blocks_product_query_args',
            [ __CLASS__, 'resolve_in_store_api' ],
            10, 2
        );

        // ── FiboSearch Pro (optional) ─────────────────────────────────────────
        add_action( 'init', [ __CLASS__, 'register_fibosearch_hook' ], 20 );
    }

    // ── Core alias resolver ───────────────────────────────────────────────────

    /**
     * Resolves a search term to an EAN8 code for the current user.
     *
     * Returns null when:
     *   - The user is a guest (unauthenticated)
     *   - The user is an administrator or shop manager (admin bypass)
     *   - No alias is found for the user + search term combination
     *
     * @param  string $search  The raw search term from the query args.
     * @return string|null     The resolved EAN8, or null to leave args unchanged.
     */
    private static function resolve( string $search ): ?string {
        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            return null; // guest — no alias resolution
        }

        // Administrators and shop managers search freely — no alias substitution.
        if ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) {
            return null;
        }

        // CIA_DB::resolve_alias() returns ?string: the EAN8 or null if not found.
        return CIA_DB::resolve_alias( $user_id, $search );
    }

    // ── Handler: WC REST API v2 ───────────────────────────────────────────────

    /**
     * woocommerce_rest_product_query
     * Fires for GET /wp-json/wc/v2/products?search=...
     * WC v2 uses 's' as the search key inside $args.
     */
    public static function resolve_in_rest_v2( array $args, WP_REST_Request $request ): array {
        $search = sanitize_text_field( $args['s'] ?? $args['search'] ?? '' );

        if ( empty( $search ) ) {
            return $args;
        }

        $ean8 = self::resolve( $search );

        if ( $ean8 ) {
            unset( $args['s'], $args['search'] );
            $args = self::apply_exact_ean_match( $args, $ean8 );
        }

        return $args;
    }

    // ── Handler: WC REST API v3 ───────────────────────────────────────────────

    /**
     * woocommerce_rest_product_object_query
     * Fires for GET /wp-json/wc/v3/products?search=...
     * WC v3 uses 'search' as the key inside $args.
     */
    public static function resolve_in_rest_v3( array $args, WP_REST_Request $request ): array {
        $search = sanitize_text_field( $args['search'] ?? '' );

        if ( empty( $search ) ) {
            return $args;
        }

        $ean8 = self::resolve( $search );

        if ( $ean8 ) {
            unset( $args['search'] );
            $args = self::apply_exact_ean_match( $args, $ean8 );
        }

        return $args;
    }

    // ── Handler: WooCommerce Store API ────────────────────────────────────────

    /**
     * woocommerce_blocks_product_query_args
     * Fires for GET /wp-json/wc/store/v1/products?search=...
     * Store API uses 's' (WP_Query convention) inside $args.
     */
    public static function resolve_in_store_api( array $args, WP_REST_Request $request ): array {
        $search = sanitize_text_field( $args['s'] ?? '' );

        if ( empty( $search ) ) {
            return $args;
        }

        $ean8 = self::resolve( $search );

        if ( $ean8 ) {
            unset( $args['s'] );
            $args = self::apply_exact_ean_match( $args, $ean8 );
        }

        return $args;
    }

    // ── Handler: FiboSearch Pro ───────────────────────────────────────────────

    /**
     * dgwt/wcas/search_query
     * FiboSearch Pro AJAX autocomplete. Only registered when FiboSearch is active.
     */
    public static function resolve_in_fibosearch( string $search_query ): string {
        $search = sanitize_text_field( $search_query );

        if ( empty( $search ) ) {
            return $search_query;
        }

        $ean8 = self::resolve( $search );

        return $ean8 ?? $search_query;
    }

    // ── Handler: MStore API Scanner ───────────────────────────────────────────

    /**
     * rest_pre_dispatch — intercepts /api/flutter_woo/scanner before MStore
     * API handles it. MStore's scanner checks _ywbc_barcode_value, _alg_ean,
     * and _sku but never _global_unique_id. We short-circuit with a full
     * product response when we find a match via EAN.
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

        // Admin bypass: let MStore API's own scanner handle it natively.
        if ( $user_id && (
            user_can( $user_id, 'manage_woocommerce' ) ||
            user_can( $user_id, 'manage_options' )
        ) ) {
            return $result;
        }

        // Step 1: resolve alias to EAN8 if user is logged in.
        $ean8 = $raw_data;
        if ( $user_id ) {
            $resolved = CIA_DB::resolve_alias( $user_id, $raw_data );
            if ( $resolved ) {
                $ean8 = $resolved;
            }
        }

        // Step 2: look up post ID by _global_unique_id.
        global $wpdb;
        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = %s
                   AND meta_value = %s
                 LIMIT 1",
                self::EAN_META_KEY,
                $ean8
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
     * wp_rest_cache/skip_caching
     * Prevents wp-rest-cache from serving a cached empty response for
     * authenticated product searches (alias resolution is user-specific).
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
        $is_authed         = is_user_logged_in();
        $is_product_search = (bool) preg_match(
            '#^/wc/(v[23]|store/v1)/products$#',
            $route
        );

        return $is_product_search && $has_search && $is_authed;
    }

    // ── FiboSearch: late registration ─────────────────────────────────────────

    public static function register_fibosearch_hook(): void {
        if ( has_filter( 'dgwt/wcas/search_query' ) || self::is_fibosearch_active() ) {
            add_filter(
                'dgwt/wcas/search_query',
                [ __CLASS__, 'resolve_in_fibosearch' ],
                10, 1
            );
        }
    }

    private static function is_fibosearch_active(): bool {
        return defined( 'DGWT_WCAS_VERSION' ) ||
               class_exists( 'DgoraWcas\Engine\SearchEngine' ) ||
               function_exists( 'dgwt_wcas' );
    }

    // ── Shared utility ────────────────────────────────────────────────────────

    /**
     * Injects an exact EAN meta_query, replacing the broad keyword search.
     * Unsetting 's'/'search' from $args must be done by the caller before
     * this method is invoked.
     */
    private static function apply_exact_ean_match( array $args, string $ean8 ): array {
        $args['meta_query']   = $args['meta_query'] ?? [];
        $args['meta_query'][] = [
            'key'     => self::EAN_META_KEY,
            'value'   => $ean8,
            'compare' => '=',
        ];
        return $args;
    }
}
