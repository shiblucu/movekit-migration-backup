<?php
/**
 * The .movekit archive format.
 *
 * An archive is a sequence of self-checking records, so a damaged or
 * truncated backup is detected before a restore touches anything:
 *
 *   "MoveKitARC1"
 *   record*            header (41 bytes) + path + header CRC32 + data
 *   manifest record    JSON describing the source site
 *   end record         JSON with record count and manifest offset
 *   tail               8-byte offset of the end record + "MoveKitEND1"
 *
 * Large files are split across several records, each with its own CRC32,
 * which keeps memory use low and lets every step resume on a record boundary.
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

class MoveKit_Archive {

	const MAGIC      = 'MVKTARC1';
	const TAIL_MAGIC = 'MVKTEND1';
	const REC_MAGIC  = 'MVKR';
	const HEADER_LEN = 41;
	const TAIL_LEN   = 16;

	const TYPE_FILE     = 'F';
	const TYPE_DIR      = 'D';
	const TYPE_DB       = 'B';
	const TYPE_MANIFEST = 'M';
	const TYPE_END      = 'Z';

	/** Maximum bytes of file data per record. */
	const CHUNK = 2097152;

	/** Archive format version, bumped on incompatible changes. */
	const FORMAT = 1;

	/**
	 * Encode one record.
	 */
	public static function encode( $type, $path, $data, $offset = 0, $filesize = null, $mtime = 0, $perms = 0 ) {
		if ( strlen( $path ) > 65535 ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: detail. */ __( 'Path too long: %s', 'movekit-migration-backup' ), substr( $path, 0, 200 ) ) ) );
		}
		if ( null === $filesize ) {
			$filesize = strlen( $data );
		}
		$header = pack( 'a4anJNJJnN', self::REC_MAGIC, $type, strlen( $path ), $offset, strlen( $data ), $filesize, $mtime, $perms & 0xFFFF, crc32( $data ) ) . $path;
		return $header . pack( 'N', crc32( $header ) ) . $data;
	}
}
