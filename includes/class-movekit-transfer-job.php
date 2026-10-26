<?php
/**
 * Push this site to a connected site, or pull a connected site into this one.
 *
 * Always runs on the site where the button was pressed (usually the local
 * one), because a live server usually can't reach a local computer. It
 * drives jobs on both sides: the backup, the transfer in pieces, and the
 * restore, each of which is resumable on its own.
 *
 * Backups are read and written with plain PHP file functions.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Push and pull.
 */
class MoveKit_Transfer_Job extends MoveKit_Job {

	/** Seconds to keep trying when the other site can't be reached. */
	const WAIT = 300;

	/**
	 * Start a push or pull.
	 *
	 * @param string $remote_id Connected site id.
	 * @param string $direction push or pull.
	 * @param mixed  $parts     What to include; empty for the whole site.
	 * @param mixed  $exclude   Patterns of files to skip; null for the saved ones.
	 * @param bool   $changed   Send only files that differ from the receiving site's.
	 * @param bool   $media     On a pull without media, show missing media from the other site.
	 * @return MoveKit_Transfer_Job
	 * @throws MoveKit_Exception When the site isn't connected or this site is multisite.
	 */
	public static function start( $remote_id, $direction, $parts = null, $exclude = null, $changed = true, $media = false ) {
		$remote = MoveKit_Connection::remote( $remote_id );
		if ( is_multisite() ) {
			throw new MoveKit_Exception( esc_html__( 'Multisite networks are not supported yet.', 'movekit-migration-backup' ) );
		}
		MoveKit_Job::assert_idle( 'pull' === $direction );
		return self::create(
			array(
				'direction'     => 'pull' === $direction ? 'pull' : 'push',
				'parts'         => MoveKit_Parts::sanitize( $parts ),
				'exclude'       => null === $exclude ? MoveKit_File_Scanner::saved_patterns() : MoveKit_File_Scanner::sanitize_patterns( $exclude ),
				'remote_id'     => $remote['id'],
				'remote'        => $remote['url'],
				'changed_only'  => (bool) $changed,
				'remote_media'  => (bool) $media,
				'offline_since' => null,
			)
		);
	}

	/**
	 * Phases for this direction.
	 */
	protected function phases() {
		return 'push' === $this->state['direction']
			? array( 'check', 'export', 'upload', 'remote_restore', 'cleanup' )
			: array( 'check', 'remote_export', 'download', 'restore', 'cleanup' );
	}

	/**
	 * Overall progress.
	 */
	protected function progress() {
		$s     = $this->state;
		$phase = $this->phases()[ $s['phase'] ];
		$child = isset( $s['child_progress'] ) ? $s['child_progress'] : 0;
		switch ( $phase ) {
			case 'check':
				return 0;
			case 'export':
			case 'remote_export':
				return 1 + 0.34 * $child;
			case 'upload':
			case 'download':
				return 35 + 30 * $s['offset'] / max( 1, $s['size'] );
			case 'remote_restore':
			case 'restore':
				return 65 + 0.34 * $child;
			default:
				return 99;
		}
	}

	/* ---------------------------------------------------------------- check */

