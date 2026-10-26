<?php
/**
 * The parts of a site a backup, push or pull can include.
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database, media, plugins, themes, and everything else in wp-content.
 */
class MoveKit_Parts {

	const ALL = array( 'database', 'media', 'plugins', 'themes', 'other' );

	/**
	 * Translated names, in display order.
	 *
	 * @return string[]
	 */
	public static function labels() {
		return array(
			'database' => __( 'Database', 'movekit-migration-backup' ),
			'media'    => __( 'Media', 'movekit-migration-backup' ),
			'plugins'  => __( 'Plugins', 'movekit-migration-backup' ),
			'themes'   => __( 'Themes', 'movekit-migration-backup' ),
			'other'    => __( 'Other files', 'movekit-migration-backup' ),
		);
	}

	/**
	 * Normalize a list of parts. Empty means everything.
	 *
	 * @param string|string[]|null $parts Array or comma-separated list.
	 * @return string[] Known parts, in standard order.
	 * @throws MoveKit_Exception When the list names nothing known.
	 */
	public static function sanitize( $parts ) {
		if ( null === $parts || '' === $parts || array() === $parts ) {
			return self::ALL;
		}
		if ( ! is_array( $parts ) ) {
			$parts = explode( ',', (string) $parts );
		}
		$aliases = array(
			'db'      => 'database',
			'uploads' => 'media',
		);
		$wanted  = array();
		foreach ( $parts as $part ) {
			$part     = strtolower( trim( (string) $part ) );
			$wanted[] = isset( $aliases[ $part ] ) ? $aliases[ $part ] : $part;
		}
		$out = array_values( array_intersect( self::ALL, $wanted ) );
		if ( ! $out ) {
			throw new MoveKit_Exception( esc_html__( 'Choose at least one thing to include: database, media, plugins, themes or other files.', 'movekit-migration-backup' ) );
		}
		return $out;
	}

	/**
	 * Parts in a backup's manifest. Backups made before 0.6.0 hold everything.
	 *
	 * @param array $manifest Manifest.
	 * @return string[]
	 */
	public static function of( array $manifest ) {
		return isset( $manifest['parts'] ) && is_array( $manifest['parts'] ) ? $manifest['parts'] : self::ALL;
	}

	/**
	 * Whether a list is a full site.
	 *
	 * @param string[] $parts Parts.
	 */
	public static function is_all( array $parts ) {
		return count( $parts ) === count( self::ALL );
	}

	/**
	 * Folders, relative to wp-content, that hold the file parts. A folder
	 * outside wp-content is null and is never backed up.
	 *
	 * @return array part => relative folder or null.
	 */
	public static function folders() {
		return array(
			'media'   => MoveKit_File_Scanner::relative( wp_upload_dir( null, false )['basedir'] ),
			'plugins' => MoveKit_File_Scanner::relative( WP_PLUGIN_DIR ),
			'themes'  => MoveKit_File_Scanner::relative( get_theme_root() ),
		);
	}

	/**
	 * "Database, Media" for messages.
	 *
	 * @param string[] $parts Parts.
	 */
	public static function describe( array $parts ) {
		$labels = self::labels();
		$names  = array();
		foreach ( $parts as $part ) {
			$names[] = $labels[ $part ];
		}
		return implode( ', ', $names );
	}
}
