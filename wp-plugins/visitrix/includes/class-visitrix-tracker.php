<?php
/**
 * Front-end tracker: enqueues the beacon script and stores incoming hits.
 *
 * @package Visitrix
 */

defined( 'ABSPATH' ) || exit;

// Custom tables: direct queries are expected here, results are not cacheable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Collects pageviews and engagement pings.
 */
class Visitrix_Tracker {

	/** A visit ends after 30 minutes of inactivity (same as Yandex Metrica). */
	const VISIT_TIMEOUT = 1800;

	/** Upper bound for a single visit duration, seconds. */
	const MAX_DURATION = 14400;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether the current request should be tracked.
	 *
	 * @return bool
	 */
	private static function should_track() {
		$settings = Visitrix_Settings::get();

		if ( empty( $settings['enabled'] ) || is_preview() || is_customize_preview() || is_robots() || is_feed() ) {
			return false;
		}
		if ( is_user_logged_in() && ! empty( $settings['exclude_roles'] ) ) {
			$user = wp_get_current_user();
			if ( array_intersect( (array) $user->roles, (array) $settings['exclude_roles'] ) ) {
				return false;
			}
		}

		/**
		 * Filters whether the current page view is tracked.
		 *
		 * @param bool $track Whether to output the tracker.
		 */
		return (bool) apply_filters( 'visitrix_should_track', true );
	}

	/**
	 * Enqueues the ~2 KB tracker script.
	 */
	public static function enqueue() {
		if ( ! self::should_track() ) {
			return;
		}
		$settings = Visitrix_Settings::get();

		wp_enqueue_script(
			'visitrix-tracker',
			VISITRIX_URL . 'assets/js/tracker.js',
			array(),
			VISITRIX_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			'visitrix-tracker',
			'var visitrixCfg = ' . wp_json_encode(
				array(
					'endpoint' => esc_url_raw( rest_url( 'visitrix/v1/collect' ) ),
					'dnt'      => (int) $settings['respect_dnt'],
				)
			) . ';',
			'before'
		);
	}

