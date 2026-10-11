<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal client for the Claude Messages API.
 *
 * Docs: https://platform.claude.com/docs/en/api/messages/create
 */
final class Claude {

	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	private const VERSION  = '2023-06-01';

	/** Lets tests replace the HTTP transport: fn( array $body ): array $response. */
	public static $transport = null;

	/**
	 * Runs a research request with the web search tool.
	 *
	 * @return array{text:string, sources:array<string,array>, searches:int}
	 */
	public static function research( string $system, string $prompt, int $job_id, string $item, int $max_tokens = 6000 ): array {
		$body = array(
			'model'      => Settings::get( 'claude_model' ),
			'max_tokens' => $max_tokens,
			'system'     => $system,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
			'tools'      => array(
				array(
					'type'     => 'web_search_20250305',
					'name'     => 'web_search',
					'max_uses' => (int) Settings::get( 'max_searches' ),
				),
			),
		);

		$response = self::send_with_continuation( $body, $job_id, $item );

		$text    = '';
		$sources = array();
		foreach ( $response['content'] ?? array() as $block ) {
			$type = $block['type'] ?? '';
			if ( 'web_search_tool_result' === $type && is_array( $block['content'] ?? null ) && array_is_list( $block['content'] ) ) {
				foreach ( $block['content'] as $result ) {
					if ( 'web_search_result' === ( $result['type'] ?? '' ) && ! empty( $result['url'] ) ) {
						$url             = (string) $result['url'];
						$sources[ $url ] = array_merge(
							$sources[ $url ] ?? array( 'cited' => false ),
							array(
								'url'      => $url,
								'title'    => (string) ( $result['title'] ?? '' ),
								'page_age' => (string) ( $result['page_age'] ?? '' ),
							)
						);
					}
				}
			} elseif ( 'text' === $type ) {
				$text .= (string) ( $block['text'] ?? '' );
				foreach ( $block['citations'] ?? array() as $citation ) {
					if ( ! empty( $citation['url'] ) ) {
						$url                      = (string) $citation['url'];
						$sources[ $url ]          = $sources[ $url ] ?? array( 'url' => $url, 'title' => (string) ( $citation['title'] ?? '' ), 'page_age' => '' );
						$sources[ $url ]['cited'] = true;
						$sources[ $url ]['quote'] = mb_substr( (string) ( $citation['cited_text'] ?? '' ), 0, 150 );
					}
				}
			}
		}

		return array(
			'text'     => trim( $text ),
			'sources'  => $sources,
			'searches' => (int) ( $response['usage']['server_tool_use']['web_search_requests'] ?? 0 ),
		);
	}

	/**
	 * Asks for a JSON answer that matches a schema (structured outputs).
	 */
	public static function json( string $system, string $prompt, array $schema, int $job_id, string $item, int $max_tokens = 8000 ): array {
		$body = array(
			'model'         => Settings::get( 'claude_model' ),
			'max_tokens'    => $max_tokens,
			'system'        => $system,
			'messages'      => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $schema,
				),
			),
		);

		try {
			$response = self::send_with_continuation( $body, $job_id, $item );
		} catch ( ApiError $e ) {
			// Some models or accounts may not accept structured outputs; fall back to plain JSON instructions.
			if ( 400 !== $e->status || ! str_contains( strtolower( $e->getMessage() ), 'output_config' ) ) {
				throw $e;
			}
			unset( $body['output_config'] );
			$body['system'] .= "\n\nReply with a single JSON object only, no prose and no code fences. It must match this JSON schema:\n" . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE );
			$response        = self::send_with_continuation( $body, $job_id, $item );
		}

		if ( 'max_tokens' === ( $response['stop_reason'] ?? '' ) ) {
			throw new \RuntimeException( 'Claude ran out of output space before finishing. Try again, or shorten the outline.' );
		}

		$text = '';
		foreach ( $response['content'] ?? array() as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) $block['text'];
			}
		}
		$data = self::decode_json( $text );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Claude returned a reply that is not valid JSON.' );
		}
		return $data;
	}

	public static function decode_json( string $text ): ?array {
		$text = trim( $text );
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		// Tolerate code fences or stray prose around the object.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return null;
	}

	/**
	 * Sends a request, continuing while the API pauses a long server-tool turn.
	 */
	private static function send_with_continuation( array $body, int $job_id, string $item ): array {
		$merged_content = array();
		$response       = array();
		for ( $round = 0; $round < 5; $round++ ) {
			$response = self::send( $body, $job_id, $item );
			$content  = $response['content'] ?? array();
			$merged_content = array_merge( $merged_content, $content );
			if ( 'pause_turn' !== ( $response['stop_reason'] ?? '' ) ) {
				break;
			}
			// Send the paused assistant turn back unchanged so the API can resume it.
			$body['messages'][] = array( 'role' => 'assistant', 'content' => $content );
		}
		$response['content'] = $merged_content;
		return $response;
	}

	/** One HTTP call with retries for rate limits and overloads. Logs its cost. */
	private static function send( array $body, int $job_id, string $item ): array {
		$key = Settings::key( 'anthropic_key' );
		if ( '' === $key && ! self::$transport ) {
			throw new \RuntimeException( 'The Claude API key is missing. Add it in Ana Afdal Writer → Settings.' );
		}

		$attempt = 0;
		while ( true ) {
			++$attempt;
			if ( self::$transport ) {
				$status = 200;
				$data   = call_user_func( self::$transport, $body );
			} else {
				$raw = wp_remote_post(
					self::ENDPOINT,
					array(
						'timeout' => 300,
						'headers' => array(
							'x-api-key'         => $key,
							'anthropic-version' => self::VERSION,
							'content-type'      => 'application/json',
						),
						'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					)
				);
				if ( is_wp_error( $raw ) ) {
					if ( $attempt < 3 ) {
						sleep( 5 * $attempt );
						continue;
					}
					throw new \RuntimeException( 'Could not reach the Claude API: ' . $raw->get_error_message() );
				}
				$status = (int) wp_remote_retrieve_response_code( $raw );
				$data   = json_decode( wp_remote_retrieve_body( $raw ), true );
			}

			if ( 200 === $status && is_array( $data ) ) {
				$usage = $data['usage'] ?? array();
				Costs::log(
					$job_id,
					'claude',
					$item,
					Costs::claude_cost( $usage ),
					array(
						'model'    => $body['model'],
						'in'       => (int) ( $usage['input_tokens'] ?? 0 ),
						'out'      => (int) ( $usage['output_tokens'] ?? 0 ),
						'searches' => (int) ( $usage['server_tool_use']['web_search_requests'] ?? 0 ),
					)
				);
				return $data;
			}

			$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			if ( in_array( $status, array( 429, 500, 502, 503, 529 ), true ) && $attempt < 3 ) {
				sleep( 10 * $attempt );
				continue;
			}
			throw new ApiError( sprintf( 'Claude API error %d: %s', $status, $message ? $message : 'unknown error' ), $status );
		}
	}
}
