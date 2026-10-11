<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown inside a running step when someone pressed Cancel.
 */
final class Cancelled extends \RuntimeException {
}
