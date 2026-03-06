<?php
defined( 'ABSPATH' ) || exit;

class CIA_Admin {

    public static function init(): void {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menu'  ] );
        add_action( 'admin_init',    [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices'  ] );
        add_action( 'wp_ajax_cia_search_customers', [ __CLASS__, 'ajax_search_customers' ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: Customer Search (Select2)
    // -------------------------------------------------------------------------

    public static function ajax_search_customers(): void {
        check_ajax_referer( 'cia_search_customers', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $search   = sanitize_text_field( $_GET['q']   ?? '' );
        $page     = max( 1, absint( $_GET['page']     ?? 1 ) );
        $per_page = 20;

        $user_query = new WP_User_Query( [
            'role'           => 'customer',
            'search'         => $search ? ( '*' . $search . '*' ) : '',
            'search_columns' => [ 'display_name', 'user_email', 'user_login' ],
            'orderby'        => 'display_name',
            'order'          => 'ASC',
            'number'         => $per_page,
            'offset'         => ( $page - 1 ) * $per_page,
            'count_total'    => true,
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
            'pagination' => [ 'more' => ( $page * $per_page ) < $user_query->get_total() ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Action handler
    // -------------------------------------------------------------------------

    public static function handle_actions(): void {
        $page   = sanitize_key( $_REQUEST['page']  ?? '' );
        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        if ( $page !== 'cia-aliases' ) return;
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        // --- Hard delete (single) ---
        if ( $action === 'delete' && ! empty( $_GET['id'] ) ) {
            $id = absint( $_GET['id'] );
            check_admin_referer( 'cia_delete_' . $id );
            CIA_DB::delete( $id );
            self::redirect( 'deleted' );
        }

        // --- Enable / Disable (single) ---
        if ( in_array( $action, [ 'enable', 'disable' ], true ) && ! empty( $_GET['id'] ) ) {
            $id = absint( $_GET['id'] );
            check_admin_referer( 'cia_toggle_' . $id );
            CIA_DB::set_active( $id, $action === 'enable' );
            self::redirect( $action === 'enable' ? 'enabled' : 'disabled' );
        }

        // --- Bulk actions ---
        if ( ! empty( $_POST['alias_ids'] ) ) {
            check_admin_referer( 'bulk-aliases' );
            $ids = array_map( 'absint', $_POST['alias_ids'] );

            if ( $action === 'bulk-delete' ) {
                CIA_DB::delete( $ids );
                self::redirect( 'bulk_deleted' );
            }

            if ( $action === 'bulk-enable' ) {
                foreach ( $ids as $id ) CIA_DB::set_active( $id, true );
                self::redirect( 'bulk_enabled' );
            }

            if ( $action === 'bulk-disable' ) {
                foreach ( $ids as $id ) CIA_DB::set_active( $id, false );
                self::redirect( 'bulk_disabled' );
            }
        }

        // --- Save (add / edit) ---
        if ( $action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            check_admin_referer( 'cia_save_alias' );

            $id         = absint( $_POST['id'] ?? 0 );
            $expires_raw = sanitize_text_field( $_POST['expires_at'] ?? '' );

            $data = [
                'user_id'    => absint( $_POST['user_id'] ),
                'alias_code' => sanitize_text_field( $_POST['alias_code'] ),
                'ean8_code'  => sanitize_text_field( $_POST['ean8_code'] ),
                'is_active'  => isset( $_POST['is_active'] ) ? 1 : 0,
                // Convert 'YYYY-MM-DDTHH:MM' (datetime-local) → MySQL datetime, or null to clear.
                'expires_at' => $expires_raw ? date( 'Y-m-d H:i:s', strtotime( $expires_raw ) ) : null,
            ];

            if ( ! $data['user_id'] ) self::redirect( 'no_user' );

            if ( ! preg_match( '/^\d{8}$/', $data['ean8_code'] ) ) self::redirect( 'invalid_ean8' );

            $id ? CIA_DB::update( $id, $data ) : CIA_DB::insert( $data );
            self::redirect( 'saved' );
        }
    }

    // -------------------------------------------------------------------------
    // Page renderers
    // -------------------------------------------------------------------------

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
        $id       = absint( $_GET['id'] ?? 0 );
        $item     = ( $action === 'edit' && $id ) ? CIA_DB::get_row( $id ) : null;
        $list_url = add_query_arg( [ 'page' => 'cia-aliases' ], admin_url( 'admin.php' ) );

        // Convert stored MySQL datetime to HTML datetime-local format (YYYY-MM-DDTHH:MM)
        $expires_at_input = '';
        if ( ! empty( $item['expires_at'] ) ) {
            $expires_at_input = date( 'Y-m-d\TH:i', strtotime( $item['expires_at'] ) );
        }

        // is_active: defaults to true for new aliases
        $is_active_checked = isset( $item['is_active'] ) ? (bool) $item['is_active'] : true;

        // ── Enqueue Select2 ───────────────────────────────────────────────────
        wp_enqueue_style( 'select2' );
        wp_enqueue_script( 'select2' );

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
            'placeholder' => __( '\u2014 Search by name or email \u2014', 'customer-item-aliases' ),
            'preselected' => $preselected,
        ] );
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

                    <!-- Customer -->
                    <tr>
                        <th><label for="cia-customer-select"><?php esc_html_e( 'Customer', 'customer-item-aliases' ); ?></label></th>
                        <td>
                            <select id="cia-customer-select" name="user_id" style="width:25em;" required>
                                <?php if ( $preselected ) : ?>
                                    <option value="<?php echo absint( $preselected['id'] ); ?>" selected>
                                        <?php echo esc_html( $preselected['text'] ); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Type a name or email address to search customers.', 'customer-item-aliases' ); ?></p>
                        </td>
                    </tr>

                    <!-- Alias Code -->
                    <tr>
                        <th><label for="alias_code"><?php esc_html_e( 'Alias Code', 'customer-item-aliases' ); ?></label></th>
                        <td>
                            <input type="text" name="alias_code" id="alias_code"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $item['alias_code'] ?? '' ); ?>"
                                   placeholder="e.g. 55012345" required />
                            <p class="description"><?php esc_html_e( 'Customer-provided item identifier.', 'customer-item-aliases' ); ?></p>
                        </td>
                    </tr>

                    <!-- EAN8 Code -->
                    <tr>
                        <th><label for="ean8_code"><?php esc_html_e( 'EAN8 Code', 'customer-item-aliases' ); ?></label></th>
                        <td>
                            <input type="text" name="ean8_code" id="ean8_code"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $item['ean8_code'] ?? '' ); ?>"
                                   placeholder="e.g. 10000014"
                                   maxlength="8" pattern="\d{8}" required />
                            <p class="description"><?php esc_html_e( 'Must be exactly 8 digits. Maps to the WooCommerce product\'s global unique ID.', 'customer-item-aliases' ); ?></p>
                        </td>
                    </tr>

                    <!-- Status (is_active) -->
                    <tr>
                        <th><?php esc_html_e( 'Status', 'customer-item-aliases' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_active" id="is_active" value="1"
                                       <?php checked( $is_active_checked, true ); ?> />
                                <?php esc_html_e( 'Active', 'customer-item-aliases' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Uncheck to disable this alias without deleting it. Disabled aliases are never resolved in product searches.', 'customer-item-aliases' ); ?></p>
                        </td>
                    </tr>

                    <!-- Expiry Date (optional) -->
                    <tr>
                        <th><label for="expires_at"><?php esc_html_e( 'Expiry Date', 'customer-item-aliases' ); ?></label></th>
                        <td>
                            <input type="datetime-local" name="expires_at" id="expires_at"
                                   value="<?php echo esc_attr( $expires_at_input ); ?>" />
                            <p class="description">
                                <?php esc_html_e( 'Optional. Leave blank for no expiry. After this date/time the alias will automatically stop resolving, regardless of the Active status above.', 'customer-item-aliases' ); ?>
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

    // -------------------------------------------------------------------------
    // Select2 init script
    // -------------------------------------------------------------------------

    private static function get_select2_init_script(): string {
        return <<<'JS'
        jQuery(function($) {
            var cfg = window.ciaCustomerSelect || {};
            var $select = $('#cia-customer-select');

            $select.select2({
                ajax: {
                    url:      cfg.ajaxUrl,
                    dataType: 'json',
                    delay:    300,
                    cache:    true,
                    data: function(params) {
                        return {
                            action : 'cia_search_customers',
                            nonce  : cfg.nonce,
                            q      : params.term || '',
                            page   : params.page || 1
                        };
                    },
                    processResults: function(data, params) {
                        return {
                            results    : data.results,
                            pagination : data.pagination
                        };
                    }
                },
                placeholder:        cfg.placeholder || '\u2014 Search by name or email \u2014',
                allowClear:         true,
                minimumInputLength: 0,
                width:              'resolve'
            });

            if (cfg.preselected && cfg.preselected.id) {
                var pre = new Option(cfg.preselected.text, cfg.preselected.id, true, true);
                $select.append(pre).trigger('change');
            }
        });
        JS;
    }

    // -------------------------------------------------------------------------
    // Admin notices
    // -------------------------------------------------------------------------

    public static function admin_notices(): void {
        $messages = [
            'saved'         => [ 'success', __( 'Alias saved successfully.',       'customer-item-aliases' ) ],
            'deleted'       => [ 'success', __( 'Alias deleted.',                  'customer-item-aliases' ) ],
            'enabled'       => [ 'success', __( 'Alias enabled.',                  'customer-item-aliases' ) ],
            'disabled'      => [ 'success', __( 'Alias disabled.',                 'customer-item-aliases' ) ],
            'bulk_deleted'  => [ 'success', __( 'Selected aliases deleted.',       'customer-item-aliases' ) ],
            'bulk_enabled'  => [ 'success', __( 'Selected aliases enabled.',       'customer-item-aliases' ) ],
            'bulk_disabled' => [ 'success', __( 'Selected aliases disabled.',      'customer-item-aliases' ) ],
            'invalid_ean8'  => [ 'error',   __( 'EAN8 must be exactly 8 digits.',  'customer-item-aliases' ) ],
            'no_user'       => [ 'error',   __( 'Please select a customer.',       'customer-item-aliases' ) ],
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

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private static function redirect( string $msg ): void {
        wp_safe_redirect( add_query_arg( [
            'page'    => 'cia-aliases',
            'cia_msg' => $msg,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
