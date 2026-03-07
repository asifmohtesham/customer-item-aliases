<?php
defined( 'ABSPATH' ) || exit;

class CIA_Admin {

    public static function init(): void {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menu'  ] );
        add_action( 'admin_init',    [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices'  ] );

        // AJAX — authenticated admin users only (no nopriv variant needed)
        add_action( 'wp_ajax_cia_search_customers', [ __CLASS__, 'ajax_search_customers' ] );
        add_action( 'wp_ajax_cia_check_alias',      [ __CLASS__, 'ajax_check_alias'      ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: Customer search (Select2)
    // -------------------------------------------------------------------------

    public static function ajax_search_customers(): void {
        check_ajax_referer( 'cia_search_customers', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $search   = sanitize_text_field( $_GET['q']  ?? '' );
        $page     = max( 1, absint( $_GET['page']    ?? 1 ) );
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
    // AJAX: Real-time duplicate / conflict check
    // -------------------------------------------------------------------------

    public static function ajax_check_alias(): void {
        check_ajax_referer( 'cia_check_alias', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $user_id    = absint( $_POST['user_id']    ?? 0 );
        $alias_code = sanitize_text_field( $_POST['alias_code'] ?? '' );
        $ean8_code  = sanitize_text_field( $_POST['ean8_code']  ?? '' );
        $exclude_id = absint( $_POST['id']         ?? 0 );

        if ( ! $user_id || $alias_code === '' ) {
            wp_send_json( [ 'exact_duplicate' => false, 'existing_mappings' => [] ] );
            return;
        }

        $exact    = $ean8_code !== ''
            ? CIA_DB::find_exact_duplicate( $user_id, $alias_code, $ean8_code, $exclude_id )
            : null;

        $mappings = CIA_DB::find_alias_mappings( $user_id, $alias_code, $exclude_id );

        wp_send_json( [
            'exact_duplicate'   => ! empty( $exact ),
            'existing_mappings' => $mappings,
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
    // Action handler (runs on admin_init — before any page output)
    // -------------------------------------------------------------------------

    public static function handle_actions(): void {
        $page = sanitize_key( $_REQUEST['page'] ?? '' );
        if ( $page !== 'cia-aliases' ) return;
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        // Resolve action: top dropdown sends 'action', bottom sends 'action2'.
        // WP uses '-1' as the "no action selected" sentinel for both dropdowns.
        $action = sanitize_key( $_REQUEST['action'] ?? '' );
        if ( $action === '' || $action === '-1' ) {
            $action = sanitize_key( $_REQUEST['action2'] ?? '' );
        }

        // --- CSV Export ---
        if ( $action === 'export' ) {
            check_admin_referer( 'cia_export' );
            self::stream_export_csv( absint( $_GET['customer_id'] ?? 0 ) );
        }

        // --- Template download ---
        if ( $action === 'download_template' ) {
            check_admin_referer( 'cia_export' );
            self::stream_template_csv();
        }

        // --- CSV Import ---
        if ( $action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            check_admin_referer( 'cia_import' );
            self::process_import();
        }

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
        // WP_List_Table nonce: action = 'bulk-aliases', field = '_wpnonce'.
        // alias_ids[] is the checkbox array from column_cb().
        if ( ! empty( $_POST['alias_ids'] ) && in_array( $action, [ 'bulk-delete', 'bulk-enable', 'bulk-disable' ], true ) ) {
            check_admin_referer( 'bulk-aliases' );

            $ids = array_filter( array_map( 'absint', (array) $_POST['alias_ids'] ) );
            if ( empty( $ids ) ) self::redirect( 'no_selection' );

            switch ( $action ) {
                case 'bulk-delete':
                    CIA_DB::delete( $ids );
                    self::redirect( 'bulk_deleted' );

                case 'bulk-enable':
                    foreach ( $ids as $id ) CIA_DB::set_active( $id, true );
                    self::redirect( 'bulk_enabled' );

                case 'bulk-disable':
                    foreach ( $ids as $id ) CIA_DB::set_active( $id, false );
                    self::redirect( 'bulk_disabled' );
            }
        }

        // --- Save (add / edit) ---
        if ( $action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            check_admin_referer( 'cia_save_alias' );

            $id          = absint( $_POST['id'] ?? 0 );
            $expires_raw = sanitize_text_field( $_POST['expires_at'] ?? '' );

            $data = [
                'user_id'    => absint( $_POST['user_id'] ),
                'alias_code' => sanitize_text_field( $_POST['alias_code'] ),
                'ean8_code'  => sanitize_text_field( $_POST['ean8_code'] ),
                'is_active'  => isset( $_POST['is_active'] ) ? 1 : 0,
                'expires_at' => $expires_raw ? date( 'Y-m-d H:i:s', strtotime( $expires_raw ) ) : null,
            ];

            if ( ! $data['user_id'] )                              self::redirect( 'no_user' );
            if ( ! preg_match( '/^\d{8}$/', $data['ean8_code'] ) ) self::redirect( 'invalid_ean8' );

            // Block exact duplicates
            $duplicate = CIA_DB::find_exact_duplicate(
                $data['user_id'],
                $data['alias_code'],
                $data['ean8_code'],
                $id
            );
            if ( $duplicate ) self::redirect( 'duplicate_alias' );

            // Detect multi-EAN alias (allowed, but inform admin after save)
            $pre_existing = CIA_DB::find_alias_mappings( $data['user_id'], $data['alias_code'], $id );
            $is_multi     = count( $pre_existing ) > 0;

            $id ? CIA_DB::update( $id, $data ) : CIA_DB::insert( $data );
            self::redirect( $is_multi ? 'saved_multi' : 'saved' );
        }
    }

    // -------------------------------------------------------------------------
    // CSV Export — streams directly to browser then exits
    // -------------------------------------------------------------------------

    private static function stream_export_csv( int $customer_id = 0 ): void {
        $rows     = CIA_DB::get_rows_for_export( $customer_id ?: null );
        $filename = $customer_id
            ? sprintf( 'aliases-customer-%d-%s.csv', $customer_id, gmdate( 'Y-m-d' ) )
            : sprintf( 'aliases-all-%s.csv', gmdate( 'Y-m-d' ) );

        while ( ob_get_level() > 0 ) ob_end_clean();

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, [ 'user_id', 'customer_name', 'customer_email', 'alias_code', 'ean8_code', 'is_active', 'expires_at', 'created_at' ] );

        foreach ( $rows as $row ) {
            $user = get_userdata( (int) $row['user_id'] );
            fputcsv( $out, [
                $row['user_id'],
                $user ? $user->display_name : '',
                $user ? $user->user_email   : '',
                $row['alias_code'],
                $row['ean8_code'],
                $row['is_active'],
                $row['expires_at'] ?? '',
                $row['created_at'],
            ] );
        }

        fclose( $out );
        exit;
    }

    // -------------------------------------------------------------------------
    // CSV Template download
    // -------------------------------------------------------------------------

    private static function stream_template_csv(): void {
        while ( ob_get_level() > 0 ) ob_end_clean();

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="aliases-import-template.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, [ 'user_id', 'customer_email', 'alias_code', 'ean8_code', 'is_active', 'expires_at' ] );
        fputcsv( $out, [ '3', 'customer@example.com', 'CUST-CODE-001', '30000070', '1', '' ] );
        fputcsv( $out, [ '3', 'customer@example.com', 'CUST-CODE-002', '30000087', '1', '2026-12-31 00:00:00' ] );
        fputcsv( $out, [ '5', 'another@example.com',  'MY-REF-XYZ',   '30000094', '0', '' ] );
        fclose( $out );
        exit;
    }

    // -------------------------------------------------------------------------
    // CSV Import processor
    // -------------------------------------------------------------------------

    private static function process_import(): void {
        $upload = $_FILES['import_csv'] ?? null;

        if ( ! $upload || $upload['error'] !== UPLOAD_ERR_OK || empty( $upload['tmp_name'] ) ) {
            self::redirect( 'no_file' );
            return;
        }

        if ( strtolower( pathinfo( $upload['name'], PATHINFO_EXTENSION ) ) !== 'csv' ) {
            self::redirect( 'invalid_file_type' );
            return;
        }

        $handle = @fopen( $upload['tmp_name'], 'r' );
        if ( ! $handle ) {
            self::redirect( 'import_read_error' );
            return;
        }

        $raw_headers = fgetcsv( $handle );
        if ( ! $raw_headers ) {
            fclose( $handle );
            self::redirect( 'import_empty' );
            return;
        }

        $raw_headers[0] = ltrim( $raw_headers[0], "\xEF\xBB\xBF" );
        $headers = array_map( 'strtolower', array_map( 'trim', $raw_headers ) );
        $col     = array_flip( $headers );

        $has_uid   = isset( $col['user_id'] );
        $has_email = isset( $col['customer_email'] );

        if ( ! $has_uid && ! $has_email ) {
            fclose( $handle );
            self::redirect( 'import_missing_user_col' );
            return;
        }
        if ( ! isset( $col['alias_code'] ) || ! isset( $col['ean8_code'] ) ) {
            fclose( $handle );
            self::redirect( 'import_missing_cols' );
            return;
        }

        $stats   = [ 'imported' => 0, 'skipped' => 0, 'errors' => [] ];
        $row_num = 1;

        while ( ( $raw = fgetcsv( $handle ) ) !== false ) {
            $row_num++;
            if ( array_filter( $raw ) === [] ) continue;

            $user_id = 0;
            if ( $has_uid && ! empty( $raw[ $col['user_id'] ] ) ) {
                $user_id = absint( trim( $raw[ $col['user_id'] ] ) );
            }
            if ( ! $user_id && $has_email && ! empty( $raw[ $col['customer_email'] ] ) ) {
                $u       = get_user_by( 'email', sanitize_email( trim( $raw[ $col['customer_email'] ] ) ) );
                $user_id = $u ? $u->ID : 0;
            }
            if ( ! $user_id ) {
                $stats['errors'][] = sprintf( __( 'Row %d: customer not found.', 'customer-item-aliases' ), $row_num );
                continue;
            }

            $alias_code = sanitize_text_field( trim( $raw[ $col['alias_code'] ] ?? '' ) );
            $ean8_code  = sanitize_text_field( trim( $raw[ $col['ean8_code']  ] ?? '' ) );

            if ( $alias_code === '' ) {
                $stats['errors'][] = sprintf( __( 'Row %d: alias_code is empty.', 'customer-item-aliases' ), $row_num );
                continue;
            }
            if ( ! preg_match( '/^\d{8}$/', $ean8_code ) ) {
                $stats['errors'][] = sprintf(
                    __( 'Row %1$d: ean8_code "%2$s" must be exactly 8 digits.', 'customer-item-aliases' ),
                    $row_num, $ean8_code
                );
                continue;
            }

            if ( CIA_DB::row_exists( $user_id, $alias_code, $ean8_code ) ) {
                $stats['skipped']++;
                continue;
            }

            $is_active = 1;
            if ( isset( $col['is_active'] ) && isset( $raw[ $col['is_active'] ] ) ) {
                $raw_active = strtolower( trim( $raw[ $col['is_active'] ] ) );
                $is_active  = in_array( $raw_active, [ '0', 'false', 'no', 'disabled' ], true ) ? 0 : 1;
            }

            $expires_at = null;
            if ( isset( $col['expires_at'] ) && ! empty( $raw[ $col['expires_at'] ] ) ) {
                $ts = strtotime( trim( $raw[ $col['expires_at'] ] ) );
                if ( $ts ) $expires_at = date( 'Y-m-d H:i:s', $ts );
            }

            $ok = CIA_DB::insert( [
                'user_id'    => $user_id,
                'alias_code' => $alias_code,
                'ean8_code'  => $ean8_code,
                'is_active'  => $is_active,
                'expires_at' => $expires_at,
            ] );

            if ( $ok ) {
                $stats['imported']++;
            } else {
                $stats['errors'][] = sprintf( __( 'Row %d: database insert failed.', 'customer-item-aliases' ), $row_num );
            }
        }

        fclose( $handle );
        set_transient( 'cia_import_result_' . get_current_user_id(), $stats, 60 );
        self::redirect( 'imported' );
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

            <?php self::render_import_export_panels(); ?>

            <?php
            /*
             * The form MUST be method="post" so bulk action checkboxes (alias_ids[])
             * and the WP_List_Table nonce reach $_POST.
             * The search_box() and pagination links are GET-param URLs that work
             * independently of the form method.
             */
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
                  id="cia-list-form">
                <input type="hidden" name="page" value="cia-aliases" />
                <?php
                    $table->search_box( __( 'Search Aliases', 'customer-item-aliases' ), 'alias' );
                    $table->display();
                ?>
            </form>
        </div>

        <script>
        jQuery(function($){
            $('#cia-list-form').on('submit', function(e){
                // Resolve which dropdown fired (top = 'action', bottom = 'action2')
                var a1 = $('[name="action"]',  this).val();
                var a2 = $('[name="action2"]', this).val();
                var action = (a1 && a1 !== '-1') ? a1 : a2;

                if (action === 'bulk-delete') {
                    var count = $('input[name="alias_ids[]"]:checked', this).length;
                    if (count === 0) { e.preventDefault(); return; }
                    if (!confirm(
                        count === 1
                            ? '<?php echo esc_js( __( 'Permanently delete 1 selected alias? This cannot be undone.', 'customer-item-aliases' ) ); ?>'
                            : count + ' <?php echo esc_js( __( 'selected aliases will be permanently deleted. This cannot be undone. Continue?', 'customer-item-aliases' ) ); ?>'
                    )) {
                        e.preventDefault();
                    }
                }
            });
        });
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Import / Export panels
    // -------------------------------------------------------------------------

    private static function render_import_export_panels(): void {
        $base_url     = admin_url( 'admin.php' );
        $export_nonce = wp_create_nonce( 'cia_export' );
        $template_url = add_query_arg( [
            'page'     => 'cia-aliases',
            'action'   => 'download_template',
            '_wpnonce' => $export_nonce,
        ], $base_url );
        $customers = CIA_DB::get_customers_with_aliases();
        ?>
        <div style="display:flex;gap:20px;margin:16px 0 20px;flex-wrap:wrap;">

            <div class="postbox" style="flex:1;min-width:280px;margin-bottom:0;">
                <h2 class="hndle" style="padding:8px 12px;font-size:14px;">
                    <span>&#8595;&nbsp;<?php esc_html_e( 'Import Aliases', 'customer-item-aliases' ); ?></span>
                </h2>
                <div class="inside" style="padding:12px 16px;">
                    <p class="description" style="margin-bottom:10px;">
                        <?php esc_html_e( 'Upload a CSV to bulk-import aliases. Duplicate rows are skipped automatically.', 'customer-item-aliases' ); ?>
                        &nbsp;<a href="<?php echo esc_url( $template_url ); ?>"><?php esc_html_e( 'Download template', 'customer-item-aliases' ); ?></a>
                    </p>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $base_url ); ?>">
                        <?php wp_nonce_field( 'cia_import' ); ?>
                        <input type="hidden" name="page"   value="cia-aliases" />
                        <input type="hidden" name="action" value="import" />
                        <input type="file" name="import_csv" accept=".csv,text/csv" required
                               style="display:block;margin-bottom:10px;max-width:100%;" />
                        <?php submit_button( __( 'Import CSV', 'customer-item-aliases' ), 'secondary', 'submit_import', false ); ?>
                    </form>
                </div>
            </div>

            <div class="postbox" style="flex:1;min-width:280px;margin-bottom:0;">
                <h2 class="hndle" style="padding:8px 12px;font-size:14px;">
                    <span>&#8593;&nbsp;<?php esc_html_e( 'Export Aliases', 'customer-item-aliases' ); ?></span>
                </h2>
                <div class="inside" style="padding:12px 16px;">
                    <p class="description" style="margin-bottom:10px;">
                        <?php esc_html_e( 'Download all aliases or a single customer\'s as CSV. Exported files can be re-imported.', 'customer-item-aliases' ); ?>
                    </p>
                    <form method="get" action="<?php echo esc_url( $base_url ); ?>">
                        <input type="hidden" name="page"     value="cia-aliases" />
                        <input type="hidden" name="action"   value="export" />
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $export_nonce ); ?>" />
                        <label for="cia-export-customer" style="display:block;margin-bottom:4px;font-weight:600;">
                            <?php esc_html_e( 'Customer', 'customer-item-aliases' ); ?>
                        </label>
                        <select name="customer_id" id="cia-export-customer"
                                style="min-width:220px;max-width:100%;margin-bottom:10px;">
                            <option value="0"><?php esc_html_e( '\u2014 All Customers \u2014', 'customer-item-aliases' ); ?></option>
                            <?php foreach ( $customers as $uid ) : ?>
                                <?php $u = get_userdata( $uid ); if ( ! $u ) continue; ?>
                                <option value="<?php echo absint( $uid ); ?>">
                                    <?php echo esc_html( sprintf( '%s (%s)', $u->display_name, $u->user_email ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select><br />
                        <?php submit_button( __( 'Export CSV', 'customer-item-aliases' ), 'secondary', 'submit_export', false ); ?>
                    </form>
                </div>
            </div>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Add / Edit form
    // -------------------------------------------------------------------------

    private static function render_form_page( string $action ): void {
        $id       = absint( $_GET['id'] ?? 0 );
        $item     = ( $action === 'edit' && $id ) ? CIA_DB::get_row( $id ) : null;
        $list_url = add_query_arg( [ 'page' => 'cia-aliases' ], admin_url( 'admin.php' ) );

        $expires_at_input  = ! empty( $item['expires_at'] )
            ? date( 'Y-m-d\TH:i', strtotime( $item['expires_at'] ) )
            : '';
        $is_active_checked = isset( $item['is_active'] ) ? (bool) $item['is_active'] : true;

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

        wp_add_inline_script( 'select2',
            'var ciaCustomerSelect = ' . wp_json_encode( [
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'cia_search_customers' ),
                'placeholder' => __( '\u2014 Search by name or email \u2014', 'customer-item-aliases' ),
                'preselected' => $preselected,
            ] ) . ';' .
            'var ciaAliasCheck = ' . wp_json_encode( [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'cia_check_alias' ),
            ] ) . ';',
            'before'
        );

        wp_add_inline_script( 'select2', self::get_select2_init_script() );
        wp_add_inline_script( 'select2', self::get_duplicate_check_script() );
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

                    <tr>
                        <th><label for="ean8_code"><?php esc_html_e( 'EAN8 Code', 'customer-item-aliases' ); ?></label></th>
                        <td>
                            <input type="text" name="ean8_code" id="ean8_code"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $item['ean8_code'] ?? '' ); ?>"
                                   placeholder="e.g. 10000014"
                                   maxlength="8" pattern="\d{8}" required />
                            <p class="description"><?php esc_html_e( 'Must be exactly 8 digits.', 'customer-item-aliases' ); ?></p>
                        </td>
                    </tr>

                    <!-- Duplicate / conflict notice row — injected by JS, hidden by default -->
                    <tr id="cia-conflict-row" style="display:none;">
                        <th></th>
                        <td><div id="cia-conflict-notice"></div></td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Status', 'customer-item-aliases' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_active" id="is_active" value="1"
                                       <?php checked( $is_active_checked, true ); ?> />
                                <?php esc_html_e( 'Active', 'customer-item-aliases' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Uncheck to disable this alias without deleting it.', 'customer-item-aliases' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="expires_at"><?php esc_html_e( 'Expiry Date', 'customer-item-aliases' ); ?></label></th>
                        <td>
                            <input type="datetime-local" name="expires_at" id="expires_at"
                                   value="<?php echo esc_attr( $expires_at_input ); ?>" />
                            <p class="description">
                                <?php esc_html_e( 'Optional. Leave blank for no expiry.', 'customer-item-aliases' ); ?>
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
                    processResults: function(data) {
                        return { results: data.results, pagination: data.pagination };
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
    // Real-time duplicate / conflict check script
    // -------------------------------------------------------------------------

    private static function get_duplicate_check_script(): string {
        return <<<'JS'
        jQuery(function ($) {
            var data  = window.ciaAliasCheck || {};
            var timer = null;

            var $row    = $('#cia-conflict-row');
            var $notice = $('#cia-conflict-notice');

            function showNotice(type, html) {
                var bg    = { error: '#fcf0f1', warning: '#fcf9e8', info: '#eaf4fb' };
                var color = { error: '#b32d2e', warning: '#996800', info: '#014671' };
                var icon  = { error: '\u274C', warning: '\u26A0\uFE0F', info: '\u2139\uFE0F' };
                $row.show();
                $notice.html(
                    '<p style="margin:0;padding:8px 10px;border-radius:3px;' +
                    'background:' + (bg[type]    || '#fff') + ';' +
                    'color:'      + (color[type] || '#333') + ';' +
                    'border-left:4px solid ' + (color[type] || '#ccc') + ';">' +
                    (icon[type] ? icon[type] + ' ' : '') + html + '</p>'
                );
            }

            function clearNotice() {
                $row.hide();
                $notice.html('');
            }

            function runCheck() {
                var userId    = $('#cia-customer-select').val();
                var aliasCode = $.trim($('#alias_code').val());
                var ean8Code  = $.trim($('#ean8_code').val());
                var excludeId = $('input[name="id"]').val() || 0;

                if (!userId || !aliasCode) { clearNotice(); return; }

                $.post(data.ajaxUrl, {
                    action    : 'cia_check_alias',
                    nonce     : data.nonce,
                    user_id   : userId,
                    alias_code: aliasCode,
                    ean8_code : ean8Code,
                    id        : excludeId
                }, function (res) {
                    if (!res) return;

                    if (res.exact_duplicate) {
                        showNotice('error',
                            '<strong>Duplicate:</strong> This exact alias mapping already exists for this customer. ' +
                            'Saving will be blocked.'
                        );
                        return;
                    }

                    if (res.existing_mappings && res.existing_mappings.length) {
                        var eans = res.existing_mappings
                            .map(function(m) { return '<code>' + $('<span>').text(m.ean8_code).html() + '</code>'; })
                            .join(', ');
                        showNotice('warning',
                            '<strong>Note:</strong> This alias already maps to EAN ' + eans +
                            ' for this customer. Saving will create a second mapping (multi-EAN alias).'
                        );
                        return;
                    }

                    clearNotice();
                });
            }

            function triggerCheck() {
                clearTimeout(timer);
                timer = setTimeout(runCheck, 500);
            }

            $('#alias_code, #ean8_code').on('input', triggerCheck);
            $('#cia-customer-select').on('change', triggerCheck);

            if ($('input[name="id"]').val() > 0) {
                runCheck();
            }
        });
        JS;
    }

    // -------------------------------------------------------------------------
    // Admin notices
    // -------------------------------------------------------------------------

    public static function admin_notices(): void {
        $msg_key = sanitize_key( $_GET['cia_msg'] ?? '' );
        if ( ! $msg_key ) return;

        if ( $msg_key === 'imported' ) {
            $uid   = get_current_user_id();
            $stats = get_transient( 'cia_import_result_' . $uid );
            delete_transient( 'cia_import_result_' . $uid );
            if ( $stats ) self::render_import_notice( $stats );
            return;
        }

        $messages = [
            'saved'                   => [ 'success', __( 'Alias saved successfully.',                                                             'customer-item-aliases' ) ],
            'saved_multi'             => [ 'info',    __( 'Alias saved. This alias now maps to multiple EAN codes for this customer.',             'customer-item-aliases' ) ],
            'deleted'                 => [ 'success', __( 'Alias deleted.',                                                                        'customer-item-aliases' ) ],
            'enabled'                 => [ 'success', __( 'Alias enabled.',                                                                        'customer-item-aliases' ) ],
            'disabled'                => [ 'success', __( 'Alias disabled.',                                                                       'customer-item-aliases' ) ],
            'bulk_deleted'            => [ 'success', __( 'Selected aliases deleted.',                                                             'customer-item-aliases' ) ],
            'bulk_enabled'            => [ 'success', __( 'Selected aliases enabled.',                                                             'customer-item-aliases' ) ],
            'bulk_disabled'           => [ 'success', __( 'Selected aliases disabled.',                                                            'customer-item-aliases' ) ],
            'no_selection'            => [ 'warning', __( 'No aliases selected. Please check at least one row before applying a bulk action.',     'customer-item-aliases' ) ],
            'no_user'                 => [ 'error',   __( 'Please select a customer.',                                                             'customer-item-aliases' ) ],
            'invalid_ean8'            => [ 'error',   __( 'EAN8 must be exactly 8 digits.',                                                        'customer-item-aliases' ) ],
            'duplicate_alias'         => [ 'error',   __( 'This exact alias mapping already exists for this customer. No changes were saved.',     'customer-item-aliases' ) ],
            'no_file'                 => [ 'error',   __( 'No file selected or upload failed.',                                                    'customer-item-aliases' ) ],
            'invalid_file_type'       => [ 'error',   __( 'Please upload a .csv file.',                                                            'customer-item-aliases' ) ],
            'import_read_error'       => [ 'error',   __( 'Could not read the uploaded file.',                                                     'customer-item-aliases' ) ],
            'import_empty'            => [ 'error',   __( 'The CSV file is empty.',                                                                'customer-item-aliases' ) ],
            'import_missing_user_col' => [ 'error',   __( 'CSV must include a user_id or customer_email column.',                                  'customer-item-aliases' ) ],
            'import_missing_cols'     => [ 'error',   __( 'CSV must include alias_code and ean8_code columns.',                                    'customer-item-aliases' ) ],
        ];

        if ( isset( $messages[ $msg_key ] ) ) {
            [ $type, $text ] = $messages[ $msg_key ];
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr( $type ),
                esc_html( $text )
            );
        }
    }

    private static function render_import_notice( array $stats ): void {
        $parts = [];
        if ( $stats['imported'] > 0 ) {
            $parts[] = sprintf(
                _n( '%d row imported', '%d rows imported', $stats['imported'], 'customer-item-aliases' ),
                $stats['imported']
            );
        }
        if ( $stats['skipped'] > 0 ) {
            $parts[] = sprintf(
                _n( '%d duplicate skipped', '%d duplicates skipped', $stats['skipped'], 'customer-item-aliases' ),
                $stats['skipped']
            );
        }

        $type    = empty( $stats['errors'] ) ? 'success' : 'warning';
        $summary = $parts
            ? implode( ', ', $parts ) . '.'
            : __( 'Import complete — no rows were inserted.', 'customer-item-aliases' );

        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible">';
        echo '<p><strong>' . esc_html__( 'Import complete', 'customer-item-aliases' ) . ':</strong> ' . esc_html( $summary ) . '</p>';

        if ( ! empty( $stats['errors'] ) ) {
            $shown  = array_slice( $stats['errors'], 0, 10 );
            $hidden = count( $stats['errors'] ) - count( $shown );
            echo '<ul style="margin-top:4px;margin-bottom:4px;">';
            foreach ( $shown as $err ) echo '<li>' . esc_html( $err ) . '</li>';
            if ( $hidden > 0 ) {
                echo '<li>' . sprintf(
                    esc_html( _n( '\u2026and %d more error.', '\u2026and %d more errors.', $hidden, 'customer-item-aliases' ) ),
                    $hidden
                ) . '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
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
