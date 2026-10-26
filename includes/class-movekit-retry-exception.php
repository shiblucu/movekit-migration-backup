<?php
/**
 * Temporary failure exception.
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * A failure that may go away if the same request is tried again, such as a
 * dropped connection or a server timeout.
 */
class MoveKit_Retry_Exception extends MoveKit_Exception {
}
