<?php
/**
 * Report queries.
 *
 * @package Visitrix
 */

defined( 'ABSPATH' ) || exit;

// Custom tables: direct queries are expected here; whole reports are cached in transients.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Builds the dashboard report for a date range.
 *
 * Bounce = visit with a single pageview that lasted less than 15 seconds (Yandex Metrica definition).
 */
class Visitrix_Stats {

	/** Seconds below which a one-page visit is a bounce. */
	const BOUNCE_SECONDS = 15;

	/**
	 * Full report, cached briefly.
	 *
	 * @param string $from Y-m-d (site timezone).
	 * @param string $to   Y-m-d (site timezone).
	 * @return array
	 */
	public static function report( $from, $to ) {
		$key    = 'visitrix_r_' . md5( $from . '|' . $to . '|' . VISITRIX_VERSION );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$tz      = wp_timezone();
		$d_from  = new DateTimeImmutable( $from, $tz );
		$d_to    = new DateTimeImmutable( $to, $tz );
		$days    = (int) $d_from->diff( $d_to )->days + 1;
		$hourly  = $days <= 3;
		$start   = $from . ' 00:00:00';
		$end     = $to . ' 23:59:59';
		$p_start = $d_from->modify( '-' . $days . ' days' )->format( 'Y-m-d' ) . ' 00:00:00';
		$p_end   = $d_from->modify( '-1 day' )->format( 'Y-m-d' ) . ' 23:59:59';

		$report = array(
			'from'      => $from,
			'to'        => $to,
			'hourly'    => $hourly,
			'totals'    => self::totals( $start, $end ),
			'previous'  => self::totals( $p_start, $p_end ),
			'series'    => self::series( $d_from, $d_to, $hourly ),
			'sources'   => self::dimension( 'source_type', $start, $end, 10 ),
			'details'   => self::source_details( $start, $end ),
			'search'    => self::dimension( 'source_name', $start, $end, 10, 'search' ),
			'returning' => self::dimension( 'is_new', $start, $end, 2 ),
			'devices'   => self::dimension( 'device', $start, $end, 5 ),
			'browsers'  => self::dimension( 'browser', $start, $end, 10 ),
			'os'        => self::dimension( 'os', $start, $end, 10 ),
			'landing'   => self::dimension( 'landing', $start, $end, 10 ),
			'pages'     => self::pages( $start, $end ),
			'utm'       => self::utm( $start, $end ),
			'generated' => current_time( 'mysql' ),
		);

		$is_live = $to >= current_time( 'Y-m-d' );
		set_transient( $key, $report, $is_live ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS );

		return $report;
	}

