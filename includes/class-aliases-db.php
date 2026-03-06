<?php
defined( 'ABSPATH' ) || exit;

class CIA_DB {

    /**
     * Returns the full prefixed table name.
     *
     * Safe to interpolate directly into SQL: $wpdb->prefix is set by WordPress
     * core and CIA_TABLE_ALIAS is a plugin constant — neither is user input.
     * This is the same pattern WooCommerce uses for $wpdb->posts, etc.
     *
     * NOTE: Do NOT use %i for the table name in prepare(). %i was added in
     * WordPress 6.2 and silently returns null on older versions.
     */
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . CIA_TABLE_ALIAS;
    }

    // -------------------------------------------------------------------------
    // Schema management
    // -------------------------------------------------------------------------

    /**
     * Create (or upgrade) the alias table via dbDelta.
     *
     * dbDelta safely adds new columns to an existing table without removing or
     * modifying existing ones, making it safe to call on every plugin upgrade.
     *
     * Columns:
     *   is_active  (TINYINT 0/1, default 1) — soft-delete flag.
     *              Disabled aliases are never resolved but remain in the DB
     *              for audit history and can be re-enabled at any time.
     *
     *   expires_at (DATETIME, nullable)      — optional expiry timestamp.
     *              NULL means the alias never expires. When a non-null value
     *              is in the past, the alias is treated as inactive by all
     *              resolvers regardless of the is_active flag.
     *              Intentionally optional: fully backwards-compatible with
     *              existing rows which will have NULL and behave identically
     *              to before.
     */
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

    /**
     * Run DB migrations if the stored schema version is behind CIA_VERSION.
     *
     * Called on every `plugins_loaded` — exits immediately when already current
     * so there is zero overhead on normal page loads.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( 'cia_db_version' ) === CIA_VERSION ) {
            return;
        }
        self::create_table(); // dbDelta adds missing columns; never destructive
    }

    // -------------------------------------------------------------------------
    // Admin list helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch rows for the admin list table.
     * Returns ALL rows (active and disabled) so admins can manage everything.
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
     * Count total rows (for pagination). Counts ALL rows regardless of status.
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
     * Fetch a single row by ID (admin use; no active/expiry filter).
     */
    public static function get_row( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        ) ?: null;
    }

    // -------------------------------------------------------------------------
    // Exact-match resolvers  (active + non-expired only)
    //
    // The shared active filter:
    //   AND is_active = 1
    //   AND (expires_at IS NULL OR expires_at > NOW())
    //
    // expires_at = NULL → never expires (fully backwards-compatible with rows
    // that predate the column — they will also have NULL after the upgrade).
    // -------------------------------------------------------------------------

    /**
     * Backward-compat wrapper: first EAN code for a customer alias.
     */
    public static function resolve_alias( int $user_id, string $alias ): ?string {
        return self::resolve_aliases( $user_id, $alias )[0] ?? null;
    }

    /**
     * Exact match: all active, non-expired EAN codes for a customer alias.
     *
     * @param  int    $user_id
     * @param  string $alias
     * @return string[]
     */
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
                $user_id,
                $alias
            )
        ) ?: [];
    }

    /**
     * Exact match: all active, non-expired EAN codes across ALL customers.
     * Used for admin searches.
     *
     * @param  string $alias
     * @return string[]
     */
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
    // Called only when exact match returns empty.
    // Pattern: %search% (contains) for maximum flexibility.
    // -------------------------------------------------------------------------

    /**
     * Partial match: active, non-expired aliases containing the term, scoped to one customer.
     *
     * @param  int    $user_id
     * @param  string $alias
     * @return string[]
     */
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
                $user_id,
                $pattern
            )
        ) ?: [];
    }

    /**
     * Partial match: active, non-expired aliases containing the term, across ALL customers.
     * Used as fallback for admin searches.
     *
     * @param  string $alias
     * @return string[]
     */
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
    // Write operations
    // -------------------------------------------------------------------------

    /**
     * Insert a new alias record.
     *
     * @param array $data {
     *   int         $user_id
     *   string      $alias_code
     *   string      $ean8_code
     *   int         $is_active   Optional. Defaults to 1 (active).
     *   string|null $expires_at  Optional. MySQL datetime or null.
     * }
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

        return (bool) $wpdb->insert(
            self::table(),
            $row,
            [ '%d', '%s', '%s', '%d', '%s' ]
        );
    }

    /**
     * Update an existing alias record.
     *
     * Passing expires_at = null explicitly clears the expiry date.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        $row = [
            'user_id'    => absint( $data['user_id'] ),
            'alias_code' => sanitize_text_field( $data['alias_code'] ),
            'ean8_code'  => sanitize_text_field( $data['ean8_code'] ),
            'is_active'  => isset( $data['is_active'] ) ? (int) $data['is_active'] : 1,
            'expires_at' => $data['expires_at'] ?? null,
        ];

        return (bool) $wpdb->update(
            self::table(),
            $row,
            [ 'id' => $id ],
            [ '%d', '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Enable or disable a single alias record (soft-delete toggle).
     *
     * @param  int  $id      Alias record ID.
     * @param  bool $active  true = enable, false = disable.
     */
    public static function set_active( int $id, bool $active ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            self::table(),
            [ 'is_active' => $active ? 1 : 0 ],
            [ 'id'        => $id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    /**
     * Delete one or multiple alias records (hard delete).
     *
     * @param int|int[] $ids
     */
    public static function delete( $ids ): void {
        global $wpdb;
        $table   = self::table();
        $ids     = array_map( 'absint', (array) $ids );
        $id_list = implode( ',', $ids );
        $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$id_list})" );
    }
}
