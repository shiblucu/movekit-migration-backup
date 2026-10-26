<?php
/**
 * Shows media that a pull left out from the site it came from.
 *
 * After a pull without Media, the local site has no uploads folder to show,
 * so its images would be broken. While a source address is saved, any
 * uploaded file that is missing here is shown from the other site instead.
 * Nothing is downloaded or stored.
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads missing media from another site.
 */
class MoveKit_Media {

	const OPTION = 'movekit_media_source';

	/**
	 * Hook the address filters.
	 */
	public static function init() {
		if ( ! self::source() ) {
			return;
		}
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'attachment_url' ) );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'srcset' ) );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 20 );
		add_filter( 'widget_block_content', array( __CLASS__, 'content' ), 20 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'content' ), 20 );
	}

	/**
	 * Address of the other site's uploads folder, or an empty string.
	 *
	 * @return string
	 */
	public static function source() {
		$url = (string) get_option( self::OPTION, '' );
		/**
		 * Filters the address that missing media is loaded from.
		 *
		 * @param string $url Uploads address of the other site; empty to turn this off.
		 */
		$url = (string) apply_filters( 'movekit_media_source', $url );
		return '' !== $url && 0 === strpos( $url, 'http' ) ? untrailingslashit( $url ) : '';
	}

	/**
	 * Start or stop loading missing media after a transfer.
	 *
	 * @param string $url Other site's uploads address; empty to stop.
	 */
	public static function save( $url ) {
		// A restore just replaced the database, so drop what was cached from the old one.
		wp_cache_flush();
		if ( '' === $url ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, esc_url_raw( $url ), false );
	}

	/**
	 * Address of this site's uploads folder, without the trailing slash.
	 *
	 * @return array baseurl, basedir.
	 */
	private static function uploads() {
		static $dirs = null;
		if ( null === $dirs ) {
			$u    = wp_upload_dir( null, false );
			$dirs = array( untrailingslashit( $u['baseurl'] ), untrailingslashit( str_replace( '\\', '/', $u['basedir'] ) ) );
		}
		return $dirs;
	}

	/**
	 * The other site's address for a local uploads address, when the file is missing here.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function remote_url( $url ) {
		static $seen = array();
		if ( isset( $seen[ $url ] ) ) {
			return $seen[ $url ];
		}
		list( $base, $dir ) = self::uploads();
		$out                = $url;
		if ( 0 === strpos( $url, $base . '/' ) ) {
			$rel = substr( $url, strlen( $base ) + 1 );
			$rel = rawurldecode( (string) strtok( $rel, '?#' ) );
			if ( '' !== $rel && false === strpos( $rel, '..' ) && false === strpos( $rel, "\0" ) && ! file_exists( $dir . '/' . $rel ) ) {
				$out = self::source() . '/' . substr( $url, strlen( $base ) + 1 );
			}
		}
		$seen[ $url ] = $out;
		return $out;
	}

	/**
	 * Filter: address of an attachment.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function attachment_url( $url ) {
		return is_string( $url ) ? self::remote_url( $url ) : $url;
	}

	/**
	 * Filter: addresses in an image's srcset.
	 *
	 * @param array $sources Sources.
	 * @return array
	 */
	public static function srcset( $sources ) {
		if ( is_array( $sources ) ) {
			foreach ( $sources as $width => $source ) {
				if ( isset( $source['url'] ) ) {
					$sources[ $width ]['url'] = self::remote_url( $source['url'] );
				}
			}
		}
		return $sources;
	}

	/**
	 * Filter: uploads addresses inside post content.
	 *
	 * @param string $html Content.
	 * @return string
	 */
	public static function content( $html ) {
		list( $base ) = self::uploads();
		if ( ! is_string( $html ) || false === strpos( $html, $base . '/' ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'~' . preg_quote( $base, '~' ) . '/[^\s"\'<>)]+~',
			function ( $m ) {
				return self::remote_url( $m[0] );
			},
			$html
		);
	}
}
