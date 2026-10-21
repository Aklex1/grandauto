<?php
/**
 * REST routes.
 *
 * @package Visitrix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers /visitrix/v1/collect (public) and /visitrix/v1/stats (admin).
 */
class Visitrix_Rest {

	/** Maximum report range, days. */
	const MAX_DAYS = 731;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Route registration.
	 */
	public static function routes() {
		register_rest_route(
			'visitrix/v1',
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'Visitrix_Tracker', 'collect' ),
				// Public endpoint by design: anonymous visitors send beacons (works with page caching).
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'visitrix/v1',
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'stats' ),
				'permission_callback' => static function () {
					return current_user_can( Visitrix_Settings::view_capability() );
				},
				'args'                => array(
					'from' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( __CLASS__, 'valid_date' ),
					),
					'to'   => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( __CLASS__, 'valid_date' ),
					),
				),
			)
		);
	}

	/**
	 * Y-m-d validator.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function valid_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Stats callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function stats( WP_REST_Request $request ) {
		$from = $request->get_param( 'from' );
		$to   = $request->get_param( 'to' );

		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}

		$tz   = wp_timezone();
		$days = ( new DateTimeImmutable( $from, $tz ) )->diff( new DateTimeImmutable( $to, $tz ) )->days;
		if ( $days > self::MAX_DAYS ) {
			return new WP_Error( 'visitrix_range', __( 'The selected range is too long.', 'visitrix' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( Visitrix_Stats::report( $from, $to ) );
	}
}
