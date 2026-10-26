<?php
/**
 * Lists the files under wp-content that go into a backup.
 *
 * Backups are read and written in pieces with plain PHP file functions:
 * WP_Filesystem has no seek/append/stream operations and would need whole
 * multi-GB files in memory.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

class MoveKit_File_Scanner {

	/**
	 * Paths relative to wp-content that are never backed up or overwritten.
	 */
	public static function excluded_paths() {
		$storage = self::relative( MoveKit_Storage::dir() );
		$plugin  = self::relative( WP_PLUGIN_DIR . '/' . dirname( MOVEKIT_BASENAME ) );
		$paths   = array(
			'cache',
			'upgrade',
			'upgrade-temp-backup',
			'object-cache.php',
			'advanced-cache.php',
			'ai1wm-backups',
			'updraft',
			'backups-dup-lite',
			'backups-dup-pro',
			'wpvividbackups',
			'backup-db',
			// Caches and logs that are rebuilt on their own.
			'et-cache',
			'litespeed',
			'wflogs',
			'debug.log',
			// Must-use plugins that hosts install and manage themselves.
			'mu-plugins/wpengine-common',
			'mu-plugins/wpe-wp-sign-on-plugin',
			'mu-plugins/wpe-wp-sign-on-plugin.php',
			'mu-plugins/wpengine-security-auditor.php',
			'mu-plugins/wpe-cache-plugin',
			'mu-plugins/wpe-cache-plugin.php',
			'mu-plugins/force-strong-passwords',
			'mu-plugins/slt-force-strong-passwords.php',
			'mu-plugins/kinsta-mu-plugins',
			'mu-plugins/kinsta-mu-plugins.php',
			'mu-plugins/gd-system-plugin',
			'mu-plugins/gd-system-plugin.php',
			'mu-plugins/endurance-page-cache.php',
			'mu-plugins/endurance-browser-cache.php',
			// WP Engine's own nightly database dump, unreadable to PHP there.
			'mysql.sql',
		);
		// WP Engine's loader has a generic name, so check that it is theirs.
		$loader = WP_CONTENT_DIR . '/mu-plugins/mu-plugin.php';
		if ( is_readable( $loader ) && false !== strpos( (string) file_get_contents( $loader, false, null, 0, 8192 ), 'wpengine' ) ) {
			$paths[] = 'mu-plugins/mu-plugin.php';
		}
		if ( null !== $storage ) {
			$paths[] = $storage;
		}
		if ( null !== $plugin ) {
			$paths[] = $plugin;
		}
		return array_values( array_unique( apply_filters( 'movekit_excluded_paths', $paths ) ) );
	}

	/**
	 * Names skipped wherever they appear: version control, build tools and
	 * operating system junk.
	 */
	const SKIPPED_NAMES = array( '.git', '.svn', '.hg', 'node_modules', '.DS_Store', 'Thumbs.db', 'desktop.ini' );

	public static function is_excluded( $rel, array $excluded ) {
		foreach ( $excluded as $ex ) {
			if ( $rel === $ex || 0 === strpos( $rel, $ex . '/' ) ) {
				return true;
			}
		}
		return in_array( basename( $rel ), self::SKIPPED_NAMES, true );
	}

	/**
	 * Clean up exclusion patterns typed by a person: one per line or comma.
	 *
	 * @param string|string[]|null $patterns Patterns.
	 * @return string[]
	 */
	public static function sanitize_patterns( $patterns ) {
		if ( is_string( $patterns ) ) {
			$patterns = preg_split( '/[\r\n,]+/', $patterns );
		}
		$out = array();
		foreach ( (array) $patterns as $p ) {
			$p = trim( str_replace( '\\', '/', (string) $p ) );
			$p = trim( preg_replace( '#/+#', '/', $p ), '/' );
			if ( '' !== $p && false === strpos( $p, '..' ) && strlen( $p ) <= 200 ) {
				$out[] = sanitize_text_field( $p );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 100 );
	}

	/**
	 * Patterns saved on the MoveKit screen.
	 *
	 * @return string[]
	 */
	public static function saved_patterns() {
		return self::sanitize_patterns( get_option( 'movekit_exclude', array() ) );
	}

	/**
	 * Whether a path matches one of the patterns. A pattern without a slash
	 * matches a file or folder name anywhere (node_modules, *.zip); one with
	 * a slash matches from wp-content (uploads/2019, plugins/x/cache).
	 *
	 * @param string   $rel      Path relative to wp-content.
	 * @param string[] $patterns Patterns.
	 */
	public static function matches( $rel, array $patterns ) {
		$name = basename( $rel );
		foreach ( $patterns as $p ) {
			if ( false === strpos( $p, '/' ) ? fnmatch( $p, $name ) : ( fnmatch( $p, $rel ) || 0 === strpos( $rel, $p . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Bytes in one file fingerprint. */
	const FINGERPRINT = 8;

	/** Most files a fingerprint list may describe. */
	const MAX_FINGERPRINTS = 300000;

	/**
	 * Short fingerprint of a file's path, size and modification time. A file
	 * MoveKit copied keeps its time, so a match means it is unchanged.
	 *
	 * @param string $rel   Path relative to wp-content.
	 * @param int    $size  Bytes.
	 * @param int    $mtime Modification time.
	 * @return string Binary.
	 */
	public static function fingerprint( $rel, $size, $mtime ) {
		return substr( md5( $rel . "\t" . (int) $size . "\t" . (int) $mtime, true ), 0, self::FINGERPRINT );
	}

	/**
	 * Sorted fingerprints of the files this site has, so the site sending a
	 * push or pull can leave out the ones that are already here.
	 *
	 * @param string[] $parts    Parts to include.
	 * @param string[] $patterns Exclusion patterns.
	 * @return string Binary; empty when there are too many files to compare.
	 * @throws MoveKit_Exception When the list can't be written.
	 */
	public static function fingerprints( array $parts, array $patterns ) {
		MoveKit_Storage::ensure();
		$list = MoveKit_Storage::dir() . '/fingerprints-' . bin2hex( random_bytes( 8 ) ) . '.txt';
		try {
			$scan = self::scan( $list, $parts, $patterns );
			if ( $scan['files'] > self::MAX_FINGERPRINTS ) {
				return '';
			}
			$out = array();
			$fh  = fopen( $list, 'rb' );
			while ( $fh && false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard fgets loop.
				$f = explode( "\t", rtrim( $line, "\n" ) );
				if ( 5 === count( $f ) && 'F' === $f[4] ) {
					$out[] = self::fingerprint( $f[0], $f[1], $f[2] );
				}
			}
			if ( $fh ) {
				fclose( $fh );
			}
		} finally {
			wp_delete_file( $list );
		}
		sort( $out, SORT_STRING );
		return implode( '', array_unique( $out ) );
	}

	/**
	 * Whether a fingerprint is in a sorted list from fingerprints().
	 *
	 * @param string $known       Sorted fingerprints.
	 * @param string $fingerprint Fingerprint to look for.
	 */
	public static function is_known( $known, $fingerprint ) {
		$lo = 0;
		$hi = intdiv( strlen( $known ), self::FINGERPRINT ) - 1;
		while ( $lo <= $hi ) {
			$mid = ( $lo + $hi ) >> 1;
			$cmp = strcmp( substr( $known, $mid * self::FINGERPRINT, self::FINGERPRINT ), $fingerprint );
			if ( 0 === $cmp ) {
				return true;
			}
			if ( $cmp < 0 ) {
				$lo = $mid + 1;
			} else {
				$hi = $mid - 1;
			}
		}
		return false;
	}

	/**
	 * Write the file list to $list_file, one "path\tsize\tmtime\tperms\ttype" line each.
	 *
	 * @param string   $list_file Where to write the list.
	 * @param string[] $parts     Parts to include (see MoveKit_Parts).
	 * @param string[] $patterns  Exclusion patterns (see matches()).
	 * @param string   $known     Fingerprints of files the receiving site already has; they are left out.
	 * @return array files, bytes, skipped (unreadable paths), links (symlinked folders followed), unchanged, unchanged_bytes.
	 */
	public static function scan( $list_file, array $parts = MoveKit_Parts::ALL, array $patterns = array(), $known = '' ) {
		$root     = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		$excluded = self::excluded_paths();
		$roots    = array( '' );
		if ( ! in_array( 'other', $parts, true ) ) {
			$roots = array();
		}
		foreach ( MoveKit_Parts::folders() as $part => $rel ) {
			if ( null === $rel ) {
				continue;
			}
			if ( ! in_array( $part, $parts, true ) ) {
				$excluded[] = $rel;
			} elseif ( ! $roots || '' !== $roots[0] ) {
				$roots[] = $rel;
			}
		}
		$out = @fopen( $list_file, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
		if ( ! $out ) {
			throw new MoveKit_Exception( esc_html__( 'Could not write the file list.', 'movekit-migration-backup' ) );
		}
		$stats = array(
			'files'           => 0,
			'bytes'           => 0,
			'skipped'         => array(),
			'links'           => array(),
			'patterns'        => $patterns,
			'seen'            => array(),
			'known'           => (string) $known,
			'unchanged'       => 0,
			'unchanged_bytes' => 0,
		);
		foreach ( $roots as $rel ) {
			if ( '' === $rel || is_dir( $root . '/' . $rel ) ) {
				self::walk( $root, $rel, $excluded, $out, $stats );
			}
		}
		unset( $stats['patterns'], $stats['seen'], $stats['known'] );
		fclose( $out );
		return $stats;
	}

	private static function walk( $root, $rel_dir, array $excluded, $out, array &$stats ) {
		$abs  = '' === $rel_dir ? $root : $root . '/' . $rel_dir;
		$real = realpath( $abs );
		if ( false !== $real ) {
			if ( isset( $stats['seen'][ $real ] ) ) {
				return; // A symlink loop, or a folder linked twice.
			}
			$stats['seen'][ $real ] = true;
		}
		$dh = @opendir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
		if ( ! $dh ) {
			$stats['skipped'][] = $rel_dir;
			return;
		}
		$entries = array();
		while ( false !== ( $name = readdir( $dh ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard readdir loop.
			if ( '.' !== $name && '..' !== $name ) {
				$entries[] = $name;
			}
		}
		closedir( $dh );
		sort( $entries, SORT_STRING );

		$kept = 0;
		foreach ( $entries as $name ) {
			$rel  = '' === $rel_dir ? $name : $rel_dir . '/' . $name;
			$path = $root . '/' . $rel;
			if ( self::is_excluded( $rel, $excluded ) || ( $stats['patterns'] && self::matches( $rel, $stats['patterns'] ) ) ) {
				continue;
			}
			if ( false !== strpbrk( $rel, "\t\n\r" ) ) {
				$stats['skipped'][] = $rel;
				continue;
			}
			if ( is_dir( $path ) ) {
				if ( is_link( $path ) ) {
					// Common for a theme or plugin being developed elsewhere.
					// Its contents are backed up as a normal folder.
					$stats['links'][] = $rel;
				}
				++$kept;
				self::walk( $root, $rel, $excluded, $out, $stats );
				continue;
			}
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				$stats['skipped'][] = $rel;
				continue;
			}
			++$kept;
			$size  = filesize( $path );
			$mtime = filemtime( $path );
			if ( '' !== $stats['known'] && self::is_known( $stats['known'], self::fingerprint( $rel, $size, $mtime ) ) ) {
				++$stats['unchanged'];
				$stats['unchanged_bytes'] += $size;
				continue;
			}
			self::write( $out, $rel . "\t" . $size . "\t" . $mtime . "\t" . ( fileperms( $path ) & 0777 ) . "\tF\n" );
			++$stats['files'];
			$stats['bytes'] += $size;
		}
		if ( 0 === $kept && '' !== $rel_dir ) {
			self::write( $out, $rel_dir . "\t0\t0\t" . 0755 . "\tD\n" );
		}
	}

	/**
	 * Write a line of the file list.
	 *
	 * @param resource $out  File list.
	 * @param string   $line Line.
	 * @throws MoveKit_Exception When the disk is full.
	 */
	private static function write( $out, $line ) {
		if ( fwrite( $out, $line ) !== strlen( $line ) ) {
			throw new MoveKit_Exception( esc_html__( 'Could not write the file list. The disk may be full.', 'movekit-migration-backup' ) );
		}
	}

	/**
	 * Path relative to wp-content, or null when outside it.
	 */
	public static function relative( $path ) {
		$root = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/';
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		return 0 === strpos( $path . '/', $root ) ? substr( $path, strlen( $root ) ) : null;
	}
}
