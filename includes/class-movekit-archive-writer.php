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
 * Appends records to an archive. Resumable: reopening with the size recorded
 * after the last complete record discards any half-written record.
 */
class MoveKit_Archive_Writer {

	private $fh;
	private $path;
	private $records;

	public function __construct( $path, $resume_size = null, $records = 0 ) {
		$this->path    = $path;
		$this->records = (int) $records;
		if ( null === $resume_size ) {
			$this->fh = @fopen( $path, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
			if ( ! $this->fh ) {
				throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: detail. */ __( 'Could not create backup file %s', 'movekit-migration-backup' ), $path ) ) );
			}
			$this->write( MoveKit_Archive::MAGIC );
		} else {
			$this->fh = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
			if ( ! $this->fh ) {
				throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: detail. */ __( 'Could not reopen backup file %s', 'movekit-migration-backup' ), $path ) ) );
			}
			ftruncate( $this->fh, $resume_size );
			fseek( $this->fh, 0, SEEK_END );
		}
	}

	public function add( $type, $path, $data, $offset = 0, $filesize = null, $mtime = 0, $perms = 0 ) {
		$this->write( MoveKit_Archive::encode( $type, $path, $data, $offset, $filesize, $mtime, $perms ) );
		++$this->records;
	}

	/**
	 * Write the manifest, end record and tail. The archive is complete after this.
	 */
	public function finish( array $manifest ) {
		$manifest_offset = $this->size();
		$this->add( MoveKit_Archive::TYPE_MANIFEST, 'manifest.json', wp_json_encode( $manifest ) );
		$end_offset = $this->size();
		$end        = wp_json_encode(
			array(
				'records'         => $this->records + 1,
				'manifest_offset' => $manifest_offset,
			)
		);
		$this->write( MoveKit_Archive::encode( MoveKit_Archive::TYPE_END, '', $end ) );
		$this->write( pack( 'J', $end_offset ) . MoveKit_Archive::TAIL_MAGIC );
		fflush( $this->fh );
	}

	public function size() {
		fflush( $this->fh );
		$stat = fstat( $this->fh );
		return $stat['size'];
	}

	public function records() {
		return $this->records;
	}

	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
	}

	private function write( $bytes ) {
		$len     = strlen( $bytes );
		$written = 0;
		while ( $written < $len ) {
			$n = fwrite( $this->fh, 0 === $written ? $bytes : substr( $bytes, $written ) );
			if ( false === $n || 0 === $n ) {
				throw new MoveKit_Exception( esc_html__( 'Writing the backup failed. The disk may be full.', 'movekit-migration-backup' ) );
			}
			$written += $n;
		}
	}
}
