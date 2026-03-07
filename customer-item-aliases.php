<?php
/**
 * Plugin Name:       Customer Item Aliases
 * Plugin URI:        https://milanoleather.ae
 * Description:       Manages customer white-label aliases for WooCommerce products,
 *                    enabling alias-based search via FluxStore REST API and FiboSearch.
 * Version:           1.4.0
 * Author:            Muhammad Asif Mohtesham
 * License:           GPL-2.0+
 * Text Domain:       customer-item-aliases
 */

defined( 'ABSPATH' ) || exit;

// --- Constants ---
define( 'CIA_VERSION',     '1.4.0' );
define( 'CIA_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CIA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'CIA_TABLE_ALIAS', 'customer_item_aliases' );
define( 'CIA_TABLE_LOG',   'cia_alias_log' );
define( 'CIA_TABLE_STATS', 'cia_search_stats' );

/**
 * The WooCommerce product meta key under which EAN / GTIN codes are stored.
 * Filterable so stores using a different meta key can override this centrally.
 *
 * @see apply_filters( 'cia_ean_meta_key', CIA_EAN_META_KEY )
 */
define( 'CIA_EAN_META_KEY', '_global_unique_id' );

// --- Autoload Includes ---
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-log.php';          // load first; DB class calls CIA_Log
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-db.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-table.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-admin.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-hooks.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-log-table.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-log-admin.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-rest-api.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-search-stats.php';
require_once CIA_PLUGIN_DIR . 'includes/class-aliases-order-notes.php';

// --- Activation ---
register_activation_hook( __FILE__, function () {
    CIA_DB::create_table();
    CIA_Log::create_table();
    CIA_Search_Stats::create_table();
} );

// --- Bootstrap ---
add_action( 'plugins_loaded', function () {
    CIA_DB::maybe_upgrade();
    CIA_Log::maybe_upgrade();
    CIA_Search_Stats::maybe_upgrade();

    CIA_Admin::init();
    CIA_Log_Admin::init();
    CIA_Hooks::init();
    CIA_REST_API::init();
    CIA_Search_Stats::init();
    CIA_Order_Notes::init();
} );
