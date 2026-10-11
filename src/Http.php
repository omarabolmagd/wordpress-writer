<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the WordPress HTTP API so tests can replace network calls.
 */
final class Http {

	/** fn( string $method, string $url, array $args ): array{status:int,body:string,headers:array} */
	public static $mock = null;

	/** @return array{status:int,body:string,headers:array} */
	public static function request( string $method, string $url, array $args = array() ): array {
		if ( self::$mock ) {
			return call_user_func( self::$mock, $method, $url, $args );
		}
		$args = array_merge(
			array(
				'timeout'             => 60,
				'redirection'         => 5,
				'limit_response_size' => 20 * MB_IN_BYTES,
			),
			$args,
			array( 'method' => $method )
		);
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Network error: ' . $response->get_error_message() );
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => (array) wp_remote_retrieve_headers( $response )->getAll(),
		);
	}

	public static function get( string $url, array $args = array() ): array {
		return self::request( 'GET', $url, $args );
	}

	public static function post_json( string $url, array $body, array $headers = array(), int $timeout = 120 ): array {
		return self::request(
			'POST',
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
	}
}
