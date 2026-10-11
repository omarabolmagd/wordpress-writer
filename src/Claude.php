<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Client for the Claude Messages API.
 *
 * - Streams every response, so the editor sees searches and writing progress live.
 * - Sets effort and thinking per step: by default Claude doesn't think silently before
 *   answering, which is slow and eats the output limit.
 * - Retries overloads and network errors, continues paused turns, and gives a reply
 *   more room if it runs out of output space.
 *
 * Docs: https://platform.claude.com/docs/en/api/messages/create
 */
final class Claude {

	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	private const VERSION  = '2023-06-01';

	/** Tests: fn( array $body ): array — returns a whole, non-streamed message. */
	public static $transport = null;

	/** Tests: fn( array $body, callable $write ): void — writes server-sent event text. */
	public static $sse_transport = null;

	/* ------------------------------------------------------------- settings */

	/**
	 * How each step should run. "fast" turns off up-front thinking and lowers effort;
	 * "thorough" lets Claude think before answering on the steps where it helps most.
	 */
	public static function profile( string $step ): array {
		$fast = array(
			'topics_research'  => array( 'effort' => 'low', 'think' => false, 'max_tokens' => 12000 ),
			'topics_structure' => array( 'effort' => 'low', 'think' => false, 'max_tokens' => 12000 ),
			'research'         => array( 'effort' => 'medium', 'think' => false, 'max_tokens' => 16000 ),
			'outline'          => array( 'effort' => 'medium', 'think' => false, 'max_tokens' => 16000 ),
			'write'            => array( 'effort' => 'medium', 'think' => false, 'max_tokens' => 32000 ),
			'quality'          => array( 'effort' => 'medium', 'think' => false, 'max_tokens' => 12000 ),
			'backlinks'        => array( 'effort' => 'low', 'think' => false, 'max_tokens' => 8000 ),
		);
		$p = $fast[ $step ] ?? array( 'effort' => 'medium', 'think' => false, 'max_tokens' => 16000 );
		if ( 'thorough' === Settings::get( 'speed' ) ) {
			$p['think']      = in_array( $step, array( 'research', 'outline', 'quality' ), true );
			$p['effort']     = in_array( $step, array( 'research', 'outline', 'quality' ), true ) ? 'high' : 'medium';
			$p['max_tokens'] = min( 64000, $p['max_tokens'] * 2 );
		}
		return (array) apply_filters( 'aaw_claude_profile', $p, $step );
	}

	/**
	 * The thinking setting that turns off up-front thinking for a model, or null to send nothing.
	 * Docs: https://platform.claude.com/docs/en/build-with-claude/thinking
	 */
	public static function no_thinking( string $model ): ?array {
		if ( preg_match( '/^claude-sonnet-5-5(?:-\d{8})?$/', $model ) ) {
			return array( 'type' => 'between_tools' );
		}
		if ( preg_match( '/^claude-(?:haiku-5-5|sonnet-5|opus-5)(?:-\d{8})?$/', $model ) ) {
			return array( 'type' => 'disabled' );
		}
		// Opus 5.5 and Fable can't turn thinking off (effort still limits it); 4.x models don't think unless asked.
		return null;
	}

	private static function endpoint(): string {
		$url = defined( 'AAW_ANTHROPIC_ENDPOINT' ) ? (string) AAW_ANTHROPIC_ENDPOINT : self::ENDPOINT;
		return (string) apply_filters( 'aaw_anthropic_endpoint', $url );
	}

