<?php
/**
 * Keeps what the last restore replaced, so it can be undone for a day.
 *
 * The replaced database tables stay as _mvo tables, and replaced files are
 * moved into a holding folder instead of being overwritten. If putting the
 * restored files in place fails half way, the same record puts the old
 * files back, so a failed restore never leaves a mix of old and new files.
 *
 * Files are moved with plain PHP file functions: WP_Filesystem cannot
 * rename within the server's own disk without copying.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * The undo point of the most recent restore.
 */
class MoveKit_Undo {

	/**
	 * WP-Cron hook that removes expired undo copies and stale job leftovers,
	 * so they go away even when nobody opens MoveKit on that site again.
	 */
	const TIDY_HOOK = 'movekit_tidy';

	/**
	 * Run the clean-up a little after $when, unless one is already due sooner.
	 *
	 * @param int $when Unix time.
	 */
	public static function schedule_tidy( $when ) {
		$next = wp_next_scheduled( self::TIDY_HOOK );
		if ( $next && $next <= $when + 60 ) {
			return;
		}
		if ( $next ) {
			wp_clear_scheduled_hook( self::TIDY_HOOK );
		}
		wp_schedule_single_event( $when + 60, self::TIDY_HOOK );
	}

	/**
	 * The scheduled clean-up.
	 */
	public static function tidy() {
		MoveKit_Job::purge_stale();
		self::expire();
		MoveKit_Job::drop_orphan_tables();
		// Something may still be waiting for its day to pass.
		$dir = self::dir();
		if ( null !== $dir ) {
			$info = self::info();
			self::schedule_tidy( $info ? $info['until'] : time() + HOUR_IN_SECONDS );
		} elseif ( glob( MoveKit_Storage::jobs_dir() . '/*', GLOB_ONLYDIR ) ) {
			self::schedule_tidy( time() + DAY_IN_SECONDS );
		}
	}

	/**
	 * Holding folder, with a random name so its files cannot be fetched on
	 * servers that ignore .htaccess.
	 *
	 * @param bool $create Create it when missing.
	 * @return string|null Absolute path, or null when there is none.
	 */
	public static function dir( $create = false ) {
		$found = glob( MoveKit_Storage::dir() . '/undo-*', GLOB_ONLYDIR );
		if ( $found ) {
			return str_replace( '\\', '/', $found[0] );
		}
		if ( ! $create ) {
			return null;
		}
		$dir = MoveKit_Storage::dir() . '/undo-' . strtolower( wp_generate_password( 20, false ) );
		if ( ! wp_mkdir_p( $dir . '/old' ) ) {
			/* translators: %s: folder path. */
			throw new MoveKit_Exception( esc_html( sprintf( __( 'Could not create folder %s. Check that wp-content is writable.', 'movekit-migration-backup' ), $dir ) ) );
		}
		return $dir;
	}

	/**
	 * The current undo point, if it has not expired.
	 *
	 * @return array|null created, until, from, tables, files.
	 */
	public static function info() {
		$dir = self::dir();
		if ( null === $dir || ! is_file( $dir . '/info.json' ) ) {
			return null;
		}
		$info = json_decode( (string) file_get_contents( $dir . '/info.json' ), true );
		if ( ! is_array( $info ) || $info['until'] < time() ) {
			return null;
		}
		return array(
			'created' => $info['created'],
			'until'   => $info['until'],
			'from'    => $info['from'],
			'tables'  => ! empty( $info['old_tables'] ) || ! empty( $info['new_tables'] ),
			'files'   => is_file( $dir . '/placed.txt' ),
		);
	}

