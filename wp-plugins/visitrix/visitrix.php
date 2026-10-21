<?php
/**
 * Plugin Name:       Visitrix – Lightweight Visitor Analytics
 * Plugin URI:        https://wordpress.org/plugins/visitrix/
 * Description:       Lightweight, privacy-friendly visitor statistics: traffic sources, search engines, devices, browsers, time on site and bounce rate. All data stays in your database.
 * Version:           1.1.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Visitrix Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       visitrix
 * Domain Path:       /languages
 *
 * @package Visitrix
 */

defined( 'ABSPATH' ) || exit;

define( 'VISITRIX_VERSION', '1.1.1' );
define( 'VISITRIX_DB_VERSION', '1' );
define( 'VISITRIX_FILE', __FILE__ );
define( 'VISITRIX_DIR', plugin_dir_path( __FILE__ ) );
define( 'VISITRIX_URL', plugin_dir_url( __FILE__ ) );

require_once VISITRIX_DIR . 'includes/class-visitrix-settings.php';
require_once VISITRIX_DIR . 'includes/class-visitrix-db.php';
require_once VISITRIX_DIR . 'includes/class-visitrix-ua.php';
require_once VISITRIX_DIR . 'includes/class-visitrix-sources.php';
require_once VISITRIX_DIR . 'includes/class-visitrix-tracker.php';
require_once VISITRIX_DIR . 'includes/class-visitrix-stats.php';
require_once VISITRIX_DIR . 'includes/class-visitrix-rest.php';

if ( is_admin() ) {
	require_once VISITRIX_DIR . 'includes/class-visitrix-admin.php';
}

register_activation_hook( __FILE__, array( 'Visitrix_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Visitrix_DB', 'deactivate' ) );

/**
 * Boots the plugin.
 */
function visitrix_init() {
	Visitrix_DB::maybe_upgrade();
	Visitrix_Tracker::init();
	Visitrix_Rest::init();

	if ( is_admin() ) {
		Visitrix_Admin::init();
	}
}
add_action( 'plugins_loaded', 'visitrix_init' );

/**
 * Loads bundled translations (translate.wordpress.org packs take priority).
 */
function visitrix_load_textdomain() {
	load_plugin_textdomain( 'visitrix', false, dirname( plugin_basename( VISITRIX_FILE ) ) . '/languages' );
}
add_action( 'init', 'visitrix_load_textdomain', 1 );

add_action( 'visitrix_daily_cleanup', array( 'Visitrix_DB', 'cleanup' ) );
