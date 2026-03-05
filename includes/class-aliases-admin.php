<?php
defined( 'ABSPATH' ) || exit;

class CIA_Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu'  ] );
        add_action( 'admin_init', [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );

        // AJAX: authenticated users only (no wp_ajax_nopriv_ — guests can't access wp-admin)
        add_action( 'wp_ajax_cia_search_customers', [ __CLASS__, 'ajax_search_customers' ] );
    }

    // ── AJAX: Customer Search ──────────────────────────────────────────────────

    /**
     * Returns a paginated, searchable JSON list of customers for Select2.
     * Called by: GET admin-ajax.php?action=cia_search_customers&q=...&page=...
     *
     * Response shape expected by Select2:
     * {
     *   "results": [ { "id": 42, "text": "Jane Doe (jane@example.com)" }, ... ],
     *   "pagination": { "more": true }
     * }
     */
    public static function ajax_search_customers(): void {
        check_ajax_referer( 'cia_search_customers', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $search   = sanitize_text_field( $_GET['q']    ?? '' );
        $page     = max( 1, absint( $_GET['page']      ?? 1 ) );
        $per_page = 20;

        /*
         * WP_User_Query: single DB trip that returns both results AND total.
         * 'search' wraps the term in wildcards automatically when using '*term*'.
         * 'search_columns' restricts matching to name and email only.
         */
        $user_query = new WP_User_Query( [
            'role'           => 'customer',
            'search'         => $search ? ( '*' . $search . '*' ) : '',
            'search_columns' => [ 'display_name', 'user_email', 'user_login' ],
            'orderby'        => 'display_name',
            'order'          => 'ASC',
            'number'         => $per_page,
            'offset'         => ( $page - 1 ) * $per_page,
            'count_total'    => true,   // populates get_total() without a second query
            'fields'         => [ 'ID', 'display_name', 'user_email' ],
        ] );

        $results = array_map(
            static fn( $u ) => [
                'id'   => $u->ID,
                'text' => sprintf( '%s (%s)', $u->display_name, $u->user_email ),
            ],
            $user_query->get_results()
        );

        wp_send_json( [
            'results'    => $results,
            'pagination' => [
                'more' => ( $page * $per_page ) < $user_query->get_total(),
            ],
        ] );
    }

    // ── Menu Registration ─────────────────────────────────────────────────────

    public static function register_menu(): void {
        add_menu_page(
            __( 'Customer Item Aliases', 'customer-item-aliases' ),
            __( 'Item Aliases',          'customer-item-aliases' ),
            'manage_woocommerce',
            'cia-aliases',
            [ __CLASS__, 'render_list_page' ],
            'dashicons-tag',
            58
        );
    }

    // ── Action Handler ────────────────────────────────────────────────────────

    public static function handle_actions(): void {
        $page   = sanitize_key( $_REQUEST['page']   ?? '' );
        $action = sanitize_key( $_REQUEST['action']  ?? '' );

        if ( $page !== 'cia-aliases' ) return;
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        if ( $action === 'bulk-delete' && ! empty( $_POST['alias_ids'] ) ) {
            check_admin_referer( 'bulk-aliases' );
            CIA_DB::delete( array_map( 'absint', $_POST['alias_ids'] ) );
            self::redirect( 'bulk_deleted' );
        }

        if ( $action === 'delete' && ! empty( $_GET['id'] ) ) {
            $id = absint( $_GET['id'] );
            check_admin_referer( 'cia_delete_' . $id );
            CIA_DB::delete( $id );
            self::redirect( 'deleted' );
        }

        if ( $action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            check_admin_referer( 'cia_save_alias' );

            $id   = absint( $_POST['id'] ?? 0 );
            $data = [
                'user_id'    => absint( $_POST['user_id'] ),
                'alias_code' => sanitize_text_field( $_POST['alias_code'] ),
                'ean8_code'  => sanitize_text_field( $_POST['ean8_code'] ),
            ];

            if ( ! $data['user_id'] ) {
                self::redirect( 'no_user' );
            }

            if ( ! preg_match( '/^\d{8}$/', $data['ean8_code'] ) ) {
                self::redirect( 'invalid_ean8' );
            }

            $id ? CIA_DB::update( $id, $data ) : CIA_DB::insert( $data );
            self::redirect( 'saved' );
        }
    }

    // ── Page Renderers ────────────────────────────────────────────────────────

    public static function render_list_page(): void {
        $action = sanitize_key( $_GET['action'] ?? '' );

        if ( in_array( $action, [ 'add', 'edit' ], true ) ) {
            self::render_form_page( $action );
            return;
        }

        $table = new CIA_List_Table();
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php esc_html_e( 'Customer Item Aliases', 'customer-item-aliases' ); ?>
            </h1>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cia-aliases', 'action' => 'add' ], admin_url( 'admin.php' ) ) ); ?>"
               class="page-title-action">
                <?php esc_html_e( 'Add New', 'customer-item-aliases' ); ?>
            </a>
            <hr class="wp-header-end">
            <form method="get">
                <input type="hidden" name="page" value="cia-aliases" />
                <?php
                    $table->search_box( __( 'Search Aliases', 'customer-item-aliases' ), 'alias' );
                    $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    private static function render_form_page( string $action ): void {
        $id        = absint( $_GET['id'] ?? 0 );
        $item      = ( $action === 'edit' && $id ) ? CIA_DB::get_row( $id ) : null;
        $list_url  = add_query_arg( [ 'page' => 'cia-aliases' ], admin_url( 'admin.php' ) );

        // ── Enqueue WordPress-bundled Select2 ─────────────────────────────────
        // Both handles are registered by WordPress core — no new library needed.
        wp_enqueue_style( 'select2' );
        wp_enqueue_script( 'select2' );

        /*
         * Pass the AJAX URL, a short-lived nonce, and the currently selected
         * user's data to JavaScript without any inline <script> blocks.
         * wp_localize_script attaches a JS object before the select2 script runs.
         */
        $preselected = null;
        if ( ! empty( $item['user_id'] ) ) {
            $u = get_userdata( $item['user_id'] );
            if ( $u ) {
                $preselected = [
                    'id'   => $u->ID,
                    'text' => sprintf( '%s (%s)', $u->display_name, $u->user_email ),
                ];
            }
        }

        wp_localize_script( 'select2', 'ciaCustomerSelect', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'cia_search_customers' ),
            'placeholder' => __( '— Search by name or email —', 'customer-item-aliases' ),
            'preselected' => $preselected, // null on add, {id, text} on edit
        ] );

        // Inline initialisation — attached after select2 is ready
        wp_add_inline_script( 'select2', self::get_select2_init_script() );
        ?>

        <div class="wrap">
            <h1><?php echo $action === 'edit'
                ? esc_html__( 'Edit Alias',    'customer-item-aliases' )
                : esc_html__( 'Add New Alias', 'customer-item-aliases' );
            ?></h1>
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">
                &larr; <?php esc_html_e( 'Back to List', 'customer-item-aliases' ); ?>
            </a>
            <hr class="wp-header-end">

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                <?php wp_nonce_field( 'cia_save_alias' ); ?>
                <input type="hidden" name="page"   value="cia-aliases" />
                <input type="hidden" name="action" value="save" />
                <input type="hidden" name="id"     value="<?php echo absint( $item['id'] ?? 0 ); ?>" />

                <table class="form-table" role="presentation">

                    <tr>
                        <th>
                            <label for="cia-customer-select">
                                <?php esc_html_e( 'Customer', 'customer-item-aliases' ); ?>
                            </label>
                        </th>
                        <td>
                            <!--
                                Minimal <select> — only the pre-selected option is rendered server-side.
                                Select2 takes over via AJAX for all search/pagination.
                                An empty <select> is intentional: Select2 populates it dynamically.
                            -->
                            <select id="cia-customer-select"
                                    name="user_id"
                                    style="width: 25em;"
                                    required>
                                <?php if ( $preselected ) : ?>
                                    <option value="<?php echo absint( $preselected['id'] ); ?>" selected>
                                        <?php echo esc_html( $preselected['text'] ); ?>
                                    </option>
                                <?php endif; ?>
                            </select>

                            <p class="description">
                                <?php esc_html_e( 'Type a name or email address to search customers.', 'customer-item-aliases' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th>
                            <label for="alias_code">
                                <?php esc_html_e( 'Alias Code', 'customer-item-aliases' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text" name="alias_code" id="alias_code"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $item['alias_code'] ?? '' ); ?>"
                                   placeholder="e.g. 55012345" required />
                            <p class="description">
                                <?php esc_html_e( 'Customer-provided item identifier.', 'customer-item-aliases' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th>
                            <label for="ean8_code">
                                <?php esc_html_e( 'EAN8 Code', 'customer-item-aliases' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text" name="ean8_code" id="ean8_code"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $item['ean8_code'] ?? '' ); ?>"
                                   placeholder="e.g. 10000014"
                                   maxlength="8" pattern="\d{8}" required />
                            <p class="description">
                                <?php esc_html_e( 'Must be exactly 8 digits. Maps to the WooCommerce product SKU.', 'customer-item-aliases' ); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <?php submit_button( $action === 'edit'
                    ? __( 'Update Alias', 'customer-item-aliases' )
                    : __( 'Add Alias',    'customer-item-aliases' )
                ); ?>
            </form>
        </div>
        <?php
    }

    // ── Select2 Initialisation Script ─────────────────────────────────────────

    /**
     * Returns the Select2 init JS as a plain string for wp_add_inline_script().
     * Kept in a separate method to avoid mixing PHP rendering with JS logic.
     *
     * Select2 AJAX response shape this expects from ajax_search_customers():
     *   { results: [{id, text}, ...], pagination: {more: bool} }
     */
    private static function get_select2_init_script(): string {
        return <<<'JS'
        jQuery(function($) {
            var cfg = window.ciaCustomerSelect || {};

            var $select = $('#cia-customer-select');

            $select.select2({
                ajax: {
                    url:      cfg.ajaxUrl,
                    dataType: 'json',
                    delay:    300,           // ms to wait after keystroke before firing AJAX
                    cache:    true,          // cache results per search term

                    data: function(params) {
                        return {
                            action : 'cia_search_customers',
                            nonce  : cfg.nonce,
                            q      : params.term  || '',
                            page   : params.page  || 1
                        };
                    },

                    processResults: function(data, params) {
                        return {
                            results    : data.results,
                            pagination : data.pagination   // {more: bool} drives infinite scroll
                        };
                    }
                },

                placeholder:        cfg.placeholder || '— Search by name or email —',
                allowClear:         true,
                minimumInputLength: 0,     // show first 20 on focus; refine by typing
                width:              'resolve'
            });

            // Pre-select the current user when editing an existing alias.
            // Select2 AJAX mode requires manually injecting the initial option object.
            if (cfg.preselected && cfg.preselected.id) {
                var pre = new Option(cfg.preselected.text, cfg.preselected.id, true, true);
                $select.append(pre).trigger('change');
            }
        });
        JS;
    }

    // ── Admin Notices ─────────────────────────────────────────────────────────

    public static function admin_notices(): void {
        $messages = [
            'saved'        => [ 'success', __( 'Alias saved successfully.',      'customer-item-aliases' ) ],
            'deleted'      => [ 'success', __( 'Alias deleted.',                 'customer-item-aliases' ) ],
            'bulk_deleted' => [ 'success', __( 'Selected aliases deleted.',      'customer-item-aliases' ) ],
            'invalid_ean8' => [ 'error',   __( 'EAN8 must be exactly 8 digits.', 'customer-item-aliases' ) ],
            'no_user'      => [ 'error',   __( 'Please select a customer.',      'customer-item-aliases' ) ],
        ];

        $msg_key = sanitize_key( $_GET['cia_msg'] ?? '' );
        if ( isset( $messages[ $msg_key ] ) ) {
            [ $type, $text ] = $messages[ $msg_key ];
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr( $type ),
                esc_html( $text )
            );
        }
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private static function redirect( string $msg ): void {
        wp_safe_redirect( add_query_arg( [
            'page'    => 'cia-aliases',
            'cia_msg' => $msg,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
