<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Turns Claude's light markup into safe HTML and validates every link in it.
 *
 * Claude writes links as [anchor](post:123) for internal posts and
 * [anchor](https://…) for external sources. Nothing else becomes a link.
 */
final class Links {

	private const LINK_RE = '/\[([^\[\]]{1,200})\]\(\s*([^()\s]{1,2000})\s*\)/u';

	/** Lets tests replace live URL checks: fn( string $url ): array. */
	public static $checker = null;

	/** @return array<int,array{anchor:string,target:string}> */
	public static function extract( string $text ): array {
		preg_match_all( self::LINK_RE, $text, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $match ) {
			$out[] = array( 'anchor' => trim( $match[1] ), 'target' => trim( $match[2] ) );
		}
		return $out;
	}

	/**
	 * Renders text to HTML. $resolve( string $target ) returns
	 * ['href' => url, 'external' => bool] or null to drop the link and keep its text.
	 */
	public static function render( string $text, callable $resolve ): string {
		$html   = '';
		$offset = 0;
		preg_match_all( self::LINK_RE, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $matches as $m ) {
			$start = $m[0][1];
			$html .= self::inline( substr( $text, $offset, $start - $offset ) );
			$anchor = trim( $m[1][0] );
			$link   = $resolve( trim( $m[2][0] ) );
			if ( $link ) {
				$attrs = ' href="' . esc_url( $link['href'] ) . '"';
				if ( ! empty( $link['external'] ) ) {
					$attrs .= ' target="_blank" rel="noreferrer noopener"';
				}
				$html .= '<a' . $attrs . '>' . self::inline( $anchor ) . '</a>';
			} else {
				$html .= self::inline( $anchor );
			}
			$offset = $start + strlen( $m[0][0] );
		}
		$html .= self::inline( substr( $text, $offset ) );
		return trim( $html );
	}

	/** Escapes text and applies **bold**. */
	public static function inline( string $text ): string {
		$escaped = esc_html( $text );
		return preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped );
	}

	/** Text with link markup removed (for word counts and plain-text views). */
	public static function strip( string $text ): string {
		$text = preg_replace( self::LINK_RE, '$1', $text );
		return str_replace( '**', '', $text );
	}

	public static function normalize( string $url ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return $url;
		}
		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		$host   = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );
		$path   = rtrim( $parts['path'] ?? '', '/' );
		$query  = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			foreach ( array_keys( $q ) as $k ) {
				if ( str_starts_with( (string) $k, 'utm_' ) ) {
					unset( $q[ $k ] );
				}
			}
			ksort( $q );
			$query = $q ? '?' . http_build_query( $q ) : '';
		}
		return $scheme . '://' . $host . $path . $query;
	}

	/** True when $url matches one of the allowed source URLs. */
	public static function is_allowed( string $url, array $allowed ): bool {
		$n = self::normalize( $url );
		foreach ( $allowed as $a ) {
			if ( self::normalize( $a ) === $n ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks several URLs live at once.
	 *
	 * @return array<string,array{ok:bool,blocked:bool,status:int}>
	 */
	public static function check_many( array $urls ): array {
		$urls = array_values( array_unique( $urls ) );
		$out  = array();
		if ( ! $urls ) {
			return $out;
		}
		if ( self::$checker ) {
			foreach ( $urls as $url ) {
				$out[ $url ] = call_user_func( self::$checker, $url );
			}
			return $out;
		}
		$valid = array();
		foreach ( $urls as $url ) {
			if ( wp_http_validate_url( $url ) ) {
				$valid[] = $url;
			} else {
				$out[ $url ] = array( 'ok' => false, 'blocked' => false, 'status' => 0 );
			}
		}
		if ( ! $valid ) {
			return $out;
		}
		$status = self::statuses( $valid, 'HEAD' );
		// Many sites reject HEAD; confirm those with a small GET.
		$retry = array_values( array_filter( $valid, static fn( $u ) => 0 === $status[ $u ] || $status[ $u ] >= 400 ) );
		if ( $retry ) {
			$status = array_merge( $status, self::statuses( $retry, 'GET' ) );
		}
		foreach ( $valid as $url ) {
			$code        = (int) $status[ $url ];
			$out[ $url ] = array(
				'ok'      => $code >= 200 && $code < 400,
				'blocked' => in_array( $code, array( 401, 403, 429, 999 ), true ),
				'status'  => $code,
			);
		}
		return $out;
	}

	/** HTTP status for each URL, fetched in parallel. 0 means no response. */
	private static function statuses( array $urls, string $method ): array {
		$class = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( '\Requests' ) ? '\Requests' : '' );
		$out   = array_fill_keys( $urls, 0 );
		if ( ! $class ) {
			foreach ( $urls as $url ) {
				$out[ $url ] = self::check( $url )['status'];
			}
			return $out;
		}
		$requests = array();
		foreach ( $urls as $i => $url ) {
			$requests[ $i ] = array(
				'url'     => $url,
				'type'    => $method,
				'headers' => array(
					'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
					'Accept-Language' => 'ar,en;q=0.8',
				),
			);
		}
		$options = array(
			'timeout'          => 12,
			'connect_timeout'  => 8,
			'follow_redirects' => true,
			'redirects'        => 5,
			'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
		);
		if ( 'GET' === $method ) {
			$options['max_bytes'] = 65536;
		}
		try {
			$responses = $class::request_multiple( $requests, $options );
		} catch ( \Throwable $e ) {
			return $out;
		}
		foreach ( $urls as $i => $url ) {
			$response    = $responses[ $i ] ?? null;
			$out[ $url ] = is_object( $response ) && isset( $response->status_code ) ? (int) $response->status_code : 0;
		}
		return $out;
	}

	/**
	 * Checks a URL live. Returns ok (link works), blocked (site refuses automated checks but exists) and status.
	 *
	 * @return array{ok:bool,blocked:bool,status:int}
	 */
	public static function check( string $url ): array {
		if ( self::$checker ) {
			return call_user_func( self::$checker, $url );
		}
		$args = array(
			'timeout'     => 12,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
			'headers'     => array( 'Accept-Language' => 'ar,en;q=0.8' ),
		);
		$response = wp_safe_remote_head( $url, $args );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 0 === $status || $status >= 400 ) {
			// Many sites reject HEAD; confirm with a small GET.
			$args['limit_response_size'] = 65536;
			$response                    = wp_safe_remote_get( $url, $args );
			$status                      = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		}
		$ok      = $status >= 200 && $status < 400;
		$blocked = in_array( $status, array( 401, 403, 429, 999 ), true );
		return array( 'ok' => $ok, 'blocked' => $blocked, 'status' => $status );
	}
}
