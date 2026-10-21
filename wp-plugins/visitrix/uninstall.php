<?php
/**
 * Uninstall: removes data only if the user opted in.
 *
 * @package Visitrix
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$visitrix_settings = get_option( 'visitrix_settings', array() );

wp_clear_scheduled_hook( 'visitrix_daily_cleanup' );

if ( is_array( $visitrix_settings ) && ! empty( $visitrix_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'visitrix_hits' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'visitrix_visits' ) );
	// phpcs:enable
	delete_option( 'visitrix_settings' );
	delete_option( 'visitrix_db_version' );
}
