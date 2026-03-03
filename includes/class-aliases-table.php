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
            'id'         => __( 'ID',          'customer-item-aliases' ),
            'user_id'    => __( 'Customer',     'customer-item-aliases' ),
            'alias_code' => __( 'Alias Code',   'customer-item-aliases' ),
            'ean8_code'  => __( 'EAN8 Code',    'customer-item-aliases' ),
            'created_at' => __( 'Created',      'customer-item-aliases' ),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'id'         => [ 'id',         true  ],
            'user_id'    => [ 'user_id',     false ],
            'alias_code' => [ 'alias_code',  false ],
            'ean8_code'  => [ 'ean8_code',   false ],
            'created_at' => [ 'created_at',  false ],
        ];
    }

    public function get_bulk_actions(): array {
        return [ 'bulk-delete' => __( 'Delete', 'customer-item-aliases' ) ];
    }

    public function column_default( $item, $column_name ): string {
        return esc_html( $item[ $column_name ] ?? '—' );
    }

    public function column_cb( $item ): string {
        return sprintf( '<input type="checkbox" name="alias_ids[]" value="%d" />', $item['id'] );
    }

    // Show WordPress display name instead of raw user ID
    public function column_user_id( $item ): string {
        $user = get_userdata( $item['user_id'] );
        return $user
            ? esc_html( $user->display_name ) . ' <small>(' . $item['user_id'] . ')</small>'
            : '—';
    }

    // Row actions on alias_code column
    public function column_alias_code( $item ): string {
        $edit_url   = add_query_arg( [ 'page' => 'cia-aliases', 'action' => 'edit',   'id' => $item['id'] ], admin_url( 'admin.php' ) );
        $delete_url = wp_nonce_url(
            add_query_arg( [ 'page' => 'cia-aliases', 'action' => 'delete', 'id' => $item['id'] ], admin_url( 'admin.php' ) ),
            'cia_delete_' . $item['id']
        );

        $actions = [
            'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Edit', 'customer-item-aliases' ) ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm(\'%s\')">%s</a>',
                esc_url( $delete_url ),
                esc_js( __( 'Delete this alias? This cannot be undone.', 'customer-item-aliases' ) ),
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
        $this->items = $data;
    }
}
