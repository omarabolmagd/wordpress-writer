<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Live progress of the step that is running right now: what it is doing, the searches it ran,
 * how many words it has written. Saved to the job every couple of seconds so the admin screen
 * can show it, and doubles as the heartbeat that tells other runners this step is still alive.
 */
final class Progress {

	private const META       = '_aaw_progress';
	private const MAX_EVENTS = 14;

	private static int $job      = 0;
	private static string $token = '';
	private static array $p      = array();
	private static bool $dirty   = false;
	private static string $text  = '';

	private static float $last_write  = 0;
	private static float $last_claim  = 0;
	private static float $last_cancel = 0;
	private static float $last_beat   = 0;

	/** Called while a browser waits on the request, to keep the connection open. */
	private static $keepalive = null;

	public static function begin( int $job_id, string $stage, string $token = '' ): void {
		self::$job        = $job_id;
		self::$token      = $token;
		self::$text       = '';
		self::$last_claim = microtime( true );
		self::$p          = array(
			'stage'          => $stage,
			'started'        => time(),
			'updated'        => time(),
			'phase'          => 'starting',
			'detail'         => '',
			'events'         => array(),
			'words'          => 0,
			'sections_done'  => 0,
			'sections_total' => 0,
			'target'         => null,
			'current'        => 0,
			'total'          => 0,
		);
		self::write();
	}

	public static function end(): void {
		if ( self::$job ) {
			self::$p['phase'] = 'finished';
			self::write();
		}
		self::$job   = 0;
		self::$token = '';
	}

	public static function active(): bool {
		return self::$job > 0;
	}

	public static function set_keepalive( ?callable $callback ): void {
		self::$keepalive = $callback;
		self::$last_beat = microtime( true );
	}

	/** What the step is doing now, e.g. "searching", "writing", "images". */
	public static function phase( string $phase, string $detail = '', array $fields = array() ): void {
		if ( ! self::$job ) {
			return;
		}
		$changed           = self::$p['phase'] !== $phase || self::$p['detail'] !== $detail;
		self::$p['phase']  = $phase;
		self::$p['detail'] = $detail;
		foreach ( $fields as $key => $value ) {
			self::$p[ $key ] = $value;
		}
		self::$dirty = true;
		self::tick( $changed );
	}

	public static function set( array $fields ): void {
		if ( ! self::$job ) {
			return;
		}
		foreach ( $fields as $key => $value ) {
			self::$p[ $key ] = $value;
		}
		self::$dirty = true;
	}

	/** Adds a line to the activity feed (a search, its results, a retry). */
	public static function event( string $type, string $text, array $fields = array() ): void {
		if ( ! self::$job ) {
			return;
		}
		self::$p['events'][] = array_merge(
			array(
				't'    => time(),
				'type' => $type,
				'text' => $text,
			),
			$fields
		);
		self::$p['events'] = array_slice( self::$p['events'], -self::MAX_EVENTS );
		self::$dirty       = true;
		self::tick( true );
	}

	/** Updates the newest event of a type, e.g. adds the result count to the last search. */
	public static function update_last( string $type, array $fields ): void {
		for ( $i = count( self::$p['events'] ?? array() ) - 1; $i >= 0; $i-- ) {
			if ( self::$p['events'][ $i ]['type'] === $type ) {
				self::$p['events'][ $i ] = array_merge( self::$p['events'][ $i ], $fields );
				self::$dirty             = true;
				return;
			}
		}
	}

	/** Streamed text from Claude, used to estimate words written. */
	public static function text( string $delta ): void {
		if ( self::$job ) {
			self::$text .= $delta;
			self::$dirty = true;
		}
	}

	public static function reset_text(): void {
		self::$text = '';
	}

