<?php
/**
 * WP-CLI commands: back up, restore and check sites from the terminal.
 *
 * WP-CLI passes positional and associative arguments to every command,
 * whether it uses them or not.
 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

class MoveKit_CLI {

	/**
	 * Create a backup of this site.
	 *
	 * ## OPTIONS
	 *
	 * [--only=<parts>]
	 * : Comma-separated parts to include: database, media, plugins, themes, other. Default: everything.
	 *
	 * [--exclude=<patterns>]
	 * : Comma-separated files or folders to skip, such as "*.zip,uploads/2019". Default: the ones saved on the MoveKit screen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp movekit export
	 *     wp movekit export --only=database,media
	 *     wp movekit export --exclude="*.zip,uploads/videos"
	 */
	public function export( $args, $assoc ) {
		$this->drive(
			$this->start(
				function () use ( $assoc ) {
					MoveKit_Job::assert_idle( false );
					return MoveKit_Export_Job::start( $this->parts( $assoc ), $this->exclude( $assoc ) );
				}
			)
		);
	}

	/**
	 * Restore a backup over this site.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a .movekit file, or the name of one in the backups folder.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public function import( $args, $assoc ) {
		$file  = $this->resolve( $args[0] );
		$check = MoveKit_Import_Job::preflight( $file );
		foreach ( $check['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		WP_CLI::confirm( 'This replaces the database and files of ' . home_url() . '. You can undo it for a day with `wp movekit undo`. Continue?', $assoc );
		$this->drive(
			$this->start(
				function () use ( $file ) {
					MoveKit_Job::assert_idle( true );
					return MoveKit_Import_Job::start( $file );
				}
			)
		);
	}

	/**
	 * Undo the last restore, push received or pull, within a day of it.
	 *
	 * Brings back the database tables and files that the restore replaced.
	 * Anything changed on the site since the restore is lost.
	 *
	 * [--discard]
	 * : Keep the restore instead, and free the space the old copy uses.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public function undo( $args, $assoc ) {
		$info = MoveKit_Undo::info();
		if ( ! $info ) {
			WP_CLI::error( 'There is no restore to undo. Undo is available for a day after a restore.' );
		}
		if ( ! empty( $assoc['discard'] ) ) {
			MoveKit_Undo::discard();
			WP_CLI::success( 'Kept the restore and removed the old copy.' );
			return;
		}
		WP_CLI::confirm( 'This puts back the site as it was before the restore from ' . $info['from'] . ' on ' . wp_date( 'Y-m-d H:i', $info['created'] ) . '. Changes made since then are lost. Continue?', $assoc );
		try {
			MoveKit_Job::assert_idle( true );
			$result = MoveKit_Undo::run();
		} catch ( MoveKit_Exception $e ) {
			WP_CLI::error( $e->text() );
		}
		if ( $result['failed'] ) {
			WP_CLI::warning( $result['failed'] . ' files could not be put back.' );
		}
		WP_CLI::success( sprintf( 'Restore undone: %d tables and %d files put back.', $result['tables'], $result['files'] ) );
	}

	/**
	 * Check a backup file's checksums without restoring it.
	 *
	 * <file>
	 * : Path to a .movekit file, or the name of one in the backups folder.
	 */
	public function verify( $args, $assoc ) {
		$reader  = new MoveKit_Archive_Reader( $this->resolve( $args[0] ) );
		$summary = $reader->summary();
		$m       = $reader->manifest();
		$pos     = 8;
		$count   = 0;
		$bar     = WP_CLI\Utils\make_progress_bar( 'Verifying', $summary['end_offset'] );
		while ( $pos < $summary['end_offset'] ) {
			$rec = $reader->read( $pos );
			$bar->tick( $rec['next'] - $pos );
			$pos = $rec['next'];
			++$count;
		}
		$bar->finish();
		if ( $count + 1 !== (int) $summary['records'] ) {
			WP_CLI::error( 'Record count does not match the index.' );
		}
		WP_CLI::success( sprintf( 'Backup is intact: %s, %d tables, %d files, made %s.', $m['site']['home'], count( $m['database']['tables'] ), $m['files']['count'], $m['created'] ) );
	}

	/**
	 * List backups in the backups folder.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array_map(
			function ( $b ) {
				return array(
					'name' => $b['name'],
					'size' => size_format( $b['size'], 1 ),
					'date' => wp_date( 'Y-m-d H:i', $b['mtime'] ),
				);
			},
			MoveKit_Storage::list_backups()
		);
		WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'size', 'date' ) );
	}

	/**
	 * Show this site's connection key, for pasting into another site.
	 *
	 * [--regenerate]
	 * : Make a new key. Sites using the old key are disconnected.
	 */
	public function key( $args, $assoc ) {
		if ( ! empty( $assoc['regenerate'] ) ) {
			MoveKit_Connection::regenerate();
		}
		WP_CLI::log( MoveKit_Connection::key() );
	}

	/**
	 * Connect to another site using its connection key.
	 *
	 * <key>
	 * : The key shown on the other site's MoveKit screen or by `wp movekit key`.
	 */
	public function connect( $args, $assoc ) {
		try {
			$remote = MoveKit_Connection::parse_key( $args[0] );
			MoveKit_Connection::check_reachable( $remote );
			$info          = ( new MoveKit_Remote_Client( $remote ) )->info();
			$remote['url'] = MoveKit_Connection::clean_url( $info['url'] );
			MoveKit_Connection::add_remote( $remote );
		} catch ( MoveKit_Exception $e ) {
			WP_CLI::error( $e->text() );
		}
		WP_CLI::success( 'Connected to ' . $remote['url'] . ' (MoveKit ' . $info['plugin'] . ').' );
	}

	/**
	 * List connected sites.
	 *
	 * @subcommand sites
	 */
	public function sites( $args, $assoc ) {
		WP_CLI\Utils\format_items( 'table', MoveKit_Connection::remotes(), array( 'id', 'url' ) );
	}

	/**
	 * Replace a connected site with this one.
	 *
	 * <site>
	 * : Connected site id or address.
	 *
	 * [--only=<parts>]
	 * : Comma-separated parts to send: database, media, plugins, themes, other. Default: everything.
	 *
	 * [--exclude=<patterns>]
	 * : Comma-separated files or folders to skip, such as "*.zip,uploads/2019". Default: the ones saved on the MoveKit screen.
	 *
	 * [--all-files]
	 * : Send every file, even the ones that are already the same on both sites.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public function push( $args, $assoc ) {
		$remote = $this->remote( $args[0] );
		$parts  = $this->parts( $assoc );
		WP_CLI::confirm( 'This replaces the ' . strtolower( MoveKit_Parts::describe( $parts ) ) . ' of ' . $remote['url'] . ' with this site\'s. Continue?', $assoc );
		$this->drive(
			$this->start(
				function () use ( $remote, $parts, $assoc ) {
					return MoveKit_Transfer_Job::start( $remote['id'], 'push', $parts, $this->exclude( $assoc ), empty( $assoc['all-files'] ) );
				}
			)
		);
	}

	/**
	 * Replace this site with a connected site.
	 *
	 * <site>
	 * : Connected site id or address.
	 *
	 * [--only=<parts>]
	 * : Comma-separated parts to fetch: database, media, plugins, themes, other. Default: everything.
	 *
	 * [--exclude=<patterns>]
	 * : Comma-separated files or folders to skip, such as "*.zip,uploads/2019". Default: the ones saved on the MoveKit screen.
	 *
	 * [--all-files]
	 * : Send every file, even the ones that are already the same on both sites.
	 *
	 * [--skip-remote-media]
	 * : When media is left out, don't show it from the other site.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public function pull( $args, $assoc ) {
		$remote = $this->remote( $args[0] );
		$parts  = $this->parts( $assoc );
		WP_CLI::confirm( 'This replaces the ' . strtolower( MoveKit_Parts::describe( $parts ) ) . ' of ' . home_url() . ' with ' . $remote['url'] . '\'s. Continue?', $assoc );
		$this->drive(
			$this->start(
				function () use ( $remote, $parts, $assoc ) {
					return MoveKit_Transfer_Job::start( $remote['id'], 'pull', $parts, $this->exclude( $assoc ), empty( $assoc['all-files'] ), empty( $assoc['skip-remote-media'] ) );
				}
			)
		);
	}

	/**
	 * Continue a push or pull that was paused by a lost connection.
	 *
	 * <job>
	 * : Job id, as printed when the transfer paused.
	 */
	public function resume( $args, $assoc ) {
		try {
			$job = MoveKit_Job::load( $args[0] );
			if ( ! $job instanceof MoveKit_Transfer_Job ) {
				WP_CLI::error( 'Only a push or pull can be resumed.' );
			}
			$job->resume();
		} catch ( MoveKit_Exception $e ) {
			WP_CLI::error( $e->text() );
		}
		$this->drive( $job );
	}

	/**
	 * Abandon a paused push or pull and remove its unfinished work from both sites.
	 *
	 * <job>
	 * : Job id, as printed when the transfer paused.
	 */
	public function discard( $args, $assoc ) {
		try {
			$warnings = MoveKit_Job::load( $args[0] )->discard();
		} catch ( MoveKit_Exception $e ) {
			WP_CLI::error( $e->text() );
		}
		foreach ( $warnings as $warning ) {
			WP_CLI::warning( $warning );
		}
		WP_CLI::success( 'Cleaned up.' );
	}

	/**
	 * Start a job, turning a refusal into a WP-CLI error.
	 *
	 * @param callable $start Returns the job.
	 * @return MoveKit_Job
	 */
	private function start( callable $start ) {
		try {
			return $start();
		} catch ( MoveKit_Exception $e ) {
			WP_CLI::error( $e->text() );
		}
	}

	/**
	 * Patterns from --exclude, or null for the saved ones.
	 *
	 * @param array $assoc Associative arguments.
	 * @return string[]|null
	 */
	private function exclude( $assoc ) {
		return isset( $assoc['exclude'] ) ? MoveKit_File_Scanner::sanitize_patterns( $assoc['exclude'] ) : null;
	}

	/**
	 * Parts from --only, or everything.
	 *
	 * @param array $assoc Associative arguments.
	 * @return string[]
	 */
	private function parts( $assoc ) {
		try {
			return MoveKit_Parts::sanitize( isset( $assoc['only'] ) ? $assoc['only'] : null );
		} catch ( MoveKit_Exception $e ) {
			WP_CLI::error( $e->text() );
		}
	}

	/**
	 * Find a connected site by id or address.
	 *
	 * @param string $which Id or address.
	 */
	private function remote( $which ) {
		foreach ( MoveKit_Connection::remotes() as $r ) {
			if ( $r['id'] === $which || untrailingslashit( $r['url'] ) === untrailingslashit( $which ) ) {
				return $r;
			}
		}
		WP_CLI::error( 'Not a connected site: ' . $which . '. Run `wp movekit sites` to list them.' );
	}

	private function resolve( $file ) {
		if ( is_file( $file ) ) {
			return realpath( $file );
		}
		try {
			$path = MoveKit_Storage::backup_path( $file );
		} catch ( MoveKit_Exception $e ) {
			WP_CLI::error( 'Backup not found: ' . $file );
		}
		if ( ! is_file( $path ) ) {
			WP_CLI::error( 'Backup not found: ' . $file );
		}
		return $path;
	}

	private function drive( MoveKit_Job $job ) {
		$last = '';
		do {
			$status = $job->step();
			$line   = sprintf( '%3d%%  %s', $status['progress'], $status['message'] );
			if ( $line !== $last ) {
				WP_CLI::log( $line );
				$last = $line;
			}
		} while ( 'running' === $status['status'] );

		foreach ( $status['log'] as $entry ) {
			WP_CLI::warning( $entry );
		}
		if ( ! empty( $status['resumable'] ) ) {
			WP_CLI::error( $status['message'] . "\nRun `wp movekit resume {$job->id()}` to continue, or `wp movekit discard {$job->id()}` to clean up." );
		}
		$job->cleanup();
		if ( 'error' === $status['status'] ) {
			WP_CLI::error( $status['message'] );
		}
		foreach ( (array) $status['result'] as $k => $v ) {
			if ( ( 'size' === $k || 'unchanged_bytes' === $k ) && is_int( $v ) ) {
				$v = size_format( $v, 1 );
			} elseif ( 'parts' === $k ) {
				$v = MoveKit_Parts::describe( (array) $v );
			} elseif ( 'undo' === $k ) {
				if ( ! is_array( $v ) ) {
					continue;
				}
				$v = 'wp movekit undo until ' . wp_date( 'Y-m-d H:i', $v['until'] );
			} elseif ( is_bool( $v ) ) {
				$v = $v ? 'yes' : 'no';
			}
			WP_CLI::log( sprintf( '  %-8s %s', $k . ':', $v ) );
		}
		WP_CLI::success( $status['message'] );
	}
}
