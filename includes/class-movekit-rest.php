<?php
/**
 * REST API routes behind the Migration screen (namespace movekit/v1).
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the plugin's REST routes.
 */
class MoveKit_REST {

	const NS = 'movekit/v1';

	/**
	 * Hook route registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function routes() {
		$admin = array( __CLASS__, 'can_manage' );
		$name  = '(?P<name>[A-Za-z0-9._-]+\.' . MoveKit_Storage::ARCHIVE_EXT . ')';

		register_rest_route(
			self::NS,
			'/backups',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_backups' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_backup' ),
					'permission_callback' => $admin,
					'args'                => array(
						'parts'   => self::parts_arg(),
						'exclude' => self::exclude_arg(),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/backups/' . $name,
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_backup' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/backups/' . $name . '/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore_backup' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/uploads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'upload_chunk' ),
				'permission_callback' => $admin,
				'args'                => array(
					'upload_id' => array(
						'type'     => 'string',
						'pattern'  => '^[a-f0-9]{32}$',
						'required' => true,
					),
					'name'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_file_name',
					),
					'total'     => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'offset'    => array(
						'type'     => 'integer',
						'minimum'  => 0,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/connection',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'connection' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/connection/regenerate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'regenerate_key' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/remotes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_remotes' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'add_remote' ),
					'permission_callback' => $admin,
					'args'                => array(
						'key' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/remotes/(?P<id>[a-f0-9]{12})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'remove_remote' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/remotes/(?P<id>[a-f0-9]{12})/(?P<direction>push|pull)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'transfer' ),
				'permission_callback' => $admin,
				'args'                => array(
					'parts'        => self::parts_arg(),
					'exclude'      => self::exclude_arg(),
					'changed_only' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'remote_media' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/backups/' . $name . '/preflight',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'restore_preflight' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/remotes/(?P<id>[a-f0-9]{12})/(?P<direction>push|pull)/preflight',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'transfer_preflight' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/media-source',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'media_stop' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/undo',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'undo_info' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'undo_run' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'undo_discard' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/transfers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'paused_transfers' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<id>[a-f0-9]{16})/(?P<action>resume|discard)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'job_action' ),
				'permission_callback' => $admin,
			)
		);
		// Steps authenticate with the job's own secret token rather than the
		// login cookie, because a restore replaces the users table mid-way.
		register_rest_route(
			self::NS,
			'/jobs/(?P<id>[a-f0-9]{16})/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'step' ),
				'permission_callback' => array( __CLASS__, 'can_step' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The optional "parts" argument: what a backup, push or pull includes.
	 */
	public static function parts_arg() {
		return array(
			'type'     => 'array',
			'items'    => array(
				'type' => 'string',
				'enum' => MoveKit_Parts::ALL,
			),
			'minItems' => 1,
			'required' => false,
		);
	}

	/**
	 * The optional "exclude" argument: patterns of files to skip, one per
	 * line. When given, it is also saved as the new default.
	 */
	public static function exclude_arg() {
		return array(
			'type'     => 'string',
			'required' => false,
		);
	}

	/**
	 * Save exclusion patterns sent from the MoveKit screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string[]|null Patterns, or null when none were sent.
	 */
	private static function exclude_from( $request ) {
		if ( null === $request['exclude'] ) {
			return null;
		}
		$patterns = MoveKit_File_Scanner::sanitize_patterns( $request['exclude'] );
		update_option( 'movekit_exclude', $patterns, false );
		return $patterns;
	}

	/**
	 * Only administrators may manage backups.
	 */
	public static function can_manage() {
		return current_user_can( MoveKit_Admin::CAP );
	}

