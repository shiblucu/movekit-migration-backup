<?php
/**
 * Connection keys for push and pull between sites.
 *
 * Secrets live in PHP files in the backups folder, not in the database:
 * a restore replaces the database, and the connection has to survive it on
 * both sides. The files start with an exit statement, so they print nothing
 * even if a web server serves the folder.
 *
 * Backups are read and written with plain PHP file functions.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * This site's connection key and the sites it is connected to.
 */
class MoveKit_Connection {

	const KEY_PREFIX = 'movekit1:';
	const GUARD      = "<?php exit; ?>\n";

	/**
	 * This site's secret, created on first use.
	 *
	 * @return string 64 hex characters.
	 */
	public static function secret() {
		$data = self::read( 'connection' );
		if ( empty( $data['secret'] ) ) {
			$data = array(
				'secret'  => bin2hex( random_bytes( 32 ) ),
				'created' => time(),
			);
			self::write( 'connection', $data );
		}
		return $data['secret'];
	}

	/**
	 * Replace this site's secret, disconnecting every site that used the old key.
	 */
	public static function regenerate() {
		self::write(
			'connection',
			array(
				'secret'  => bin2hex( random_bytes( 32 ) ),
				'created' => time(),
			)
		);
	}

	/**
	 * The key to paste into another site: this site's REST address and secret.
	 */
	public static function key() {
		$payload = wp_json_encode(
			array(
				'u' => home_url(),
				'r' => rest_url(),
				's' => self::secret(),
			)
		);
		return self::KEY_PREFIX . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Packs the URL and secret into one copyable string.
	}

