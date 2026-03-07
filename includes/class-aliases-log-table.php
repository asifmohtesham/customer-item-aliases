<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * CIA_Log_Table — WP_List_Table subclass for the Audit Log admin screen.
 *
 * Displays every audit entry with:
 *  - Colour-coded action badge
 *  - Inline before → after diff for 'updated' entries
 *  - Sortable Date and Action columns
 *  - Free-text search (alias_code, ean8_code) + action filter tabs
 */
class CIA_Log_Table extends WP_List_Table {

    /** Tracked fields shown in the Changes diff column. */
    private const DIFF_FIELDS = [ 'alias_code', 'ean8_code', 'is_active', 'expires_at', 'user_id' ];

    /** Badge colours per action. */
    private const ACTION_COLORS = [
        'created'  => [ 'bg' => '#00a32a', 'label' => 'CREATED'  ],
        'updated'  => [ 'bg' => '#0073aa', 'label' => 'UPDATED'  ],
        'deleted'  => [ 'bg' => '#d63638', 'label' => 'DELETED'  ],
        'enabled'  => [ 'bg' => '#00a32a', 'label' => 'ENABLED'  ],
        'disabled' => [ 'bg' => '#996800', 'label' => 'DISABLED' ],
    ];

    public function __construct() {
        parent::__construct( [
            'singular' => 'log_entry',
            'plural'   => 'log_entries',
            'ajax'     => false,
        ] );
    }

    // -------------------------------------------------------------------------
    // Column definitions
    // -------------------------------------------------------------------------

    public function get_columns(): array {
        return [
            'created_at'   => __( 'Date / Time',   'customer-item-aliases' ),
            'action'       => __( 'Action',        'customer-item-aliases' ),
            'alias_code'   => __( 'Alias Code',    'customer-item-aliases' ),
            'ean8_code'    => __( 'EAN8',          'customer-item-aliases' ),
            'customer_id'  => __( 'Customer',      'customer-item-aliases' ),
            'performed_by' => __( 'Changed By',    'customer-item-aliases' ),
            'changes'      => __( 'Changes',       'customer-item-aliases' ),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'created_at' => [ 'created_at', true  ],
            'action'     => [ 'action',     false ],
            'alias_code' => [ 'alias_code', false ],
        ];
    }

    // -------------------------------------------------------------------------
    // Data preparation
    // -------------------------------------------------------------------------

    public function prepare_items(): void {
        $per_page     = 25;
        $current_page = $this->get_pagenum();

        $args = [
            'search'        => sanitize_text_field( $_GET['s']          ?? '' ),
            'action_filter' => sanitize_key(        $_GET['log_action'] ?? '' ),
            'customer_id'   => absint(              $_GET['log_customer'] ?? 0 ),
            'orderby'       => sanitize_key(        $_GET['orderby']    ?? 'id' ),
            'order'         => sanitize_key(        $_GET['order']      ?? 'desc' ),
            'per_page'      => $per_page,
            'offset'        => ( $current_page - 1 ) * $per_page,
        ];

        $this->set_pagination_args( [
            'total_items' => CIA_Log::count_rows( $args ),
            'per_page'    => $per_page,
        ] );

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
        $this->items           = CIA_Log::get_rows( $args );
    }

    // -------------------------------------------------------------------------
    // Column renderers
    // -------------------------------------------------------------------------

    public function column_default( $item, $column_name ) {
        return esc_html( $item[ $column_name ] ?? '' );
    }

    public function column_created_at( $item ): string {
        $ts     = strtotime( $item['created_at'] );
        $date   = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
        $ago    = human_time_diff( $ts, time() );
        return sprintf(
            '<span title="%s">%s</span><br><small style="color:#666;">%s ago</small>',
            esc_attr( $item['created_at'] ),
            esc_html( $date ),
            esc_html( $ago )
        );
    }

    public function column_action( $item ): string {
        $action = $item['action'];
        $cfg    = self::ACTION_COLORS[ $action ] ?? [ 'bg' => '#666', 'label' => strtoupper( $action ) ];
        return sprintf(
            '<span style="display:inline-block;padding:3px 9px;border-radius:3px;font-size:11px;font-weight:700;letter-spacing:.4px;background:%s;color:#fff;">%s</span>',
            esc_attr( $cfg['bg'] ),
            esc_html( $cfg['label'] )
        );
    }

    public function column_alias_code( $item ): string {
        return '<code>' . esc_html( $item['alias_code'] ) . '</code>';
    }

    public function column_ean8_code( $item ): string {
        return '<code>' . esc_html( $item['ean8_code'] ) . '</code>';
    }

    public function column_customer_id( $item ): string {
        $uid  = absint( $item['customer_id'] );
        $user = $uid ? get_userdata( $uid ) : null;

        if ( ! $user ) {
            return $uid ? sprintf( '<em>#%d</em>', $uid ) : '<em>—</em>';
        }

        $filter_url = add_query_arg( [
            'page'         => 'cia-alias-log',
            'log_customer' => $uid,
        ], admin_url( 'admin.php' ) );

        return sprintf(
            '<a href="%s">%s</a><br><small style="color:#666;">%s</small>',
            esc_url( $filter_url ),
            esc_html( $user->display_name ),
            esc_html( $user->user_email )
        );
    }

    public function column_performed_by( $item ): string {
        $uid  = absint( $item['performed_by'] );
        $user = $uid ? get_userdata( $uid ) : null;
        $name = $user ? esc_html( $user->display_name ) : ( $uid ? sprintf( '<em>#%d</em>', $uid ) : '<em>system</em>' );
        $ip   = ! empty( $item['ip_address'] )
            ? sprintf( '<br><small style="color:#999;">%s</small>', esc_html( $item['ip_address'] ) )
            : '';
        return $name . $ip;
    }

    public function column_changes( $item ): string {
        if ( $item['action'] !== 'updated' ) {
            return '<span style="color:#ccc">—</span>';
        }

        $old   = json_decode( $item['old_values'] ?? '{}', true ) ?: [];
        $new   = json_decode( $item['new_values'] ?? '{}', true ) ?: [];
        $diffs = [];

        foreach ( self::DIFF_FIELDS as $field ) {
            $o = (string) ( $old[ $field ] ?? '' );
            $n = (string) ( $new[ $field ] ?? '' );
            if ( $o === $n ) continue;

            // Human-friendly labels
            $label = match ( $field ) {
                'is_active'  => 'Status',
                'expires_at' => 'Expires',
                'user_id'    => 'Customer',
                default      => $field,
            };

            // For is_active show On/Off instead of 1/0
            if ( $field === 'is_active' ) {
                $o = $o === '1' ? 'Active' : 'Disabled';
                $n = $n === '1' ? 'Active' : 'Disabled';
            }

            $diffs[] = sprintf(
                '<span style="color:#555;font-size:11px;">%s:</span> ' .
                '<del style="background:#ffeef0;color:#d63638;text-decoration:none;padding:0 2px;border-radius:2px;">%s</del>' .
                ' <span style="color:#aaa;font-size:10px;">→</span> ' .
                '<ins style="background:#e6ffec;color:#00a32a;text-decoration:none;padding:0 2px;border-radius:2px;">%s</ins>',
                esc_html( $label ),
                esc_html( $o ?: '(empty)' ),
                esc_html( $n ?: '(empty)' )
            );
        }

        return $diffs
            ? implode( '<br>', $diffs )
            : '<span style="color:#ccc">(no tracked changes)</span>';
    }

    public function no_items(): void {
        esc_html_e( 'No audit log entries found.', 'customer-item-aliases' );
    }
}