	/**
	 * Key metrics for a range.
	 *
	 * @param string $start Local datetime.
	 * @param string $end   Local datetime.
	 * @return array
	 */
	public static function totals( $start, $end ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS visits,
					COUNT(DISTINCT visitor_key) AS visitors,
					COALESCE(SUM(pageviews), 0) AS pageviews,
					COALESCE(SUM(pageviews <= 1 AND duration < %d), 0) AS bounces,
					COALESCE(AVG(duration), 0) AS duration,
					COUNT(DISTINCT CASE WHEN is_new = 1 THEN visitor_key END) AS new_visitors
				FROM %i WHERE started_at BETWEEN %s AND %s',
				self::BOUNCE_SECONDS,
				Visitrix_DB::visits_table(),
				$start,
				$end
			),
			ARRAY_A
		);

		$visits   = (int) $row['visits'];
		$visitors = (int) $row['visitors'];

		return array(
			'visits'       => $visits,
			'visitors'     => $visitors,
			'pageviews'    => (int) $row['pageviews'],
			'bounce'       => $visits ? round( $row['bounces'] / $visits * 100, 1 ) : 0,
			'depth'        => $visits ? round( $row['pageviews'] / $visits, 2 ) : 0,
			'duration'     => (int) round( $row['duration'] ),
			'new_visitors' => $visitors ? round( $row['new_visitors'] / $visitors * 100, 1 ) : 0,
		);
	}

	/**
	 * Time series bucketed by hour or day, gaps filled with zeros.
	 *
	 * @param DateTimeImmutable $from   Start day.
	 * @param DateTimeImmutable $to     End day.
	 * @param bool              $hourly Hourly buckets.
	 * @return array
	 */
	private static function series( DateTimeImmutable $from, DateTimeImmutable $to, $hourly ) {
		global $wpdb;
		$len  = $hourly ? 13 : 10; // "Y-m-d H" or "Y-m-d" prefix of the DATETIME.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT LEFT(started_at, %d) AS b, COUNT(*) AS v, COUNT(DISTINCT visitor_key) AS u, SUM(pageviews) AS p,
					COUNT(DISTINCT CASE WHEN is_new = 1 THEN visitor_key END) AS n
				FROM %i WHERE started_at BETWEEN %s AND %s GROUP BY b ORDER BY b',
				$len,
				Visitrix_DB::visits_table(),
				$from->format( 'Y-m-d' ) . ' 00:00:00',
				$to->format( 'Y-m-d' ) . ' 23:59:59'
			),
			OBJECT_K
		);

		$out    = array();
		$now    = current_time( $hourly ? 'Y-m-d H' : 'Y-m-d' );
		$step   = $hourly ? '+1 hour' : '+1 day';
		$fmt    = $hourly ? 'Y-m-d H' : 'Y-m-d';
		$cursor = $from->setTime( 0, 0 );
		$last   = $to->setTime( 23, 0 );

		while ( $cursor <= $last ) {
			$b = $cursor->format( $fmt );
			if ( $b > $now ) {
				break;
			}
			$out[]  = array(
				'b' => $b,
				'v' => isset( $rows[ $b ] ) ? (int) $rows[ $b ]->v : 0,
				'u' => isset( $rows[ $b ] ) ? (int) $rows[ $b ]->u : 0,
				'p' => isset( $rows[ $b ] ) ? (int) $rows[ $b ]->p : 0,
				'n' => isset( $rows[ $b ] ) ? (int) $rows[ $b ]->n : 0,
			);
			$cursor = $cursor->modify( $step );
		}
		return $out;
	}

	/**
	 * Breakdown by a visits column with Metrica-style metrics.
	 *
	 * @param string $column One of the whitelisted columns.
	 * @param string $start  Local datetime.
	 * @param string $end    Local datetime.
	 * @param int    $limit  Rows.
	 * @param string $only   Optional source_type filter.
	 * @return array
	 */
	private static function dimension( $column, $start, $end, $limit, $only = '' ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'source_type', 'source_name', 'device', 'browser', 'os', 'landing', 'is_new' ), true ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %i AS name, COUNT(*) AS visits, COUNT(DISTINCT visitor_key) AS visitors,
					SUM(pageviews <= 1 AND duration < %d) / COUNT(*) * 100 AS bounce,
					AVG(pageviews) AS depth, AVG(duration) AS duration
				FROM %i
				WHERE started_at BETWEEN %s AND %s AND ( %s = \'\' OR source_type = %s )
				GROUP BY name ORDER BY visits DESC LIMIT %d',
				$column,
				self::BOUNCE_SECONDS,
				Visitrix_DB::visits_table(),
				$start,
				$end,
				$only,
				$only,
				$limit
			),
			ARRAY_A
		);
		return self::rows( $rows );
	}

	/**
	 * Sources broken down by type + name (sites, engines, networks).
	 *
	 * @param string $start Local datetime.
	 * @param string $end   Local datetime.
	 * @return array
	 */
	private static function source_details( $start, $end ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_type AS type, source_name AS name, COUNT(*) AS visits, COUNT(DISTINCT visitor_key) AS visitors,
					SUM(pageviews <= 1 AND duration < %d) / COUNT(*) * 100 AS bounce,
					AVG(pageviews) AS depth, AVG(duration) AS duration
				FROM %i
				WHERE started_at BETWEEN %s AND %s AND source_type NOT IN (\'direct\', \'internal\')
				GROUP BY source_type, source_name ORDER BY visits DESC LIMIT 20',
				self::BOUNCE_SECONDS,
				Visitrix_DB::visits_table(),
				$start,
				$end
			),
			ARRAY_A
		);
		return self::rows( $rows );
	}

	/**
	 * UTM campaigns.
	 *
	 * @param string $start Local datetime.
	 * @param string $end   Local datetime.
	 * @return array
	 */
	private static function utm( $start, $end ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT CONCAT_WS(\' / \', utm_source, NULLIF(utm_medium, \'\'), NULLIF(utm_campaign, \'\')) AS name,
					COUNT(*) AS visits, COUNT(DISTINCT visitor_key) AS visitors,
					SUM(pageviews <= 1 AND duration < %d) / COUNT(*) * 100 AS bounce,
					AVG(pageviews) AS depth, AVG(duration) AS duration
				FROM %i
				WHERE started_at BETWEEN %s AND %s AND utm_source <> \'\'
				GROUP BY name ORDER BY visits DESC LIMIT 10',
				self::BOUNCE_SECONDS,
				Visitrix_DB::visits_table(),
				$start,
				$end
			),
			ARRAY_A
		);
		return self::rows( $rows );
	}

	/**
	 * Most viewed pages.
	 *
	 * @param string $start Local datetime.
	 * @param string $end   Local datetime.
	 * @return array
	 */
	private static function pages( $start, $end ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT path AS name, MAX(title) AS title, COUNT(*) AS views, COUNT(DISTINCT visit_id) AS visits
				FROM %i WHERE created_at BETWEEN %s AND %s
				GROUP BY path ORDER BY views DESC LIMIT 15',
				Visitrix_DB::hits_table(),
				$start,
				$end
			),
			ARRAY_A
		);
		return array_map(
			static function ( $r ) {
				return array(
					'name'   => (string) $r['name'],
					'title'  => (string) $r['title'],
					'views'  => (int) $r['views'],
					'visits' => (int) $r['visits'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Casts numeric columns.
	 *
	 * @param array|null $rows Rows.
	 * @return array
	 */
	private static function rows( $rows ) {
		$out = array();
		foreach ( (array) $rows as $r ) {
			$item = array(
				'name'     => (string) $r['name'],
				'visits'   => (int) $r['visits'],
				'visitors' => (int) $r['visitors'],
				'bounce'   => round( (float) $r['bounce'], 1 ),
				'depth'    => round( (float) $r['depth'], 2 ),
				'duration' => (int) round( (float) $r['duration'] ),
			);
			if ( isset( $r['type'] ) ) {
				$item['type'] = (string) $r['type'];
			}
			$out[] = $item;
		}
		return $out;
	}
}
