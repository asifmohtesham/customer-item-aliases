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

    public static function init(): void {

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
     * Shared logic: given a search term and authenticated user ID,
     * return the EAN8 if an alias match is found, or null otherwise.
     */
    private static function resolve( string $search_term ): ?string {
        $user_id = get_current_user_id();

        // Guests: no alias context — pass through unchanged
        if ( ! $user_id ) {
            return null;
        }

        return CIA_DB::resolve_alias( $user_id, $search_term );
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
     * Injects an exact SKU meta_query into the WP_Query args array.
     * Used by all REST API handlers to force a precise product match
     * instead of a broad keyword search.
     */
    private static function apply_exact_sku_match( array $args, string $ean8 ): array {
        $args['meta_query']   = $args['meta_query'] ?? [];
        $args['meta_query'][] = [
            'key'     => '_sku',
            'value'   => $ean8,
            'compare' => '=',
        ];
        return $args;
    }
}