	/**
	 * Make sure the other site is reachable and compatible.
	 *
	 * @param float $deadline Unix time.
	 */
	protected function run_check( $deadline ) {
		$this->message(
			sprintf(
				/* translators: %s: site address. */
				__( 'Connecting to %s', 'movekit-migration-backup' ),
				$this->state['remote']
			)
		);
		$info = $this->remote_call(
			function ( $client ) {
				return $client->info();
			}
		);
		if ( null === $info ) {
			return false;
		}
		if ( MoveKit_Archive::FORMAT !== (int) $info['format'] ) {
			throw new MoveKit_Exception( esc_html__( 'The two sites have incompatible MoveKit versions. Install the same version on both sites.', 'movekit-migration-backup' ) );
		}
		if ( ! empty( $info['multisite'] ) ) {
			throw new MoveKit_Exception( esc_html__( 'The other site is a multisite network, which is not supported yet.', 'movekit-migration-backup' ) );
		}
		if ( 'push' === $this->state['direction'] && ! empty( $info['busy'] ) ) {
			/* translators: 1: site address, 2: reason. */
			throw new MoveKit_Exception( esc_html( sprintf( __( '%1$s is busy: %2$s', 'movekit-migration-backup' ), $this->state['remote'], $info['busy'] ) ) );
		}
		$this->state['remote_chunk']   = max( 262144, min( MoveKit_Remote_API::MAX_CHUNK, (int) $info['chunk'] ) );
		$this->state['remote_uploads'] = isset( $info['uploads'] ) ? (string) $info['uploads'] : '';
		$this->state['compare']        = ! empty( $this->state['changed_only'] )
			&& array_diff( $this->parts(), array( 'database' ) )
			&& in_array( 'changed-only', isset( $info['features'] ) ? (array) $info['features'] : array(), true );

		if ( 'push' === $this->state['direction'] ) {
			$known = '';
			if ( $this->state['compare'] ) {
				$this->message( __( 'Comparing files with the other site', 'movekit-migration-backup' ) );
				$parts   = $this->parts();
				$exclude = $this->exclude();
				$known   = $this->remote_call(
					function ( $client ) use ( $parts, $exclude ) {
						return $client->fingerprints( $parts, $exclude );
					}
				);
				if ( null === $known ) {
					return false;
				}
			}
			$this->state['child'] = MoveKit_Export_Job::start( $this->parts(), $this->exclude(), $known )->id();
			$this->message( __( 'Backing up this site', 'movekit-migration-backup' ) );
		} else {
			$this->message( __( 'Backing up the other site', 'movekit-migration-backup' ) );
		}
		return true;
	}

	/* ----------------------------------------------------------------- push */

	/**
	 * Back up this site.
	 *
	 * @param float $deadline Unix time.
	 */
	protected function run_export( $deadline ) {
		$status = $this->step_child( $deadline );
		if ( 'done' !== $status['status'] ) {
			return false;
		}
		$this->note_unchanged( $status['result'] );
		$this->state['file']      = MoveKit_Storage::dir() . '/' . $status['result']['name'];
		$this->state['size']      = filesize( $this->state['file'] );
		$this->state['offset']    = 0;
		$this->state['upload_id'] = bin2hex( random_bytes( 16 ) );
		$this->message( __( 'Sending backup to the other site', 'movekit-migration-backup' ) );
		return true;
	}

	/**
	 * Send the backup in pieces.
	 *
	 * @param float $deadline Unix time.
	 */
	protected function run_upload( $deadline ) {
		$fh = fopen( $this->state['file'], 'rb' );
		if ( ! $fh ) {
			throw new MoveKit_Exception( esc_html__( 'The backup to send has disappeared.', 'movekit-migration-backup' ) );
		}
		$name = 'push-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 8, false ) ) . '.' . MoveKit_Storage::ARCHIVE_EXT;
		if ( ! empty( $this->state['upload_name'] ) ) {
			$name = $this->state['upload_name'];
		}
		$this->state['upload_name'] = $name;
		$done                       = false;

		while ( ! $done && microtime( true ) < $deadline ) {
			fseek( $fh, $this->state['offset'] );
			$data   = (string) fread( $fh, $this->state['remote_chunk'] );
			$offset = $this->state['offset'];
			$size   = $this->state['size'];
			$id     = $this->state['upload_id'];
			$result = $this->remote_call(
				function ( $client ) use ( $id, $name, $size, $offset, $data ) {
					return $client->upload( $id, $name, $size, $offset, $data );
				}
			);
			if ( null === $result ) {
				break;
			}
			$this->state['offset'] = (int) $result['offset'];
			$this->message( $this->transfer_message( __( 'Sending backup to the other site', 'movekit-migration-backup' ) ) );
			if ( ! empty( $result['done'] ) ) {
				$this->state['remote_file'] = $result['name'];
				$done                       = true;
			}
		}
		fclose( $fh );
		if ( $done ) {
			$this->message( __( 'Restoring on the other site', 'movekit-migration-backup' ) );
		}
		return $done;
	}

