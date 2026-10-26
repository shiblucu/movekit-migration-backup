<?php
/**
 * Clears caches that would keep showing the old site after a restore.
 *
 * Page builders keep generated CSS with the old site's addresses, and page
 * cache plugins keep serving old pages. Their own clearing functions are
 * called on the first request after the restore, when the restored site's
 * plugins are the ones loaded.
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cache clearing after a restore or an undo.
 */
class MoveKit_Cache {

	const FLAG = 'movekit_clear_caches';

	/**
	 * Hook the deferred clearing.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_clear' ), 99 );
	}

	/**
	 * Run right after the database and files were switched.
	 *
	 * @param string $prefix Table prefix of the live tables.
	 */
	public static function after_restore( $prefix ) {
		global $wpdb;
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Often restricted by the host; then nothing to do.
		}
		$options = $prefix . 'options';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Written straight to the restored table; the options cache belongs to the old site.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `$options` (option_name, option_value, autoload) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from the table prefix.
				self::FLAG,
				(string) time()
			)
		);
		wp_cache_flush();
	}

	/**
	 * On the first request after a restore, ask the active page builders and
	 * cache plugins to clear what they generated for the old site.
	 */
	public static function maybe_clear() {
		if ( ! get_option( self::FLAG ) ) {
			return;
		}
		delete_option( self::FLAG );

		$calls = array(
			function () {
				if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				}
			},
			function () {
				if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
					ET_Core_PageResource::remove_static_resources( 'all', 'all' );
				}
			},
			function () {
				if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'delete_asset_cache_for_all_posts' ) ) {
					FLBuilderModel::delete_asset_cache_for_all_posts();
				}
			},
			function () {
				if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
					autoptimizeCache::clearall();
				}
			},
			function () {
				foreach ( array( 'rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_clear_cache', 'sg_cachepress_purge_cache', 'wpo_cache_flush' ) as $fn ) {
					if ( function_exists( $fn ) ) {
						call_user_func( $fn );
					}
				}
			},
			function () {
				// Plugins that clear their whole cache on these actions.
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
				do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's own action.
				do_action( 'wpfc_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Fastest Cache's own action.
				do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own action.
				do_action( 'wphb_clear_page_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hummingbird's own action.
			},
		);
		foreach ( $calls as $call ) {
			try {
				$call();
			} catch ( Throwable $e ) {
				continue; // Another plugin's problem must not break the site.
			}
		}

		/**
		 * Fires after MoveKit asked caches to clear following a restore.
		 * Hook in to clear a cache MoveKit does not know about.
		 */
		do_action( 'movekit_after_restore' );
	}
}
