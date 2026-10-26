<?php
/**
 * Locations of backups and job working folders, and their protection.
 *
 * Job state lives on disk rather than in the database, because a restore
 * replaces the database while the job is still running.
 *
 * Backups are streamed in pieces of a few MB with plain PHP file functions:
 * WP_Filesystem has no append/seek/stream operations and would have to hold
 * whole multi-GB files in memory.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage paths and file helpers.
 */
class MoveKit_Storage {

	const ARCHIVE_EXT = 'movekit';

	/**
	 * Folder holding backups.
	 */
	public static function dir() {
		/**
		 * Filters the folder where backups are stored.
		 *
		 * @param string $dir Absolute path.
		 */
		$dir = apply_filters( 'movekit_storage_dir', WP_CONTENT_DIR . '/movekit-backups' );
		return rtrim( str_replace( '\\', '/', $dir ), '/' );
	}

	/**
	 * Folder holding job working folders.
	 */
	public static function jobs_dir() {
		return self::dir() . '/jobs';
	}

	/**
	 * Create the storage folders and block direct web access to them.
	 *
	 * @throws MoveKit_Exception When a folder cannot be created.
	 */
	public static function ensure() {
		foreach ( array( self::dir(), self::jobs_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				/* translators: %s: folder path. */
				throw new MoveKit_Exception( esc_html( sprintf( __( 'Could not create folder %s. Check that wp-content is writable.', 'movekit-migration-backup' ), $dir ) ) );
			}
			self::protect( $dir );
		}
	}