	/**
	 * Restore the sent backup on the other site.
	 *
	 * @param float $deadline Unix time.
	 */
	protected function run_remote_restore( $deadline ) {
		if ( empty( $this->state['remote_job'] ) ) {
			$file = $this->state['remote_file'];
			$job  = $this->remote_call(
				function ( $client ) use ( $file ) {
					return $client->start_import( $file );
				}
			);
			if ( null === $job ) {
				return false;
			}
			$this->state['remote_job'] = $job;
		}
		return $this->step_remote( __( 'Other site', 'movekit-migration-backup' ) );
	}

	/* ----------------------------------------------------------------- pull */

	/**
	 * Back up the other site.
	 *
	 * @param float $deadline Unix time.
	 */
	protected function run_remote_export( $deadline ) {
		if ( empty( $this->state['remote_job'] ) ) {
			$parts = $this->parts();
			$known = '';
			if ( ! empty( $this->state['compare'] ) ) {
				$this->message( __( 'Comparing files with the other site', 'movekit-migration-backup' ) );
				$known = MoveKit_File_Scanner::fingerprints( $parts, $this->exclude() );
				if ( strlen( $known ) > $this->state['remote_chunk'] ) {
					$known = ''; // Too big to send in one request; send everything.
				}
			}
			$job = $this->remote_call(
				function ( $client ) use ( $parts, $known ) {
					return $client->start_export( $parts, $this->exclude(), $known );
				}
			);
			if ( null === $job ) {
				return false;
			}
			$this->state['remote_job'] = $job;
		}
		if ( ! $this->step_remote( __( 'Other site', 'movekit-migration-backup' ) ) ) {
			return false;
		}
		$result = $this->state['remote_result'];
		$this->note_unchanged( $result );
		$this->state['file']        = MoveKit_Storage::backup_path( $result['name'] );
		$this->state['remote_file'] = basename( $this->state['file'] );
		$this->state['size']        = (int) $result['size'];
		$this->state['offset']      = 0;
		$this->state['remote_job']  = null;
		// Room for the download and for restoring it afterwards.
		$problem = MoveKit_Storage::space_problem( 2 * $this->state['size'] );
		if ( $problem ) {
			throw new MoveKit_Exception( esc_html( $problem ) );
		}
		$this->message( __( 'Downloading backup from the other site', 'movekit-migration-backup' ) );
		return true;
	}

	/**
	 * Fetch the backup in pieces.
	 *
	 * @param float $deadline Unix time.
	 */
	protected function run_download( $deadline ) {
		$part = $this->state['file'] . '.part';
		$fh   = fopen( $part, 0 === $this->state['offset'] ? 'wb' : 'cb' );
		if ( ! $fh ) {
			throw new MoveKit_Exception( esc_html__( 'Could not save the download. Check that wp-content is writable.', 'movekit-migration-backup' ) );
		}
		// Drop anything written after the last confirmed piece.
		ftruncate( $fh, $this->state['offset'] );

		while ( $this->state['offset'] < $this->state['size'] && microtime( true ) < $deadline ) {
			$name   = $this->state['remote_file'];
			$offset = $this->state['offset'];
			$length = (int) min( MoveKit_Remote_API::MAX_CHUNK, $this->state['size'] - $offset );
			$data   = $this->remote_call(
				function ( $client ) use ( $name, $offset, $length ) {
					return $client->download( $name, $offset, $length );
				}
			);
			if ( null === $data ) {
				break;
			}
			fseek( $fh, $offset );
			if ( fwrite( $fh, $data ) !== strlen( $data ) ) {
				fclose( $fh );
				throw new MoveKit_Exception( esc_html__( 'Saving the download failed. The disk may be full.', 'movekit-migration-backup' ) );
			}
			$this->state['offset'] += strlen( $data );
			$this->message( $this->transfer_message( __( 'Downloading backup from the other site', 'movekit-migration-backup' ) ) );
		}
		fclose( $fh );
		if ( $this->state['offset'] < $this->state['size'] ) {
			return false;
		}

		$reader = new MoveKit_Archive_Reader( $part );
		$reader->summary();
		$reader->close();
		if ( ! rename( $part, $this->state['file'] ) ) {
			throw new MoveKit_Exception( esc_html__( 'Could not save the download.', 'movekit-migration-backup' ) );
		}
		$this->state['child'] = MoveKit_Import_Job::start( $this->state['file'] )->id();
		$this->message( __( 'Restoring on this site', 'movekit-migration-backup' ) );
		return true;
	}

