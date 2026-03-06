<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CIA_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'alias',
            'plural'   => 'aliases',
            'ajax'     => false,
        ] );
    }

    public function get_columns(): array {
        return [
            'cb'         => '<input type="checkbox" />',
            'id'         => __( 'ID',         'customer-item-aliases' ),
            'user_id'    => __( 'Customer',    'customer-item-aliases' ),
            'alias_code' => __( 'Alias Code',  'customer-item-aliases' ),
            'ean8_code'  => __( 'EAN8 Code',   'customer-item-aliases' ),
            'status'     => __( 'Status',      'customer-item-aliases' ),
            'expires_at' => __( 'Expires',     'customer-item-aliases' ),
            'created_at' => __( 'Created',     'customer-item-aliases' ),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'id'         => [ 'id',         true  ],
            'user_id'    => [ 'user_id',     false ],
            'alias_code' => [ 'alias_code',  false ],
            'ean8_code'  => [ 'ean8_code',   false ],
            'expires_at' => [ 'expires_at',  false ],
            'created_at' => [ 'created_at',  false ],
        ];
    }

    public function get_bulk_actions(): array {
        return [
            'bulk-enable'  => __( 'Enable',  'customer-item-aliases' ),
            'bulk-disable' => __( 'Disable', 'customer-item-aliases' ),
            'bulk-delete'  => __( 'Delete',  'customer-item-aliases' ),
        ];
    }

    public function column_default( $item, $column_name ): string {
        return esc_html( $item[ $column_name ] ?? '\u2014' );
    }

    public function column_cb( $item ): string {
        return sprintf( '<input type="checkbox" name="alias_ids[]" value="%d" />', $item['id'] );
    }

    // Customer display name instead of raw user ID.
    public function column_user_id( $item ): string {
        $user = get_userdata( (int) $item['user_id'] );
        return $user
            ? esc_html( $user->display_name ) . ' <small>(' . (int) $item['user_id'] . ')</small>'
            : '\u2014';
    }

    /**
     * Status badge column.
     *
     * Effective status (in priority order):
     *   1. Expired   — expires_at is set and in the past (overrides is_active)
     *   2. Disabled  — is_active = 0
     *   3. Expiring  — active but expires within 30 days
     *   4. Active    — everything else
     */
    public function column_status( $item ): string {
        $is_active  = (bool) $item['is_active'];
        $expires_at = $item['expires_at'] ?? null;
        $now        = time();

        if ( $expires_at && strtotime( $expires_at ) <= $now ) {
            return '<span style="color:#b32d2e;font-weight:600;">&#9679; '
                 . esc_html__( 'Expired', 'customer-item-aliases' ) . '</span>';
        }

        if ( ! $is_active ) {
            return '<span style="color:#b32d2e;font-weight:600;">&#9679; '
                 . esc_html__( 'Disabled', 'customer-item-aliases' ) . '</span>';
        }

        if ( $expires_at && strtotime( $expires_at ) <= $now + 30 * DAY_IN_SECONDS ) {
            return '<span style="color:#996800;font-weight:600;">&#9679; '
                 . esc_html__( 'Expiring Soon', 'customer-item-aliases' ) . '</span>';
        }

        return '<span style="color:#006505;font-weight:600;">&#9679; '
             . esc_html__( 'Active', 'customer-item-aliases' ) . '</span>';
    }

    /**
     * Expiry date column.
     * Coloured red if past, amber if within 30 days, plain if further out.
     */
    public function column_expires_at( $item ): string {
        $expires_at = $item['expires_at'] ?? null;

        if ( ! $expires_at ) {
            return '<span style="color:#999;">' . esc_html__( 'Never', 'customer-item-aliases' ) . '</span>';
        }

        $ts       = strtotime( $expires_at );
        $now      = time();
        $fmt      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $label    = esc_html( wp_date( $fmt, $ts ) );

        if ( $ts <= $now ) {
            return '<span style="color:#b32d2e;">' . $label . '</span>';
        }
        if ( $ts <= $now + 30 * DAY_IN_SECONDS ) {
            return '<span style="color:#996800;">' . $label . '</span>';
        }
        return $label;
    }

    /**
     * Alias code column with row actions: Edit | Enable/Disable | Delete.
     */
    public function column_alias_code( $item ): string {
        $id         = (int) $item['id'];
        $is_active  = (bool) $item['is_active'];
        $expires_at = $item['expires_at'] ?? null;
        $is_expired = $expires_at && strtotime( $expires_at ) <= time();
        $effectively_active = $is_active && ! $is_expired;

        $edit_url = add_query_arg(
            [ 'page' => 'cia-aliases', 'action' => 'edit', 'id' => $id ],
            admin_url( 'admin.php' )
        );

        $toggle_action = $effectively_active ? 'disable' : 'enable';
        $toggle_label  = $effectively_active
            ? __( 'Disable', 'customer-item-aliases' )
            : __( 'Enable',  'customer-item-aliases' );
        $toggle_url    = wp_nonce_url(
            add_query_arg(
                [ 'page' => 'cia-aliases', 'action' => $toggle_action, 'id' => $id ],
                admin_url( 'admin.php' )
            ),
            'cia_toggle_' . $id
        );

        $delete_url = wp_nonce_url(
            add_query_arg(
                [ 'page' => 'cia-aliases', 'action' => 'delete', 'id' => $id ],
                admin_url( 'admin.php' )
            ),
            'cia_delete_' . $id
        );

        $actions = [
            'edit'   => sprintf(
                '<a href="%s">%s</a>',
                esc_url( $edit_url ),
                __( 'Edit', 'customer-item-aliases' )
            ),
            'toggle' => sprintf(
                '<a href="%s">%s</a>',
                esc_url( $toggle_url ),
                esc_html( $toggle_label )
            ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm(\'%s\')">%s</a>',
                esc_url( $delete_url ),
                esc_js( __( 'Permanently delete this alias? This cannot be undone.', 'customer-item-aliases' ) ),
                __( 'Delete', 'customer-item-aliases' )
            ),
        ];

        return esc_html( $item['alias_code'] ) . $this->row_actions( $actions );
    }

    public function prepare_items(): void {
        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $search       = sanitize_text_field( $_REQUEST['s'] ?? '' );
        $orderby      = sanitize_key( $_GET['orderby'] ?? 'id' );
        $order        = sanitize_key( $_GET['order']   ?? 'ASC' );

        $total = CIA_DB::count_rows( $search );
        $data  = CIA_DB::get_rows( [
            'per_page' => $per_page,
            'offset'   => ( $current_page - 1 ) * $per_page,
            'search'   => $search,
            'orderby'  => $orderby,
            'order'    => $order,
        ] );

        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $per_page,
        ] );
        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
        $this->items           = $data;
    }
}