	/**
	 * A step is allowed when the request carries the job's token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function can_step( $request ) {
		try {
			$job = MoveKit_Job::load( $request['id'] );
		} catch ( MoveKit_Exception $e ) {
			return new WP_Error( 'movekit_no_job', $e->text(), array( 'status' => 404 ) );
		}
		if ( ! hash_equals( $job->token(), (string) $request['token'] ) ) {
			return new WP_Error( 'movekit_forbidden', __( 'Not allowed.', 'movekit-migration-backup' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /backups
	 */
	public static function list_backups() {
		$out = array();
		foreach ( MoveKit_Storage::list_backups() as $b ) {
			$from  = '';
			$ok    = true;
			$parts = MoveKit_Parts::ALL;
			try {
				$reader = new MoveKit_Archive_Reader( $b['path'] );
				$m      = $reader->manifest();
				$reader->close();
				$from  = $m['site']['home'];
				$parts = MoveKit_Parts::of( $m );
			} catch ( MoveKit_Exception $e ) {
				$ok = false;
			}
			$out[] = array(
				'name'     => $b['name'],
				'size'     => $b['size'],
				'sizeText' => size_format( $b['size'], 1 ),
				'date'     => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $b['mtime'] ),
				'from'     => $from,
				'ok'       => $ok,
				'parts'    => $parts,
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /backups
	 *
	 * @param WP_REST_Request $request Request; optional parts.
	 */
	public static function create_backup( $request ) {
		return self::job_response(
			function () use ( $request ) {
				MoveKit_Job::assert_idle( false );
				return MoveKit_Export_Job::start( $request['parts'], self::exclude_from( $request ) );
			}
		);
	}

	/**
	 * POST /backups/{name}/restore
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function restore_backup( $request ) {
		return self::job_response(
			function () use ( $request ) {
				MoveKit_Job::assert_idle( true );
				return MoveKit_Import_Job::start( MoveKit_Storage::backup_path( $request['name'] ) );
			}
		);
	}

	/**
	 * GET /backups/{name}/preflight — what to know before restoring.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function restore_preflight( $request ) {
		try {
			$check = MoveKit_Import_Job::preflight( MoveKit_Storage::backup_path( $request['name'] ) );
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
		$check['undo'] = (bool) MoveKit_Undo::info();
		return rest_ensure_response( $check );
	}

	/**
	 * GET /remotes/{id}/(push|pull)/preflight — what to know before a transfer.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function transfer_preflight( $request ) {
		global $wp_version;
		try {
			$remote = MoveKit_Connection::remote( $request['id'] );
			$info   = ( new MoveKit_Remote_Client( $remote ) )->info();
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
		$errors = array();
		if ( ! empty( $info['multisite'] ) ) {
			$errors[] = __( 'The other site is a multisite network, which is not supported yet.', 'movekit-migration-backup' );
		}
		$push = 'push' === $request['direction'];
		if ( $push && ! empty( $info['busy'] ) ) {
			/* translators: 1: site address, 2: reason. */
			$errors[] = sprintf( __( '%1$s is busy: %2$s', 'movekit-migration-backup' ), $remote['url'], $info['busy'] );
		}
		$here     = array(
			'wp_version'  => $wp_version,
			'php_version' => PHP_VERSION,
		);
		$there    = array(
			'wp_version'  => isset( $info['wp'] ) ? $info['wp'] : '',
			'php_version' => isset( $info['php'] ) ? $info['php'] : '',
		);
		$warnings = $push
			? MoveKit_Import_Job::version_warnings( $here, $there['wp_version'], $there['php_version'], $remote['url'] )
			: MoveKit_Import_Job::version_warnings( $there, $wp_version, PHP_VERSION );
		if ( $push && '0' === (string) get_option( 'blog_public' ) ) {
			$warnings[] = __( 'This site asks search engines not to index it. The other site keeps its own setting if it is a live site.', 'movekit-migration-backup' );
		}
		return rest_ensure_response(
			array(
				'errors'   => $errors,
				'warnings' => $warnings,
				'live'     => ! MoveKit_Connection::is_local_host( (string) wp_parse_url( $remote['url'], PHP_URL_HOST ) ),
			)
		);
	}

