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
 * all FluxStore requests. Customer identity is resolved independently of
 * WordPress's current user by reading the `User-Cookie` HTTP header that
 * FluxStore already embeds in every authenticated request.
 *
 * WHY NOT determine_current_user?
 * WooCommerce's own OAuth handler hooks determine_current_user at priority 10
 * and sets the current user to the admin / service account whose consumer key
 * is being used. Any hook at priority > 10 sees $user_id already set and
 * returns early. We therefore read the header directly inside each REST
 * handler instead, treating it as an explicit customer identity signal that
 * is fully independent of the WC OAuth layer.
 *
 * Customer identity resolution order (per REST handler):
 *   1. User-Cookie header decoded inline  ← FluxStore (no param needed)
 *   2. ?customer_id= query param          ← Postman / API testing
 *   3. get_current_user_id()              ← FiboSearch AJAX / session auth
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

    // ── User-Cookie header decoder ──────────────────────────────────────────────

    /**
     * Decode the FluxStore `User-Cookie` HTTP header into a WordPress user ID.
     *
     * FluxStore encodes the WordPress auth cookie as:
     *   User-Cookie: base64( urlencode( <wp_logged_in_cookie> ) )
     *
     * This is identical to the encoding MStore API uses for its own endpoints.
     * We apply the same decoding and then validate the cookie with WordPress's
     * cryptographic HMAC check (wp_validate_auth_cookie) before trusting it.
     *
     * Called directly from each REST handler so that it operates completely
     * independently of WordPress's current-user state, which is held by the
     * WC OAuth admin/service account at query time.
     *
     * @return int|null  Validated customer user ID, or null if absent / invalid.
     */
    private static function get_customer_id_from_user_cookie(): ?int {
        $raw_header = $_SERVER['HTTP_USER_COOKIE'] ?? '';
        if ( empty( $raw_header ) ) {
            return null;
        }

        // Decode: base64 → urldecode → WordPress auth cookie string.
        $cookie = urldecode( base64_decode( $raw_header ) );
        if ( empty( $cookie ) ) {
            return null;
        }

        // Cryptographic validation: checks HMAC, expiry, and session token.
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

    // ── Core alias resolver ───────────────────────────────────────────────────

    /**
     * Resolves a search term to ALL matching EAN codes for the given customer.
     *
     * Customer identity resolution order:
     *   1. Explicit $customer_id argument
     *      └─ sourced from User-Cookie header OR ?customer_id= param by caller
     *   2. get_current_user_id()
     *      └─ FiboSearch AJAX, Store API with session cookie, etc.
     *
     * NOTE: $customer_id intentionally bypasses the admin check because it
     * was resolved from the *customer's* own validated cookie, not from the
     * WC service-account OAuth session.
     *
     * Returns an empty array when:
     *   - No customer identity can be determined
     *   - The session-based user (fallback path only) is an admin/shop manager
     *   - No aliases exist for this customer + search term combination
     *
     * @param  string   $search       Raw search term from the query args.
     * @param  int|null $customer_id  Validated customer ID (bypasses admin check).
     * @return string[]               All matching EAN codes; empty = no resolution.
     */
    private static function resolve( string $search, ?int $customer_id = null ): array {
        if ( $customer_id ) {
            // Explicit, cryptographically validated customer identity.
            // Admin check intentionally skipped: the service-account admin is
            // NOT the one searching; the customer identified by their cookie is.
            return CIA_DB::resolve_aliases( $customer_id, $search );
        }

        // Fallback: session-based user (FiboSearch, browser, etc.)
        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            return [];
        }

        // For session-based fallback, skip admins / shop managers.
        if ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) {
            return [];
        }

        return CIA_DB::resolve_aliases( $user_id, $search );
    }

    // ── Shared: resolve customer identity for REST handlers ───────────────────

    /**
     * Determine customer ID for a WooCommerce REST product query.
     *
     * Priority:
     *   1. User-Cookie header (FluxStore — always present when logged in)
     *   2. ?customer_id= param  (Postman / explicit override)
     *
     * Returns null when neither is present; resolve() will then fall back
     * to get_current_user_id() (session / FiboSearch path).
     *
     * @param  WP_REST_Request $request  The current REST request.
     * @return int|null
     */
    private static function customer_id_for_request( WP_REST_Request $request ): ?int {
        // 1. User-Cookie header — FluxStore's implicit identity signal.
        $from_cookie = self::get_customer_id_from_user_cookie();
        if ( $from_cookie ) {
            return $from_cookie;
        }

        // 2. Explicit param — Postman / testing without a live app session.
        $from_param = absint( $request->get_param( 'customer_id' ) ?? 0 );
        if ( $from_param ) {
            return $from_param;
        }

        return null;
    }

    // ── Handler: WC REST API v2 ───────────────────────────────────────────────

    /**
     * woocommerce_rest_product_query
     * Fires for GET /wp-json/wc/v2/products?search=...
     */
    public static function resolve_in_rest_v2( array $args, WP_REST_Request $request ): array {
        $search      = sanitize_text_field( $args['s'] ?? $args['search'] ?? '' );
        $customer_id = self::customer_id_for_request( $request );

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
     * Fires for GET /wp-json/wc/v3/products?search=...
     * WC v3 maps the 'search' URL param to 's' inside $args.
     */
    public static function resolve_in_rest_v3( array $args, WP_REST_Request $request ): array {
        $search      = sanitize_text_field( $args['s'] ?? $args['search'] ?? '' );
        $customer_id = self::customer_id_for_request( $request );

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
     * Fires for GET /wp-json/wc/store/v1/products?search=...
     */
    public static function resolve_in_store_api( array $args, WP_REST_Request $request ): array {
        $search      = sanitize_text_field( $args['s'] ?? $args['search'] ?? '' );
        $customer_id = self::customer_id_for_request( $request );

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
     * is active. Falls back to session user (no request object available).
     *
     * When multiple EANs are resolved, returns the first one only — FiboSearch
     * handles its own product query and only accepts a single search string.
     */
    public static function resolve_in_fibosearch( string $search_query ): string {
        $search = sanitize_text_field( $search_query );

        if ( empty( $search ) ) {
            return $search_query;
        }

        // FiboSearch is browser-based — try User-Cookie header first, then session.
        $customer_id = self::get_customer_id_from_user_cookie();
        $ean_codes   = self::resolve( $search, $customer_id );

        return $ean_codes[0] ?? $search_query;
    }

    // ── Handler: MStore API Scanner ───────────────────────────────────────────

    /**
     * rest_pre_dispatch — intercepts /api/flutter_woo/scanner before MStore
     * API handles it. MStore's scanner checks _ywbc_barcode_value, _alg_ean,
     * and _sku but never _global_unique_id. We short-circuit with a full
     * product response when a match is found via EAN.
     *
     * Scanner sends User-Cookie in its request; MStore API validates it and
     * calls wp_set_current_user() before dispatch, so get_current_user_id()
     * already returns the correct customer here.
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
     * Bypasses cache for any product search that carries a customer identity
     * signal, ensuring alias resolution always runs against live data.
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
        $has_user_cookie   = ! empty( $_SERVER['HTTP_USER_COOKIE'] );
        $is_authed         = is_user_logged_in();
        $is_product_search = (bool) preg_match(
            '#^/wc/(v[23]|store/v1)/products$#',
            $route
        );

        return $is_product_search
            && $has_search
            && ( $is_authed || $has_customer_id || $has_user_cookie );
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
     * Uses '=' for a single EAN and 'IN' for multiple so every product
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
            'type'    => 'CHAR',
        ];
        return $args;
    }
}
