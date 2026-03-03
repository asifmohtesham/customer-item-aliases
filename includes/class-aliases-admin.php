<?php
defined( 'ABSPATH' ) || exit;

class CIA_Admin {

    public static function init(): void {
        add_action( 'admin_menu',  [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',  [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
    }

    public static function register_menu(): void {
        add_menu_page(
            __( 'Customer Item Aliases', 'customer-item-aliases' ),
            __( 'Item Aliases',          'customer-item-aliases' ),
            'manage_woocommerce',
            'cia-aliases',
            [ __CLASS__, 'render_list_page' ],
            'dashicons-tag',
            58
        );
    }

    // --- Action Handler ---

    public static function handle_actions(): void {
        $page   = sanitize_key( $_REQUEST['page']   ?? '' );
        $action = sanitize_key( $_REQUEST['action']  ?? '' );

        if ( $page !== 'cia-aliases' ) return;
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        // --- Bulk Delete ---
        if ( $action === 'bulk-delete' && ! empty( $_POST['alias_ids'] ) ) {
            check_admin_referer( 'bulk-aliases' );
            CIA_DB::delete( array_map( 'absint', $_POST['alias_ids'] ) );
            self::redirect( 'bulk_deleted' );
        }

        // --- Single Delete ---
        if ( $action === 'delete' && ! empty( $_GET['id'] ) ) {
            $id = absint( $_GET['id'] );
            check_admin_referer( 'cia_delete_' . $id );
            CIA_DB::delete( $id );
            self::redirect( 'deleted' );
        }

        // --- Save (Insert or Update) ---
        if ( $action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            check_admin_referer( 'cia_save_alias' );
        
            $id   = absint( $_POST['id'] ?? 0 );
            $data = [
                'user_id'    => absint( $_POST['user_id'] ),
                'alias_code' => sanitize_text_field( $_POST['alias_code'] ),
                'ean8_code'  => sanitize_text_field( $_POST['ean8_code'] ),
            ];
        
            // ✅ Guard: user must be selected
            if ( ! $data['user_id'] ) {
                self::redirect( 'no_user' );
            }
        
            // Validate EAN8
            if ( ! preg_match( '/^\d{8}$/', $data['ean8_code'] ) ) {
                self::redirect( 'invalid_ean8' );
            }
        
            $id ? CIA_DB::update( $id, $data ) : CIA_DB::insert( $data );
            self::redirect( 'saved' );
        }
    }

    // --- Page Renderers ---

    public static function render_list_page(): void {
        $action = sanitize_key( $_GET['action'] ?? '' );

        if ( in_array( $action, [ 'add', 'edit' ], true ) ) {
            self::render_form_page( $action );
            return;
        }

        $table = new CIA_List_Table();
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php esc_html_e( 'Customer Item Aliases', 'customer-item-aliases' ); ?>
            </h1>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cia-aliases', 'action' => 'add' ], admin_url( 'admin.php' ) ) ); ?>"
               class="page-title-action">
                <?php esc_html_e( 'Add New', 'customer-item-aliases' ); ?>
            </a>
            <hr class="wp-header-end">
            <form method="get">
                <input type="hidden" name="page" value="cia-aliases" />
                <?php
                    $table->search_box( __( 'Search Aliases', 'customer-item-aliases' ), 'alias' );
                    $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    private static function render_form_page( string $action ): void {
        $id   = absint( $_GET['id'] ?? 0 );
        $item = ( $action === 'edit' && $id ) ? CIA_DB::get_row( $id ) : null;
        $list_url = add_query_arg( [ 'page' => 'cia-aliases' ], admin_url( 'admin.php' ) );
    
        // Enqueue Select2 (bundled in WP Admin)
        wp_enqueue_style( 'select2' );
        wp_enqueue_script( 'select2' );
        ?>
        <div class="wrap">
            <h1><?php echo $action === 'edit'
                ? esc_html__( 'Edit Alias',    'customer-item-aliases' )
                : esc_html__( 'Add New Alias', 'customer-item-aliases' );
            ?></h1>
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">
                &larr; <?php esc_html_e( 'Back to List', 'customer-item-aliases' ); ?>
            </a>
            <hr class="wp-header-end">
    
            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                <?php wp_nonce_field( 'cia_save_alias' ); ?>
                <input type="hidden" name="page"   value="cia-aliases" />
                <input type="hidden" name="action" value="save" />
                <input type="hidden" name="id"     value="<?php echo absint( $item['id'] ?? 0 ); ?>" />
    
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="user_id">
                            <?php esc_html_e( 'Customer', 'customer-item-aliases' ); ?>
                        </label></th>
                        <td>
                            <?php
                            // Fetch only users with the WooCommerce 'customer' role
                            $customers = get_users( [
                                // 'role'    => 'customer',
                                'role__in'    => [ 'customer', 'subscriber' ],
                                'orderby' => 'display_name',
                                'order'   => 'ASC',
                                'fields'  => [ 'ID', 'display_name', 'user_email' ],
                            ] );
                    
                            if ( empty( $customers ) ) :
                            ?>
                                <!--
                                    No customers yet — render a disabled placeholder input
                                    and a subtle inline notice. Form submission is blocked via 'disabled'.
                                -->
                                <input type="text"
                                       class="regular-text"
                                       value="<?php esc_attr_e( 'No customers found', 'customer-item-aliases' ); ?>"
                                       disabled />
                                <p class="description" style="color:#b32d2e;">
                                    <?php
                                    printf(
                                        /* translators: %s: URL to the Users admin screen */
                                        wp_kses(
                                            __( 'No customers exist yet. <a href="%s">Create a customer account</a> first, then return here to add an alias.', 'customer-item-aliases' ),
                                            [ 'a' => [ 'href' => [] ] ]
                                        ),
                                        esc_url( admin_url( 'user-new.php' ) )
                                    );
                                    ?>
                                </p>
                                <!--
                                    Hidden sentinel: triggers server-side 'no_user' guard
                                    so the form cannot be accidentally submitted even if JS is bypassed
                                -->
                                <input type="hidden" name="user_id" value="0" />
                    
                            <?php else : ?>
                    
                                <select name="user_id" id="user_id" class="regular-text" required>
                                    <option value="0"><?php esc_html_e( '— Select Customer —', 'customer-item-aliases' ); ?></option>
                                    <?php foreach ( $customers as $customer ) : ?>
                                        <option value="<?php echo absint( $customer->ID ); ?>"
                                            <?php selected( $item['user_id'] ?? 0, $customer->ID ); ?>>
                                            <?php echo esc_html(
                                                sprintf( '%s (%s)', $customer->display_name, $customer->user_email )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                    
                                <?php
                                // When editing, show the currently assigned customer below the dropdown
                                if ( ! empty( $item['user_id'] ) ) {
                                    $assigned = get_userdata( $item['user_id'] );
                                    if ( $assigned ) {
                                        printf(
                                            '<p class="description">%s</p>',
                                            esc_html( sprintf(
                                                __( 'Currently assigned: %s — ID %d', 'customer-item-aliases' ),
                                                $assigned->display_name,
                                                $assigned->ID
                                            ) )
                                        );
                                    }
                                }
                                ?>
                    
                            <?php endif; ?>
                        </td>
                    </tr>
    
                    <tr>
                        <th><label for="alias_code">
                            <?php esc_html_e( 'Alias Code', 'customer-item-aliases' ); ?>
                        </label></th>
                        <td>
                            <input type="text" name="alias_code" id="alias_code"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $item['alias_code'] ?? '' ); ?>"
                                   placeholder="e.g. 55012345" required />
                            <p class="description">
                                <?php esc_html_e( 'Customer-provided item identifier.', 'customer-item-aliases' ); ?>
                            </p>
                        </td>
                    </tr>
    
                    <tr>
                        <th><label for="ean8_code">
                            <?php esc_html_e( 'EAN8 Code', 'customer-item-aliases' ); ?>
                        </label></th>
                        <td>
                            <input type="text" name="ean8_code" id="ean8_code"
                                   class="regular-text"
                                   value="<?php echo esc_attr( $item['ean8_code'] ?? '' ); ?>"
                                   placeholder="e.g. 10000014"
                                   maxlength="8" pattern="\d{8}" required />
                            <p class="description">
                                <?php esc_html_e( 'Must be exactly 8 digits. Maps to the WooCommerce product SKU.', 'customer-item-aliases' ); ?>
                            </p>
                        </td>
                    </tr>
    
                </table>
    
                <?php submit_button( $action === 'edit'
                    ? __( 'Update Alias', 'customer-item-aliases' )
                    : __( 'Add Alias',    'customer-item-aliases' )
                ); ?>
            </form>
        </div>
        <?php
    }

    // --- Admin Notices ---

    public static function admin_notices(): void {
        $messages = [
            'saved'        => [ 'success', __( 'Alias saved successfully.',       'customer-item-aliases' ) ],
            'deleted'      => [ 'success', __( 'Alias deleted.',                  'customer-item-aliases' ) ],
            'bulk_deleted' => [ 'success', __( 'Selected aliases deleted.',       'customer-item-aliases' ) ],
            'invalid_ean8' => [ 'error',   __( 'EAN8 must be exactly 8 digits.',  'customer-item-aliases' ) ],
            'no_user'      => [ 'error',   __( 'Please select a customer.',       'customer-item-aliases' ) ], // ✅ New
        ];

        $msg_key = sanitize_key( $_GET['cia_msg'] ?? '' );
        if ( isset( $messages[ $msg_key ] ) ) {
            [ $type, $text ] = $messages[ $msg_key ];
            printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
        }
    }

    // --- Helper ---

    private static function redirect( string $msg ): void {
        wp_safe_redirect( add_query_arg( [
            'page'    => 'cia-aliases',
            'cia_msg' => $msg,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
