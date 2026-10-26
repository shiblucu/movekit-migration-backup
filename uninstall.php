<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes working files only. Backup files are the site owner's data and can
 * be large and hard to recreate, so they are kept in wp-content/movekit-backups
 * unless the MOVEKIT_DELETE_BACKUPS_ON_UNINSTALL constant is true.
 *
 * @package MoveKit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-movekit-exception.php';
require_once __DIR__ . '/includes/class-movekit-storage.php';
require_once __DIR__ . '/includes/class-movekit-db.php';
require_once __DIR__ . '/includes/class-movekit-job.php';
require_once __DIR__ . '/includes/class-movekit-import-job.php';
require_once __DIR__ . '/includes/class-movekit-undo.php';

delete_option( 'movekit_exclude' );
delete_option( 'movekit_clear_caches' );
delete_option( 'movekit_media_source' );
wp_clear_scheduled_hook( 'movekit_tidy' );
// Records of used request nonces (see MoveKit_Connection::use_nonce()).
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'movekit_nonce_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup of rows with generated names.

// The copy kept for undoing the last restore, including its old database tables.
try {
	MoveKit_Undo::discard();
} catch ( Exception $e ) {
	unset( $e ); // Left for the site owner to delete.
}

if ( defined( 'MOVEKIT_DELETE_BACKUPS_ON_UNINSTALL' ) && MOVEKIT_DELETE_BACKUPS_ON_UNINSTALL ) {
	MoveKit_Storage::rrmdir( MoveKit_Storage::dir() );
	return;
}

MoveKit_Storage::rrmdir( MoveKit_Storage::jobs_dir() );
// Connection secrets and saved sites (nonces.php is from older versions).
foreach ( array( 'connection', 'remotes', 'nonces' ) as $movekit_file ) {
	wp_delete_file( MoveKit_Storage::dir() . '/' . $movekit_file . '.php' );
}
foreach ( (array) glob( MoveKit_Storage::dir() . '/*.part' ) as $movekit_part ) {
	wp_delete_file( $movekit_part );
}