	/**
	 * Call often. Keeps the browser connection and the claim alive, saves progress,
	 * and throws Cancelled when someone pressed Cancel.
	 */
	public static function tick( bool $force = false ): void {
		if ( ! self::$job ) {
			return;
		}
		$now = microtime( true );

		if ( self::$keepalive && $now - self::$last_beat >= 8 ) {
			self::$last_beat = $now;
			call_user_func( self::$keepalive );
		}
		if ( self::$token && $now - self::$last_claim >= 15 ) {
			self::$last_claim = $now;
			Worker::heartbeat( self::$job, self::$token );
		}
		if ( $now - self::$last_cancel >= 3 ) {
			self::$last_cancel = $now;
			if ( 'cancelled' === Jobs::status_fresh( self::$job ) ) {
				throw new Cancelled( 'Cancelled.' );
			}
		}
		$since = $now - self::$last_write;
		if ( ( self::$dirty && $since >= ( $force ? 0.3 : 1.5 ) ) || $since >= 20 ) {
			self::write();
		}
	}

	/** Before a long call that can't report progress: tell other runners not to take over yet. */
	public static function hold( int $seconds ): void {
		if ( self::$job && self::$token ) {
			Worker::heartbeat( self::$job, self::$token, time() + $seconds );
			self::$last_claim = microtime( true ) + $seconds - 15;
		}
	}

	/** Rough word count of streamed text, ignoring JSON keys. */
	public static function count_words( string $text ): int {
		$words = preg_match_all( '/[\p{Arabic}\p{Latin}\d]{2,}/u', $text );
		$keys  = preg_match_all( '/"[a-z_]+"\s*:/', $text );
		return max( 0, (int) $words - (int) $keys );
	}

	private static function write(): void {
		if ( ! self::$job ) {
			return;
		}
		if ( '' !== self::$text ) {
			self::$p['words']         = self::count_words( self::$text );
			self::$p['sections_done'] = substr_count( self::$text, '"heading"' );
		}
		self::$p['updated'] = time();
		update_post_meta( self::$job, self::META, wp_slash( wp_json_encode( self::$p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		self::$last_write = microtime( true );
		self::$dirty      = false;
	}

	public static function get( int $job_id ): ?array {
		wp_cache_delete( $job_id, 'post_meta' );
		$raw = get_post_meta( $job_id, self::META, true );
		$val = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $val ) ? $val : null;
	}

	/* ------------------------------------------------- stream events from Claude */

	/** Turns Claude's streamed events into progress the editor can follow. */
	public static function on_stream_event( string $kind, array $data ): void {
		if ( ! self::$job ) {
			return;
		}
		switch ( $kind ) {
			case 'block_start':
				$type = (string) ( $data['type'] ?? '' );
				if ( 'thinking' === $type || 'redacted_thinking' === $type ) {
					self::phase( 'thinking' );
				} elseif ( 'server_tool_use' === $type && 'web_search' === ( $data['name'] ?? '' ) ) {
					self::phase( 'searching' );
				} elseif ( 'web_search_tool_result' === $type ) {
					$content = $data['content'] ?? array();
					if ( is_array( $content ) && array_is_list( $content ) ) {
						$hosts = array();
						foreach ( array_slice( $content, 0, 4 ) as $r ) {
							$host = (string) wp_parse_url( (string) ( $r['url'] ?? '' ), PHP_URL_HOST );
							if ( $host ) {
								$hosts[] = preg_replace( '/^www\./', '', $host );
							}
						}
						self::update_last( 'search', array( 'results' => count( $content ), 'hosts' => array_values( array_unique( $hosts ) ) ) );
					} else {
						self::update_last( 'search', array( 'error' => (string) ( $content['error_code'] ?? 'error' ) ) );
					}
					self::phase( 'reading' );
				} elseif ( 'text' === $type ) {
					self::phase( 'writing' );
				}
				break;
			case 'block_stop':
				if ( 'server_tool_use' === ( $data['type'] ?? '' ) && 'web_search' === ( $data['name'] ?? '' ) ) {
					$query = (string) ( $data['input']['query'] ?? '' );
					self::event( 'search', $query );
				}
				break;
			case 'text':
				self::text( (string) ( $data['text'] ?? '' ) );
				break;
		}
		self::tick();
	}
}