	/**
	 * Restore the downloaded backup here.
	 *
	 * @param float $deadline Unix time.
	 */
	protected function run_restore( $deadline ) {
		$status = $this->step_child( $deadline );
		return 'done' === $status['status'];
	}

	/* -------------------------------------------------------------- cleanup */

	/**
	 * Remove the temporary backups on both sites.
	 *
	 * @param float $deadline Unix time.
	 */
	protected function run_cleanup( $deadline ) {
		$this->remove_temp_files();
		$this->state['media_loaded'] = false;
		if ( 'pull' === $this->state['direction'] ) {
			$show = ! in_array( 'media', $this->parts(), true ) && ! empty( $this->state['remote_media'] ) && ! empty( $this->state['remote_uploads'] );
			MoveKit_Media::save( $show ? $this->state['remote_uploads'] : '' );
			$this->state['media_loaded'] = $show;
		}
		$this->state['result'] = array(
			'direction' => $this->state['direction'],
			'remote'    => $this->state['remote'],
			'parts'     => $this->parts(),
			'undo'      => 'pull' === $this->state['direction'] ? MoveKit_Undo::info() : null,
		);
		if ( ! empty( $this->state['media_loaded'] ) ) {
			$this->state['result']['media'] = true;
		}
		if ( ! empty( $this->state['unchanged'] ) ) {
			$this->state['result']['unchanged']       = $this->state['unchanged'][0];
			$this->state['result']['unchanged_bytes'] = $this->state['unchanged'][1];
		}
		$this->message(
			'push' === $this->state['direction']
				? __( 'Push complete.', 'movekit-migration-backup' )
				: ( in_array( 'database', $this->parts(), true )
					? __( 'Pull complete. Log in with the username and password from the site you pulled.', 'movekit-migration-backup' )
					: __( 'Pull complete.', 'movekit-migration-backup' ) )
		);
		return true;
	}

	/**
	 * A lost connection pauses the transfer instead of ending it: everything
	 * is kept so it can be resumed or cleaned up later.
	 *
	 * @param Throwable $e Error.
	 */
	protected function fail( $e ) {
		if ( ! $e instanceof MoveKit_Retry_Exception ) {
			parent::fail( $e );
			return;
		}
		$this->state['status']    = 'error';
		$this->state['resumable'] = true;
		$minutes                  = (int) ceil( self::wait() / 60 );
		$this->message(
			sprintf(
				/* translators: 1: site address, 2: how long, e.g. "5 minutes". */
				__( 'Could not reach %1$s for %2$s, so the transfer was paused. Resume it when the connection is back, or clean up to remove the unfinished transfer from both sites.', 'movekit-migration-backup' ),
				$this->state['remote'],
				/* translators: %d: number of minutes. */
				sprintf( _n( '%d minute', '%d minutes', $minutes, 'movekit-migration-backup' ), $minutes )
			)
		);
	}

	/**
	 * Seconds to keep trying before pausing.
	 */
	private static function wait() {
		/**
		 * Filters how long a push or pull keeps trying to reach the other site
		 * before it pauses.
		 *
		 * @param int $seconds Seconds. Default 300.
		 */
		return max( 10, (int) apply_filters( 'movekit_reconnect_wait', self::WAIT ) );
	}