	/**
	 * Decode a key pasted from another site.
	 *
	 * @param string $key Key.
	 * @return array url, rest, secret.
	 * @throws MoveKit_Exception When the key is malformed.
	 */
	public static function parse_key( $key ) {
		$key = trim( (string) $key );
		if ( 0 !== strpos( $key, self::KEY_PREFIX ) ) {
			throw new MoveKit_Exception( esc_html__( 'That is not a MoveKit connection key. Copy it from the MoveKit screen on the other site.', 'movekit-migration-backup' ) );
		}
		$raw   = base64_decode( strtr( substr( $key, strlen( self::KEY_PREFIX ) ), '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See key().
		$data  = $raw ? json_decode( $raw, true ) : null;
		$valid = is_array( $data )
			&& isset( $data['u'], $data['r'], $data['s'] )
			&& preg_match( '/^[a-f0-9]{64}$/', (string) $data['s'] );
		if ( ! $valid ) {
			throw new MoveKit_Exception( esc_html__( 'The connection key is incomplete. Copy the whole key again.', 'movekit-migration-backup' ) );
		}
		$remote = array(
			'url'    => self::clean_url( $data['u'] ),
			'rest'   => self::clean_url( $data['r'] ),
			'secret' => $data['s'],
		);
		self::check_transport( $remote );
		return $remote;
	}

	/**
	 * A web address limited to http and https.
	 *
	 * @param string $url Address.
	 * @return string
	 * @throws MoveKit_Exception When it is not a web address.
	 */
	public static function clean_url( $url ) {
		$url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
		if ( ! preg_match( '#^https?://[^/?\#]+#i', $url ) ) {
			throw new MoveKit_Exception( esc_html__( 'The connection key is incomplete. Copy the whole key again.', 'movekit-migration-backup' ) );
		}
		return $url;
	}

	/**
	 * Refuse plain http to a site on the internet, where someone on the
	 * network could tamper with what is transferred. Local development sites
	 * rarely have certificates, so they are allowed.
	 *
	 * @param array $remote url, rest.
	 * @throws MoveKit_Exception When the connection would not be encrypted.
	 */
	public static function check_transport( array $remote ) {
		if ( 0 === stripos( $remote['rest'], 'https://' ) ) {
			return;
		}
		$host  = strtolower( (string) wp_parse_url( $remote['rest'], PHP_URL_HOST ) );
		$local = self::is_local_host( $host );
		/**
		 * Filters whether a connected site may be reached over plain http.
		 *
		 * @param bool   $allowed Whether it is allowed. True for local addresses.
		 * @param string $url     The other site's address.
		 */
		if ( ! apply_filters( 'movekit_allow_http', $local, $remote['url'] ) ) {
			throw new MoveKit_Exception(
				esc_html(
					sprintf(
						/* translators: %s: site address. */
						__( '%s does not use https, so the transfer could be read or changed by others on the network. Turn on SSL for that site, then copy its connection key again.', 'movekit-migration-backup' ),
						$remote['url']
					)
				)
			);
		}
	}

	/**
	 * Refuse keys that cannot work from here: this site's own key, or a
	 * local site's key pasted on a live server, which can't reach it.
	 *
	 * @param array $remote url, rest.
	 * @throws MoveKit_Exception With what to do instead.
	 */
	public static function check_reachable( array $remote ) {
		if ( untrailingslashit( $remote['url'] ) === untrailingslashit( home_url() ) ) {
			throw new MoveKit_Exception( esc_html__( 'That is this site\'s own key. Paste the key from the other site.', 'movekit-migration-backup' ) );
		}
		$local_key = self::is_local_host( (string) wp_parse_url( $remote['url'], PHP_URL_HOST ) );
		if ( $local_key && ! self::is_local_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			throw new MoveKit_Exception( esc_html__( 'That key belongs to a local site, which this server can\'t reach. Copy this site\'s connection key instead, and paste it into MoveKit on your local site.', 'movekit-migration-backup' ) );
		}
	}

	/**
	 * Whether a host name points at this computer or a private network.
	 *
	 * @param string $host Host name or IP address.
	 * @return bool
	 */
	public static function is_local_host( $host ) {
		$host = trim( $host, '[]' );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		if ( false === strpos( $host, '.' ) ) {
			return true; // localhost, or a Docker service name.
		}
		return (bool) preg_match( '/\.(local|localhost|test|example|invalid|lan|internal|home\.arpa|docker|lndo\.site|ddev\.site)$/', $host );
	}

	/**
	 * Saved remote sites, without their secrets.
	 *
	 * @return array[] id, url, added.
	 */
	public static function remotes() {
		$out = array();
		foreach ( self::read( 'remotes' ) as $id => $r ) {
			$out[] = array(
				'id'    => $id,
				'url'   => $r['url'],
				'added' => $r['added'],
			);
		}
		return $out;
	}

	/**
	 * A saved remote site, including its secret.
	 *
	 * @param string $id Remote id.
	 * @return array
	 * @throws MoveKit_Exception When it is not saved.
	 */
	public static function remote( $id ) {
		$all = self::read( 'remotes' );
		if ( ! isset( $all[ $id ] ) ) {
			throw new MoveKit_Exception( esc_html__( 'That connected site was removed.', 'movekit-migration-backup' ) );
		}
		return $all[ $id ] + array( 'id' => $id );
	}

	/**
	 * Save a remote site, replacing any earlier entry for the same address.
	 *
	 * @param array $remote url, rest, secret.
	 * @return string Remote id.
	 */
	public static function add_remote( array $remote ) {
		$all = self::read( 'remotes' );
		foreach ( $all as $id => $r ) {
			if ( untrailingslashit( $r['url'] ) === untrailingslashit( $remote['url'] ) ) {
				unset( $all[ $id ] );
			}
		}
		$id         = bin2hex( random_bytes( 6 ) );
		$all[ $id ] = array(
			'url'    => self::clean_url( $remote['url'] ),
			'rest'   => self::clean_url( $remote['rest'] ),
			'secret' => $remote['secret'],
			'added'  => time(),
		);
		self::write( 'remotes', $all );
		return $id;
	}

	/**
	 * Forget a remote site.
	 *
	 * @param string $id Remote id.
	 */
	public static function remove_remote( $id ) {
		$all = self::read( 'remotes' );
		unset( $all[ $id ] );
		self::write( 'remotes', $all );
	}

	/* ------------------------------------------------------- signatures */

	/**
	 * Signature headers, and the query parameters that carry the same values.
	 */
	const AUTH_PARAMS = array(
		'X-MoveKit-Time'      => 'movekit_t',
		'X-MoveKit-Nonce'     => 'movekit_n',
		'X-MoveKit-Signature' => 'movekit_s',
	);

	/**
	 * Headers that prove a request comes from a holder of $secret.
	 *
	 * @param string $secret Shared secret.
	 * @param string $method HTTP method.
	 * @param string $route  REST route, e.g. /movekit/v1/remote/info.
	 * @param array  $query  Query parameters.
	 * @param string $body   Raw request body.
	 * @return array
	 */
	public static function sign( $secret, $method, $route, array $query, $body ) {
		$time  = (string) time();
		$nonce = bin2hex( random_bytes( 12 ) );
		return array(
			'X-MoveKit-Time'      => $time,
			'X-MoveKit-Nonce'     => $nonce,
			'X-MoveKit-Signature' => self::signature( $secret, $method, $route, $query, $body, $time, $nonce ),
		);
	}

	/**
	 * Check a signed request against this site's secret.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function verify( $request ) {
		// Sign what was sent, not the parameters after WordPress sanitized them
		// (it turns "a,b" into an array for array arguments, for example).
		$query = array();
		parse_str( isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : '', $query ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared byte for byte with the signature, never output or stored.
		unset( $query['rest_route'] );
		$auth = array();
		foreach ( self::AUTH_PARAMS as $header => $param ) {
			$auth[ $header ] = (string) $request->get_header( strtolower( str_replace( '-', '_', $header ) ) );
			if ( '' === $auth[ $header ] && isset( $query[ $param ] ) && is_string( $query[ $param ] ) ) {
				// Some hosts drop unknown headers; the same values also travel in the address.
				$auth[ $header ] = $query[ $param ];
			}
			unset( $query[ $param ] );
		}
		$time  = $auth['X-MoveKit-Time'];
		$nonce = $auth['X-MoveKit-Nonce'];
		$sig   = $auth['X-MoveKit-Signature'];
		$deny  = function ( $why ) {
			return new WP_Error(
				'movekit_forbidden',
				sprintf(
					/* translators: 1: site address, 2: technical reason. */
					__( 'The connection key was not accepted. Copy the current key from %1$s and connect again. (%2$s)', 'movekit-migration-backup' ),
					home_url(),
					$why
				),
				array( 'status' => 403 )
			);
		};
		if ( '' === $sig || ! ctype_digit( $time ) || ! preg_match( '/^[a-f0-9]{24}$/', $nonce ) ) {
			return new WP_Error(
				'movekit_unsigned',
				sprintf(
					/* translators: %s: site address. */
					__( 'The request reached %s without its MoveKit signature. A proxy, cache or security plugin in front of that site removed it. Ask the host to pass requests to /wp-json/movekit/ through unchanged.', 'movekit-migration-backup' ),
					home_url()
				),
				array( 'status' => 403 )
			);
		}
		if ( abs( time() - (int) $time ) > 600 ) {
			return new WP_Error( 'movekit_clock', __( 'The two sites\' clocks are more than 10 minutes apart. Check the date and time on both computers.', 'movekit-migration-backup' ), array( 'status' => 403 ) );
		}
		$expected = self::signature( self::secret(), $request->get_method(), $request->get_route(), $query, (string) $request->get_body(), $time, $nonce );
		if ( ! hash_equals( $expected, $sig ) ) {
			return $deny( __( 'signature mismatch', 'movekit-migration-backup' ) );
		}
		try {
			$used = self::use_nonce( $nonce, (int) $time );
		} catch ( MoveKit_Exception $e ) {
			return new WP_Error(
				'movekit_storage',
				sprintf(
					/* translators: 1: site address, 2: error. */
					__( 'MoveKit on %1$s could not record the request in its database: %2$s', 'movekit-migration-backup' ),
					home_url(),
					$e->text()
				),
				array( 'status' => 500 )
			);
		}
		if ( ! $used ) {
			return $deny( __( 'request received twice', 'movekit-migration-backup' ) );
		}
		return true;
	}

	/**
	 * HMAC over everything that matters in a request.
	 *
	 * @param string $secret Secret.
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $query  Query parameters.
	 * @param string $body   Body.
	 * @param string $time   Unix time.
	 * @param string $nonce  Random value.
	 * @return string
	 */
	private static function signature( $secret, $method, $route, array $query, $body, $time, $nonce ) {
		ksort( $query );
		$canonical = implode( "\n", array( strtoupper( $method ), '/' . ltrim( $route, '/' ), http_build_query( $query ), hash( 'sha256', $body ), $time, $nonce ) );
		return hash_hmac( 'sha256', $canonical, $secret );
	}

	/**
	 * Refuse a nonce that was already used, so a captured request can't be replayed.
	 *
	 * Kept in the options table, not a file: some hosts (WP Engine, for one)
	 * don't let PHP create files in wp-content during these requests. The
	 * unique option name makes the check and the record one atomic step.
	 *
	 * @param string $nonce Nonce.
	 * @param int    $time  Request time.
	 * @return bool False when already used.
	 * @throws MoveKit_Exception When the database refuses the record.
	 */
	private static function use_nonce( $nonce, $time ) {
		global $wpdb;
		$prefix = 'movekit_nonce_';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- An atomic insert-or-fail; the options API can't tell "already there" from "added", and these rows must never be cached.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( $prefix ) . '%',
				time() - 1200
			)
		);
		$added = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$prefix . $nonce,
				(string) $time
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( false === $added ) {
			throw new MoveKit_Exception( esc_html( $wpdb->last_error ) );
		}
		return 1 === (int) $added;
	}

	/* ------------------------------------------------------------ files */

	/**
	 * Path of a private data file.
	 *
	 * @param string $name Base name.
	 */
	private static function path( $name ) {
		return MoveKit_Storage::dir() . '/' . $name . '.php';
	}

	/**
	 * Read a private data file.
	 *
	 * @param string $name Base name.
	 * @return array
	 */
	private static function read( $name ) {
		$file = self::path( $name );
		$raw  = is_file( $file ) ? file_get_contents( $file ) : '';
		$data = json_decode( (string) substr( (string) $raw, strlen( self::GUARD ) ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Write a private data file atomically.
	 *
	 * @param string $name Base name.
	 * @param array  $data Data.
	 * @throws MoveKit_Exception When it cannot be written.
	 */
	private static function write( $name, array $data ) {
		MoveKit_Storage::ensure();
		$file = self::path( $name );
		// A .php name, so the guard line keeps it from being shown if it is left behind.
		$tmp = substr( $file, 0, -4 ) . '-' . bin2hex( random_bytes( 6 ) ) . '.tmp.php';
		if ( false === @file_put_contents( $tmp, self::GUARD . wp_json_encode( $data ) ) || ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reported below.
			$error = error_get_last();
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A leftover temporary file is harmless.
			throw new MoveKit_Exception(
				esc_html(
					sprintf(
						/* translators: 1: file path, 2: PHP error message. */
						__( 'Could not save %1$s: %2$s', 'movekit-migration-backup' ),
						$file,
						$error ? $error['message'] : __( 'unknown error', 'movekit-migration-backup' )
					)
				)
			);
		}
		chmod( $file, 0600 ); // Best effort; some hosts ignore it.
	}
}
