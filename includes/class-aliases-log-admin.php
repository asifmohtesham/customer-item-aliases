<?php
defined( 'ABSPATH' ) || exit;

/**
 * CIA_Log_Admin — Admin page for the alias audit log.
 *
 * Adds an “Audit Log” submenu under the main Item Aliases menu page.
 * Features:
 *  - Paginated list table (CIA_Log_Table)
 *  - Free-text search (alias_code, ean8_code)
 *  - Action filter tabs (All | Created | Updated | Deleted | Enabled | Disabled)
 *  - Customer filter (click customer name in any row)
 *  - “Purge entries older than N days” form (with confirmation)
 */
class CIA_Log_Admin {

    private const ACTIONS = [ 'created', 'updated', 'deleted', 'enabled', 'disabled' ];

    public static function init(): void {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menu'  ] );
        add_action( 'admin_init',    [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices'  ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public static function register_menu(): void {
        add_submenu_page(
            'cia-aliases',
            __( 'Alias Audit Log',  'customer-item-aliases' ),
            __( 'Audit Log',        'customer-item-aliases' ),
            'manage_woocommerce',
            'cia-alias-log',
            [ __CLASS__, 'render_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // Action handler
    // -------------------------------------------------------------------------

    public static function handle_actions(): void {
        $page   = sanitize_key( $_REQUEST['page']   ?? '' );
        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        if ( $page !== 'cia-alias-log' ) return;
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        if ( $action === 'purge' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            check_admin_referer( 'cia_purge_log' );
            $days    = max( 1, absint( $_POST['purge_days'] ?? 90 ) );
            $deleted = CIA_Log::purge_old( $days );
            wp_safe_redirect( add_query_arg( [
                'page'        => 'cia-alias-log',
                'cia_log_msg' => 'purged',
                'purge_count' => $deleted,
            ], admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Notices
    // -------------------------------------------------------------------------

    public static function admin_notices(): void {
        $msg = sanitize_key( $_GET['cia_log_msg'] ?? '' );
        if ( $msg !== 'purged' ) return;

        $count = absint( $_GET['purge_count'] ?? 0 );
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(
                sprintf(
                    _n(
                        '%d audit log entry purged successfully.',
                        '%d audit log entries purged successfully.',
                        $count,
                        'customer-item-aliases'
                    ),
                    $count
                )
            )
        );
    }

    // -------------------------------------------------------------------------
    // Page renderer
    // -------------------------------------------------------------------------

    public static function render_page(): void {
        $base_url      = admin_url( 'admin.php' );
        $search        = sanitize_text_field( $_GET['s']            ?? '' );
        $action_filter = sanitize_key(        $_GET['log_action']   ?? '' );
        $customer_id   = absint(              $_GET['log_customer'] ?? 0 );

        $table = new CIA_Log_Table();
        $table->prepare_items();

        ?>
        <div class="wrap">

            <h1><?php esc_html_e( 'Alias Audit Log', 'customer-item-aliases' ); ?></h1>

            <?php if ( $customer_id ) :
                $cu = get_userdata( $customer_id );
                if ( $cu ) : ?>
                    <p>
                        <?php
                        printf(
                            /* translators: %s customer display name */
                            esc_html__( 'Showing entries for customer: %s', 'customer-item-aliases' ),
                            '<strong>' . esc_html( $cu->display_name ) . '</strong>'
                        );
                        ?>
                        &mdash;
                        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cia-alias-log' ], $base_url ) ); ?>">
                            <?php esc_html_e( 'Clear filter', 'customer-item-aliases' ); ?>
                        </a>
                    </p>
                <?php endif;
            endif; ?>

            <hr class="wp-header-end">

            <!-- Toolbar: Search | Action Tabs | Purge -->
            <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:16px;">

                <!-- Search form -->
                <form method="get" style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" name="page"         value="cia-alias-log" />
                    <input type="hidden" name="log_action"   value="<?php echo esc_attr( $action_filter ); ?>" />
                    <input type="hidden" name="log_customer" value="<?php echo esc_attr( $customer_id ); ?>" />
                    <input type="search" name="s"
                           value="<?php echo esc_attr( $search ); ?>"
                           placeholder="<?php esc_attr_e( 'Search alias code or EAN…', 'customer-item-aliases' ); ?>"
                           style="min-width:220px;" />
                    <?php submit_button(
                        __( 'Search', 'customer-item-aliases' ),
                        'secondary small',
                        '',
                        false,
                        [ 'style' => 'margin:0;' ]
                    ); ?>
                    <?php if ( $search ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( [
                            'page'       => 'cia-alias-log',
                            'log_action' => $action_filter,
                        ], $base_url ) ); ?>">
                            <?php esc_html_e( 'Clear', 'customer-item-aliases' ); ?>
                        </a>
                    <?php endif; ?>
                </form>

                <!-- Action filter tabs -->
                <div style="display:flex;gap:2px;align-items:center;flex-wrap:wrap;">
                    <?php
                    $tabs = array_merge( [ '' => __( 'All Actions', 'customer-item-aliases' ) ],
                        array_combine(
                            self::ACTIONS,
                            array_map( 'ucfirst', self::ACTIONS )
                        )
                    );
                    foreach ( $tabs as $tab_key => $tab_label ) :
                        $active = $tab_key === $action_filter;
                        $url    = add_query_arg( [
                            'page'         => 'cia-alias-log',
                            's'            => $search,
                            'log_customer' => $customer_id,
                            'log_action'   => $tab_key,
                        ], $base_url );

                        $badge_color = self::ACTIONS !== [] && $tab_key !== ''
                            ? ( CIA_Log_Table::ACTION_COLORS[ $tab_key ]['bg'] ?? '#666' )
                            : '#666';
                        ?>
                        <a href="<?php echo esc_url( $url ); ?>"
                           style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:3px;font-size:12px;font-weight:<?php echo $active ? '700' : '400'; ?>;text-decoration:none;
                                  background:<?php echo $active ? '#f0f0f1' : 'transparent'; ?>;border:1px solid <?php echo $active ? '#c3c4c7' : 'transparent'; ?>;color:#1d2327;">
                            <?php if ( $tab_key !== '' ) : ?>
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $badge_color ); ?>;"></span>
                            <?php endif; ?>
                            <?php echo esc_html( $tab_label ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Purge form — pushed to the right -->
                <form method="post"
                      action="<?php echo esc_url( $base_url ); ?>"
                      style="margin-left:auto;display:flex;gap:6px;align-items:center;"
                      onsubmit="return confirm('<?php echo esc_js(
                          __( 'Permanently delete log entries older than the specified number of days?', 'customer-item-aliases' )
                      ); ?>')">
                    <?php wp_nonce_field( 'cia_purge_log' ); ?>
                    <input type="hidden" name="page"   value="cia-alias-log" />
                    <input type="hidden" name="action" value="purge" />

                    <label for="cia-purge-days" style="font-size:13px;white-space:nowrap;">
                        <?php esc_html_e( 'Purge entries older than', 'customer-item-aliases' ); ?>
                    </label>
                    <input type="number" id="cia-purge-days" name="purge_days"
                           value="90" min="1" max="3650"
                           style="width:65px;" />
                    <label style="font-size:13px;"><?php esc_html_e( 'days', 'customer-item-aliases' ); ?></label>
                    <?php submit_button(
                        __( 'Purge', 'customer-item-aliases' ),
                        'delete small',
                        '',
                        false,
                        [ 'style' => 'margin:0;' ]
                    ); ?>
                </form>

            </div><!-- /toolbar -->

            <!-- List table -->
            <form method="get">
                <input type="hidden" name="page"         value="cia-alias-log" />
                <input type="hidden" name="log_action"   value="<?php echo esc_attr( $action_filter ); ?>" />
                <input type="hidden" name="log_customer" value="<?php echo esc_attr( $customer_id ); ?>" />
                <?php $table->display(); ?>
            </form>

        </div><!-- /wrap -->
        <?php
    }
}
