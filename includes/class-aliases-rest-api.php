<?php
defined( 'ABSPATH' ) || exit;

/**
 * CIA_REST_API — WP REST API controller for Customer Item Aliases.
 *
 * Base URL: /wp-json/cia/v1/
 *
 * Endpoints
 * ┌─────────────────────────┬────────┬──────────────────────────────────────┐
 * │ Route                    │ Method │ Description                          │
 * ├─────────────────────────┼────────┼──────────────────────────────────────┤
 * │ /cia/v1/aliases          │ GET    │ List aliases (filterable + paginated) │
 * │ /cia/v1/aliases          │ POST   │ Create a new alias                    │
 * │ /cia/v1/aliases/{id}     │ GET    │ Get a single alias                    │
 * │ /cia/v1/aliases/{id}     │ PUT    │ Full replacement update               │
 * │ /cia/v1/aliases/{id}     │ PATCH  │ Partial update                        │
 * │ /cia/v1/aliases/{id}     │ DELETE │ Delete an alias                       │
 * │ /cia/v1/resolve          │ GET    │ Resolve alias code → EAN(s) + products│
 * └─────────────────────────┴────────┴──────────────────────────────────────┘
 *
 * Authentication
 *   CRUD routes require the manage_woocommerce capability.
 *   Use WP Application Passwords (WP 5.6+) for machine-to-machine access.
 *
 *   /resolve requires any authenticated user. Non-admins are automatically
 *   scoped to their own aliases; admins may pass ?customer_id= to scope to
 *   a specific customer, or omit it to resolve globally.
 *
 * Pagination headers (CRUD list endpoint)
 *   X-WP-Total       — total matching rows
 *   X-WP-TotalPages  — total pages at current per_page
 */
class CIA_REST_API {

    const NAMESPACE = 'cia/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    public static function register_routes(): void {

