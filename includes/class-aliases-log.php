<?php
defined( 'ABSPATH' ) || exit;

/**
 * CIA_Log — Audit log table for all alias write operations.
 *
 * Every insert, update, delete, enable, and disable in CIA_DB automatically
 * calls CIA_Log::record(). The log is append-only; old entries can be purged
 * via the admin UI or programmatically with CIA_Log::purge_old().
 *
 * Table: {prefix}cia_alias_log
 * +--------------+------------------------------------------+
 * | alias_id     | affected alias row ID (preserved on del) |
 * | action       | created|updated|deleted|enabled|disabled  |
 * | customer_id  | alias owner’s WP user ID                 |
 * | alias_code   | snapshot at time of action               |
 * | ean8_code    | snapshot at time of action               |
 * | old_values   | JSON of row BEFORE change (null=create)  |
 * | new_values   | JSON of row AFTER change  (null=delete)  |
 * | performed_by | WP user who triggered the action         |
 * | ip_address   | client IP (IPv4 / IPv6)                  |
 * | created_at   | UTC timestamp                            |
 * +--------------+------------------------------------------+
 */
class CIA_Log {

    public static function log_table(): string {
        global $wpdb;
        return $wpdb->prefix . CIA_TABLE_LOG;
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        $table           = self::log_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            alias_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            action       VARCHAR(20)         NOT NULL,
            customer_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            alias_code   VARCHAR(100)        NOT NULL DEFAULT '',
            ean8_code    VARCHAR(50)         NOT NULL DEFAULT '',
            old_values   LONGTEXT                     DEFAULT NULL,
            new_values   LONGTEXT                     DEFAULT NULL,
            performed_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            ip_address   VARCHAR(45)                  DEFAULT NULL,
            created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_alias_id  (alias_id),
            INDEX idx_action    (action),
            INDEX idx_performed (performed_by),
            INDEX idx_created   (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'cia_log_db_version', CIA_VERSION );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'cia_log_db_version' ) === CIA_VERSION ) {
            return;
        }
        self::create_table();
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Record an audit log entry. Called automatically by every CIA_DB write.
     *
     * @param string     $action   created|updated|deleted|enabled|disabled
     * @param array      $new_row  Row snapshot AFTER the action (or the row
     *                             being deleted, for deleted entries).
     * @param array|null $old_row  Row snapshot BEFORE the action (updates only).
     */
    public static function record(
        string $action,
        array  $new_row,
        ?array $old_row = null
    ): void {
        global $wpdb;

        // Silently bail if the log table doesn’t exist yet (e.g. mid-upgrade)
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '" . self::log_table() . "'" ) ) {
            return;
        }

        $wpdb->insert(
            self::log_table(),
            [
                'alias_id'     => absint( $new_row['id'] ?? 0 ),
                'action'       => $action,
                'customer_id'  => absint( $new_row['user_id'] ?? 0 ),
                'alias_code'   => $new_row['alias_code'] ?? '',
                'ean8_code'    => $new_row['ean8_code']  ?? '',
                'old_values'   => $old_row !== null ? wp_json_encode( $old_row ) : null,
                'new_values'   => $action !== 'deleted' ? wp_json_encode( $new_row ) : null,
                'performed_by' => get_current_user_id(),
                'ip_address'   => self::get_client_ip(),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Fetch paginated log rows for the admin list table.
     *
     * @param array $args {
     *   string $search        Searches alias_code and ean8_code.
     *   string $action_filter One of the action constants, or '' for all.
     *   string $orderby
     *   string $order         ASC|DESC
     *   int    $per_page
     *   int    $offset
     * }
     */
    public static function get_rows( array $args = [] ): array {
        global $wpdb;
        $table   = self::log_table();
        $orderby = sanitize_sql_orderby( $args['orderby'] ?? 'id' ) ?: 'id';
        $order   = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
        $limit   = absint( $args['per_page'] ?? 20 );
        $offset  = absint( $args['offset']   ?? 0 );

        $wheres = self::build_wheres( $args );
        $where  = $wheres ? ( 'WHERE ' . implode( ' AND ', $wheres ) ) : '';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$where}
                 ORDER BY {$orderby} {$order}
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function count_rows( array $args = [] ): int {
        global $wpdb;
        $table  = self::log_table();
        $wheres = self::build_wheres( $args );
        $where  = $wheres ? ( 'WHERE ' . implode( ' AND ', $wheres ) ) : '';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
    }

    // -------------------------------------------------------------------------
    // Purge
    // -------------------------------------------------------------------------

    /**
     * Hard-delete log entries older than $days days. Returns deleted row count.
     */
    public static function purge_old( int $days = 90 ): int {
        global $wpdb;
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . self::log_table() . "
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function build_wheres( array $args ): array {
        global $wpdb;
        $wheres        = [];
        $search        = $args['search']        ?? '';
        $action_filter = $args['action_filter'] ?? '';
        $customer_id   = absint( $args['customer_id'] ?? 0 );

        if ( $search !== '' ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $wheres[] = $wpdb->prepare(
                '(alias_code LIKE %s OR ean8_code LIKE %s)',
                $like, $like
            );
        }
        if ( $action_filter !== '' ) {
            $wheres[] = $wpdb->prepare( 'action = %s', $action_filter );
        }
        if ( $customer_id ) {
            $wheres[] = $wpdb->prepare( 'customer_id = %d', $customer_id );
        }

        return $wheres;
    }

    private static function get_client_ip(): string {
        $keys = [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        foreach ( $keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }
}