	/**
	 * Keep the restore as it is: drop the old tables and delete the old files.
	 */
	public static function discard() {
		$dir = self::dir();
		if ( null === $dir ) {
			return;
		}
		$info = is_file( $dir . '/info.json' ) ? json_decode( (string) file_get_contents( $dir . '/info.json' ), true ) : null;
		if ( is_array( $info ) && ! empty( $info['old_tables'] ) ) {
			MoveKit_DB::session_setup();
			foreach ( $info['old_tables'] as $final ) {
				try {
					MoveKit_DB::query( 'DROP TABLE IF EXISTS ' . MoveKit_DB::ident( MoveKit_Import_Job::OLD_PREFIX . $final ) );
				} catch ( MoveKit_Exception $e ) {
					continue; // A leftover copy is harmless.
				}
			}
		}
		MoveKit_Storage::rrmdir( $dir );
	}

	/**
	 * Discard the undo point once its day is over.
	 */
	public static function expire() {
		$dir = self::dir();
		if ( null === $dir ) {
			return;
		}
		$info = is_file( $dir . '/info.json' ) ? json_decode( (string) file_get_contents( $dir . '/info.json' ), true ) : null;
		$age  = is_array( $info ) ? $info['until'] : filemtime( $dir ) + DAY_IN_SECONDS;
		if ( $age < time() ) {
			self::discard();
		}
	}

	/**
	 * Record what the restore did, once it has switched over.
	 *
	 * @param array $info from, old_tables, new_tables, old_fks.
	 */
	public static function save( array $info ) {
		$dir = self::dir( true );
		$now = time();
		/**
		 * Filters how long a restore can be undone, in seconds.
		 *
		 * @param int $seconds Default one day.
		 */
		$keep = (int) apply_filters( 'movekit_undo_period', DAY_IN_SECONDS );
		$info = array_merge(
			$info,
			array(
				'created' => $now,
				'until'   => $now + max( 0, $keep ),
			)
		);
		if ( false === file_put_contents( $dir . '/info.json', wp_json_encode( $info ) ) ) {
			throw new MoveKit_Exception( esc_html__( 'Could not save the undo information. The disk may be full.', 'movekit-migration-backup' ) );
		}
	}

	/**
	 * Note a change to a file before making it, so it can be reversed.
	 *
	 * @param string $type R (replaced; the old file is in old/), N (new file) or M (new folder).
	 * @param string $rel  Path relative to wp-content.
	 */
	public static function note( $type, $rel ) {
		$dir = self::dir( true );
		if ( false === file_put_contents( $dir . '/placed.txt', $type . "\t" . $rel . "\n", FILE_APPEND ) ) {
			throw new MoveKit_Exception( esc_html__( 'Could not record restored files. The disk may be full.', 'movekit-migration-backup' ) );
		}
	}

