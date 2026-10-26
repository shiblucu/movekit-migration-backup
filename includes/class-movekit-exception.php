<?php
/**
 * Plugin exception.
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * An error whose message is safe and useful to show to the site owner.
 *
 * Messages are HTML-escaped when thrown; text() returns them as plain text
 * for places that escape on output themselves (JSON, the CLI).
 */
class MoveKit_Exception extends Exception {

	/**
	 * The message as plain text.
	 *
	 * @return string
	 */
	public function text() {
		return html_entity_decode( $this->getMessage(), ENT_QUOTES, 'UTF-8' );
	}
}
