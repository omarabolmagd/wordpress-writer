<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds a complete Messages API response from its server-sent events, and reports
 * each event as it arrives.
 *
 * Docs: https://platform.claude.com/docs/en/build-with-claude/streaming
 */
final class StreamAccumulator {

	public array $message = array();
	public bool $done     = false;
	public ?array $error  = null;

	private string $buffer  = '';
	private array $partial  = array();
	private $on_event;

	public function __construct( ?callable $on_event = null ) {
		$this->on_event = $on_event;
	}

	public function feed( string $chunk ): void {
		$this->buffer .= str_replace( "\r\n", "\n", $chunk );
		while ( false !== ( $pos = strpos( $this->buffer, "\n\n" ) ) ) {
			$raw          = substr( $this->buffer, 0, $pos );
			$this->buffer = substr( $this->buffer, $pos + 2 );
			$this->handle_raw( $raw );
		}
	}

	private function handle_raw( string $raw ): void {
		$data_lines = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			if ( str_starts_with( $line, 'data:' ) ) {
				$data_lines[] = ltrim( substr( $line, 5 ), ' ' );
			}
		}
		if ( ! $data_lines ) {
			return;
		}
		$data = json_decode( implode( "\n", $data_lines ), true );
		if ( is_array( $data ) ) {
			$this->handle( $data );
		}
	}

	private function emit( string $kind, array $data ): void {
		if ( $this->on_event ) {
			call_user_func( $this->on_event, $kind, $data );
		}
	}

	private function handle( array $e ): void {
		$type = (string) ( $e['type'] ?? '' );
		switch ( $type ) {
			case 'message_start':
				$this->message            = (array) ( $e['message'] ?? array() );
				$this->message['content'] = array();
				break;

			case 'content_block_start':
				$i                              = (int) $e['index'];
				$block                          = (array) ( $e['content_block'] ?? array() );
				$this->message['content'][ $i ] = $block;
				if ( array_key_exists( 'input', $block ) ) {
					$this->partial[ $i ] = '';
				}
				$this->emit( 'block_start', $block );
				break;

			case 'content_block_delta':
				$i     = (int) $e['index'];
				$delta = (array) ( $e['delta'] ?? array() );
				switch ( $delta['type'] ?? '' ) {
					case 'text_delta':
						$this->message['content'][ $i ]['text'] = ( $this->message['content'][ $i ]['text'] ?? '' ) . $delta['text'];
						$this->emit( 'text', array( 'text' => (string) $delta['text'] ) );
						break;
					case 'input_json_delta':
						$this->partial[ $i ] = ( $this->partial[ $i ] ?? '' ) . $delta['partial_json'];
						break;
					case 'citations_delta':
						$this->message['content'][ $i ]['citations'][] = $delta['citation'];
						break;
					case 'thinking_delta':
						$this->message['content'][ $i ]['thinking'] = ( $this->message['content'][ $i ]['thinking'] ?? '' ) . $delta['thinking'];
						break;
					case 'signature_delta':
						$this->message['content'][ $i ]['signature'] = $delta['signature'];
						break;
				}
				break;

			case 'content_block_stop':
				$i = (int) $e['index'];
				if ( isset( $this->partial[ $i ] ) ) {
					$input = '' === trim( $this->partial[ $i ] ) ? array() : json_decode( $this->partial[ $i ], true );
					$this->message['content'][ $i ]['input'] = is_array( $input ) ? $input : array();
					unset( $this->partial[ $i ] );
				}
				$this->emit( 'block_stop', $this->message['content'][ $i ] ?? array() );
				break;

			case 'message_delta':
				foreach ( (array) ( $e['delta'] ?? array() ) as $key => $value ) {
					$this->message[ $key ] = $value;
				}
				// Usage in message_delta is cumulative, so newer values replace older ones.
				foreach ( (array) ( $e['usage'] ?? array() ) as $key => $value ) {
					$this->message['usage'][ $key ] = $value;
				}
				break;

			case 'message_stop':
				ksort( $this->message['content'] );
				$this->message['content'] = array_values( $this->message['content'] );
				$this->done               = true;
				break;

			case 'error':
				$this->error = (array) ( $e['error'] ?? array( 'type' => 'api_error', 'message' => 'Unknown streaming error' ) );
				break;
		}
	}
}