        // Aliases — collection
        register_rest_route( self::NAMESPACE, '/aliases', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_aliases' ],
                'permission_callback' => [ __CLASS__, 'perm_manage_aliases' ],
                'args'                => self::collection_args(),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'create_alias' ],
                'permission_callback' => [ __CLASS__, 'perm_manage_aliases' ],
                'args'                => self::write_args(),
            ],
        ] );

        // Aliases — single item
        register_rest_route( self::NAMESPACE, '/aliases/(?P<id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_alias' ],
                'permission_callback' => [ __CLASS__, 'perm_manage_aliases' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,   // PUT + PATCH
                'callback'            => [ __CLASS__, 'update_alias' ],
                'permission_callback' => [ __CLASS__, 'perm_manage_aliases' ],
                'args'                => array_merge( [ 'id' => self::id_arg() ], self::write_args( false ) ),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ __CLASS__, 'delete_alias' ],
                'permission_callback' => [ __CLASS__, 'perm_manage_aliases' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );

        // Resolve — alias code → EAN codes + matching products
        register_rest_route( self::NAMESPACE, '/resolve', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'resolve' ],
            'permission_callback' => [ __CLASS__, 'perm_authenticated' ],
            'args'                => [
                'alias_code' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'The alias code to resolve.',
                ],
                'customer_id' => [
                    'required'    => false,
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Customer user ID. Admins only — defaults to the authenticated user.',
                ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Permission callbacks
    // -------------------------------------------------------------------------

    /**
     * Requires manage_woocommerce (shop manager / admin).
     * Returns 401 for unauthenticated requests, 403 for authenticated but
     * insufficient-capability requests.
     */
    public static function perm_manage_aliases(): bool|WP_Error {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            __( 'You do not have permission to manage customer aliases.', 'customer-item-aliases' ),
            [ 'status' => is_user_logged_in() ? 403 : 401 ]
        );
    }

    /**
     * Requires any authenticated user (used by /resolve).
     */
    public static function perm_authenticated(): bool|WP_Error {
        if ( is_user_logged_in() ) {
            return true;
        }

        return new WP_Error(
            'rest_not_logged_in',
            __( 'You must be logged in to use the alias resolver.', 'customer-item-aliases' ),
            [ 'status' => 401 ]
        );
    }

    // -------------------------------------------------------------------------
    // GET /aliases
    // -------------------------------------------------------------------------

    /**
     * List aliases with optional filters.
     *
     * ?search=       LIKE match on alias_code and ean8_code.
     * ?customer_id=  Filter to one customer.
     * ?status=       all | active | disabled | expired
     * ?page=         Pagination page (default 1).
     * ?per_page=     Items per page 1–100 (default 20).
     * ?orderby=      id | alias_code | ean8_code | created_at | expires_at
     * ?order=        asc | desc
     */
    public static function get_aliases( WP_REST_Request $request ): WP_REST_Response {
        $per_page = absint( $request->get_param( 'per_page' ) ?? 20 );
        $page     = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );

        $args = [
            'search'      => $request->get_param( 'search' )      ?? '',
            'customer_id' => absint( $request->get_param( 'customer_id' ) ?? 0 ),
            'status'      => $request->get_param( 'status' )       ?? 'all',
            'orderby'     => $request->get_param( 'orderby' )      ?? 'id',
            'order'       => strtoupper( $request->get_param( 'order' ) ?? 'asc' ),
            'per_page'    => $per_page,
            'offset'      => ( $page - 1 ) * $per_page,
        ];

        $total    = CIA_DB::count_rows_filtered( $args );
        $rows     = CIA_DB::get_rows( $args );
        $items    = array_map( [ __CLASS__, 'prepare_item' ], $rows );
        $response = rest_ensure_response( $items );

        $response->header( 'X-WP-Total',      $total );
        $response->header( 'X-WP-TotalPages', (int) ceil( $total / max( 1, $per_page ) ) );

        return $response;
    }

    // -------------------------------------------------------------------------
    // POST /aliases
    // -------------------------------------------------------------------------

    public static function create_alias( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $validated = self::validate_write_params( $request );
        if ( is_wp_error( $validated ) ) return $validated;

        [ 'user_id' => $user_id, 'data' => $data ] = $validated;

        $duplicate = CIA_DB::find_exact_duplicate( $user_id, $data['alias_code'], $data['ean8_code'] );
        if ( $duplicate ) {
            return new WP_Error(
                'alias_duplicate',
                __( 'This exact alias mapping already exists for this customer.', 'customer-item-aliases' ),
                [ 'status' => 409 ]
            );
        }

        $new_id = CIA_DB::insert( array_merge( [ 'user_id' => $user_id ], $data ) );
        if ( ! $new_id ) {
            return new WP_Error(
                'alias_create_failed',
                __( 'Failed to create alias — database error.', 'customer-item-aliases' ),
                [ 'status' => 500 ]
            );
        }

        $response = rest_ensure_response( self::prepare_item( CIA_DB::get_row( $new_id ) ) );
        $response->set_status( 201 );
        $response->header( 'Location', rest_url( self::NAMESPACE . '/aliases/' . $new_id ) );
        return $response;
    }

    // -------------------------------------------------------------------------
    // GET /aliases/{id}
    // -------------------------------------------------------------------------

    public static function get_alias( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $row = CIA_DB::get_row( (int) $request['id'] );
        if ( ! $row ) {
            return self::not_found();
        }
        return rest_ensure_response( self::prepare_item( $row ) );
    }

    // -------------------------------------------------------------------------
    // PUT|PATCH /aliases/{id}
    // -------------------------------------------------------------------------

    /**
     * Handles both PUT (full replacement) and PATCH (partial update).
     *
     * PUT  — all of customer_id, alias_code, ean8_code must be provided.
     * PATCH — only supplied fields are changed; omitted fields keep their value.
     */
    public static function update_alias( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id  = (int) $request['id'];
        $row = CIA_DB::get_row( $id );
        if ( ! $row ) return self::not_found();

        $is_patch = strtoupper( $request->get_method() ) === 'PATCH';

        // PUT requires all core fields
        if ( ! $is_patch ) {
            foreach ( [ 'customer_id', 'alias_code', 'ean8_code' ] as $field ) {
                if ( $request->get_param( $field ) === null ) {
                    return new WP_Error(
                        'missing_param',
                        sprintf( __( 'PUT requires “%s”.', 'customer-item-aliases' ), $field ),
                        [ 'status' => 422 ]
                    );
                }
            }
        }

        // Merge incoming params over existing values (PATCH semantics)
        $user_id    = $request->get_param( 'customer_id' ) !== null
            ? absint( $request->get_param( 'customer_id' ) )
            : (int) $row['user_id'];

        $alias_code = $request->get_param( 'alias_code' ) !== null
            ? sanitize_text_field( $request->get_param( 'alias_code' ) )
            : $row['alias_code'];

        $ean8_code  = $request->get_param( 'ean8_code' ) !== null
            ? sanitize_text_field( $request->get_param( 'ean8_code' ) )
            : $row['ean8_code'];

        $is_active  = $request->get_param( 'is_active' ) !== null
            ? (bool) $request->get_param( 'is_active' )
            : (bool) $row['is_active'];

        // expires_at: null means "clear"; not provided means "keep existing"
        if ( $request->get_param( 'expires_at' ) !== null ) {
            $raw        = sanitize_text_field( $request->get_param( 'expires_at' ) );
            $expires_at = $raw ? date( 'Y-m-d H:i:s', strtotime( $raw ) ) : null;
        } else {
            $expires_at = $row['expires_at'] ?? null;
        }

        // Validate resolved values
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return new WP_Error(
                'invalid_customer',
                __( 'Customer not found.', 'customer-item-aliases' ),
                [ 'status' => 422 ]
            );
        }
        if ( ! preg_match( '/^\d{8}$/', $ean8_code ) ) {
            return new WP_Error(
                'invalid_ean8',
                __( 'EAN8 must be exactly 8 digits.', 'customer-item-aliases' ),
                [ 'status' => 422 ]
            );
        }

        $duplicate = CIA_DB::find_exact_duplicate( $user_id, $alias_code, $ean8_code, $id );
        if ( $duplicate ) {
            return new WP_Error(
                'alias_duplicate',
                __( 'This exact alias mapping already exists for this customer.', 'customer-item-aliases' ),
                [ 'status' => 409 ]
            );
        }

        $ok = CIA_DB::update( $id, [
            'user_id'    => $user_id,
            'alias_code' => $alias_code,
            'ean8_code'  => $ean8_code,
            'is_active'  => $is_active ? 1 : 0,
            'expires_at' => $expires_at,
        ] );

        if ( $ok === false ) {
            return new WP_Error(
                'alias_update_failed',
                __( 'Failed to update alias — database error.', 'customer-item-aliases' ),
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( self::prepare_item( CIA_DB::get_row( $id ) ) );
    }

    // -------------------------------------------------------------------------
    // DELETE /aliases/{id}
    // -------------------------------------------------------------------------

    public static function delete_alias( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id  = (int) $request['id'];
        $row = CIA_DB::get_row( $id );
        if ( ! $row ) return self::not_found();

        CIA_DB::delete( $id );

        return rest_ensure_response( [
            'deleted'  => true,
            'previous' => self::prepare_item( $row ),
        ] );
    }

    // -------------------------------------------------------------------------
    // GET /resolve
    // -------------------------------------------------------------------------

    /**
     * Resolve an alias code to its EAN codes and matching WooCommerce products.
     *
     * Scope:
     *   Admin + ?customer_id=N  → resolve for customer N.
     *   Admin + no customer_id  → resolve globally (all customers).
     *   Non-admin               → always scoped to the authenticated user.
     *
     * Response:
     * {
     *   "alias_code":  "CUST-001",
     *   "customer_id": 3,          // null if global admin resolve
     *   "ean_codes":   ["30000070"],
     *   "products":    [{ id, ean8_code, name, sku, price, stock_status, permalink }],
     *   "count":       1
     * }
     */
    public static function resolve( WP_REST_Request $request ): WP_REST_Response {
        $alias_code  = sanitize_text_field( $request->get_param( 'alias_code' ) );
        $param_cid   = absint( $request->get_param( 'customer_id' ) ?? 0 );
        $is_admin    = current_user_can( 'manage_woocommerce' );
        $current_uid = get_current_user_id();

        // Determine effective customer scope
        if ( $is_admin && $param_cid ) {
            $customer_id = $param_cid;           // admin scoped to specific customer
        } elseif ( $is_admin && ! $param_cid ) {
            $customer_id = null;                  // admin global
        } else {
            $customer_id = $current_uid;          // non-admin: always self
        }

        // Resolve alias → EAN codes
        $ean_codes = $customer_id
            ? CIA_DB::resolve_aliases( $customer_id, $alias_code )
            : CIA_DB::resolve_aliases_global( $alias_code );

        // Find matching published products via EAN meta
        $products = [];
        if ( ! empty( $ean_codes ) ) {
            $ean_meta_key = (string) apply_filters( 'cia_ean_meta_key', CIA_EAN_META_KEY );
            $products     = self::find_products_by_ean( $ean_codes, $ean_meta_key );
        }

        return rest_ensure_response( [
            'alias_code'  => $alias_code,
            'customer_id' => $customer_id,
            'ean_codes'   => $ean_codes,
            'products'    => $products,
            'count'       => count( $products ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Product lookup helper
    // -------------------------------------------------------------------------

    /**
     * Look up published products whose $ean_meta_key meta matches any of the
     * provided EAN codes. Returns a lightweight product array (not full WC REST
     * product objects) to keep the response fast and minimal.
     *
     * @param  string[] $ean_codes
     * @param  string   $ean_meta_key
     * @return array[]
     */
    private static function find_products_by_ean( array $ean_codes, string $ean_meta_key ): array {
        global $wpdb;

        if ( empty( $ean_codes ) ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $ean_codes ), '%s' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value AS ean8_code
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key   = %s
                   AND pm.meta_value IN ({$placeholders})
                   AND p.post_type   = 'product'
                   AND p.post_status = 'publish'
                 ORDER BY pm.post_id ASC",
                array_merge( [ $ean_meta_key ], $ean_codes )
            ),
            ARRAY_A
        ) ?: [];

        $products = [];
        foreach ( $rows as $r ) {
            $pid = (int) $r['post_id'];
            $p   = wc_get_product( $pid );
            if ( ! $p ) continue;

            $image_id  = $p->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );

            $products[] = [
                'id'           => $pid,
                'ean8_code'    => $r['ean8_code'],
                'name'         => $p->get_name(),
                'sku'          => $p->get_sku(),
                'price'        => $p->get_price(),
                'regular_price'=> $p->get_regular_price(),
                'sale_price'   => $p->get_sale_price(),
                'currency'     => get_woocommerce_currency(),
                'stock_status' => $p->get_stock_status(),
                'stock_qty'    => $p->get_stock_quantity(),
                'permalink'    => get_permalink( $pid ),
                'image'        => $image_url ?: null,
            ];
        }

        return $products;
    }

    // -------------------------------------------------------------------------
    // Item preparation
    // -------------------------------------------------------------------------

    /**
     * Shape a raw DB row into the REST API response object.
     * Includes a nested 'customer' object and HAL-style '_links'.
     */
    private static function prepare_item( ?array $row ): ?array {
        if ( ! $row ) return null;

        $user = get_userdata( (int) $row['user_id'] );

        return [
            'id'          => (int)  $row['id'],
            'customer_id' => (int)  $row['user_id'],
            'customer'    => $user ? [
                'id'    => (int) $user->ID,
                'name'  => $user->display_name,
                'email' => $user->user_email,
            ] : null,
            'alias_code'  => $row['alias_code'],
            'ean8_code'   => $row['ean8_code'],
            'is_active'   => (bool) $row['is_active'],
            'expires_at'  => $row['expires_at'] ?? null,
            'created_at'  => $row['created_at'],
            '_links'      => [
                'self'       => [ [ 'href' => rest_url( self::NAMESPACE . '/aliases/' . $row['id'] ) ] ],
                'collection' => [ [ 'href' => rest_url( self::NAMESPACE . '/aliases' ) ] ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Shared write-param validator
    // -------------------------------------------------------------------------

    /**
     * Validate and sanitize the core write params (used by create_alias).
     *
     * @return WP_Error|array{ user_id: int, data: array }
     */
    private static function validate_write_params( WP_REST_Request $request ): WP_Error|array {
        $user_id = absint( $request->get_param( 'customer_id' ) );
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return new WP_Error(
                'invalid_customer',
                __( 'Customer not found.', 'customer-item-aliases' ),
                [ 'status' => 422 ]
            );
        }

        $alias_code = sanitize_text_field( $request->get_param( 'alias_code' ) );
        $ean8_code  = sanitize_text_field( $request->get_param( 'ean8_code' ) );

        if ( ! preg_match( '/^\d{8}$/', $ean8_code ) ) {
            return new WP_Error(
                'invalid_ean8',
                __( 'EAN8 must be exactly 8 digits.', 'customer-item-aliases' ),
                [ 'status' => 422 ]
            );
        }

        $raw_exp    = sanitize_text_field( $request->get_param( 'expires_at' ) ?? '' );
        $expires_at = $raw_exp ? date( 'Y-m-d H:i:s', strtotime( $raw_exp ) ) : null;

        return [
            'user_id' => $user_id,
            'data'    => [
                'alias_code' => $alias_code,
                'ean8_code'  => $ean8_code,
                'is_active'  => $request->get_param( 'is_active' ) !== false ? 1 : 0,
                'expires_at' => $expires_at,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Argument schema helpers
    // -------------------------------------------------------------------------

    private static function id_arg(): array {
        return [
            'type'              => 'integer',
            'minimum'           => 1,
            'required'          => true,
            'sanitize_callback' => 'absint',
            'description'       => 'Alias record ID.',
        ];
    }

    /**
     * Write args shared by POST (required=true) and PUT/PATCH (required=false).
     * For PUT we validate required fields manually in the callback, which lets
     * us share one route registration for both PUT and PATCH.
     */
    private static function write_args( bool $required = true ): array {
        return [
            'customer_id' => [
                'type'              => 'integer',
                'minimum'           => 1,
                'required'          => $required,
                'sanitize_callback' => 'absint',
                'description'       => 'WordPress user ID of the customer who owns this alias.',
            ],
            'alias_code'  => [
                'type'              => 'string',
                'required'          => $required,
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Customer-provided alias identifier (e.g. their internal part number).',
            ],
            'ean8_code'   => [
                'type'              => 'string',
                'required'          => $required,
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Exactly 8-digit EAN8 product barcode.',
            ],
            'is_active'   => [
                'type'        => 'boolean',
                'default'     => true,
                'description' => 'Whether the alias is currently active.',
            ],
            'expires_at'  => [
                'type'              => [ 'string', 'null' ],
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Optional expiry (ISO 8601 or MySQL datetime). Pass null or empty string to clear.',
            ],
        ];
    }

    private static function collection_args(): array {
        return [
            'page'        => [ 'type' => 'integer', 'minimum' => 1,   'maximum' => 9999, 'default' => 1 ],
            'per_page'    => [ 'type' => 'integer', 'minimum' => 1,   'maximum' => 100,  'default' => 20 ],
            'search'      => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            'customer_id' => [ 'type' => 'integer', 'minimum' => 1 ],
            'status'      => [
                'type'    => 'string',
                'enum'    => [ 'all', 'active', 'disabled', 'expired' ],
                'default' => 'all',
            ],
            'orderby'     => [
                'type'    => 'string',
                'enum'    => [ 'id', 'alias_code', 'ean8_code', 'created_at', 'expires_at' ],
                'default' => 'id',
            ],
            'order'       => [
                'type'    => 'string',
                'enum'    => [ 'asc', 'desc' ],
                'default' => 'asc',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Micro-helpers
    // -------------------------------------------------------------------------

    private static function not_found(): WP_Error {
        return new WP_Error(
            'alias_not_found',
            __( 'Alias not found.', 'customer-item-aliases' ),
            [ 'status' => 404 ]
        );
    }
}