	/**
	 * Carry on with a transfer paused by a lost connection.
	 *
	 * @throws MoveKit_Exception When it can't be resumed.
	 */
	public function resume() {
		if ( 'error' !== $this->state['status'] || empty( $this->state['resumable'] ) ) {
			throw new MoveKit_Exception( esc_html__( 'This transfer cannot be resumed. Start it again.', 'movekit-migration-backup' ) );
		}
		$this->state['status']        = 'running';
		$this->state['resumable']     = false;
		$this->state['offline_since'] = null;
		$this->message( __( 'Resuming', 'movekit-migration-backup' ) );
		$this->save();
	}

	/**
	 * Fields the screen needs to show a countdown and offer Resume.
	 */
	protected function status_extra() {
		$left = null;
		if ( ! empty( $this->state['offline_since'] ) && 'running' === $this->state['status'] ) {
			$left = max( 0, self::wait() - ( time() - (int) $this->state['offline_since'] ) );
		}
		return array(
			'wait_left' => $left,
			'resumable' => ! empty( $this->state['resumable'] ),
			'direction' => $this->state['direction'],
			'remote'    => $this->state['remote'],
		);
	}

	/**
	 * Tidy up after an error.
	 */
	protected function on_fail() {
		foreach ( $this->on_discard( false ) as $warning ) {
			$this->log( $warning );
		}
	}

	/**
	 * Undo an unfinished transfer on both sites.
	 *
	 * @param bool $local_only Skip the other site.
	 * @return string[] Problems worth telling the user about.
	 */
	protected function on_discard( $local_only ) {
		$warnings = array();
		if ( ! $local_only ) {
			try {
				$client = $this->client();
				if ( ! empty( $this->state['remote_job'] ) ) {
					$client->discard_job( $this->state['remote_job'] );
				}
				if ( ! empty( $this->state['upload_id'] ) && empty( $this->state['remote_file'] ) ) {
					$client->discard_upload( $this->state['upload_id'] );
				}
				if ( ! empty( $this->state['remote_file'] ) ) {
					$client->delete( $this->state['remote_file'] );
				}
			} catch ( MoveKit_Exception $e ) {
				$warnings[] = sprintf(
					/* translators: 1: site address, 2: error. */
					__( 'Could not clean up on %1$s (%2$s). Its unfinished files and tables are removed automatically after a day.', 'movekit-migration-backup' ),
					$this->state['remote'],
					$e->text()
				);
			}
		}
		if ( ! empty( $this->state['file'] ) ) {
			wp_delete_file( $this->state['file'] );
			wp_delete_file( $this->state['file'] . '.part' );
		}
		if ( ! empty( $this->state['child'] ) ) {
			try {
				MoveKit_Job::load( $this->state['child'] )->discard( true );
			} catch ( MoveKit_Exception $e ) {
				unset( $e ); // Already gone.
			}
		}
		return $warnings;
	}

	/**
	 * Delete this transfer's backup files, here and on the other site.
	 */
	private function remove_temp_files() {
		if ( ! empty( $this->state['file'] ) ) {
			wp_delete_file( $this->state['file'] );
			wp_delete_file( $this->state['file'] . '.part' );
		}
		if ( ! empty( $this->state['remote_file'] ) ) {
			$name = $this->state['remote_file'];
			try {
				$this->client()->delete( $name );
			} catch ( MoveKit_Exception $e ) {
				/* translators: %s: file name. */
				$this->log( sprintf( __( 'Could not delete the temporary backup %s on the other site. You can delete it from its MoveKit screen.', 'movekit-migration-backup' ), $name ) );
			}
		}
		if ( ! empty( $this->state['child'] ) ) {
			try {
				MoveKit_Job::load( $this->state['child'] )->cleanup();
			} catch ( MoveKit_Exception $e ) {
				return; // Already gone.
			}
		}
	}

	/* -------------------------------------------------------------- helpers */

	/**
	 * What this transfer includes. Jobs started before 0.6.0 include everything.
	 *
	 * @return string[]
	 */
	private function parts() {
		return isset( $this->state['parts'] ) ? $this->state['parts'] : MoveKit_Parts::ALL;
	}

