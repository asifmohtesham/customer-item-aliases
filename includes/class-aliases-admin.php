<?php
defined( 'ABSPATH' ) || exit;

class CIA_Admin {

    public static function init(): void {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menu'  ] );
        add_action( 'admin_init',    [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices'  ] );

        // AJAX — authenticated admin users only (no nopriv variant needed)
        add_action( 'wp_ajax_cia_search_customers',  [ __CLASS__, 'ajax_search_customers'  ] );
        add_action( 'wp_ajax_cia_check_alias',       [ __CLASS__, 'ajax_check_alias'       ] );
        add_action( 'wp_ajax_cia_reverse_lookup',    [ __CLASS__, 'ajax_reverse_lookup'    ] );
        add_action( 'wp_ajax_cia_search_stats_data', [ __CLASS__, 'ajax_search_stats_data' ] );
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
    // AJAX: Reverse lookup (EAN → aliases + products)
    // -------------------------------------------------------------------------

    public static function ajax_reverse_lookup(): void {
        check_ajax_referer( 'cia_reverse_lookup', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $ean          = sanitize_text_field( $_GET['ean'] ?? '' );
        $ean_meta_key = (string) apply_filters( 'cia_ean_meta_key', CIA_EAN_META_KEY );
        $table        = $wpdb->prefix . CIA_TABLE_ALIAS;

        // Find all aliases (any customer) mapping to this EAN
        $alias_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.id, a.user_id, a.alias_code, a.is_active, a.expires_at, a.created_at
                 FROM {$table} a
                 WHERE a.ean8_code = %s
                 ORDER BY a.user_id ASC, a.id ASC",
                $ean
            ),
            ARRAY_A
        ) ?: [];

        // Enrich with customer info
        $aliases = array_map( static function( $r ) {
            $user = get_userdata( (int) $r['user_id'] );
            return [
                'id'            => (int) $r['id'],
                'customer_id'   => (int) $r['user_id'],
                'customer_name' => $user ? $user->display_name : '',
                'alias_code'    => $r['alias_code'],
                'is_active'     => (bool) $r['is_active'],
                'expires_at'    => $r['expires_at'] ?? null,
                'created_at'    => $r['created_at'],
            ];
        }, $alias_rows );

        // Find product(s) with this EAN
        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type   = 'product'
                   AND p.post_status = 'publish'
                   AND pm.meta_key   = %s
                   AND pm.meta_value = %s",
                $ean_meta_key,
                $ean
            )
        ) ?: [];

        $products = array_map( static function( $pid ) {
            $p = wc_get_product( (int) $pid );
            return $p ? [
                'id'    => $p->get_id(),
                'name'  => $p->get_name(),
                'sku'   => $p->get_sku(),
                'price' => $p->get_price(),
                'link'  => get_permalink( $p->get_id() ),
            ] : null;
        }, $product_ids );

        $products = array_filter( $products );

        wp_send_json( [
            'ean'      => $ean,
            'aliases'  => $aliases,
            'products' => $products,
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: Search stats data (JSON for admin dashboard)
    // -------------------------------------------------------------------------

    public static function ajax_search_stats_data(): void {
        check_ajax_referer( 'cia_search_stats', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $args = [
            'user_id' => absint( $_GET['customer_id'] ?? 0 ) ?: null,
            'from'    => sanitize_text_field( $_GET['from'] ?? '' ),
            'to'      => sanitize_text_field( $_GET['to']   ?? '' ),
            'limit'   => 50,
        ];

        $top_terms   = CIA_Search_Stats::get_top_terms( $args );
        $unresolved  = CIA_Search_Stats::get_unresolved( $args );

        wp_send_json( [
            'top_terms'  => $top_terms,
            'unresolved' => $unresolved,
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

        // Reverse lookup submenu
        add_submenu_page(
            'cia-aliases',
            __( 'Reverse Lookup', 'customer-item-aliases' ),
            __( 'Reverse Lookup', 'customer-item-aliases' ),
            'manage_woocommerce',
            'cia-reverse-lookup',
            [ __CLASS__, 'render_reverse_lookup_page' ]
        );

        // Search analytics submenu
        add_submenu_page(
            'cia-aliases',
            __( 'Search Analytics', 'customer-item-aliases' ),
            __( 'Search Analytics', 'customer-item-aliases' ),
            'manage_woocommerce',
            'cia-search-analytics',
            [ __CLASS__, 'render_search_analytics_page' ]
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

    // ... (CSV export, import, form page methods remain unchanged) ...

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
    // Reverse Lookup Page
    // -------------------------------------------------------------------------

    public static function render_reverse_lookup_page(): void {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Reverse Lookup: EAN → Aliases', 'customer-item-aliases' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Enter an 8-digit EAN code to find all customer aliases and products mapped to it.', 'customer-item-aliases' ); ?>
            </p>

            <form method="get" id="cia-reverse-form" style="margin-top:20px;">
                <input type="hidden" name="page" value="cia-reverse-lookup" />
                <label for="cia-ean-input" style="font-weight:600;display:block;margin-bottom:6px;">
                    <?php esc_html_e( 'EAN8 Code', 'customer-item-aliases' ); ?>
                </label>
                <input type="text" id="cia-ean-input" name="ean"
                       placeholder="e.g. 30000070" maxlength="8" pattern="\d{8}"
                       style="width:220px;" required />
                <?php submit_button( __( 'Lookup', 'customer-item-aliases' ), 'primary', 'submit', false ); ?>
            </form>

            <div id="cia-reverse-results" style="margin-top:30px;"></div>
        </div>

        <script>
        jQuery(function($){
            var $form    = $('#cia-reverse-form');
            var $results = $('#cia-reverse-results');
            var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'cia_reverse_lookup' ) ); ?>;

            $form.on('submit', function(e){
                e.preventDefault();
                var ean = $.trim($('#cia-ean-input').val());
                if (!ean || !/^\d{8}$/.test(ean)) {
                    $results.html('<p style="color:#b32d2e;">❌ Please enter a valid 8-digit EAN code.</p>');
                    return;
                }

                $results.html('<p>⏳ Loading…</p>');

                $.get(ajaxurl, { action: 'cia_reverse_lookup', nonce: nonce, ean: ean })
                 .done(function(res){
                    if (!res || !res.ean) {
                        $results.html('<p style="color:#b32d2e;">❌ No data found.</p>');
                        return;
                    }

                    var html = '<h2>Results for EAN <code>' + res.ean + '</code></h2>';

                    if (res.products && res.products.length) {
                        html += '<h3>🛍️ Products (' + res.products.length + ')</h3><table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>Name</th><th>SKU</th><th>Price</th><th>Link</th></tr></thead><tbody>';
                        res.products.forEach(function(p){
                            html += '<tr>';
                            html += '<td>' + p.id + '</td>';
                            html += '<td>' + $('<span>').text(p.name).html() + '</td>';
                            html += '<td>' + (p.sku || '—') + '</td>';
                            html += '<td>' + (p.price || '—') + '</td>';
                            html += '<td><a href="' + p.link + '" target="_blank">View</a></td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                    } else {
                        html += '<p>⚠️ No published products found with this EAN.</p>';
                    }

                    if (res.aliases && res.aliases.length) {
                        html += '<h3 style="margin-top:20px;">🔖 Customer Aliases (' + res.aliases.length + ')</h3><table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>Customer</th><th>Alias</th><th>Status</th><th>Expires</th></tr></thead><tbody>';
                        res.aliases.forEach(function(a){
                            html += '<tr>';
                            html += '<td>' + a.id + '</td>';
                            html += '<td>' + $('<span>').text(a.customer_name).html() + ' (' + a.customer_id + ')</td>';
                            html += '<td><strong>' + $('<span>').text(a.alias_code).html() + '</strong></td>';
                            html += '<td>' + (a.is_active ? '✅ Active' : '❌ Disabled') + '</td>';
                            html += '<td>' + (a.expires_at || '—') + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                    } else {
                        html += '<p style="margin-top:20px;">⚠️ No customer aliases found for this EAN.</p>';
                    }

                    $results.html(html);
                })
                 .fail(function(){
                    $results.html('<p style="color:#b32d2e;">❌ AJAX request failed.</p>');
                });
            });
        });
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Search Analytics Page
    // -------------------------------------------------------------------------

    public static function render_search_analytics_page(): void {
        $customers = CIA_DB::get_customers_with_aliases();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Search Analytics', 'customer-item-aliases' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Track the most searched alias terms and failed (unresolved) lookups. Use this data to identify missing aliases.', 'customer-item-aliases' ); ?>
            </p>

            <form method="get" id="cia-stats-form" style="margin-top:20px;">
                <input type="hidden" name="page" value="cia-search-analytics" />
                <label style="display:inline-block;margin-right:10px;">
                    <strong><?php esc_html_e( 'Customer', 'customer-item-aliases' ); ?></strong><br />
                    <select name="customer_id" style="min-width:200px;">
                        <option value="0">— <?php esc_html_e( 'All Customers', 'customer-item-aliases' ); ?> —</option>
                        <?php foreach ( $customers as $uid ) : ?>
                            <?php $u = get_userdata( $uid ); if ( ! $u ) continue; ?>
                            <option value="<?php echo absint( $uid ); ?>">
                                <?php echo esc_html( sprintf( '%s (%s)', $u->display_name, $u->user_email ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label style="display:inline-block;margin-right:10px;">
                    <strong><?php esc_html_e( 'From', 'customer-item-aliases' ); ?></strong><br />
                    <input type="date" name="from" />
                </label>

                <label style="display:inline-block;margin-right:10px;">
                    <strong><?php esc_html_e( 'To', 'customer-item-aliases' ); ?></strong><br />
                    <input type="date" name="to" />
                </label>

                <?php submit_button( __( 'Refresh', 'customer-item-aliases' ), 'primary', 'submit', false ); ?>
            </form>

            <div id="cia-stats-results" style="margin-top:30px;">
                <p><?php esc_html_e( 'Select filters and click Refresh to load analytics.', 'customer-item-aliases' ); ?></p>
            </div>
        </div>

        <script>
        jQuery(function($){
            var $form    = $('#cia-stats-form');
            var $results = $('#cia-stats-results');
            var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'cia_search_stats' ) ); ?>;

            $form.on('submit', function(e){
                e.preventDefault();
                var params = {
                    action      : 'cia_search_stats_data',
                    nonce       : nonce,
                    customer_id : $('[name="customer_id"]', this).val(),
                    from        : $('[name="from"]', this).val(),
                    to          : $('[name="to"]', this).val()
                };

                $results.html('<p>⏳ Loading analytics…</p>');

                $.get(ajaxurl, params)
                 .done(function(data){
                    if (!data || (!data.top_terms && !data.unresolved)) {
                        $results.html('<p>⚠️ No data available for the selected filters.</p>');
                        return;
                    }

                    var html = '';

                    if (data.top_terms && data.top_terms.length) {
                        html += '<h2>📊 Top Searched Terms</h2>';
                        html += '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Term</th><th>Total</th><th>Resolved</th><th>Not Resolved</th></tr></thead><tbody>';
                        data.top_terms.forEach(function(t){
                            html += '<tr>';
                            html += '<td><strong>' + $('<span>').text(t.search_term).html() + '</strong></td>';
                            html += '<td>' + t.total + '</td>';
                            html += '<td style="color:#006505;">' + t.resolved + '</td>';
                            html += '<td style="color:#b32d2e;">' + t.not_resolved + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                    } else {
                        html += '<p>No search data found.</p>';
                    }

                    if (data.unresolved && data.unresolved.length) {
                        html += '<h2 style="margin-top:30px;">❌ Unresolved Searches (Most Recent)</h2>';
                        html += '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Term</th><th>Customer</th><th>Searched At</th></tr></thead><tbody>';
                        data.unresolved.forEach(function(u){
                            var userName = u.user_id ? ('Customer ' + u.user_id) : 'Anonymous';
                            html += '<tr>';
                            html += '<td><strong>' + $('<span>').text(u.search_term).html() + '</strong></td>';
                            html += '<td>' + userName + '</td>';
                            html += '<td>' + u.searched_at + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                    }

                    $results.html(html);
                })
                 .fail(function(){
                    $results.html('<p style="color:#b32d2e;">❌ AJAX request failed.</p>');
                });
            });
        });
        </script>
        <?php
    }

    // ... (rest of the class remains unchanged: CSV export, import, form, notices) ...
}
