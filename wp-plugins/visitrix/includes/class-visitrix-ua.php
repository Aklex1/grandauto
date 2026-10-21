<?php
/**
 * Minimal user-agent parser: device type, browser and OS.
 *
 * @package Visitrix
 */

defined( 'ABSPATH' ) || exit;

/**
 * User agent helpers.
 */
class Visitrix_UA {

	/**
	 * Whether the user agent looks like a bot or tool.
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	public static function is_bot( $ua ) {
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot\b|bot\/|crawl|spider|slurp|mediapartners|facebookexternalhit|headless|lighthouse|pagespeed|phantomjs|python|curl\/|wget|httpclient|java\//i', $ua );
	}

	/**
	 * Parses the user agent.
	 *
	 * @param string $ua    User agent.
	 * @param int    $touch navigator.maxTouchPoints reported by the browser.
	 * @return array{device:string,browser:string,os:string}
	 */
	public static function parse( $ua, $touch = 0 ) {
		return array(
			'device'  => self::device( $ua, $touch ),
			'browser' => self::match(
				$ua,
				array(
					'/YaBrowser|YaSearchBrowser|YandexSearch/' => 'Yandex Browser',
					'/SamsungBrowser/'                         => 'Samsung Internet',
					'/MiuiBrowser/'                            => 'MIUI Browser',
					'/UCBrowser/'                              => 'UC Browser',
					'/OPR\/|OPiOS|Opera/'                      => 'Opera',
					'/Edg(e|A|iOS)?\//'                        => 'Edge',
					'/Vivaldi/'                                => 'Vivaldi',
					'/Atom\//'                                 => 'Atom',
					'/FxiOS|Firefox\//'                        => 'Firefox',
					'/CriOS|Chrome\/|Chromium/'                => 'Chrome',
					'/MSIE |Trident\//'                        => 'Internet Explorer',
					'/Safari\//'                               => 'Safari',
				)
			),
			'os'      => self::os( $ua, $touch ),
		);
	}

	/**
	 * Device type.
	 *
	 * @param string $ua    User agent.
	 * @param int    $touch Touch points.
	 * @return string desktop|mobile|tablet
	 */
	private static function device( $ua, $touch ) {
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|Kindle|SM-T\d/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Android/i', $ua ) && ! preg_match( '/Mobile/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Mobi|iPhone|iPod|Android|Windows Phone|IEMobile|Opera Mini|BlackBerry/i', $ua ) ) {
			return 'mobile';
		}
		// iPadOS 13+ pretends to be a Mac.
		if ( $touch > 1 && false !== strpos( $ua, 'Macintosh' ) ) {
			return 'tablet';
		}
		return 'desktop';
	}

	/**
	 * Operating system.
	 *
	 * @param string $ua    User agent.
	 * @param int    $touch Touch points.
	 * @return string
	 */
	private static function os( $ua, $touch ) {
		if ( $touch > 1 && false !== strpos( $ua, 'Macintosh' ) ) {
			return 'iOS';
		}
		return self::match(
			$ua,
			array(
				'/Windows Phone/'      => 'Windows Phone',
				'/Windows/'            => 'Windows',
				'/HarmonyOS/'          => 'HarmonyOS',
				'/Android/'            => 'Android',
				'/iPhone|iPad|iPod/'   => 'iOS',
				'/CrOS/'               => 'ChromeOS',
				'/Mac OS X|Macintosh/' => 'macOS',
				'/Linux|X11/'          => 'Linux',
			)
		);
	}

	/**
	 * First matching pattern wins.
	 *
	 * @param string $ua  User agent.
	 * @param array  $map Pattern => name.
	 * @return string
	 */
	private static function match( $ua, $map ) {
		foreach ( $map as $pattern => $name ) {
			if ( preg_match( $pattern, $ua ) ) {
				return $name;
			}
		}
		return 'Other';
	}
}
