<?php
/**
 * Traffic source classification (referrer + UTM tags).
 *
 * @package Visitrix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classifies a visit into a source type, Yandex Metrica style:
 * direct, search, social, messenger, ai, referral, ad, internal.
 */
class Visitrix_Sources {

	/**
	 * Host pattern => name, per source type. Order matters: AI before search.
	 *
	 * @return array
	 */
	private static function rules() {
		$rules = array(
			'ai'        => array(
				'/(^|\.)(chatgpt\.com|chat\.openai\.com)$/' => 'ChatGPT',
				'/(^|\.)perplexity\.ai$/'                   => 'Perplexity',
				'/(^|\.)claude\.ai$/'                       => 'Claude',
				'/^gemini\.google\.com$/'                   => 'Gemini',
				'/^copilot\.microsoft\.com$/'               => 'Copilot',
				'/(^|\.)deepseek\.com$/'                    => 'DeepSeek',
				'/^alice\.yandex\.ru$|^alice\.ya\.ru$/'     => 'Alice',
			),
			'search'    => array(
				'/(^|\.)google\.[a-z.]{2,6}$/'      => 'Google',
				'/(^|\.)yandex\.[a-z.]{2,6}$|^ya\.ru$/' => 'Yandex',
				'/(^|\.)bing\.com$/'                => 'Bing',
				'/(^|\.)duckduckgo\.com$/'          => 'DuckDuckGo',
				'/(^|\.)search\.yahoo\.|^yahoo\./'  => 'Yahoo',
				'/^(go|search)\.mail\.ru$/'         => 'Mail.ru',
				'/(^|\.)rambler\.ru$/'              => 'Rambler',
				'/(^|\.)baidu\.com$/'               => 'Baidu',
				'/(^|\.)ecosia\.org$/'              => 'Ecosia',
				'/^search\.brave\.com$/'            => 'Brave Search',
				'/(^|\.)naver\.com$/'               => 'Naver',
				'/(^|\.)seznam\.cz$/'               => 'Seznam',
				'/(^|\.)qwant\.com$/'               => 'Qwant',
				'/(^|\.)startpage\.com$/'           => 'Startpage',
			),
			'social'    => array(
				'/(^|\.)vk\.(com|ru)$|^vk\.cc$/'          => 'VK',
				'/(^|\.)(facebook\.com|fb\.com|fb\.me)$/' => 'Facebook',
				'/(^|\.)instagram\.com$/'                 => 'Instagram',
				'/(^|\.)ok\.ru$/'                         => 'Odnoklassniki',
				'/(^|\.)(twitter\.com|x\.com)$|^t\.co$/'  => 'X (Twitter)',
				'/(^|\.)(youtube\.com|youtu\.be)$/'       => 'YouTube',
				'/(^|\.)(linkedin\.com|lnkd\.in)$/'       => 'LinkedIn',
				'/(^|\.)pinterest\.[a-z.]+$/'             => 'Pinterest',
				'/(^|\.)reddit\.com$/'                    => 'Reddit',
				'/(^|\.)tiktok\.com$/'                    => 'TikTok',
				'/(^|\.)dzen\.ru$|^zen\.yandex\.ru$/'     => 'Dzen',
				'/(^|\.)threads\.(net|com)$/'             => 'Threads',
				'/(^|\.)rutube\.ru$/'                     => 'Rutube',
				'/(^|\.)pikabu\.ru$/'                     => 'Pikabu',
			),
			'messenger' => array(
				'/(^|\.)(t\.me|telegram\.org|telegram\.me)$/' => 'Telegram',
				'/(^|\.)whatsapp\.(com|net)$|^wa\.me$/'      => 'WhatsApp',
				'/(^|\.)viber\.com$/'                        => 'Viber',
				'/(^|\.)discord(app)?\.com$/'                => 'Discord',
				'/(^|\.)max\.ru$/'                           => 'MAX',
				'/(^|\.)slack\.com$/'                        => 'Slack',
			),
		);

		/**
		 * Filters source detection rules.
		 *
		 * @param array $rules Source type => array( host regex => display name ).
		 */
		return apply_filters( 'visitrix_source_rules', $rules );
	}