	/**
	 * GET /undo — the restore that can be undone, if any.
	 */
	public static function undo_info() {
		$info = MoveKit_Undo::info();
		if ( $info ) {
			$info['untilText'] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $info['until'] );
		}
		// False rather than null: a null response has an empty body, which is not JSON.
		return rest_ensure_response( $info ? $info : false );
	}

	/**
	 * POST /undo — undo the last restore.
	 */
	public static function undo_run() {
		try {
			MoveKit_Job::assert_idle( true );
			$result = MoveKit_Undo::run();
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * DELETE /undo — keep the restore and free the space the old copy uses.
	 */
	public static function undo_discard() {
		MoveKit_Undo::discard();
		return rest_ensure_response( true );
	}

	/**
	 * DELETE /media-source — stop showing missing media from the other site.
	 */
	public static function media_stop() {
		MoveKit_Media::save( '' );
		return rest_ensure_response( true );
	}

	/**
	 * DELETE /backups/{name}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function delete_backup( $request ) {
		try {
			$path = MoveKit_Storage::backup_path( $request['name'] );
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
		if ( is_file( $path ) && ! wp_delete_file_from_directory( $path, MoveKit_Storage::dir() ) ) {
			return self::error( __( 'Could not delete the file. Check folder permissions.', 'movekit-migration-backup' ) );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /jobs/{id}/step
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function step( $request ) {
		$job = MoveKit_Job::load( $request['id'] );
		// Short steps keep the progress bar moving and stay clear of proxy timeouts.
		return rest_ensure_response( $job->step( min( 8, MoveKit_Job::default_budget() ) ) );
	}

	/**
	 * POST /uploads — receive one piece of an upload.
	 *
	 * Pieces arrive in order. If one goes missing, the reply's offset tells
	 * the browser where to continue from.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function upload_chunk( $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['chunk']['tmp_name'] ) || UPLOAD_ERR_OK !== $files['chunk']['error'] ) {
			return self::error(
				sprintf(
					/* translators: %d: PHP upload error code. */
					__( 'A piece of the upload was rejected by the server (error %d).', 'movekit-migration-backup' ),
					isset( $files['chunk']['error'] ) ? (int) $files['chunk']['error'] : -1
				)
			);
		}
		try {
			$result = MoveKit_Storage::receive_chunk( $request['upload_id'], $request['name'], (int) $request['total'], (int) $request['offset'], $files['chunk']['tmp_name'] );
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * GET /connection — this site's connection key.
	 */
	public static function connection() {
		try {
			return rest_ensure_response( array( 'key' => MoveKit_Connection::key() ) );
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
	}

	/**
	 * POST /connection/regenerate — new key; sites using the old one are disconnected.
	 */
	public static function regenerate_key() {
		try {
			MoveKit_Connection::regenerate();
			return self::connection();
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
	}

	/**
	 * GET /remotes
	 */
	public static function list_remotes() {
		$out = array();
		foreach ( MoveKit_Connection::remotes() as $r ) {
			$r['addedText'] = wp_date( get_option( 'date_format' ), $r['added'] );
			$out[]          = $r;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /remotes — connect a site by its key, after checking that the key works.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function add_remote( $request ) {
		try {
			$remote = MoveKit_Connection::parse_key( $request['key'] );
			MoveKit_Connection::check_reachable( $remote );
			$client = new MoveKit_Remote_Client( $remote );
			$info   = $client->info();
			if ( MoveKit_Archive::FORMAT !== (int) $info['format'] ) {
				return self::error( __( 'The two sites have incompatible MoveKit versions. Install the same version on both sites.', 'movekit-migration-backup' ) );
			}
			$remote['url'] = MoveKit_Connection::clean_url( $info['url'] );
			MoveKit_Connection::add_remote( $remote );
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
		return self::list_remotes();
	}

	/**
	 * DELETE /remotes/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function remove_remote( $request ) {
		try {
			MoveKit_Connection::remove_remote( $request['id'] );
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
		return self::list_remotes();
	}

	/**
	 * POST /remotes/{id}/push and /remotes/{id}/pull
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function transfer( $request ) {
		return self::job_response(
			function () use ( $request ) {
				return MoveKit_Transfer_Job::start( $request['id'], $request['direction'], $request['parts'], self::exclude_from( $request ), (bool) $request['changed_only'], (bool) $request['remote_media'] );
			}
		);
	}

	/**
	 * GET /transfers — pushes and pulls paused by a lost connection.
	 */
	public static function paused_transfers() {
		$out = array();
		foreach ( (array) glob( MoveKit_Storage::jobs_dir() . '/*', GLOB_ONLYDIR ) as $dir ) {
			try {
				$job = MoveKit_Job::load( basename( $dir ) );
			} catch ( MoveKit_Exception $e ) {
				continue;
			}
			$status = $job->status();
			if ( $job instanceof MoveKit_Transfer_Job && ! empty( $status['resumable'] ) ) {
				$out[] = array(
					'job'       => $job->id(),
					'direction' => $status['direction'],
					'remote'    => $status['remote'],
					'progress'  => $status['progress'],
				);
			}
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /jobs/{id}/resume and /jobs/{id}/discard
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function job_action( $request ) {
		try {
			$job = MoveKit_Job::load( $request['id'] );
			if ( 'discard' === $request['action'] ) {
				return rest_ensure_response( array( 'warnings' => $job->discard() ) );
			}
			if ( ! $job instanceof MoveKit_Transfer_Job ) {
				return self::error( __( 'Only a push or pull can be resumed.', 'movekit-migration-backup' ) );
			}
			$job->resume();
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
		return rest_ensure_response(
			array(
				'job'   => $job->id(),
				'token' => $job->token(),
			)
		);
	}

	/**
	 * Start a job and return its id and token.
	 *
	 * @param callable $start Returns an MoveKit_Job.
	 */
	public static function job_response( $start ) {
		try {
			$job = $start();
		} catch ( MoveKit_Exception $e ) {
			return self::error( $e->text() );
		}
		return rest_ensure_response(
			array(
				'job'   => $job->id(),
				'token' => $job->token(),
			)
		);
	}

	/**
	 * An error the screen shows as-is.
	 *
	 * @param string $message Message.
	 */
	public static function error( $message ) {
		return new WP_Error( 'movekit_error', $message, array( 'status' => 400 ) );
	}
}
