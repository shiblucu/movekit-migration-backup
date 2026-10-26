<?php
/**
 * A long-running export or import, done in short steps.
 *
 * Each call to step() works until a time budget runs out and saves its
 * progress, so big sites never hit PHP's max_execution_time and a failed
 * request can simply be retried. State is kept in a JSON file in the job's
 * own folder, guarded by a lock so two steps never run at once.
 *
 * Backups are read and written in pieces with plain PHP file functions:
 * WP_Filesystem has no seek/append/stream operations and would need whole
 * multi-GB files in memory.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

abstract class MoveKit_Job {

	protected $id;
	protected $state;

	/**
	 * Phases in order; each maps to a method run_<phase>( $deadline ) that
	 * returns true when the phase is complete.
	 */
	abstract protected function phases();

	/**
	 * Overall percentage from the current state.
	 */
	abstract protected function progress();

	public static function create( array $data ) {
		MoveKit_Storage::ensure();
		self::purge_stale();
		MoveKit_Undo::expire();
		MoveKit_Undo::schedule_tidy( time() + DAY_IN_SECONDS );
		$id  = bin2hex( random_bytes( 8 ) );
		$dir = MoveKit_Storage::jobs_dir() . '/' . $id;
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: detail. */ __( 'Could not create a working folder in %s', 'movekit-migration-backup' ), MoveKit_Storage::jobs_dir() ) ) );
		}
		$job        = new static();
		$job->id    = $id;
		$job->state = array_merge(
			array(
				'class'   => get_class( $job ),
				'token'   => bin2hex( random_bytes( 16 ) ),
				'status'  => 'running',
				'phase'   => 0,
				'message' => '',
				'created' => time(),
				'log'     => array(),
			),
			$data
		);
		$job->save();
		return $job;
	}

	/**
	 * @return MoveKit_Job
	 */
	public static function load( $id ) {
		if ( ! preg_match( '/^[a-f0-9]{16}$/', (string) $id ) ) {
			throw new MoveKit_Exception( esc_html__( 'Invalid job.', 'movekit-migration-backup' ) );
		}
		$file = MoveKit_Storage::jobs_dir() . '/' . $id . '/state.json';
		$raw  = is_file( $file ) ? file_get_contents( $file ) : false;
		$data = $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || ! is_subclass_of( $data['class'], __CLASS__ ) ) {
			throw new MoveKit_Exception( esc_html__( 'This job no longer exists.', 'movekit-migration-backup' ) );
		}
		$job        = new $data['class']();
		$job->id    = $id;
		$job->state = $data;
		return $job;
	}

	public function id() {
		return $this->id;
	}

	public function token() {
		return $this->state['token'];
	}

	public function dir() {
		return MoveKit_Storage::jobs_dir() . '/' . $this->id;
	}

	/**
	 * Run for up to $budget seconds and report where the job stands.
	 */
	public function step( $budget = null ) {
		if ( 'running' !== $this->state['status'] ) {
			return $this->status();
		}
		$lock = @fopen( $this->dir() . '/lock', 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
		$held = 0;
		// When the lock file can't be opened, or the file system has no locks
		// (some network storage), the job runs unlocked rather than never.
		if ( $lock && ! flock( $lock, LOCK_EX | LOCK_NB, $held ) && $held ) {
			$status         = $this->status();
			$status['busy'] = true;
			return $status;
		}

		// Another request may have advanced the job while we waited for the lock.
		$fresh       = self::load( $this->id );
		$this->state = $fresh->state;

		$deadline = microtime( true ) + ( null === $budget ? self::default_budget() : $budget );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- Steps are time-boxed; this only stops a slow host killing one mid-write.
		}
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled on some hosts; the job resumes anyway.
		$phases = $this->phases();

		// A fatal error (out of memory, time limit) skips the catch below.
		// Record it, so the screen shows why instead of retrying forever.
		$reserve  = str_repeat( ' ', 256 * KB_IN_BYTES );
		$finished = false;
		register_shutdown_function(
			function () use ( &$finished, &$reserve, $lock ) {
				$reserve = null;
				if ( $finished ) {
					return;
				}
				$error = error_get_last();
				if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					return;
				}
				$this->fatal( $error['message'] );
				if ( $lock ) {
					flock( $lock, LOCK_UN );
				}
			}
		);

		try {
			$this->before_step();
			while ( 'running' === $this->state['status'] && microtime( true ) < $deadline ) {
				$phase = $phases[ $this->state['phase'] ];
				if ( $this->{'run_' . $phase}( $deadline ) ) {
					++$this->state['phase'];
					if ( $this->state['phase'] >= count( $phases ) ) {
						$this->state['status'] = 'done';
					}
				}
			}
		} catch ( Exception $e ) {
			$this->fail( $e );
		} catch ( Error $e ) {
			$this->fail( $e );
		}

		$this->save();
		$finished = true;
		$reserve  = null;
		if ( $lock ) {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
		return $this->status();
	}

	/**
	 * Hook for subclasses: runs at the start of every step.
	 */
	protected function before_step() {
	}

	/**
	 * Record a fatal PHP error that ended a step.
	 *
	 * @param string $message PHP's error message.
	 */
	private function fatal( $message ) {
		$step = $this->state['message'];
		if ( false !== stripos( $message, 'Allowed memory size' ) ) {
			$text = sprintf(
				/* translators: 1: what MoveKit was doing, 2: PHP memory limit such as 128M. */
				__( 'The server ran out of memory while "%1$s" (PHP memory_limit is %2$s). Ask your host to raise memory_limit to at least 256M, then try again.', 'movekit-migration-backup' ),
				$step,
				ini_get( 'memory_limit' )
			);
		} elseif ( false !== stripos( $message, 'Maximum execution time' ) ) {
			$text = sprintf(
				/* translators: %s: what MoveKit was doing. */
				__( 'The server stopped MoveKit for taking too long while "%s". Ask your host to raise max_execution_time, then try again.', 'movekit-migration-backup' ),
				$step
			);
		} else {
			$text = sprintf(
				/* translators: 1: what MoveKit was doing, 2: PHP error message. */
				__( 'A PHP error stopped MoveKit while "%1$s": %2$s', 'movekit-migration-backup' ),
				$step,
				$message
			);
		}
		try {
			$this->fail( new MoveKit_Exception( esc_html( $text ) ) );
			$this->save();
		} catch ( Throwable $e ) {
			return; // Nothing more can be done in a dying request.
		}
	}

	public function status() {
		$phases = $this->phases();
		return array(
			'id'       => $this->id,
			'status'   => $this->state['status'],
			'phase'    => $phases[ min( $this->state['phase'], count( $phases ) - 1 ) ],
			'progress' => 'done' === $this->state['status'] ? 100 : (int) floor( $this->progress() ),
			'message'  => $this->state['message'],
			'log'      => $this->state['log'],
			'result'   => isset( $this->state['result'] ) ? $this->state['result'] : null,
		) + $this->status_extra();
	}

	/**
	 * Extra status fields for subclasses.
	 *
	 * @return array
	 */
	protected function status_extra() {
		return array();
	}

	/**
	 * Abandon the job: undo its unfinished work (temporary tables, partial
	 * files) and remove its working folder.
	 *
	 * @param bool $local_only Skip anything that needs another site.
	 * @return string[] Problems worth telling the user about.
	 */
	public function discard( $local_only = false ) {
		$warnings = array();
		if ( 'done' !== $this->state['status'] ) {
			$warnings = (array) $this->on_discard( $local_only );
		}
		$this->cleanup();
		return $warnings;
	}

	/**
	 * Undo unfinished work. Defaults to the same tidy-up as after an error.
	 *
	 * @param bool $local_only Skip anything that needs another site.
	 * @return string[] Problems worth telling the user about.
	 */
	protected function on_discard( $local_only ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Used by subclasses.
		$this->on_fail();
		return array();
	}

	/**
	 * Refuse to start while another task is changing this site. Two restores
	 * would share temporary table names, and a backup taken mid-switch would
	 * mix old and new tables.
	 *
	 * @param bool $changes_site Whether the new task replaces this site
	 *                           (restore, pull, or receiving a push).
	 * @throws MoveKit_Exception When another task is running.
	 */
	public static function assert_idle( $changes_site = true ) {
		// A step saves its state at least every 20 seconds, or every few
		// seconds while waiting for a lost connection.
		$fresh = time() - 15 * MINUTE_IN_SECONDS;
		foreach ( (array) glob( MoveKit_Storage::jobs_dir() . '/*/state.json' ) as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$running = 'running' === $data['status'] && filemtime( $file ) >= $fresh;
			$paused  = $changes_site && ! empty( $data['resumable'] );
			if ( $running || $paused ) {
				throw new MoveKit_Exception(
					$paused
						? esc_html__( 'A paused push or pull is waiting on this site. Resume it or clean it up on the MoveKit screen first.', 'movekit-migration-backup' )
						: esc_html__( 'Another MoveKit backup, restore or transfer is running on this site. Wait for it to finish, then try again.', 'movekit-migration-backup' )
				);
			}
		}
	}

	/**
	 * Delete working folders of jobs untouched for a day, and leftover
	 * partial backups from them.
	 */
	public static function purge_stale() {
		$cutoff = time() - DAY_IN_SECONDS;
		foreach ( (array) glob( MoveKit_Storage::jobs_dir() . '/*', GLOB_ONLYDIR ) as $dir ) {
			$state = $dir . '/state.json';
			if ( filemtime( is_file( $state ) ? $state : $dir ) < $cutoff ) {
				try {
					// Drops temporary tables and partial files, not just the folder.
					self::load( basename( $dir ) )->discard( true );
				} catch ( Exception $e ) {
					MoveKit_Storage::rrmdir( $dir );
				}
			}
		}
		foreach ( (array) glob( MoveKit_Storage::dir() . '/*.part' ) as $part ) {
			if ( filemtime( $part ) < $cutoff ) {
				@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A leftover temporary file is harmless.
			}
		}
	}

	/**
	 * Drop temporary and old-copy tables that nothing owns any more, left behind
	 * when a working folder was removed by hand or by the host. Does nothing while
	 * any job folder exists, because a paused job still needs its tables.
	 */
	public static function drop_orphan_tables() {
		if ( glob( MoveKit_Storage::jobs_dir() . '/*', GLOB_ONLYDIR ) ) {
			return;
		}
		try {
			foreach ( MoveKit_DB::rows( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'", MYSQLI_NUM ) as $row ) {
				// Old copies are kept only for an undo; with none pending they are leftovers.
				$old = 0 === strpos( $row[0], MoveKit_Import_Job::OLD_PREFIX ) && null === MoveKit_Undo::dir();
				if ( $old || 0 === strpos( $row[0], MoveKit_Import_Job::TMP_PREFIX ) ) {
					MoveKit_DB::query( 'DROP TABLE IF EXISTS ' . MoveKit_DB::ident( $row[0] ) );
				}
			}
		} catch ( MoveKit_Exception $e ) {
			unset( $e ); // Left for the next clean-up.
		}
	}

	/**
	 * Remove the job's working folder.
	 */
	public function cleanup() {
		MoveKit_Storage::rrmdir( $this->dir() );
	}

	protected function message( $text ) {
		$this->state['message'] = $text;
	}

	protected function log( $text ) {
		$this->state['log'][] = $text;
	}

	protected function fail( $e ) {
		$this->state['status']  = 'error';
		$this->state['message'] = $e instanceof MoveKit_Exception
			? $e->text()
			/* translators: 1: error message, 2: file name, 3: line number. */
			: sprintf( __( 'Unexpected error: %1$s (%2$s:%3$d)', 'movekit-migration-backup' ), $e->getMessage(), basename( $e->getFile() ), $e->getLine() );
		$this->on_fail();
	}

	/**
	 * Hook for subclasses to tidy up after an error.
	 */
	protected function on_fail() {
	}

	protected function save() {
		$file = $this->dir() . '/state.json';
		$tmp  = $file . '.tmp';
		if ( false === file_put_contents( $tmp, wp_json_encode( $this->state ) ) || ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
			throw new MoveKit_Exception( esc_html__( 'Could not save job progress. The disk may be full.', 'movekit-migration-backup' ) );
		}
	}

	/**
	 * Seconds per step: comfortably under max_execution_time, never above 20.
	 */
	public static function default_budget() {
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return 20;
		}
		return max( 3, min( 20, (int) floor( $max / 2 ) ) );
	}
}