	/**
	 * Add files that stop web servers from serving the folder's contents.
	 *
	 * @param string $dir Folder.
	 */
	private static function protect( $dir ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $content );
			}
		}
	}

	/**
	 * Backups in the storage folder, newest first.
	 *
	 * @return array[] Each with name, path, size, mtime.
	 */
	public static function list_backups() {
		$out = array();
		foreach ( (array) glob( self::dir() . '/*.' . self::ARCHIVE_EXT ) as $path ) {
			$out[] = array(
				'name'  => basename( $path ),
				'path'  => $path,
				'size'  => filesize( $path ),
				'mtime' => filemtime( $path ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['mtime'] - $a['mtime'];
			}
		);
		return $out;
	}

	/**
	 * Resolve a backup name to a path inside the storage folder, refusing anything else.
	 *
	 * @param string $name File name.
	 * @throws MoveKit_Exception When the name is not a valid backup name.
	 */
	public static function backup_path( $name ) {
		$name = basename( (string) $name );
		if ( ! preg_match( '/^[A-Za-z0-9._-]+\.' . self::ARCHIVE_EXT . '$/', $name ) ) {
			throw new MoveKit_Exception( esc_html__( 'Invalid backup name.', 'movekit-migration-backup' ) );
		}
		return self::dir() . '/' . $name;
	}

	/**
	 * Make a backup readable only by the account PHP runs as, so other accounts
	 * on a shared server can't read the database inside it. Best effort: some
	 * hosts ignore it.
	 *
	 * @param string $path File.
	 */
	public static function make_private( $path ) {
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
	}

	/**
	 * A file name that is hard to guess, since nginx ignores .htaccess.
	 */
	public static function new_backup_name() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$slug = sanitize_file_name( $host ? $host : 'site' );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		if ( $port ) {
			$slug .= '-' . $port;
		}
		return sprintf( '%s-%s-%s.%s', $slug, gmdate( 'Ymd-His' ), strtolower( wp_generate_password( 16, false ) ), self::ARCHIVE_EXT );
	}

	/**
	 * Move a finished upload into the backups folder under a free name.
	 *
	 * @param string $part Uploaded file.
	 * @param string $name Original file name.
	 * @return string Stored file name.
	 * @throws MoveKit_Exception When the file cannot be moved.
	 */
	public static function store_upload( $part, $name ) {
		$base = preg_replace( '/[^A-Za-z0-9._-]/', '-', preg_replace( '/\.' . self::ARCHIVE_EXT . '$/i', '', basename( (string) $name ) ) );
		$base = substr( ltrim( $base, '.' ), 0, 120 );
		// A random ending, so nobody can fetch the file by guessing its name.
		do {
			$final = ( '' === $base ? 'upload' : $base ) . '-' . strtolower( wp_generate_password( 16, false ) ) . '.' . self::ARCHIVE_EXT;
		} while ( file_exists( self::dir() . '/' . $final ) );
		if ( ! rename( $part, self::dir() . '/' . $final ) ) {
			throw new MoveKit_Exception( esc_html__( 'Could not save the upload.', 'movekit-migration-backup' ) );
		}
		self::make_private( self::dir() . '/' . $final );
		return $final;
	}

	/**
	 * Add one piece to an upload in progress, and store the backup once all
	 * pieces are in. Pieces must arrive in order; when one doesn't match,
	 * the returned offset tells the sender where to continue from.
	 *
	 * @param string $upload_id 32 hex characters chosen by the sender.
	 * @param string $name      Original file name.
	 * @param int    $total     Full file size.
	 * @param int    $offset    Where this piece starts.
	 * @param string $src       File holding this piece.
	 * @param int    $room      Free space needed, as a multiple of the file size.
	 * @return array offset, and done + name once complete.
	 * @throws MoveKit_Exception On invalid input, write failure or a damaged backup.
	 */
	public static function receive_chunk( $upload_id, $name, $total, $offset, $src, $room = 1 ) {
		$name = sanitize_file_name( $name );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $upload_id ) || $total < 1 || $offset < 0 ) {
			throw new MoveKit_Exception( esc_html__( 'Invalid upload.', 'movekit-migration-backup' ) );
		}
		if ( ! preg_match( '/\.' . self::ARCHIVE_EXT . '$/i', $name ) ) {
			throw new MoveKit_Exception( esc_html__( 'Choose a .movekit backup file.', 'movekit-migration-backup' ) );
		}
		self::ensure();
		$part = self::dir() . '/upload-' . $upload_id . '.part';
		clearstatcache();
		$current = is_file( $part ) ? filesize( $part ) : 0;
		if ( $offset !== $current ) {
			return array( 'offset' => $current );
		}
		$problem = 0 === $offset ? self::space_problem( $total * $room ) : null;
		if ( $problem ) {
			throw new MoveKit_Exception( esc_html( $problem ) );
		}
		if ( ! self::append_file( $src, $part ) ) {
			throw new MoveKit_Exception( esc_html__( 'Could not save the upload. The disk may be full.', 'movekit-migration-backup' ) );
		}
		clearstatcache();
		$current = filesize( $part );
		if ( $current < $total ) {
			return array( 'offset' => $current );
		}
		if ( $current > $total ) {
			wp_delete_file( $part );
			throw new MoveKit_Exception( esc_html__( 'The upload got mixed up. Please try again.', 'movekit-migration-backup' ) );
		}
		try {
			$reader = new MoveKit_Archive_Reader( $part );
			$reader->summary();
			$reader->close();
		} catch ( MoveKit_Exception $e ) {
			wp_delete_file( $part );
			throw $e;
		}
		return array(
			'offset' => $current,
			'done'   => true,
			'name'   => self::store_upload( $part, $name ),
		);
	}

	/**
	 * Append one file to another.
	 *
	 * @param string $src  File to read.
	 * @param string $dest File to append to.
	 * @return bool
	 */
	public static function append_file( $src, $dest ) {
		$in  = fopen( $src, 'rb' );
		$out = fopen( $dest, 'ab' );
		$ok  = $in && $out && false !== stream_copy_to_stream( $in, $out );
		if ( $in ) {
			fclose( $in );
		}
		if ( $out ) {
			fclose( $out );
		}
		return $ok;
	}

	/**
	 * Send part of a file to the browser in 1 MB pieces.
	 *
	 * @param string $path   File.
	 * @param int    $start  First byte.
	 * @param int    $length Number of bytes.
	 */
	public static function stream( $path, $start, $length ) {
		$fh = fopen( $path, 'rb' );
		fseek( $fh, $start );
		while ( $length > 0 && ! feof( $fh ) && ! connection_aborted() ) {
			$buf     = fread( $fh, min( 1048576, $length ) );
			$length -= strlen( $buf );
			echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file download.
			flush();
		}
		fclose( $fh );
	}

	/**
	 * Whether the backups folder's disk lacks room for $bytes more, with a
	 * 10% margin. Some hosts hide the free space; then nothing is said.
	 *
	 * @param int $bytes Space needed.
	 * @return string|null What to tell the user, or null when there is room.
	 */
	public static function space_problem( $bytes ) {
		$dir  = is_dir( self::dir() ) ? self::dir() : WP_CONTENT_DIR;
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Often disabled by hosts.
		$need = (int) ( $bytes * 1.1 );
		if ( false === $free || $free >= $need ) {
			return null;
		}
		return sprintf(
			/* translators: 1: space needed, 2: space free. */
			__( 'Not enough disk space: this needs about %1$s free, but the server has %2$s. Delete old backups or ask your host for more space.', 'movekit-migration-backup' ),
			size_format( $need, 1 ),
			size_format( $free, 1 )
		);
	}

	/**
	 * Remove a folder and everything in it.
	 *
	 * @param string $dir Folder.
	 */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			if ( $f->isDir() && ! $f->isLink() ) {
				rmdir( $f->getPathname() );
			} else {
				wp_delete_file( $f->getPathname() );
			}
		}
		rmdir( $dir );
	}
}
