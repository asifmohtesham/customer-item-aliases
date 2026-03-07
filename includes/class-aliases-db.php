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
            INDEX idx_user_ean (user_id, ean8_code),
            INDEX idx_ean8 (ean8_code),
            INDEX idx_alias_active (alias_code, is_active),
            INDEX idx_expires (expires_at, is_active),
            INDEX idx_active (is_active),
            FULLTEXT INDEX ft_alias_code (alias_code)
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
    // WHERE condition builder (shared by get_rows, count_rows_filtered)
    // -------------------------------------------------------------------------

    /**
     * Build an array of prepared WHERE conditions from a filter args array.
     *
     * Supported keys:
     *   string $search       LIKE match on alias_code and ean8_code.
     *   int    $customer_id  Exact match on user_id.
     *   string $status       'active' | 'disabled' | 'expired' | 'all' (default).
     *
     * @return string[]  Ready-to-implode SQL fragments (already escaped).
     */
    private static function build_conditions( array $args ): array {
        global $wpdb;
        $conditions  = [];
        $search      = $args['search']      ?? '';
        $customer_id = absint( $args['customer_id'] ?? 0 );
        $status      = $args['status']      ?? 'all';

        if ( $search !== '' ) {
            $like         = '%' . $wpdb->esc_like( $search ) . '%';
            $conditions[] = $wpdb->prepare(
                '(alias_code LIKE %s OR ean8_code LIKE %s)',
                $like, $like
            );
        }

        if ( $customer_id ) {
            $conditions[] = $wpdb->prepare( 'user_id = %d', $customer_id );
        }

        switch ( $status ) {
            case 'active':
                $conditions[] = 'is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())';
                break;
            case 'disabled':
                $conditions[] = 'is_active = 0';
                break;
            case 'expired':
                $conditions[] = 'expires_at IS NOT NULL AND expires_at <= NOW()';
                break;
            // 'all': no extra condition
        }

        return $conditions;
    }

    // -------------------------------------------------------------------------
    // Admin list helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch paginated rows.
     *
     * @param array $args {
     *   int    $per_page
     *   int    $offset
     *   string $search       LIKE on alias_code / ean8_code.
     *   int    $customer_id  Filter by WP user ID.
     *   string $status       all | active | disabled | expired.
     *   string $orderby
     *   string $order        ASC | DESC
     * }
     */
    public static function get_rows( array $args = [] ): array {
        global $wpdb;
        $table      = self::table();
        $orderby    = sanitize_sql_orderby( $args['orderby'] ?? 'id' ) ?: 'id';
        $order      = strtoupper( $args['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
        $limit      = absint( $args['per_page'] ?? 20 );
        $offset     = absint( $args['offset']   ?? 0 );
        $conditions = self::build_conditions( $args );
        $where      = $conditions ? ( 'WHERE ' . implode( ' AND ', $conditions ) ) : '';

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

    /**
     * Count rows matching a simple search string.
     * Kept for backward compatibility with CIA_List_Table.
     */
    public static function count_rows( string $search = '' ): int {
        return self::count_rows_filtered( [ 'search' => $search ] );
    }

    /**
     * Count rows matching a full filter args array.
     * Used by CIA_REST_API and any caller needing customer_id / status filters.
     */
    public static function count_rows_filtered( array $args = [] ): int {
        global $wpdb;
        $table      = self::table();
        $conditions = self::build_conditions( $args );
        $where      = $conditions ? ( 'WHERE ' . implode( ' AND ', $conditions ) ) : '';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
    }

    /** Fetch a single row by ID (admin; no active/expiry filter). */
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

    /**
     * Check if an EAN code exists in the product catalog.
     * 
     * @param string $ean8_code The EAN to validate.
     * @return bool True if product with this EAN exists.
     */
    public static function ean_exists_in_catalog( string $ean8_code ): bool {
        global $wpdb;
        $ean_meta_key = (string) apply_filters( 'cia_ean_meta_key', CIA_EAN_META_KEY );
        
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id
                 FROM {$wpdb->postmeta}
                 WHERE meta_key   = %s
                   AND meta_value = %s
                 LIMIT 1",
                $ean_meta_key,
                $ean8_code
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
    // Write operations  (each records an audit log entry via CIA_Log)
    // -------------------------------------------------------------------------

    /**
     * Insert a new alias record.
     *
     * @return int  New row ID on success, 0 on failure.
     *              Truthy/falsy for backward-compatible callers that do if($ok).
     */
    public static function insert( array $data ): int {
        global $wpdb;

        $row = [
            'user_id'    => absint( $data['user_id'] ),
            'alias_code' => sanitize_text_field( $data['alias_code'] ),
            'ean8_code'  => sanitize_text_field( $data['ean8_code'] ),
            'is_active'  => isset( $data['is_active'] ) ? (int) $data['is_active'] : 1,
            'expires_at' => $data['expires_at'] ?? null,
        ];

        $inserted = $wpdb->insert(
            self::table(),
            $row,
            [ '%d', '%s', '%s', '%d', '%s' ]
        );

        if ( ! $inserted ) {
            return 0;
        }

        $new_id = (int) $wpdb->insert_id;
        CIA_Log::record( 'created', array_merge( [ 'id' => $new_id ], $row ) );
        return $new_id;
    }

    /**
     * Update an existing alias record.
     * Returns true if the row was updated (including no-op updates where values
     * are unchanged — wpdb::update returns 0 rows but no error in that case).
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

        // wpdb::update returns int (rows affected) or false on error.
        // !== false covers the case where values haven't changed (0 rows affected)
        // which is a valid successful no-op update, not an error.
        $result = $wpdb->update(
            self::table(),
            $row,
            [ 'id' => $id ],
            [ '%d', '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        if ( $result === false ) {
            return false;
        }

        CIA_Log::record( 'updated', array_merge( [ 'id' => $id ], $row ), $old );
        return true;
    }

    /** Enable or disable a single alias record. */
    public static function set_active( int $id, bool $active ): bool {
        global $wpdb;

        $old    = self::get_row( $id );
        $result = $wpdb->update(
            self::table(),
            [ 'is_active' => $active ? 1 : 0 ],
            [ 'id'        => $id ],
            [ '%d' ],
            [ '%d' ]
        );

        if ( $result === false ) {
            return false;
        }

        if ( $old ) {
            $new = array_merge( $old, [ 'is_active' => $active ? 1 : 0 ] );
            CIA_Log::record( $active ? 'enabled' : 'disabled', $new, $old );
        }

        return true;
    }

    /**
     * Hard-delete one or multiple alias records.
     * Captures rows before deletion so the audit log preserves the data.
     *
     * @param int|int[] $ids
     */
    public static function delete( $ids ): void {
        global $wpdb;
        $table   = self::table();
        $ids     = array_filter( array_map( 'absint', (array) $ids ) );
        if ( empty( $ids ) ) return;

        $id_list = implode( ',', $ids );

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
