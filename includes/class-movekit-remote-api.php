<?php
/**
 * REST routes another site calls during push and pull (movekit/v1/remote/*).
 *
 * Every route requires a request signed with this site's connection secret.
 * Job steps use the token route in MoveKit_REST.
 *
 * Backups are read and written with plain PHP file functions.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remote routes.
 */
class MoveKit_Remote_API {

	/** Largest piece sent or fetched in one request. */
	const MAX_CHUNK = 8388608;

	/**
	 * Hook route registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_cache' ), 10, 3 );
	}

	/**
	 * Keep host and CDN page caches (WP Engine, GoDaddy, Cloudflare rules) from
	 * storing MoveKit replies, which are private and change with every request.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function no_cache( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . MoveKit_REST::NS . '/' ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		}
		return $response;
	}

	/**
	 * Register routes.
	 */
	public static function routes() {
		$signed = array( 'MoveKit_Connection', 'verify' );
		$name   = '(?P<name>[A-Za-z0-9._-]+\.' . MoveKit_Storage::ARCHIVE_EXT . ')';
		$ns     = MoveKit_REST::NS;

		register_rest_route(
			$ns,
			'/remote/info',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'info' ),
				'permission_callback' => $signed,
			)
		);
		register_rest_route(
			$ns,
			'/remote/backups',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'start_export' ),
				'permission_callback' => $signed,
				'args'                => array(
					'parts'   => MoveKit_REST::parts_arg(),
					'exclude' => MoveKit_REST::exclude_arg(),
				),
			)
		);
		register_rest_route(
			$ns,
			'/remote/fingerprints',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'fingerprints' ),
				'permission_callback' => $signed,
				'args'                => array(
					'parts'   => MoveKit_REST::parts_arg(),
					'exclude' => MoveKit_REST::exclude_arg(),
				),
			)
		);
		register_rest_route(
			$ns,
			'/remote/backups/' . $name,
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( 'MoveKit_REST', 'delete_backup' ),
				'permission_callback' => $signed,
			)
		);
		register_rest_route(
			$ns,
			'/remote/backups/' . $name . '/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'MoveKit_REST', 'restore_backup' ),
				'permission_callback' => $signed,
			)
		);
		register_rest_route(
			$ns,
			'/remote/backups/' . $name . '/chunk',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'chunk' ),
				'permission_callback' => $signed,
				'args'                => array(
					'offset' => array(
						'type'     => 'integer',
						'minimum'  => 0,
						'required' => true,
					),
					'length' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'maximum'  => self::MAX_CHUNK,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/remote/jobs/(?P<id>[a-f0-9]{16})/discard',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'discard_job' ),
				'permission_callback' => $signed,
			)
		);
		register_rest_route(
			$ns,
			'/remote/uploads/(?P<upload_id>[a-f0-9]{32})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'discard_upload' ),
				'permission_callback' => $signed,
			)
		);
		register_rest_route(
			$ns,
			'/remote/uploads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'upload' ),
				'permission_callback' => $signed,
				'args'                => array(
					'upload_id' => array(
						'type'     => 'string',
						'pattern'  => '^[a-f0-9]{32}$',
						'required' => true,
					),
					'name'      => array(
						'type'     => 'string',
						'required' => true,
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
	}

	/**
	 * GET remote/info
	 */
	public static function info() {
		global $wp_version;
		$busy = '';
		try {
			MoveKit_Job::assert_idle( true );
		} catch ( MoveKit_Exception $e ) {
			$busy = $e->text();
		}
		return rest_ensure_response(
			array(
				'busy'      => $busy,
				'url'       => home_url(),
				'plugin'    => MOVEKIT_VERSION,
				'format'    => MoveKit_Archive::FORMAT,
				'wp'        => $wp_version,
				'php'       => PHP_VERSION,
				'multisite' => is_multisite(),
				'chunk'     => self::upload_limit(),
				// Added in 0.9.0: sends and accepts lists of files to leave out.
				'features'  => array( 'changed-only', 'uploads-url' ),
				'uploads'   => untrailingslashit( wp_upload_dir( null, false )['baseurl'] ),
			)
		);
	}

	/**
	 * POST remote/backups
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function start_export( $request ) {
		return MoveKit_REST::job_response(
			function () use ( $request ) {
				MoveKit_Job::assert_idle( false );
				// The site that pulls decides what to skip, and sends the
				// fingerprints of the files it already has in the body.
				$known = (string) $request->get_body();
				if ( 0 !== strlen( $known ) % MoveKit_File_Scanner::FINGERPRINT ) {
					$known = '';
				}
				return MoveKit_Export_Job::start( $request['parts'], null === $request['exclude'] ? array() : $request['exclude'], $known );
			}
		);
	}

	/**
	 * POST remote/fingerprints — raw bytes: the files this site has, so the
	 * site pushing to it can leave out the ones that are the same.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function fingerprints( $request ) {
		try {
			$data = MoveKit_File_Scanner::fingerprints( MoveKit_Parts::sanitize( $request['parts'] ), null === $request['exclude'] ? array() : MoveKit_File_Scanner::sanitize_patterns( $request['exclude'] ) );
		} catch ( MoveKit_Exception $e ) {
			return MoveKit_REST::error( $e->text() );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( 200 );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . strlen( $data ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary data, not HTML.
		exit;
	}

	/**
	 * GET remote/backups/{name}/chunk — raw bytes.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function chunk( $request ) {
		try {
			$path = MoveKit_Storage::backup_path( $request['name'] );
		} catch ( MoveKit_Exception $e ) {
			return MoveKit_REST::error( $e->text() );
		}
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'movekit_not_found', __( 'Backup not found.', 'movekit-migration-backup' ), array( 'status' => 404 ) );
		}
		$size   = filesize( $path );
		$offset = (int) $request['offset'];
		$length = min( (int) $request['length'], max( 0, $size - $offset ) );
		if ( $length < 1 ) {
			return MoveKit_REST::error( __( 'Requested past the end of the backup.', 'movekit-migration-backup' ) );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( 200 );
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . $length );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Accel-Buffering: no' );
		MoveKit_Storage::stream( $path, $offset, $length );
		exit;
	}

	/**
	 * POST remote/uploads — raw bytes in the body.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function upload( $request ) {
		MoveKit_Storage::ensure();
		$tmp = MoveKit_Storage::dir() . '/chunk-' . bin2hex( random_bytes( 8 ) ) . '.part';
		if ( false === file_put_contents( $tmp, $request->get_body() ) ) {
			wp_delete_file( $tmp );
			return MoveKit_REST::error( __( 'Could not save the upload. The disk may be full.', 'movekit-migration-backup' ) );
		}
		try {
			// Room for the backup and for restoring it afterwards.
			$result = MoveKit_Storage::receive_chunk( $request['upload_id'], $request['name'], (int) $request['total'], (int) $request['offset'], $tmp, 2 );
		} catch ( MoveKit_Exception $e ) {
			$result = MoveKit_REST::error( $e->text() );
		}
		wp_delete_file( $tmp );
		return rest_ensure_response( $result );
	}

	/**
	 * POST remote/jobs/{id}/discard
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function discard_job( $request ) {
		try {
			$warnings = MoveKit_Job::load( $request['id'] )->discard( true );
		} catch ( MoveKit_Exception $e ) {
			$warnings = array(); // Already gone.
		}
		return rest_ensure_response( array( 'warnings' => $warnings ) );
	}

	/**
	 * DELETE remote/uploads/{upload_id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function discard_upload( $request ) {
		wp_delete_file( MoveKit_Storage::dir() . '/upload-' . $request['upload_id'] . '.part' );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Largest request body this server accepts, with room for overhead.
	 */
	public static function upload_limit() {
		$post = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		if ( $post <= 0 ) {
			$post = self::MAX_CHUNK;
		}
		return (int) max( 262144, min( self::MAX_CHUNK, $post - 65536 ) );
	}
}
