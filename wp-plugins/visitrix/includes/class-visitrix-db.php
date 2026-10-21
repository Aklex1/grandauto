<?php
/**
 * Database schema, upgrades and retention cleanup.
 *
 * @package Visitrix
 */

defined( 'ABSPATH' ) || exit;

// Custom tables: direct queries are expected here, results are not cacheable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Database layer.
 */
class Visitrix_DB {

	/**
	 * Visits (sessions) table name.
	 *
	 * @return string
	 */
	public static function visits_table() {
		global $wpdb;
		return $wpdb->prefix . 'visitrix_visits';
	}

	/**
	 * Pageviews table name.
	 *
	 * @return string
	 */
	public static function hits_table() {
		global $wpdb;
		return $wpdb->prefix . 'visitrix_hits';
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::install();
		add_option( Visitrix_Settings::OPTION, Visitrix_Settings::defaults() );
		if ( ! wp_next_scheduled( 'visitrix_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'visitrix_daily_cleanup' );
		}
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'visitrix_daily_cleanup' );
	}

	/**
	 * Runs the installer when the schema version changes (e.g. after an update).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'visitrix_db_version' ) !== VISITRIX_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates or updates the tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$visits  = self::visits_table();
		$hits    = self::hits_table();

		$sql = "CREATE TABLE {$visits} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  visit_key char(32) NOT NULL,
  visitor_key char(32) NOT NULL,
  started_at datetime NOT NULL,
  ts_start int(10) unsigned NOT NULL,
  ts_last int(10) unsigned NOT NULL,
  duration int(10) unsigned NOT NULL DEFAULT 0,
  pageviews smallint(5) unsigned NOT NULL DEFAULT 1,
  is_new tinyint(1) unsigned NOT NULL DEFAULT 0,
  source_type varchar(16) NOT NULL DEFAULT 'direct',
  source_name varchar(100) NOT NULL DEFAULT '',
  referrer varchar(255) NOT NULL DEFAULT '',
  landing varchar(255) NOT NULL DEFAULT '',
  utm_source varchar(100) NOT NULL DEFAULT '',
  utm_medium varchar(100) NOT NULL DEFAULT '',
  utm_campaign varchar(100) NOT NULL DEFAULT '',
  device varchar(10) NOT NULL DEFAULT 'desktop',
  browser varchar(32) NOT NULL DEFAULT '',
  os varchar(32) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY visit_key (visit_key),
  KEY started_at (started_at)
) {$charset};
CREATE TABLE {$hits} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  visit_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  path varchar(255) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY visit_id (visit_id)
) {$charset};";

		dbDelta( $sql );
		update_option( 'visitrix_db_version', VISITRIX_DB_VERSION, false );
	}

	/**
	 * Deletes data older than the retention period (daily cron).
	 */
	public static function cleanup() {
		global $wpdb;
		$settings = Visitrix_Settings::get();
		$cutoff   = wp_date( 'Y-m-d H:i:s', time() - (int) $settings['retention_days'] * DAY_IN_SECONDS );

		// Batched deletes keep locks short on large tables.
		for ( $i = 0; $i < 50; $i++ ) {
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 5000', self::hits_table(), $cutoff ) );
			if ( ! $deleted ) {
				break;
			}
		}
		for ( $i = 0; $i < 50; $i++ ) {
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE started_at < %s LIMIT 5000', self::visits_table(), $cutoff ) );
			if ( ! $deleted ) {
				break;
			}
		}
	}

	/**
	 * Removes all collected statistics.
	 */
	public static function truncate() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::hits_table() ) );
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::visits_table() ) );
	}

	/**
	 * Drops the plugin tables (used on uninstall).
	 */
	public static function drop() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::hits_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::visits_table() ) );
	}
}