	/**
	 * Patterns of files to skip.
	 *
	 * @return string[]
	 */
	private function exclude() {
		return isset( $this->state['exclude'] ) ? $this->state['exclude'] : array();
	}

	/**
	 * Remember how many files were left out because the other site has them.
	 *
	 * @param array $result Backup result.
	 */
	private function note_unchanged( array $result ) {
		if ( ! empty( $result['unchanged'] ) ) {
			$this->state['unchanged'] = array( (int) $result['unchanged'], (int) $result['unchanged_bytes'] );
		}
	}

	/**
	 * Client for the connected site.
	 */
	private function client() {
		return new MoveKit_Remote_Client( MoveKit_Connection::remote( $this->state['remote_id'] ) );
	}

	/**
	 * Run a remote request, turning temporary failures into a retry later.
	 *
	 * @param callable $request Receives the client.
	 * @return mixed|null Result, or null when it should be tried again.
	 * @throws MoveKit_Exception After too many failures in a row.
	 */
	private function remote_call( $request ) {
		$started = time();
		try {
			$result                       = $request( $this->client() );
			$this->state['offline_since'] = null;
			return $result;
		} catch ( MoveKit_Retry_Exception $e ) {
			if ( empty( $this->state['offline_since'] ) ) {
				$this->state['offline_since'] = $started;
			}
			$left = self::wait() - ( time() - (int) $this->state['offline_since'] );
			if ( $left <= 0 ) {
				throw $e;
			}
			$this->message(
				sprintf(
					/* translators: %s: site address. */
					__( 'Lost the connection to %s. Waiting for it to come back.', 'movekit-migration-backup' ),
					$this->state['remote']
				)
			);
			sleep( (int) min( 5, $left ) );
			return null;
		}
	}

	/**
	 * Run one step of the job on the other site. Returns true when it is done.
	 *
	 * @param string $label Prefix for progress messages.
	 * @throws MoveKit_Exception When the remote job fails.
	 */
	private function step_remote( $label ) {
		$job    = $this->state['remote_job'];
		$status = $this->remote_call(
			function ( $client ) use ( $job ) {
				return $client->step( $job );
			}
		);
		if ( null === $status ) {
			return false;
		}
		$this->state['child_progress'] = (int) $status['progress'];
		$this->message( $label . ': ' . $status['message'] );
		if ( 'error' === $status['status'] ) {
			throw new MoveKit_Exception(
				esc_html(
					sprintf(
						/* translators: %s: error from the other site. */
						__( 'The other site reported: %s', 'movekit-migration-backup' ),
						$status['message']
					)
				)
			);
		}
		if ( 'done' !== $status['status'] ) {
			return false;
		}
		foreach ( (array) $status['log'] as $entry ) {
			$this->log( $label . ': ' . $entry );
		}
		$this->state['remote_result'] = $status['result'];
		return true;
	}

	/**
	 * Run the local child job for the rest of this step.
	 *
	 * @param float $deadline Unix time.
	 * @return array Child status.
	 * @throws MoveKit_Exception When the child job fails.
	 */
	private function step_child( $deadline ) {
		$child                         = MoveKit_Job::load( $this->state['child'] );
		$status                        = $child->step( max( 1, $deadline - microtime( true ) ) );
		$this->state['child_progress'] = (int) $status['progress'];
		$this->message( $status['message'] );
		if ( 'error' === $status['status'] ) {
			throw new MoveKit_Exception( esc_html( $status['message'] ) );
		}
		if ( 'done' === $status['status'] ) {
			foreach ( (array) $status['log'] as $entry ) {
				$this->log( $entry );
			}
			$child->cleanup();
		}
		return $status;
	}

	/**
	 * "Label: 120 MB of 1.3 GB".
	 *
	 * @param string $label Label.
	 */
	private function transfer_message( $label ) {
		return sprintf(
			/* translators: 1: what is happening, 2: bytes done, 3: total bytes. */
			__( '%1$s: %2$s of %3$s', 'movekit-migration-backup' ),
			$label,
			size_format( $this->state['offset'], 1 ),
			size_format( $this->state['size'], 1 )
		);
	}
}
