<?php
defined( 'ABSPATH' ) || exit;

class CIA_DB {

    /**
     * Returns the full prefixed table name.
     * Safe to interpolate directly into SQL — same pattern WooCommerce uses.
     * Do NOT use %i for table names: added in WP 6.2, silent null on older.
     */
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . CIA_TABLE_ALIAS;
    }

    // -------------------------------------------------------------------------
    // Schema management
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT(20) UNSIGNED NOT NULL,
            alias_code  VARCHAR(100)        NOT NULL,
            ean8_code   VARCHAR(50)         NOT NULL,
            is_active   TINYINT(1)          NOT NULL DEFAULT 1,
            expires_at  DATETIME                     DEFAULT NULL,
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user_alias (user_id, alias_code),
            INDEX idx_ean8       (ean8_code),
            INDEX idx_active     (is_active)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'cia_db_version', CIA_VERSION );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'cia_db_version' ) === CIA_VERSION ) {
            return;
        }
        self::create_table();
    }

    // -------------------------------------------------------------------------
    // Admin list helpers
    // -------------------------------------------------------------------------

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
                "SELECT id, user_id, alias_code, ean8_code, is_active, expires_at, created_at
                 FROM {$table} {$where}
                 ORDER BY {$orderby} {$order}
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

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

    public static function get_row( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        ) ?: null;
    }

    // -------------------------------------------------------------------------
    // Duplicate / conflict detection
    // -------------------------------------------------------------------------

    /**
     * Find an exact (user_id, alias_code, ean8_code) duplicate.
     * Pass $exclude_id when editing so a row doesn't flag itself.
     */
    public static function find_exact_duplicate(
        int $user_id,
        string $alias_code,
        string $ean8_code,
        int $exclude_id = 0
    ): ?array {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, alias_code, ean8_code
                 FROM {$table}
                 WHERE user_id    = %d
                   AND alias_code = %s
                   AND ean8_code  = %s
                   AND id        != %d
                 LIMIT 1",
                $user_id, $alias_code, $ean8_code, $exclude_id
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Find all OTHER EAN mappings for a given (user_id, alias_code) pair.
     * Non-empty result = multi-EAN alias (intentional, admin should be informed).
     */
    public static function find_alias_mappings(
        int $user_id,
        string $alias_code,
        int $exclude_id = 0
    ): array {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, ean8_code, is_active, expires_at
                 FROM {$table}
                 WHERE user_id    = %d
                   AND alias_code = %s
                   AND id        != %d
                 ORDER BY id ASC",
                $user_id, $alias_code, $exclude_id
            ),
            ARRAY_A
        ) ?: [];
    }

    // -------------------------------------------------------------------------
    // Export helpers
    // -------------------------------------------------------------------------

    public static function get_rows_for_export( ?int $user_id = null ): array {
        global $wpdb;
        $table = self::table();
        $where = $user_id ? $wpdb->prepare( 'WHERE user_id = %d', $user_id ) : '';

        return $wpdb->get_results(
            "SELECT id, user_id, alias_code, ean8_code, is_active, expires_at, created_at
             FROM {$table} {$where}
             ORDER BY user_id ASC, alias_code ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function get_customers_with_aliases(): array {
        global $wpdb;
        $table = self::table();
        $ids   = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} ORDER BY user_id ASC" );
        return array_map( 'intval', $ids ?: [] );
    }

    // -------------------------------------------------------------------------
    // Import helpers
    // -------------------------------------------------------------------------

    public static function row_exists( int $user_id, string $alias_code, string $ean8_code ): bool {
        global $wpdb;
        $table = self::table();
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE user_id    = %d
                   AND alias_code = %s
                   AND ean8_code  = %s
                 LIMIT 1",
                $user_id, $alias_code, $ean8_code
            )
        );
    }

    // -------------------------------------------------------------------------
    // Exact-match resolvers (active + non-expired only)
    // -------------------------------------------------------------------------

    public static function resolve_alias( int $user_id, string $alias ): ?string {
        return self::resolve_aliases( $user_id, $alias )[0] ?? null;
    }

    public static function resolve_aliases( int $user_id, string $alias ): array {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ean8_code
                 FROM {$table}
                 WHERE user_id    = %d
                   AND alias_code = %s
                   AND is_active  = 1
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY id ASC",
                $user_id, $alias
            )
        ) ?: [];
    }

    public static function resolve_aliases_global( string $alias ): array {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT ean8_code
                 FROM {$table}
                 WHERE alias_code = %s
                   AND is_active  = 1
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY ean8_code ASC",
                $alias
            )
        ) ?: [];
    }

    // -------------------------------------------------------------------------
    // LIKE (partial) fallback resolvers
    // -------------------------------------------------------------------------

    public static function resolve_aliases_like( int $user_id, string $alias ): array {
        global $wpdb;
        $table   = self::table();
        $pattern = '%' . $wpdb->esc_like( $alias ) . '%';

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT ean8_code
                 FROM {$table}
                 WHERE user_id    = %d
                   AND alias_code LIKE %s
                   AND is_active  = 1
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY ean8_code ASC",
                $user_id, $pattern
            )
        ) ?: [];
    }

    public static function resolve_aliases_global_like( string $alias ): array {
        global $wpdb;
        $table   = self::table();
        $pattern = '%' . $wpdb->esc_like( $alias ) . '%';

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT ean8_code
                 FROM {$table}
                 WHERE alias_code LIKE %s
                   AND is_active  = 1
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY ean8_code ASC",
                $pattern
            )
        ) ?: [];
    }

    // -------------------------------------------------------------------------
    // Write operations  (each one records an audit log entry)
    // -------------------------------------------------------------------------

    /**
     * Insert a new alias record and log the creation.
     */
    public static function insert( array $data ): bool {
        global $wpdb;

        $row = [
            'user_id'    => absint( $data['user_id'] ),
            'alias_code' => sanitize_text_field( $data['alias_code'] ),
            'ean8_code'  => sanitize_text_field( $data['ean8_code'] ),
            'is_active'  => isset( $data['is_active'] ) ? (int) $data['is_active'] : 1,
            'expires_at' => $data['expires_at'] ?? null,
        ];

        $ok = (bool) $wpdb->insert(
            self::table(),
            $row,
            [ '%d', '%s', '%s', '%d', '%s' ]
        );

        if ( $ok ) {
            CIA_Log::record( 'created', array_merge( [ 'id' => $wpdb->insert_id ], $row ) );
        }

        return $ok;
    }

    /**
     * Update an existing alias record.
     * Fetches the old row before writing so the log captures a before/after diff.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        $old = self::get_row( $id );

        $row = [
            'user_id'    => absint( $data['user_id'] ),
            'alias_code' => sanitize_text_field( $data['alias_code'] ),
            'ean8_code'  => sanitize_text_field( $data['ean8_code'] ),
            'is_active'  => isset( $data['is_active'] ) ? (int) $data['is_active'] : 1,
            'expires_at' => $data['expires_at'] ?? null,
        ];

        $ok = (bool) $wpdb->update(
            self::table(),
            $row,
            [ 'id' => $id ],
            [ '%d', '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        if ( $ok ) {
            CIA_Log::record( 'updated', array_merge( [ 'id' => $id ], $row ), $old );
        }

        return $ok;
    }

    /**
     * Enable or disable a single alias record.
     * Fetches the row before toggling so the log captures the state change.
     */
    public static function set_active( int $id, bool $active ): bool {
        global $wpdb;

        $old = self::get_row( $id );

        $ok = (bool) $wpdb->update(
            self::table(),
            [ 'is_active' => $active ? 1 : 0 ],
            [ 'id'        => $id ],
            [ '%d' ],
            [ '%d' ]
        );

        if ( $ok && $old ) {
            $new = array_merge( $old, [ 'is_active' => $active ? 1 : 0 ] );
            CIA_Log::record( $active ? 'enabled' : 'disabled', $new, $old );
        }

        return $ok;
    }

    /**
     * Hard-delete one or multiple alias records.
     * Captures each row before deletion so the audit log preserves the data.
     *
     * @param int|int[] $ids
     */
    public static function delete( $ids ): void {
        global $wpdb;
        $table   = self::table();
        $ids     = array_map( 'absint', (array) $ids );
        $id_list = implode( ',', $ids );

        // Capture rows before deletion for audit log
        $to_log = [];
        foreach ( $ids as $id ) {
            $row = self::get_row( $id );
            if ( $row ) $to_log[] = $row;
        }

        $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$id_list})" );

        foreach ( $to_log as $row ) {
            CIA_Log::record( 'deleted', $row );
        }
    }
}