	public static function body( string $step, string $system, string $prompt, ?array $schema = null, bool $search = false, int $max_searches = 0 ): array {
		$p     = self::profile( $step );
		$model = (string) Settings::get( 'claude_model' );
		$body  = array(
			'model'      => $model,
			'max_tokens' => (int) $p['max_tokens'],
			'system'     => $system,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
		);
		$output = array();
		if ( ! empty( $p['effort'] ) ) {
			$output['effort'] = (string) $p['effort'];
		}
		if ( $schema ) {
			$output['format'] = array(
				'type'   => 'json_schema',
				'schema' => $schema,
			);
		}
		if ( $output ) {
			$body['output_config'] = $output;
		}
		if ( empty( $p['think'] ) ) {
			$thinking = self::no_thinking( $model );
			if ( $thinking ) {
				$body['thinking'] = $thinking;
			}
		}
		if ( $search ) {
			$body['tools'] = array(
				array(
					'type'     => 'web_search_20250305',
					'name'     => 'web_search',
					'max_uses' => $max_searches > 0 ? $max_searches : (int) Settings::get( 'max_searches' ),
				),
			);
		}
		return $body;
	}

	/* --------------------------------------------------------------- public */

	/**
	 * Runs a research request with the web search tool.
	 *
	 * @return array{text:string, sources:array<string,array>, searches:int}
	 */
	public static function research( string $system, string $prompt, int $job_id, string $step, int $max_searches = 0 ): array {
		$response = self::call( self::body( $step, $system, $prompt, null, true, $max_searches ), $job_id, $step );

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
	public static function json( string $system, string $prompt, array $schema, int $job_id, string $step ): array {
		$body = self::body( $step, $system, $prompt, $schema );

		for ( $attempt = 1; ; $attempt++ ) {
			$response = self::call( $body, $job_id, $step );
			$stop     = (string) ( $response['stop_reason'] ?? '' );
			if ( 'refusal' === $stop ) {
				throw new \RuntimeException( 'Claude declined to answer this request. Try a different topic or wording.' );
			}
			if ( 'max_tokens' !== $stop ) {
				break;
			}
			if ( $attempt >= 2 ) {
				throw new \RuntimeException( 'Claude ran out of output space twice. Try again, or remove a section or two from the outline.' );
			}
			// Give the reply more room and ask for less deliberation, then try once more.
			$body['max_tokens'] = min( 64000, (int) $body['max_tokens'] * 2 );
			if ( isset( $body['output_config']['effort'] ) ) {
				$body['output_config']['effort'] = 'low';
			}
			Progress::event( 'retry', 'max_tokens' );
			Progress::reset_text();
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

	/* -------------------------------------------------------------- plumbing */

	/** Sends a request, continuing while the API pauses a long server-tool turn. */
	private static function call( array $body, int $job_id, string $item ): array {
		$merged   = array();
		$response = array();
		for ( $round = 0; $round < 5; $round++ ) {
			$response = self::send_adaptive( $body, $job_id, $item );
			$content  = $response['content'] ?? array();
			$merged   = array_merge( $merged, $content );
			if ( 'pause_turn' !== ( $response['stop_reason'] ?? '' ) ) {
				break;
			}
			// Send the paused assistant turn back unchanged so the API can resume it.
			$body['messages'][] = array(
				'role'    => 'assistant',
				'content' => self::for_resend( $content ),
			);
		}
		$response['content'] = $merged;
		return $response;
	}

	/** Empty JSON objects decode to [] in PHP; turn them back into {} before resending. */
	public static function for_resend( array $content ): array {
		foreach ( $content as $i => $block ) {
			if ( is_array( $block ) && array_key_exists( 'input', $block ) && array() === $block['input'] ) {
				$content[ $i ]['input'] = new \stdClass();
			}
		}
		return $content;
	}

	/** If the API rejects a setting this model doesn't support, drop it and try again. */
	private static function send_adaptive( array $body, int $job_id, string $item ): array {
		for ( $i = 0; $i < 4; $i++ ) {
			try {
				return self::send( $body, $job_id, $item );
			} catch ( ApiError $e ) {
				if ( 400 !== $e->status ) {
					throw $e;
				}
				$message = strtolower( $e->getMessage() );
				if ( isset( $body['thinking'] ) && str_contains( $message, 'thinking' ) ) {
					unset( $body['thinking'] );
					continue;
				}
				if ( isset( $body['output_config']['effort'] ) && str_contains( $message, 'effort' ) ) {
					unset( $body['output_config']['effort'] );
					if ( ! $body['output_config'] ) {
						unset( $body['output_config'] );
					}
					continue;
				}
				if ( isset( $body['output_config']['format'] ) && ( str_contains( $message, 'output_config' ) || str_contains( $message, 'format' ) || str_contains( $message, 'schema' ) ) ) {
					$schema = $body['output_config']['format']['schema'];
					unset( $body['output_config']['format'] );
					if ( ! $body['output_config'] ) {
						unset( $body['output_config'] );
					}
					$body['system'] .= "\n\nReply with a single JSON object only, no prose and no code fences. It must match this JSON schema:\n" . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE );
					continue;
				}
				throw $e;
			}
		}
		throw new \RuntimeException( 'Claude rejected the request settings.' );
	}

	/** One request with retries for overloads, rate limits and dropped connections. Logs its cost. */
	private static function send( array $body, int $job_id, string $item ): array {
		$key = Settings::key( 'anthropic_key' );
		if ( '' === $key && ! self::$transport && ! self::$sse_transport && ! defined( 'AAW_ANTHROPIC_ENDPOINT' ) ) {
			throw new \RuntimeException( 'The Claude API key is missing. Add it in Ana Afdal Writer → Settings.' );
		}

		for ( $attempt = 1; ; $attempt++ ) {
			try {
				if ( self::$transport ) {
					$data = call_user_func( self::$transport, $body );
				} elseif ( self::$sse_transport || self::can_stream() ) {
					$data = self::stream( $body, $key );
				} else {
					$data = self::plain( $body, $key );
				}
				$usage = (array) ( $data['usage'] ?? array() );
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
			} catch ( ApiError $e ) {
				if ( in_array( $e->status, array( 0, 429, 500, 502, 503, 504, 529 ), true ) && $attempt < 4 ) {
					$wait = (int) apply_filters( 'aaw_retry_wait', 8 * $attempt, $attempt );
					Progress::event( 'retry', 'busy', array( 'status' => $e->status, 'wait' => $wait ) );
					Progress::phase( 'waiting' );
					Progress::reset_text();
					self::sleep_with_ticks( $wait );
					continue;
				}
				throw $e;
			}
		}
	}

	private static function sleep_with_ticks( int $seconds ): void {
		for ( $i = 0; $i < $seconds; $i++ ) {
			sleep( 1 );
			Progress::tick();
		}
	}

	public static function can_stream(): bool {
		return function_exists( 'curl_init' ) && (bool) apply_filters( 'aaw_use_streaming', true );
	}

	/** Maps a streamed error type to the HTTP status it would have had. */
	private static function stream_error_status( string $type ): int {
		$map = array(
			'invalid_request_error' => 400,
			'authentication_error'  => 401,
			'permission_error'      => 403,
			'not_found_error'       => 404,
			'request_too_large'     => 413,
			'rate_limit_error'      => 429,
			'api_error'             => 500,
			'overloaded_error'      => 529,
		);
		return $map[ $type ] ?? 500;
	}

	private static function stream( array $body, string $key ): array {
		$body['stream'] = true;
		$acc            = new StreamAccumulator( array( Progress::class, 'on_stream_event' ) );

		if ( self::$sse_transport ) {
			call_user_func(
				self::$sse_transport,
				$body,
				static function ( string $chunk ) use ( $acc ) {
					$acc->feed( $chunk );
				}
			);
		} else {
			self::curl_stream( $body, $key, $acc );
		}

		if ( $acc->error ) {
			$type = (string) ( $acc->error['type'] ?? 'api_error' );
			throw new ApiError( sprintf( 'Claude API error (%s): %s', $type, (string) ( $acc->error['message'] ?? '' ) ), self::stream_error_status( $type ) );
		}
		if ( ! $acc->done ) {
			throw new ApiError( 'The connection to Claude closed before the reply finished.', 0 );
		}
		return $acc->message;
	}

	private static function curl_stream( array $body, string $key, StreamAccumulator $acc ): void {
		$status    = 0;
		$err_body  = '';
		$cancelled = null;

		$ch = curl_init( self::endpoint() );
		$options = array(
			CURLOPT_POST             => true,
			CURLOPT_POSTFIELDS       => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			CURLOPT_HTTPHEADER       => array(
				'x-api-key: ' . $key,
				'anthropic-version: ' . self::VERSION,
				'content-type: application/json',
				'accept: text/event-stream',
			),
			CURLOPT_RETURNTRANSFER   => false,
			CURLOPT_CONNECTTIMEOUT   => 20,
			CURLOPT_TIMEOUT          => 900,
			// Claude sends pings while it works; a silent minute and a half means the connection is dead.
			CURLOPT_LOW_SPEED_LIMIT  => 1,
			CURLOPT_LOW_SPEED_TIME   => 90,
			CURLOPT_HEADERFUNCTION   => static function ( $ch, string $line ) use ( &$status ): int {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
					$status = (int) $m[1];
				}
				return strlen( $line );
			},
			CURLOPT_WRITEFUNCTION    => static function ( $ch, string $data ) use ( &$status, &$err_body, &$cancelled, $acc ): int {
				if ( 200 !== $status ) {
					$err_body .= $data;
					return strlen( $data );
				}
				try {
					$acc->feed( $data );
				} catch ( Cancelled $e ) {
					$cancelled = $e;
					return 0;
				}
				return strlen( $data );
			},
			CURLOPT_NOPROGRESS       => false,
			CURLOPT_XFERINFOFUNCTION => static function () use ( &$cancelled ): int {
				try {
					Progress::tick();
				} catch ( Cancelled $e ) {
					$cancelled = $e;
					return 1;
				}
				return 0;
			},
		);
		$ca = (string) apply_filters( 'aaw_ca_bundle', ABSPATH . WPINC . '/certificates/ca-bundle.crt' );
		if ( $ca && is_readable( $ca ) && str_starts_with( self::endpoint(), 'https://' ) ) {
			$options[ CURLOPT_CAINFO ] = $ca;
		}
		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( self::endpoint() ) ) {
			$options[ CURLOPT_PROXY ] = $proxy->host() . ':' . $proxy->port();
			if ( $proxy->use_authentication() ) {
				$options[ CURLOPT_PROXYUSERPWD ] = $proxy->authentication();
			}
		}
		curl_setopt_array( $ch, $options );
		curl_exec( $ch );
		$errno = curl_errno( $ch );
		$error = curl_error( $ch );
		unset( $ch );

		if ( $cancelled ) {
			throw $cancelled;
		}
		if ( 200 !== $status && 0 !== $status ) {
			$data = json_decode( $err_body, true );
			throw new ApiError( sprintf( 'Claude API error %d: %s', $status, (string) ( $data['error']['message'] ?? 'unknown error' ) ), $status );
		}
		if ( $errno && ! $acc->done ) {
			throw new ApiError( 'Could not reach the Claude API: ' . $error, 0 );
		}
	}

	/** Non-streaming request, for servers without the PHP curl extension. */
	private static function plain( array $body, string $key ): array {
		Progress::phase( 'waiting_reply' );
		Progress::hold( 330 );
		$raw = wp_remote_post(
			self::endpoint(),
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
			throw new ApiError( 'Could not reach the Claude API: ' . $raw->get_error_message(), 0 );
		}
		$status = (int) wp_remote_retrieve_response_code( $raw );
		$data   = json_decode( wp_remote_retrieve_body( $raw ), true );
		if ( 200 !== $status || ! is_array( $data ) ) {
			throw new ApiError( sprintf( 'Claude API error %d: %s', $status, (string) ( $data['error']['message'] ?? 'unknown error' ) ), $status );
		}
		return $data;
	}
}
