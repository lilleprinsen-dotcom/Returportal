<?php
/**
 * Plugin Name:       LP Cargonizer Return Portal
 * Plugin URI:        https://lilleprinsen.no/
 * Description:       Return portal for WooCommerce using Cargonizer with free-shipping bonus handling, return reasons, secure label regeneration, return logs, and performance optimizations.
 * Version:           2.5.0
 * Author:            Lilleprinsen / ChatGPT
 * Author URI:        https://lilleprinsen.no/
 * Text Domain:       lp-cargo
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'LP_CARGO_RETURN_PLUGIN_FILE' ) ) {
    define( 'LP_CARGO_RETURN_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'LP_CARGO_RETURN_PLUGIN_DIR' ) ) {
    define( 'LP_CARGO_RETURN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'LP_CARGO_RETURN_VERSION' ) ) {
    define( 'LP_CARGO_RETURN_VERSION', '2.5.0' );
}

require_once LP_CARGO_RETURN_PLUGIN_DIR . 'includes/class-lp-cargonizer-api-client.php';
require_once LP_CARGO_RETURN_PLUGIN_DIR . 'includes/class-lp-cargonizer-returns.php';
require_once LP_CARGO_RETURN_PLUGIN_DIR . 'includes/class-lp-cargonizer-settings.php';

/**
 * Handle plugin activation tasks such as setting up cron events and database tables.
 */
function lp_cargo_return_portal_activate() {
    LP_Cargonizer_Returns::activate();
}
register_activation_hook( __FILE__, 'lp_cargo_return_portal_activate' );

/**
 * Clean up scheduled events on deactivation.
 */
function lp_cargo_return_portal_deactivate() {
    LP_Cargonizer_Returns::deactivate();
}
register_deactivation_hook( __FILE__, 'lp_cargo_return_portal_deactivate' );

/**
 * Initialize the plugin once all other plugins are loaded.
 */
function lp_cargo_return_portal_run() {
    if ( class_exists( 'WooCommerce' ) ) {
        LP_Cargonizer_Returns::instance();
    } else {
        add_action( 'admin_notices', 'lp_cargo_return_portal_wc_notice' );
    }
}
add_action( 'plugins_loaded', 'lp_cargo_return_portal_run' );

/**
 * Display an admin notice when WooCommerce is not active.
 */
function lp_cargo_return_portal_wc_notice() {
    if ( current_user_can( 'activate_plugins' ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'LP Cargonizer Return Portal requires WooCommerce to be installed and active.', 'lp-cargo' ) . '</p></div>';
    }
}