	/**
	 * Classifies a visit.
	 *
	 * @param string $referrer Full referrer URL.
	 * @param array  $params   Landing page query parameters.
	 * @return array{type:string,name:string}
	 */
	public static function classify( $referrer, $params ) {
		$utm_source = isset( $params['utm_source'] ) ? strtolower( (string) $params['utm_source'] ) : '';
		$utm_medium = isset( $params['utm_medium'] ) ? strtolower( (string) $params['utm_medium'] ) : '';

		// Paid traffic: click IDs or a paid utm_medium.
		$paid = preg_match( '/^(cpc|ppc|cpm|cpa|cpv|paid|paidsearch|paid_social|display|banner|ad|ads|retargeting)$/', $utm_medium );
		if ( ! empty( $params['yclid'] ) ) {
			return array( 'type' => 'ad', 'name' => '' !== $utm_source ? $utm_source : 'Yandex Direct' );
		}
		if ( ! empty( $params['gclid'] ) ) {
			return array( 'type' => 'ad', 'name' => '' !== $utm_source ? $utm_source : 'Google Ads' );
		}
		if ( $paid ) {
			return array( 'type' => 'ad', 'name' => '' !== $utm_source ? $utm_source : $utm_medium );
		}

		$host = self::host( $referrer );

		if ( '' !== $host ) {
			if ( $host === self::host( home_url() ) ) {
				return array( 'type' => 'internal', 'name' => $host );
			}
			$match = self::match_host( $host );
			return $match ? $match : array( 'type' => 'referral', 'name' => $host );
		}

		// No referrer (apps, messengers, https→http) – try the utm_source tag.
		if ( '' !== $utm_source ) {
			$match = self::match_host( $utm_source . '.com' );
			if ( ! $match ) {
				$match = self::match_keyword( $utm_source );
			}
			return $match ? $match : array( 'type' => 'referral', 'name' => $utm_source );
		}

		return array( 'type' => 'direct', 'name' => '' );
	}

	/**
	 * Normalized host of a URL without "www." / "m.".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return (string) preg_replace( '/^(www|m|l|lm|away)\./', '', $host );
	}

	/**
	 * Finds a rule matching the host.
	 *
	 * @param string $host Host.
	 * @return array|null
	 */
	private static function match_host( $host ) {
		foreach ( self::rules() as $type => $patterns ) {
			foreach ( $patterns as $pattern => $name ) {
				if ( preg_match( $pattern, $host ) ) {
					return array( 'type' => $type, 'name' => $name );
				}
			}
		}
		return null;
	}

	/**
	 * Matches a bare utm_source keyword ("vk", "telegram", "yandex"...) to a rule name.
	 *
	 * @param string $keyword Keyword.
	 * @return array|null
	 */
	private static function match_keyword( $keyword ) {
		$keyword = preg_replace( '/[^a-z0-9]/', '', $keyword );
		if ( '' === $keyword ) {
			return null;
		}
		foreach ( self::rules() as $type => $patterns ) {
			foreach ( $patterns as $name ) {
				if ( preg_replace( '/[^a-z0-9]/', '', strtolower( $name ) ) === $keyword ) {
					return array( 'type' => $type, 'name' => $name );
				}
			}
		}
		$aliases = array(
			'tg'        => array( 'messenger', 'Telegram' ),
			'vkontakte' => array( 'social', 'VK' ),
			'fb'        => array( 'social', 'Facebook' ),
			'ig'        => array( 'social', 'Instagram' ),
			'ya'        => array( 'search', 'Yandex' ),
		);
		return isset( $aliases[ $keyword ] ) ? array( 'type' => $aliases[ $keyword ][0], 'name' => $aliases[ $keyword ][1] ) : null;
	}
}
