<?php
/**
 * Talks to MoveKit on another site with signed requests.
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client for a connected site's remote routes.
 */
class MoveKit_Remote_Client {

	/**
	 * Remote site: url, rest, secret.
	 *
	 * @var array
	 */
	private $remote;

	/**
	 * Constructor.
	 *
	 * @param array $remote url, rest, secret.
	 */
	public function __construct( array $remote ) {
		$this->remote = $remote;
	}

	/**
	 * Basic facts about the remote site; also proves the key works.
	 *
	 * @return array
	 */
	public function info() {
		return $this->call( 'GET', 'remote/info' );
	}

	/**
	 * Start a backup on the remote site.
	 *
	 * @param string[] $parts   What to include.
	 * @param string[] $exclude Patterns of files to skip.
	 * @param string   $known   Fingerprints of files this site already has, which are left out.
	 * @return array job, token.
	 */
	public function start_export( array $parts = MoveKit_Parts::ALL, array $exclude = array(), $known = '' ) {
		return $this->call(
			'POST',
			'remote/backups',
			array(
				'parts'   => implode( ',', $parts ),
				'exclude' => implode( "\n", $exclude ),
			),
			$known
		);
	}

	/**
	 * Fingerprints of the files the remote site has.
	 *
	 * @param string[] $parts   What to include.
	 * @param string[] $exclude Patterns of files to skip.
	 * @return string Binary, see MoveKit_File_Scanner::fingerprints().
	 * @throws MoveKit_Exception When the reply is not a fingerprint list.
	 */
	public function fingerprints( array $parts, array $exclude ) {
		$data = $this->call(
			'POST',
			'remote/fingerprints',
			array(
				'parts'   => implode( ',', $parts ),
				'exclude' => implode( "\n", $exclude ),
			),
			'',
			true
		);
		if ( 0 !== strlen( $data ) % MoveKit_File_Scanner::FINGERPRINT ) {
			throw new MoveKit_Exception( esc_html__( 'The other site sent an unreadable file list.', 'movekit-migration-backup' ) );
		}
		return $data;
	}

	/**
	 * Start restoring a backup that is on the remote site.
	 *
	 * @param string $name Backup file name.
	 * @return array job, token.
	 */
	public function start_import( $name ) {
		return $this->call( 'POST', 'remote/backups/' . rawurlencode( $name ) . '/restore' );
	}

	/**
	 * Delete a backup on the remote site.
	 *
	 * @param string $name Backup file name.
	 */
	public function delete( $name ) {
		return $this->call( 'DELETE', 'remote/backups/' . rawurlencode( $name ) );
	}

	/**
	 * Abandon a job on the remote site, undoing its unfinished work.
	 *
	 * @param array $job job, token.
	 */
	public function discard_job( array $job ) {
		return $this->call( 'POST', 'remote/jobs/' . $job['job'] . '/discard' );
	}

	/**
	 * Delete a partly sent upload on the remote site.
	 *
	 * @param string $upload_id Upload id.
	 */
	public function discard_upload( $upload_id ) {
		return $this->call( 'DELETE', 'remote/uploads/' . $upload_id );
	}

	/**
	 * Run one step of a job on the remote site.
	 *
	 * @param array $job job, token.
	 * @return array Job status.
	 */
	public function step( array $job ) {
		// The token goes in the body so it stays out of server access logs.
		return $this->call( 'POST', 'jobs/' . $job['job'] . '/step', array(), wp_json_encode( array( 'token' => $job['token'] ) ), false, 'application/json' );
	}

	/**
	 * Fetch part of a backup from the remote site.
	 *
	 * @param string $name   Backup file name.
	 * @param int    $offset First byte.
	 * @param int    $length Number of bytes.
	 * @return string Bytes.
	 * @throws MoveKit_Retry_Exception When fewer bytes arrive than asked for.
	 */
	public function download( $name, $offset, $length ) {
		$data = $this->call(
			'GET',
			'remote/backups/' . rawurlencode( $name ) . '/chunk',
			array(
				'offset' => $offset,
				'length' => $length,
			),
			'',
			true
		);
		if ( strlen( $data ) !== $length ) {
			throw new MoveKit_Retry_Exception( esc_html__( 'A download from the other site was cut short.', 'movekit-migration-backup' ) );
		}
		return $data;
	}