	/**
	 * REST callback: stores a pageview or a ping. Always answers 204.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function collect( WP_REST_Request $request ) {
		$done     = new WP_REST_Response( null, 204 );
		$settings = Visitrix_Settings::get();

		if ( empty( $settings['enabled'] ) || ! self::origin_ok( $request ) ) {
			return $done;
		}

		// The browser signal is honoured on the server too: the script checks
		// it before sending, but a beacon can reach this route on its own.
		if ( ! empty( $settings['respect_dnt'] ) && self::opted_out( $request ) ) {
			return $done;
		}

		$ua = substr( (string) $request->get_header( 'user_agent' ), 0, 512 );
		if ( Visitrix_UA::is_bot( $ua ) ) {
			return $done;
		}

		$body = $request->get_body();
		if ( strlen( $body ) > 4096 ) {
			return $done;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return $done;
		}

		$visit_key = self::key( $data, 's' );
		if ( '' === $visit_key ) {
			return $done;
		}

		$type = isset( $data['t'] ) ? $data['t'] : '';
		if ( 'ping' === $type ) {
			self::ping( $visit_key );
		} elseif ( 'pv' === $type ) {
			self::pageview( $visit_key, $data, $ua );
		}

		return $done;
	}

	/**
	 * Whether the visitor asked not to be tracked (Do Not Track / Global Privacy Control).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function opted_out( WP_REST_Request $request ) {
		return '1' === (string) $request->get_header( 'dnt' )
			|| '1' === (string) $request->get_header( 'sec_gpc' );
	}

	/**
	 * Rejects cross-site submissions when the browser sends an Origin header.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function origin_ok( WP_REST_Request $request ) {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			return true;
		}
		$host    = Visitrix_Sources::host( $origin );
		$allowed = array( Visitrix_Sources::host( home_url() ), Visitrix_Sources::host( site_url() ) );
		return in_array( $host, $allowed, true );
	}

	/**
	 * Validated 32-char hex id from the payload.
	 *
	 * @param array  $data  Payload.
	 * @param string $field Field name.
	 * @return string
	 */
	private static function key( $data, $field ) {
		return ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) && preg_match( '/^[a-f0-9]{32}$/', $data[ $field ] ) ) ? $data[ $field ] : '';
	}

	/**
	 * Extends visit duration while the visitor is still on the page.
	 *
	 * @param string $visit_key Visit id.
	 */
	private static function ping( $visit_key ) {
		global $wpdb;
		$now = time();
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET ts_last = %d, duration = LEAST(GREATEST(CAST(%d AS SIGNED) - CAST(ts_start AS SIGNED), 0), %d) WHERE visit_key = %s AND ts_last >= %d',
				Visitrix_DB::visits_table(),
				$now,
				$now,
				self::MAX_DURATION,
				$visit_key,
				$now - self::VISIT_TIMEOUT
			)
		);
	}

	/**
	 * Records a pageview, creating the visit on its first hit.
	 *
	 * @param string $visit_key Visit id.
	 * @param array  $data      Payload.
	 * @param string $ua        User agent.
	 */
	private static function pageview( $visit_key, $data, $ua ) {
		global $wpdb;
		$visits = Visitrix_DB::visits_table();
		$now    = time();
		$local  = current_time( 'mysql' );
		$path   = self::clean_path( isset( $data['u'] ) ? $data['u'] : '' );
		$title  = isset( $data['ti'] ) && is_string( $data['ti'] ) ? mb_substr( sanitize_text_field( $data['ti'] ), 0, 255 ) : '';

		$visit_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE visit_key = %s', $visits, $visit_key ) );

		if ( $visit_id ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET pageviews = LEAST(pageviews + 1, 65000), ts_last = %d, duration = LEAST(GREATEST(CAST(%d AS SIGNED) - CAST(ts_start AS SIGNED), 0), %d) WHERE id = %d',
					$visits,
					$now,
					$now,
					self::MAX_DURATION,
					$visit_id
				)
			);
		} else {
			$visitor_key = self::key( $data, 'v' );
			if ( '' === $visitor_key ) {
				$visitor_key = md5( $visit_key );
			}

			$referrer = isset( $data['r'] ) && is_string( $data['r'] ) ? esc_url_raw( substr( $data['r'], 0, 1024 ) ) : '';
			$params   = array();
			if ( isset( $data['q'] ) && is_string( $data['q'] ) ) {
				wp_parse_str( ltrim( substr( $data['q'], 0, 1024 ), '?' ), $params );
			}
			$utm = array();
			foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $tag ) {
				$utm[ $tag ] = isset( $params[ $tag ] ) && is_string( $params[ $tag ] ) ? mb_substr( sanitize_text_field( $params[ $tag ] ), 0, 100 ) : '';
			}

			$source = Visitrix_Sources::classify( $referrer, $params );
			$client = Visitrix_UA::parse( $ua, isset( $data['tp'] ) ? absint( $data['tp'] ) : 0 );

			// INSERT IGNORE: two parallel first hits of the same visit must not fail.
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (visit_key, visitor_key, started_at, ts_start, ts_last, duration, pageviews, is_new, source_type, source_name, referrer, landing, utm_source, utm_medium, utm_campaign, device, browser, os)
					VALUES (%s, %s, %s, %d, %d, 0, 1, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
					$visits,
					$visit_key,
					$visitor_key,
					$local,
					$now,
					$now,
					empty( $data['nv'] ) ? 0 : 1,
					$source['type'],
					mb_substr( $source['name'], 0, 100 ),
					mb_substr( $referrer, 0, 255 ),
					$path,
					$utm['utm_source'],
					$utm['utm_medium'],
					$utm['utm_campaign'],
					$client['device'],
					$client['browser'],
					$client['os']
				)
			);
			$visit_id = (int) $wpdb->insert_id;
			if ( ! $visit_id ) {
				$visit_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE visit_key = %s', $visits, $visit_key ) );
			}
			if ( ! $visit_id ) {
				return;
			}
		}

		$wpdb->insert(
			Visitrix_DB::hits_table(),
			array(
				'visit_id'   => $visit_id,
				'created_at' => $local,
				'path'       => $path,
				'title'      => $title,
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Path part of a URL, max 255 chars.
	 *
	 * @param mixed $url Raw value.
	 * @return string
	 */
	private static function clean_path( $url ) {
		if ( ! is_string( $url ) ) {
			return '/';
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		// Control characters and spaces go; everything else outside plain ASCII
		// is percent-encoded rather than dropped. Dropping it merged every
		// Cyrillic address into one row «//», and the admin screen decodes
		// percent-encoding back for display anyway.
		$path = preg_replace( '/[\x00-\x20\x7F]/', '', $path );
		if ( preg_match( '/[^\x21-\x7E]/', $path ) ) {
			$path = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
		}
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		return substr( $path, 0, 255 );
	}
}
