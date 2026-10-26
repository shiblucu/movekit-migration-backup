<?php
/**
 * Creates a full-site backup: database plus everything in wp-content.
 *
 * Backups are read and written in pieces with plain PHP file functions:
 * WP_Filesystem has no seek/append/stream operations and would need whole
 * multi-GB files in memory.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

class MoveKit_Export_Job extends MoveKit_Job {

	/**
	 * @param string[]|null        $parts   What to include; null for the whole site.
	 * @param string|string[]|null $exclude Patterns of files to skip; null for the ones saved on the MoveKit screen.
	 * @param string               $known   Fingerprints of files the receiving site already has, which are left out.
	 */
	public static function start( $parts = null, $exclude = null, $known = '' ) {
		if ( is_multisite() ) {
			throw new MoveKit_Exception( esc_html__( 'Multisite networks are not supported yet.', 'movekit-migration-backup' ) );
		}
		$name = MoveKit_Storage::new_backup_name();
		$job  = self::create(
			array(
				'parts'        => MoveKit_Parts::sanitize( $parts ),
				'exclude'      => null === $exclude ? MoveKit_File_Scanner::saved_patterns() : MoveKit_File_Scanner::sanitize_patterns( $exclude ),
				'name'         => $name,
				'archive'      => MoveKit_Storage::dir() . '/' . $name,
				'archive_size' => null,
				'records'      => 0,
			)
		);
		if ( '' !== $known && false === file_put_contents( $job->dir() . '/known.bin', $known ) ) {
			$job->discard( true );
			throw new MoveKit_Exception( esc_html__( 'Could not save the list of files to compare.', 'movekit-migration-backup' ) );
		}
		return $job;
	}

	protected function phases() {
		return array( 'prepare', 'database', 'archive_database', 'files', 'finish' );
	}

	protected function progress() {
		$s = $this->state;
		switch ( $this->phases()[ $s['phase'] ] ) {
			case 'prepare':
				return 0;
			case 'database':
				return 2 + 28 * $s['db']['ti'] / max( 1, count( $s['db']['tables'] ) );
			case 'archive_database':
				return 30 + 5 * $s['db_pos'] / max( 1, $s['db_size'] );
			case 'files':
				return 35 + 64 * $s['bytes_done'] / max( 1, $s['manifest']['files']['bytes'] );
			default:
				return 99;
		}
	}

	protected function run_prepare( $deadline ) {
		global $wpdb, $wp_version;
		$this->message( __( 'Scanning files', 'movekit-migration-backup' ) );
		$parts   = $this->parts();
		$with_db = in_array( 'database', $parts, true );
		$tables  = $with_db ? MoveKit_DB::tables( $wpdb->prefix ) : array();
		if ( $with_db && ! $tables ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: detail. */ __( 'No database tables found for prefix %s', 'movekit-migration-backup' ), $wpdb->prefix ) ) );
		}
		$known = is_file( $this->dir() . '/known.bin' ) ? (string) file_get_contents( $this->dir() . '/known.bin' ) : '';
		$scan  = MoveKit_File_Scanner::scan( $this->dir() . '/files.txt', $parts, isset( $this->state['exclude'] ) ? $this->state['exclude'] : array(), $known );
		unset( $known );
		wp_delete_file( $this->dir() . '/known.bin' );
		foreach ( $scan['skipped'] as $path ) {
			/* translators: %s: path relative to wp-content. */
			$this->log( sprintf( __( 'Skipped a file or folder that could not be read: %s', 'movekit-migration-backup' ), $path ) );
		}
		foreach ( $scan['links'] as $path ) {
			/* translators: %s: path relative to wp-content. */
			$this->log( sprintf( __( 'Included %s, a symlinked folder, as a normal folder.', 'movekit-migration-backup' ), $path ) );
		}

		$this->state['manifest']   = array(
			'format'         => MoveKit_Archive::FORMAT,
			'plugin_version' => MOVEKIT_VERSION,
			'created'        => gmdate( 'c' ),
			'parts'          => $parts,
			'site'           => array(
				'home'         => untrailingslashit( get_option( 'home' ) ),
				'siteurl'      => untrailingslashit( get_option( 'siteurl' ) ),
				'abspath'      => untrailingslashit( str_replace( '\\', '/', ABSPATH ) ),
				'content_dir'  => untrailingslashit( str_replace( '\\', '/', WP_CONTENT_DIR ) ),
				'content_url'  => untrailingslashit( content_url() ),
				'uploads_dir'  => untrailingslashit( str_replace( '\\', '/', wp_upload_dir( null, false )['basedir'] ) ),
				'table_prefix' => $wpdb->prefix,
				'wp_version'   => $wp_version,
				'php_version'  => PHP_VERSION,
				'db_version'   => MoveKit_DB::value( 'SELECT VERSION()' ),
				'multisite'    => is_multisite(),
				'template'     => get_option( 'template' ),
				'stylesheet'   => get_option( 'stylesheet' ),
				'plugins'      => array_values( (array) get_option( 'active_plugins', array() ) ),
			),
			'database'       => array( 'tables' => $tables ),
			'files'          => array(
				'count'     => $scan['files'],
				'bytes'     => $scan['bytes'],
				'unchanged' => $scan['unchanged'],
			),
		);
		$this->state['db']         = $with_db
			? MoveKit_DB_Exporter::init( $this->dir() . '/database.sql', $tables )
			: array(
				'ti'     => 0,
				'tables' => array(),
			);
		$this->state['unchanged']  = array( $scan['unchanged'], $scan['unchanged_bytes'] );
		$this->state['db_pos']     = 0;
		$this->state['db_size']    = 0;
		$this->state['list_pos']   = 0;
		$this->state['file_pos']   = 0;
		$this->state['file_size']  = null;
		$this->state['bytes_done'] = 0;
		$this->message( $with_db ? __( 'Exporting database', 'movekit-migration-backup' ) : __( 'Adding files to backup', 'movekit-migration-backup' ) );
		return true;
	}

	protected function run_database( $deadline ) {
		if ( ! $this->has_database() ) {
			return true;
		}
		$done = MoveKit_DB_Exporter::run( $this->state['db'], $deadline );
		if ( $done ) {
			$this->state['manifest']['database']['rows'] = $this->state['db']['rows'];
			$this->state['db_size']                      = filesize( $this->state['db']['sql_file'] );
			$this->message( __( 'Adding database to backup', 'movekit-migration-backup' ) );
		}
		return $done;
	}

	protected function run_archive_database( $deadline ) {
		if ( ! $this->has_database() ) {
			return true;
		}
		$writer = $this->writer();
		$fh     = fopen( $this->state['db']['sql_file'], 'rb' );
		fseek( $fh, $this->state['db_pos'] );
		$size = $this->state['db_size'];
		do {
			$data = (string) fread( $fh, MoveKit_Archive::CHUNK );
			$writer->add( MoveKit_Archive::TYPE_DB, 'database.sql', $data, $this->state['db_pos'], $size );
			$this->state['db_pos'] += strlen( $data );
			$this->commit( $writer );
		} while ( $this->state['db_pos'] < $size && microtime( true ) < $deadline );
		fclose( $fh );
		$writer->close();

		if ( $this->state['db_pos'] >= $size ) {
			@unlink( $this->state['db']['sql_file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A leftover temporary file is harmless.
			$this->message( __( 'Adding files to backup', 'movekit-migration-backup' ) );
			return true;
		}
		return false;
	}

	protected function run_files( $deadline ) {
		$writer  = $this->writer();
		$list_fh = fopen( $this->dir() . '/files.txt', 'rb' );
		$root    = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		fseek( $list_fh, $this->state['list_pos'] );

		while ( microtime( true ) < $deadline ) {
			$line = fgets( $list_fh );
			if ( false === $line ) {
				break;
			}
			list( $rel, , $mtime, $perms, $type ) = explode( "\t", rtrim( $line, "\n" ) );

			if ( 'D' === $type ) {
				$writer->add( MoveKit_Archive::TYPE_DIR, $rel, '', 0, 0, 0, (int) $perms );
				$this->next_file( $list_fh, $writer );
				continue;
			}

			$path = $root . '/' . $rel;
			$fh   = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
			if ( ! $fh ) {
				$this->log( sprintf( /* translators: %s: detail. */ __( 'Skipped file that disappeared or became unreadable: %s', 'movekit-migration-backup' ), $rel ) );
				$this->next_file( $list_fh, $writer );
				continue;
			}
			if ( null === $this->state['file_size'] ) {
				// Use the size at the moment we start, in case the file changed since the scan.
				$this->state['file_size'] = filesize( $path );
			}
			$size = $this->state['file_size'];
			fseek( $fh, $this->state['file_pos'] );

			do {
				$want = min( MoveKit_Archive::CHUNK, $size - $this->state['file_pos'] );
				$data = $want > 0 ? (string) fread( $fh, $want ) : '';
				if ( strlen( $data ) < $want ) {
					// The file shrank while being read; pad so the archive stays consistent.
					$this->log( sprintf( /* translators: %s: detail. */ __( 'File changed while being backed up: %s', 'movekit-migration-backup' ), $rel ) );
					$data .= str_repeat( "\0", $want - strlen( $data ) );
				}
				$writer->add( MoveKit_Archive::TYPE_FILE, $rel, $data, $this->state['file_pos'], $size, (int) $mtime, (int) $perms );
				$this->state['file_pos']   += strlen( $data );
				$this->state['bytes_done'] += strlen( $data );
				$this->commit( $writer );
			} while ( $this->state['file_pos'] < $size && microtime( true ) < $deadline );
			fclose( $fh );

			if ( $this->state['file_pos'] < $size ) {
				break; // Out of time mid-file; resume from file_pos next step.
			}
			$this->next_file( $list_fh, $writer );
		}

		$done = false === fgets( $list_fh ) && feof( $list_fh );
		fclose( $list_fh );
		$writer->close();
		if ( $done ) {
			$this->message( __( 'Finishing backup', 'movekit-migration-backup' ) );
		}
		return $done;
	}

	protected function run_finish( $deadline ) {
		$writer                             = $this->writer();
		$this->state['manifest']['records'] = $writer->records();
		$writer->finish( $this->state['manifest'] );
		$writer->close();

		// Prove the archive reads back before calling it done.
		$reader  = new MoveKit_Archive_Reader( $this->part() );
		$summary = $reader->summary();
		$reader->close();

		if ( ! @rename( $this->part(), $this->state['archive'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
			throw new MoveKit_Exception( esc_html__( 'Could not finalize the backup file.', 'movekit-migration-backup' ) );
		}
		MoveKit_Storage::make_private( $this->state['archive'] );
		@unlink( $this->dir() . '/files.txt' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A leftover temporary file is harmless.
		$this->state['result'] = array(
			'name'    => $this->state['name'],
			'size'    => filesize( $this->state['archive'] ),
			'records' => $summary['records'],
			'tables'  => count( $this->state['manifest']['database']['tables'] ),
			'files'   => $this->state['manifest']['files']['count'],
			'parts'   => $this->parts(),
		);
		if ( ! empty( $this->state['unchanged'][0] ) ) {
			$this->state['result']['unchanged']       = $this->state['unchanged'][0];
			$this->state['result']['unchanged_bytes'] = $this->state['unchanged'][1];
		}
		$this->message( __( 'Backup complete', 'movekit-migration-backup' ) );
		return true;
	}

	protected function on_fail() {
		@unlink( $this->part() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A leftover temporary file is harmless.
	}

	/**
	 * What this backup includes. Jobs started before 0.6.0 include everything.
	 *
	 * @return string[]
	 */
	private function parts() {
		return isset( $this->state['parts'] ) ? $this->state['parts'] : MoveKit_Parts::ALL;
	}

	private function has_database() {
		return in_array( 'database', $this->parts(), true );
	}

	private function part() {
		return $this->state['archive'] . '.part';
	}

	private function writer() {
		return new MoveKit_Archive_Writer( $this->part(), $this->state['archive_size'], $this->state['records'] );
	}

	/**
	 * Remember the archive size after a complete record, so a resumed step
	 * can cut off anything written after it.
	 */
	private function commit( MoveKit_Archive_Writer $writer ) {
		$this->state['archive_size'] = $writer->size();
		$this->state['records']      = $writer->records();
	}

	private function next_file( $list_fh, MoveKit_Archive_Writer $writer ) {
		$this->state['list_pos']  = ftell( $list_fh );
		$this->state['file_pos']  = 0;
		$this->state['file_size'] = null;
		$this->commit( $writer );
	}
}
