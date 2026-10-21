<?php
/**
 * Plugin settings.
 *
 * @package Visitrix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the plugin options.
 */
class Visitrix_Settings {

	const OPTION = 'visitrix_settings';

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'             => 1,
			'exclude_roles'       => array( 'administrator' ),
			'respect_dnt'         => 0,
			'retention_days'      => 365,
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$roles = array_keys( wp_roles()->get_names() );

		$exclude = isset( $input['exclude_roles'] ) && is_array( $input['exclude_roles'] )
			? array_values( array_intersect( array_map( 'sanitize_key', $input['exclude_roles'] ), $roles ) )
			: array();

		$days = isset( $input['retention_days'] ) ? absint( $input['retention_days'] ) : 365;

		return array(
			'enabled'             => empty( $input['enabled'] ) ? 0 : 1,
			'exclude_roles'       => $exclude,
			'respect_dnt'         => empty( $input['respect_dnt'] ) ? 0 : 1,
			'retention_days'      => min( 3650, max( 30, $days ) ),
			'delete_on_uninstall' => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
		);
	}

	/**
	 * Capability required to view statistics.
	 *
	 * @return string
	 */
	public static function view_capability() {
		/**
		 * Filters the capability required to view Visitrix statistics.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return (string) apply_filters( 'visitrix_view_capability', 'manage_options' );
	}
}
