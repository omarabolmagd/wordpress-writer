<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * An error returned by an external API, with its HTTP status.
 */
final class ApiError extends \RuntimeException {

	public int $status;

	public function __construct( string $message, int $status = 0 ) {
		parent::__construct( $message );
		$this->status = $status;
	}
}