	/**
	 * Move a file, or copy it when moving is refused. Some hosts (WP Engine)
	 * let PHP change a file's contents but not rename or remove it, and a
	 * move between two disks always fails.
	 *
	 * @param string $from Source.
	 * @param string $to   Destination; replaced if it exists.
	 * @return bool
	 */
	public static function move( $from, $to ) {
		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Falls back below.
			return true;
		}
		if ( ! is_file( $from ) || ! @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The caller reports the failure.
			return false;
		}
		// Keep the date, so the next push or pull sees the file as unchanged.
		@touch( $to, (int) filemtime( $from ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Keeping the date is optional.
		@unlink( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May be refused; the copy is what counts.
		return true;
	}

	/**
	 * Where a replaced file is kept.
	 *
	 * @param string $rel Path relative to wp-content.
	 */
	public static function old_path( $rel ) {
		// Not under its own name: some hosts (WP Engine) refuse new .php files here.
		return self::dir( true ) . '/old/' . $rel . '.movekit-old';
	}

	/**
	 * Put back the files a restore replaced, and remove the ones it added.
	 *
	 * @param string $content_dir wp-content folder.
	 * @return int Files that could not be put back.
	 */
	public static function rollback_files( $content_dir ) {
		$dir = self::dir();
		if ( null === $dir || ! is_file( $dir . '/placed.txt' ) ) {
			return 0;
		}
		$lines  = file( $dir . '/placed.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$failed = 0;
		// Newest first, so a file replaced twice by a retried attempt ends up as the original.
		foreach ( array_reverse( (array) $lines ) as $line ) {
			$parts = explode( "\t", $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			list( $type, $rel ) = $parts;
			$path               = $content_dir . '/' . $rel;
			if ( 'N' === $type ) {
				if ( is_file( $path ) || is_link( $path ) ) {
					wp_delete_file( $path );
				}
			} elseif ( 'M' === $type ) {
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Only removes it when empty.
			} elseif ( 'R' === $type ) {
				$old = $dir . '/old/' . $rel . '.movekit-old';
				if ( ! file_exists( $old ) ) {
					continue;
				}
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
				if ( ! is_dir( dirname( $path ) ) ) {
					wp_mkdir_p( dirname( $path ) );
				}
				if ( ! self::move( $old, $path ) ) {
					++$failed;
				}
			}
		}
		wp_delete_file( $dir . '/placed.txt' );
		return $failed;
	}

	/**
	 * Undo the last restore: bring back the old tables and files.
	 *
	 * @return array tables, files (counts), failed (files not put back).
	 * @throws MoveKit_Exception When there is nothing to undo or the database refuses.
	 */
	public static function run() {
		global $wpdb;
		$dir  = self::dir();
		$info = null === $dir || ! is_file( $dir . '/info.json' ) ? null : json_decode( (string) file_get_contents( $dir . '/info.json' ), true );
		if ( ! is_array( $info ) || $info['until'] < time() ) {
			throw new MoveKit_Exception( esc_html__( 'There is no restore to undo. Undo is available for a day after a restore.', 'movekit-migration-backup' ) );
		}

		$tables = 0;
		if ( ! empty( $info['old_tables'] ) || ! empty( $info['new_tables'] ) ) {
			MoveKit_DB::session_setup();
			$renames = array();
			$discard = array();
			foreach ( array_merge( $info['old_tables'], $info['new_tables'] ) as $final ) {
				$gone      = MoveKit_Import_Job::GONE_PREFIX . $final;
				$renames[] = MoveKit_DB::ident( $final ) . ' TO ' . MoveKit_DB::ident( $gone );
				$discard[] = $gone;
			}
			foreach ( $info['old_tables'] as $final ) {
				$renames[] = MoveKit_DB::ident( MoveKit_Import_Job::OLD_PREFIX . $final ) . ' TO ' . MoveKit_DB::ident( $final );
			}
			MoveKit_DB::query( 'RENAME TABLE ' . implode( ', ', $renames ) );
			foreach ( $discard as $gone ) {
				MoveKit_DB::query( 'DROP TABLE IF EXISTS ' . MoveKit_DB::ident( $gone ) );
			}
			foreach ( (array) $info['old_fks'] as $fk ) {
				try {
					MoveKit_DB::query( 'ALTER TABLE ' . MoveKit_DB::ident( $fk['table'] ) . ' ADD CONSTRAINT ' . MoveKit_DB::ident( $fk['name'] ) . ' ' . $fk['definition'] );
				} catch ( MoveKit_Exception $e ) {
					continue; // The data is back; only the constraint is missing.
				}
			}
			$tables = count( $info['old_tables'] );
			MoveKit_Import_Job::keep_plugin_active( $wpdb->prefix );
		}

		$files  = is_file( $dir . '/placed.txt' ) ? count( (array) file( $dir . '/placed.txt', FILE_SKIP_EMPTY_LINES ) ) : 0;
		$failed = self::rollback_files( untrailingslashit( str_replace( '\\', '/', WP_CONTENT_DIR ) ) );
		MoveKit_Storage::rrmdir( $dir );
		MoveKit_Cache::after_restore( $wpdb->prefix );
		return array(
			'tables' => $tables,
			'files'  => $files,
			'failed' => $failed,
		);
	}
}
