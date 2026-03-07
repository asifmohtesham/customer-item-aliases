<?php
defined( 'ABSPATH' ) || exit;

/**
 * CIA_Search_Stats
 *
 * Tracks alias resolution searches for admin analytics.
 * Every search (both REST and FluxStore product queries) is logged with:
 *   - Which customer performed the search
 *   - The raw search term
 *   - Whether the alias was found
 *   - The resolved EAN code(s), if any
 *   - Timestamp
 */
class CIA_Search_Stats {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . CIA_TABLE_STATS;
    }

    // -------------------------------------------------------------------------
    // Schema management
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT(20) UNSIGNED     NULL,
            search_term  VARCHAR(200)        NOT NULL,
            resolved     TINYINT(1)          NOT NULL DEFAULT 0,
            ean_codes    TEXT                    NULL,
            searched_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user_searched (user_id, searched_at),
            INDEX idx_term_searched (search_term, searched_at),
            FULLTEXT INDEX ft_search_term (search_term)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'cia_search_stats_db_version', CIA_VERSION );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'cia_search_stats_db_version' ) === CIA_VERSION ) {
            return;
        }
        self::create_table();
    }

    // -------------------------------------------------------------------------
    // Hook registration
    // -------------------------------------------------------------------------

    public static function init(): void {
        add_filter( 'cia_after_resolve', [ __CLASS__, 'track_search' ], 10, 3 );
    }

    /**
     * Track an alias resolution event.
     *
     * Hooked to 'cia_after_resolve' — fired by CIA_Hooks::resolve() after
     * returning the EAN code array.
     *
     * @param string[] $ean_codes   The resolved EAN codes (empty if not found).
     * @param string   $search_term The original search term.
     * @param int|null $user_id     The customer who searched (null if anonymous / global admin).
     */
    public static function track_search( array $ean_codes, string $search_term, ?int $user_id ): array {
        global $wpdb;

        $wpdb->insert(
            self::table(),
            [
                'user_id'     => $user_id,
                'search_term' => $search_term,
                'resolved'    => empty( $ean_codes ) ? 0 : 1,
                'ean_codes'   => empty( $ean_codes ) ? null : implode( ',', $ean_codes ),
            ],
            [ '%d', '%s', '%d', '%s' ]
        );

        return $ean_codes;
    }

    // -------------------------------------------------------------------------
    // Admin analytics queries
    // -------------------------------------------------------------------------

    /**
     * Get top searched terms (aggregated) with optional date + user filters.
     *
     * @param array $args {
     *   int    $user_id  Filter to one customer (optional).
     *   string $from     YYYY-MM-DD start date (optional).
     *   string $to       YYYY-MM-DD end date (optional).
     *   int    $limit    Max results (default 20).
     * }
     * @return array[] Rows: [ search_term, total, resolved, not_resolved ]
     */
    public static function get_top_terms( array $args = [] ): array {
        global $wpdb;
        $table = self::table();

        $where = [];
        if ( ! empty( $args['user_id'] ) ) {
            $where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
        }
        if ( ! empty( $args['from'] ) ) {
            $where[] = $wpdb->prepare( 'searched_at >= %s', $args['from'] . ' 00:00:00' );
        }
        if ( ! empty( $args['to'] ) ) {
            $where[] = $wpdb->prepare( 'searched_at < %s', date( 'Y-m-d H:i:s', strtotime( $args['to'] . ' +1 day' ) ) );
        }

        $where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
        $limit     = absint( $args['limit'] ?? 20 );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT search_term,
                        COUNT(*) AS total,
                        SUM(resolved) AS resolved,
                        SUM(1 - resolved) AS not_resolved
                 FROM {$table}
                 {$where_sql}
                 GROUP BY search_term
                 ORDER BY total DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get unresolved (alias not found) searches, most recent first.
     */
    public static function get_unresolved( array $args = [] ): array {
        global $wpdb;
        $table = self::table();

        $where = [ 'resolved = 0' ];
        if ( ! empty( $args['user_id'] ) ) {
            $where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
        }
        if ( ! empty( $args['from'] ) ) {
            $where[] = $wpdb->prepare( 'searched_at >= %s', $args['from'] . ' 00:00:00' );
        }
        if ( ! empty( $args['to'] ) ) {
            $where[] = $wpdb->prepare( 'searched_at < %s', date( 'Y-m-d H:i:s', strtotime( $args['to'] . ' +1 day' ) ) );
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );
        $limit     = absint( $args['limit'] ?? 50 );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, search_term, searched_at
                 FROM {$table}
                 {$where_sql}
                 ORDER BY searched_at DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }
}
