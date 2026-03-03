<?php
defined( 'ABSPATH' ) || exit;

class CIA_DB {

    /**
     * Returns the full prefixed table name.
     */
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . CIA_TABLE_ALIAS;
    }

    /**
     * Creates the table on plugin activation via dbDelta.
     */
    public static function create_table(): void {
        global $wpdb;
        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT(20) UNSIGNED NOT NULL,
            alias_code  VARCHAR(100)        NOT NULL,
            ean8_code   CHAR(8)             NOT NULL,
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user_alias (user_id, alias_code),
            INDEX idx_ean8 (ean8_code)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'cia_db_version', CIA_VERSION );
    }

    /**
     * Fetch all rows for the list table (with pagination & sorting).
     */
    public static function get_rows( array $args = [] ): array {
        global $wpdb;
        $table   = self::table();
        $orderby = sanitize_sql_orderby( $args['orderby'] ?? 'id' ) ?: 'id';
        $order   = strtoupper( $args['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
        $limit   = absint( $args['per_page'] ?? 20 );
        $offset  = absint( $args['offset']   ?? 0 );
        $search  = $args['search'] ?? '';

        $where = '';
        if ( $search ) {
            $where = $wpdb->prepare(
                'WHERE alias_code LIKE %s OR ean8_code LIKE %s',
                '%' . $wpdb->esc_like( $search ) . '%',
                '%' . $wpdb->esc_like( $search ) . '%'
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, alias_code, ean8_code, created_at
                 FROM {$table} {$where}
                 ORDER BY {$orderby} {$order}
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Count total rows (for pagination).
     */
    public static function count_rows( string $search = '' ): int {
        global $wpdb;
        $table = self::table();
        $where = '';

        if ( $search ) {
            $where = $wpdb->prepare(
                'WHERE alias_code LIKE %s OR ean8_code LIKE %s',
                '%' . $wpdb->esc_like( $search ) . '%',
                '%' . $wpdb->esc_like( $search ) . '%'
            );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
    }

    /**
     * Fetch a single row by ID.
     */
    public static function get_row( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", self::table(), $id ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Resolve a customer alias to its master EAN8.
     */
    public static function resolve_alias( int $user_id, string $alias ): ?string {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ean8_code FROM %i WHERE user_id = %d AND alias_code = %s LIMIT 1",
                self::table(),
                $user_id,
                $alias
            )
        ) ?: null;
    }

    /**
     * Insert a new alias record.
     */
    public static function insert( array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->insert(
            self::table(),
            [
                'user_id'    => absint( $data['user_id'] ),
                'alias_code' => sanitize_text_field( $data['alias_code'] ),
                'ean8_code'  => sanitize_text_field( $data['ean8_code'] ),
            ],
            [ '%d', '%s', '%s' ]
        );
    }

    /**
     * Update an existing alias record.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            self::table(),
            [
                'user_id'    => absint( $data['user_id'] ),
                'alias_code' => sanitize_text_field( $data['alias_code'] ),
                'ean8_code'  => sanitize_text_field( $data['ean8_code'] ),
            ],
            [ 'id' => $id ],
            [ '%d', '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Delete one or multiple alias records.
     *
     * @param int|int[] $ids
     */
    public static function delete( $ids ): void {
        global $wpdb;
        $ids        = array_map( 'absint', (array) $ids );
        $id_list    = implode( ',', $ids );
        $wpdb->query( "DELETE FROM " . self::table() . " WHERE id IN ({$id_list})" );
    }
}
