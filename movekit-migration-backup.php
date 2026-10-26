<?php
/**
 * Plugin Name:       MoveKit – Site Migration & Backup
 * Plugin URI:        https://github.com/shiblucu/movekit-migration-backup
 * Description:       Reliable full-site backup, restore and migration between local and live WordPress sites, built for large sites.
 * Version:           0.10.1
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Tawfiqur Rahman
 * Author URI:        https://tawfiq.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       movekit-migration-backup
 * Domain Path:       /languages
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

define( 'MOVEKIT_VERSION', '0.10.1' );
define( 'MOVEKIT_FILE', __FILE__ );
define( 'MOVEKIT_DIR', __DIR__ );
define( 'MOVEKIT_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'MoveKit_' ) ) {
			return;
		}
		$file = MOVEKIT_DIR . '/includes/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'MoveKit_Storage', 'ensure' ) );
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( MoveKit_Undo::TIDY_HOOK );
	}
);
add_action( MoveKit_Undo::TIDY_HOOK, array( 'MoveKit_Undo', 'tidy' ) );

MoveKit_REST::init();
MoveKit_Remote_API::init();
MoveKit_Cache::init();
add_action( 'init', array( 'MoveKit_Media', 'init' ) );
if ( is_admin() ) {
	MoveKit_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'movekit', 'MoveKit_CLI' );
}
