<?php
/**
 * Backups are read and written in pieces with plain PHP file functions:
 * WP_Filesystem has no seek/append/stream operations and would need whole
 * multi-GB files in memory.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and verifies records.
 */
class MoveKit_Archive_Reader {

	private $fh;
	private $path;
	private $size;

	public function __construct( $path ) {
		$this->path = $path;
		$this->fh   = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
		if ( ! $this->fh ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: detail. */ __( 'Could not open backup file %s', 'movekit-migration-backup' ), basename( $path ) ) ) );
		}
		$this->size = filesize( $path );
		if ( fread( $this->fh, 8 ) !== MoveKit_Archive::MAGIC ) {
			throw new MoveKit_Exception( esc_html__( 'This is not a MoveKit backup file.', 'movekit-migration-backup' ) );
		}
	}

	public function size() {
		return $this->size;
	}

	/**
	 * Check that the archive was fully written, without reading all of it.
	 *
	 * @return array End record summary: records, manifest_offset, end_offset.
	 */
	public function summary() {
		if ( $this->size < 8 + MoveKit_Archive::TAIL_LEN ) {
			throw new MoveKit_Exception( esc_html__( 'The backup file is incomplete (too small).', 'movekit-migration-backup' ) );
		}
		fseek( $this->fh, $this->size - MoveKit_Archive::TAIL_LEN );
		$tail = fread( $this->fh, MoveKit_Archive::TAIL_LEN );
		if ( substr( $tail, 8 ) !== MoveKit_Archive::TAIL_MAGIC ) {
			throw new MoveKit_Exception( esc_html__( 'The backup file is incomplete: it was cut off before the end. Download or upload it again.', 'movekit-migration-backup' ) );
		}
		$u = unpack( 'Joffset', substr( $tail, 0, 8 ) );
		if ( $u['offset'] < 8 || $u['offset'] >= $this->size ) {
			throw new MoveKit_Exception( esc_html__( 'The backup file is damaged (missing end marker).', 'movekit-migration-backup' ) );
		}
		$end = $this->read( $u['offset'] );
		if ( MoveKit_Archive::TYPE_END !== $end['type'] ) {
			throw new MoveKit_Exception( esc_html__( 'The backup file is damaged (missing end marker).', 'movekit-migration-backup' ) );
		}
		$summary = json_decode( $end['data'], true );
		if ( ! is_array( $summary ) || ! isset( $summary['manifest_offset'] ) ) {
			throw new MoveKit_Exception( esc_html__( 'The backup file is damaged (unreadable end marker).', 'movekit-migration-backup' ) );
		}
		$summary['end_offset'] = $u['offset'];
		return $summary;
	}

	public function manifest() {
		$summary = $this->summary();
		$rec     = $this->read( $summary['manifest_offset'] );
		if ( MoveKit_Archive::TYPE_MANIFEST !== $rec['type'] ) {
			throw new MoveKit_Exception( esc_html__( 'The backup file is damaged (missing manifest).', 'movekit-migration-backup' ) );
		}
		$manifest = json_decode( $rec['data'], true );
		if ( ! is_array( $manifest ) ) {
			throw new MoveKit_Exception( esc_html__( 'The backup file is damaged (unreadable manifest).', 'movekit-migration-backup' ) );
		}
		return $manifest;
	}

	/**
	 * Read and verify the record at $offset.
	 *
	 * @return array type, path, offset, filesize, mtime, perms, data, next.
	 */
	public function read( $offset ) {
		if ( $offset + MoveKit_Archive::HEADER_LEN + 4 > $this->size ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: Placeholders: byte offset or file path, and details. */ __( 'The backup file is damaged: unexpected end at byte %d.', 'movekit-migration-backup' ), $offset ) ) );
		}
		fseek( $this->fh, $offset );
		$fixed = fread( $this->fh, MoveKit_Archive::HEADER_LEN );
		$h     = unpack( self::header_format(), $fixed );
		if ( MoveKit_Archive::REC_MAGIC !== $h['magic'] ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: Placeholders: byte offset or file path, and details. */ __( 'The backup file is damaged at byte %d.', 'movekit-migration-backup' ), $offset ) ) );
		}
		$path = $h['pathlen'] > 0 ? fread( $this->fh, $h['pathlen'] ) : '';
		$hcrc = unpack( 'N', fread( $this->fh, 4 ) );
		if ( crc32( $fixed . $path ) !== $hcrc[1] ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: Placeholders: byte offset or file path, and details. */ __( 'The backup file is damaged at byte %d (header checksum).', 'movekit-migration-backup' ), $offset ) ) );
		}
		// Records are written in pieces of CHUNK bytes. Refuse anything much larger
		// instead of reading it into memory.
		if ( $h['datalen'] > 16 * MoveKit_Archive::CHUNK ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: Placeholders: byte offset or file path, and details. */ __( 'The backup file is damaged at byte %d (record too large).', 'movekit-migration-backup' ), $offset ) ) );
		}
		$data_start = $offset + MoveKit_Archive::HEADER_LEN + $h['pathlen'] + 4;
		if ( $data_start + $h['datalen'] > $this->size ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: Placeholders: byte offset or file path, and details. */ __( 'The backup file is damaged: "%s" is cut off.', 'movekit-migration-backup' ), $path ) ) );
		}
		$data = '';
		$left = $h['datalen'];
		while ( $left > 0 ) {
			$chunk = fread( $this->fh, $left );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
			$left -= strlen( $chunk );
		}
		if ( strlen( $data ) !== $h['datalen'] || crc32( $data ) !== $h['datacrc'] ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: Placeholders: byte offset or file path, and details. */ __( 'The backup file is damaged: "%s" failed its checksum.', 'movekit-migration-backup' ), $path ) ) );
		}
		return array(
			'type'     => $h['type'],
			'path'     => $path,
			'offset'   => $h['offset'],
			'filesize' => $h['filesize'],
			'mtime'    => $h['mtime'],
			'perms'    => $h['perms'],
			'data'     => $data,
			'next'     => $data_start + $h['datalen'],
		);
	}

	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
	}

	private static function header_format() {
		return 'a4magic/atype/npathlen/Joffset/Ndatalen/Jfilesize/Jmtime/nperms/Ndatacrc';
	}
}