	/**
	 * Send part of a backup to the remote site.
	 *
	 * @param string $upload_id Upload id.
	 * @param string $name      File name.
	 * @param int    $total     Full size.
	 * @param int    $offset    Where this piece starts.
	 * @param string $data      Bytes.
	 * @return array offset, and done + name once complete.
	 */
	public function upload( $upload_id, $name, $total, $offset, $data ) {
		return $this->call(
			'POST',
			'remote/uploads',
			array(
				'upload_id' => $upload_id,
				'name'      => $name,
				'total'     => $total,
				'offset'    => $offset,
			),
			$data
		);
	}

	/**
	 * Make a signed request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route below movekit/v1/.
	 * @param array  $query  Query parameters.
	 * @param string $body   Raw body.
	 * @param bool   $raw    Return the body as-is instead of decoding JSON.
	 * @param string $type   Content type of the body.
	 * @return mixed
	 * @throws MoveKit_Retry_Exception On network trouble or server errors.
	 * @throws MoveKit_Exception When the remote site refuses the request.
	 */
	private function call( $method, $path, array $query = array(), $body = '', $raw = false, $type = 'application/octet-stream' ) {
		MoveKit_Connection::check_transport( $this->remote );
		$route   = '/' . MoveKit_REST::NS . '/' . $path;
		$base    = $this->remote['rest'];
		$url     = false !== strpos( $base, 'rest_route=' )
			? $base . ltrim( $route, '/' )
			: trailingslashit( $base ) . ltrim( $route, '/' );
		$headers = MoveKit_Connection::sign( $this->remote['secret'], $method, $route, $query, $body );
		if ( '' !== $body ) {
			$headers['Content-Type'] = $type;
			// cURL otherwise waits up to a second per piece for a "100 Continue"
			// that some servers never send.
			$headers['Expect'] = '';
		}
		// The signature also goes in the address: some hosts drop unknown
		// headers, and a unique address keeps caches from answering instead
		// of WordPress.
		foreach ( MoveKit_Connection::AUTH_PARAMS as $header => $param ) {
			$query[ $param ] = $headers[ $header ];
		}
		$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );

		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => '' === $body ? null : $body,
				// Pieces of a backup can take a while on a slow line; everything else is quick.
				'timeout'     => ( '' !== $body || $raw ) ? 120 : 60,
				'redirection' => 0,
				/**
				 * Filters whether to verify the other site's SSL certificate.
				 * Turn off only for local sites with self-signed certificates.
				 *
				 * @param bool  $verify Whether to verify.
				 * @param array $remote Remote site url.
				 */
				'sslverify'   => apply_filters( 'movekit_sslverify', true, $this->remote['url'] ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new MoveKit_Retry_Exception(
				esc_html(
					sprintf(
						/* translators: 1: site address, 2: error. */
						__( 'Could not reach %1$s: %2$s', 'movekit-migration-backup' ),
						$this->remote['url'],
						$response->get_error_message()
					)
				)
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$content = wp_remote_retrieve_body( $response );
		if ( $code >= 200 && $code < 300 ) {
			if ( $raw ) {
				return $content;
			}
			$data = self::decode( $content );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		$data = self::decode( $content );
		if ( $code >= 300 && $code < 400 ) {
			throw new MoveKit_Exception(
				esc_html(
					sprintf(
						/* translators: %s: site address. */
						__( '%s redirected the request, so its address has probably changed. Copy a fresh connection key from it.', 'movekit-migration-backup' ),
						$this->remote['url']
					)
				)
			);
		}
		if ( $code >= 500 && is_array( $data ) && isset( $data['code'], $data['message'] ) && 'movekit_' === substr( (string) $data['code'], 0, 8 ) ) {
			// MoveKit's own server-side errors won't go away by retrying.
			throw new MoveKit_Exception(
				esc_html(
					sprintf(
						/* translators: %s: error from the other site. */
						__( 'The other site said: %s', 'movekit-migration-backup' ),
						$data['message']
					)
				)
			);
		}
		if ( 'internal_server_error' === ( is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '' ) ) {
			throw new MoveKit_Retry_Exception(
				esc_html(
					sprintf(
						/* translators: %s: site address. */
						__( 'A PHP error stopped WordPress on %s. Its PHP error log, or the email WordPress sent to its administrator, names the plugin or file at fault.', 'movekit-migration-backup' ),
						$this->remote['url']
					)
				)
			);
		}
		if ( $code < 500 && is_array( $data ) && isset( $data['code'], $data['message'] ) ) {
			if ( 'rest_no_route' === $data['code'] ) {
				throw new MoveKit_Exception( esc_html__( 'MoveKit is not active on the other site, or it is an older version. Install the same MoveKit version on both sites.', 'movekit-migration-backup' ) );
			}
			throw new MoveKit_Exception(
				esc_html(
					sprintf(
						/* translators: %s: error from the other site. */
						__( 'The other site said: %s', 'movekit-migration-backup' ),
						$data['message']
					)
				)
			);
		}
		if ( $code >= 400 && $code < 500 && 429 !== $code ) {
			// WordPress always answers REST requests with JSON, so this came from
			// something in front of it. Retrying won't get past it.
			throw new MoveKit_Exception( esc_html( $this->blocked_message( $response, $code ) ) );
		}
		throw new MoveKit_Retry_Exception(
			esc_html(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The other site returned an error (HTTP %d).', 'movekit-migration-backup' ),
					$code
				)
			)
		);
	}

	/**
	 * Decode a JSON reply, tolerating a byte-order mark or PHP notices printed
	 * before it by a plugin or theme on the other site.
	 *
	 * @param string $content Response body.
	 * @return mixed
	 */
	private static function decode( $content ) {
		$content = ltrim( (string) $content, "\xEF\xBB\xBF \t\r\n" );
		$data    = json_decode( $content, true );
		if ( null === $data && 0 !== strpos( $content, '{' ) ) {
			$start = strpos( $content, '{"' );
			$data  = false === $start ? null : json_decode( substr( $content, $start ), true );
		}
		return $data;
	}

	/**
	 * Explain a refusal that came from a firewall or the web server rather than WordPress.
	 *
	 * @param array $response HTTP response.
	 * @param int   $code     HTTP status code.
	 * @return string
	 */
	private function blocked_message( $response, $code ) {
		$header  = function ( $name ) use ( $response ) {
			return (string) wp_remote_retrieve_header( $response, $name );
		};
		$server  = strtolower( $header( 'server' ) );
		$body    = substr( (string) wp_remote_retrieve_body( $response ), 0, 4096 );
		$service = '';
		$id      = '';
		$advice  = '';
		if ( '' !== $header( 'x-hcdn-request-id' ) || false !== strpos( $server, 'hcdn' ) ) {
			$service = 'Hostinger CDN';
			$id      = $header( 'x-hcdn-request-id' );
			$advice  = __( 'In hPanel, open Websites, then Dashboard, Performance, CDN, Manage, and on the Security tab set the security level to Medium or Low. If that is not enough, disable the CDN while you transfer.', 'movekit-migration-backup' );
		} elseif ( '' !== $header( 'cf-ray' ) ) {
			$service = 'Cloudflare';
			$id      = $header( 'cf-ray' );
			$advice  = __( 'In Cloudflare, check Security, then Events, for the blocked request and add a rule that skips it.', 'movekit-migration-backup' );
		} elseif ( '' !== $header( 'x-sucuri-id' ) || false !== strpos( $server, 'sucuri' ) ) {
			$service = 'Sucuri';
		} elseif ( false !== stripos( $body, 'wordfence' ) ) {
			$service = 'Wordfence';
			$advice  = __( 'In Wordfence on that site, check Tools, then Live Traffic, and allowlist the blocked request.', 'movekit-migration-backup' );
		} elseif ( false !== stripos( $body, 'imunify' ) ) {
			$service = 'Imunify360';
		} elseif ( false !== stripos( $body, 'mod_security' ) || false !== stripos( $body, 'modsecurity' ) ) {
			$service = 'ModSecurity';
		}

		$message = sprintf(
			/* translators: 1: site address, 2: HTTP status code. */
			__( 'A firewall or security setting on %1$s blocked the request before it reached WordPress (HTTP %2$d).', 'movekit-migration-backup' ),
			$this->remote['url'],
			$code
		);
		if ( '' !== $service ) {
			/* translators: %s: name of a firewall or security service. */
			$message .= ' ' . sprintf( __( 'It was blocked by %s.', 'movekit-migration-backup' ), $service );
		}
		if ( '' !== $advice ) {
			$message .= ' ' . $advice;
		} else {
			$message .= ' ' . __( 'Check the firewall or security plugin on that site, or ask the host to allow requests to /wp-json/movekit/.', 'movekit-migration-backup' );
		}
		if ( '' !== $id ) {
			/* translators: %s: request ID from the firewall. */
			$message .= ' ' . sprintf( __( 'Request ID for the host: %s', 'movekit-migration-backup' ), $id );
		}
		return $message;
	}
}
