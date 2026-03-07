<?php
/**
 * Plugin Name:       Customer Item Aliases
 * Plugin URI:        https://milanoleather.ae
 * Description:       Manages customer white-label aliases for WooCommerce products,
 *                    enabling alias-based search via FluxStore REST API and FiboSearch.
 * Version:           1.2.0
 * Author:            Muhammad Asif Mohtesham
 * License:           GPL-2.0+
 * Text Domain:       customer-item-aliases
 */

defined( 'ABSPATH' ) || exit;

// --- Constants ---
define( 'CIA_VERSION',     '1.2.0' );
define( 'CIA_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CIA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'CIA_TABLE_ALIAS', 'customer_item_aliases' );
define( 'CIA_TABLE_LOG',   'cia_alias_log' );

// --- Autoload Includes ---
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-log.php';       // load first; DB class calls CIA_Log
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-db.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-table.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-admin.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-hooks.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-log-table.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-log-admin.php';

// --- Activation ---
register_activation_hook( __FILE__, function () {
    CIA_DB::create_table();
    CIA_Log::create_table();
} );

// --- Bootstrap ---
add_action( 'plugins_loaded', function () {
    CIA_DB::maybe_upgrade();
    CIA_Log::maybe_upgrade();

    CIA_Admin::init();
    CIA_Log_Admin::init();
    CIA_Hooks::init();
} );
