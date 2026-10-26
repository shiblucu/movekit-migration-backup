<?php
/**
 * The Migration admin screen and backup downloads.
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, page, assets and the download handler.
 */
class MoveKit_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'movekit';

	/**
	 * Hook into the admin.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_movekit_download', array( __CLASS__, 'download' ) );
		add_filter( 'plugin_action_links_' . MOVEKIT_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add the Migration menu item.
	 */
	public static function menu() {
		add_menu_page(
			'MoveKit',
			'MoveKit',
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-migrate',
			80
		);
	}

	/**
	 * Add a "Backups" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Backups', 'movekit-migration-backup' ) )
		);
		return $links;
	}

	/**
	 * Load the screen's script and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'movekit-admin', plugins_url( 'assets/admin.css', MOVEKIT_FILE ), array(), MOVEKIT_VERSION );
		wp_enqueue_script( 'movekit-admin', plugins_url( 'assets/admin.js', MOVEKIT_FILE ), array( 'wp-api-fetch', 'wp-i18n' ), MOVEKIT_VERSION, true );
		wp_set_script_translations( 'movekit-admin', 'movekit-migration-backup', MOVEKIT_DIR . '/languages' );
		$config = array(
			'restUrl'  => esc_url_raw( rest_url( MoveKit_REST::NS . '/' ) ),
			'download' => admin_url( 'admin-post.php?action=movekit_download&_wpnonce=' . wp_create_nonce( 'movekit_download' ) . '&name=' ),
			'login'    => wp_login_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			'chunk'    => self::upload_chunk_size(),
			'parts'    => MoveKit_Parts::labels(),
			'home'     => home_url(),
			'local'    => MoveKit_Connection::is_local_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
		);
		wp_add_inline_script( 'movekit-admin', 'window.MoveKit = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Upload pieces must fit under the server's upload and POST limits.
	 */
	private static function upload_chunk_size() {
		return (int) max( 262144, min( 8388608, wp_max_upload_size() - 65536 ) );
	}

	/**
	 * Render the Migration screen. The backups list is filled in by the script.
	 */
	public static function render() {
		$error = '';
		try {
			MoveKit_Storage::ensure();
		} catch ( MoveKit_Exception $e ) {
			$error = $e->text();
		}
		$rel   = MoveKit_File_Scanner::relative( MoveKit_Storage::dir() );
		$local = MoveKit_Connection::is_local_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$skip  = MoveKit_File_Scanner::saved_patterns();
		?>
		<div class="wrap movekit">
			<h1>MoveKit</h1>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info movekit-undo" id="movekit-undo" hidden></div>

			<?php if ( MoveKit_Media::source() ) : ?>
				<div class="notice notice-info" id="movekit-media-note">
					<p>
						<?php
						printf(
							/* translators: %s: site address. */
							esc_html__( 'Images and other uploads that are missing on this site are shown from %s. Nothing is downloaded.', 'movekit-migration-backup' ),
							'<code>' . esc_html( MoveKit_Media::source() ) . '</code>'
						);
						?>
						<button type="button" class="button-link" id="movekit-media-stop"><?php esc_html_e( 'Stop', 'movekit-migration-backup' ); ?></button>
					</p>
				</div>
			<?php endif; ?>

			<div class="movekit-intro" id="movekit-intro" hidden>
				<h2><?php esc_html_e( 'Two ways to move a site', 'movekit-migration-backup' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'With a backup file: create a backup here and download it. On the other site, install MoveKit, upload the file and click Restore.', 'movekit-migration-backup' ); ?></li>
					<li><?php esc_html_e( 'Directly: install MoveKit on the live site and copy its connection key. Paste the key under Connect a site on your local site, then click Push or Pull.', 'movekit-migration-backup' ); ?></li>
				</ol>
				<p><?php esc_html_e( 'Either way, the site that receives the copy keeps its own address, and the site it replaces can be put back with Undo for a day.', 'movekit-migration-backup' ); ?></p>
			</div>

			<fieldset class="movekit-parts" id="movekit-parts">
				<legend><?php esc_html_e( 'What to include', 'movekit-migration-backup' ); ?></legend>
				<?php foreach ( MoveKit_Parts::labels() as $part => $label ) : ?>
					<label><input type="checkbox" value="<?php echo esc_attr( $part ); ?>" checked> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
				<button type="button" class="button-link" id="movekit-select-all"><?php esc_html_e( 'Select all', 'movekit-migration-backup' ); ?></button>
				<span class="description"><?php esc_html_e( 'Applies to backups, push and pull. Other files are everything else in wp-content, such as mu-plugins and languages.', 'movekit-migration-backup' ); ?></span>
				<details class="movekit-skip"<?php echo $skip ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'Skip files', 'movekit-migration-backup' ); ?></summary>
					<label for="movekit-exclude"><?php esc_html_e( 'Files and folders to leave out, one per line:', 'movekit-migration-backup' ); ?></label>
					<textarea id="movekit-exclude" class="code" rows="3" spellcheck="false" placeholder="*.zip&#10;uploads/2019"><?php echo esc_textarea( implode( "\n", $skip ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'A name such as *.zip or old-theme is skipped wherever it appears. A path such as uploads/2019 is counted from wp-content. Saved for next time. Caches, other backup plugins\' folders, .git and node_modules are always skipped.', 'movekit-migration-backup' ); ?></p>
				</details>
			</fieldset>

			<div class="movekit-actions">
				<div class="movekit-card">
					<h2><?php esc_html_e( 'Back up this site', 'movekit-migration-backup' ); ?></h2>
					<p><?php esc_html_e( 'Saves what is selected under What to include into one file you can download or restore on another site.', 'movekit-migration-backup' ); ?></p>
					<button type="button" class="button button-primary button-hero" id="movekit-export"><?php esc_html_e( 'Create backup', 'movekit-migration-backup' ); ?></button>
				</div>
				<div class="movekit-card">
					<h2><?php esc_html_e( 'Upload a backup', 'movekit-migration-backup' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %s: file extension. */
							esc_html__( 'Add a %s file from another site. Large files are sent in pieces, so upload limits don\'t matter.', 'movekit-migration-backup' ),
							'<code>.movekit</code>'
						);
						?>
					</p>
					<button type="button" class="button button-hero" id="movekit-choose"><?php esc_html_e( 'Choose file', 'movekit-migration-backup' ); ?></button>
					<input type="file" id="movekit-file" accept=".movekit" hidden>
				</div>
			</div>

			<div class="movekit-progress" id="movekit-progress" hidden>
				<div class="movekit-progress-head">
					<strong id="movekit-progress-title"></strong>
					<span id="movekit-progress-pct">0%</span>
				</div>
				<div class="movekit-bar" id="movekit-bar-track" role="progressbar" aria-labelledby="movekit-progress-title" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="movekit-bar-fill" id="movekit-bar"></div></div>
				<p class="movekit-progress-msg" id="movekit-progress-msg"></p>
				<p class="movekit-warn"><?php esc_html_e( 'Keep this page open until it finishes. If it closes, open MoveKit again and it carries on.', 'movekit-migration-backup' ); ?></p>
			</div>
			<div class="screen-reader-text" id="movekit-status" role="status" aria-live="polite"></div>

			<div class="movekit-result" id="movekit-result" tabindex="-1" hidden></div>

			<h2 class="movekit-list-title"><?php esc_html_e( 'Backups', 'movekit-migration-backup' ); ?></h2>
			<table class="widefat striped movekit-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'movekit-migration-backup' ); ?></th>
						<th><?php esc_html_e( 'From site', 'movekit-migration-backup' ); ?></th>
						<th><?php esc_html_e( 'Size', 'movekit-migration-backup' ); ?></th>
						<th><?php esc_html_e( 'Created', 'movekit-migration-backup' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'movekit-migration-backup' ); ?></span></th>
					</tr>
				</thead>
				<tbody id="movekit-list"><tr><td colspan="5"><?php esc_html_e( 'Loading…', 'movekit-migration-backup' ); ?></td></tr></tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: %s: folder where backups are stored. */
					esc_html__( 'Stored in %s. Restoring replaces this site\'s database and wp-content files; wp-config.php and WordPress core are kept. What was replaced is kept for a day, so you can undo the restore.', 'movekit-migration-backup' ),
					'<code>' . esc_html( null !== $rel ? 'wp-content/' . $rel : MoveKit_Storage::dir() ) . '</code>'
				);
				?>
			</p>

			<h2 class="movekit-list-title"><?php esc_html_e( 'Push and pull', 'movekit-migration-backup' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Copy a site straight to or from another site that has MoveKit, without downloading files. Connect from the site that starts the transfer, usually your local site.', 'movekit-migration-backup' ); ?></p>
			<div class="movekit-actions">
				<div class="movekit-card">
					<h3><?php esc_html_e( 'Connect a site', 'movekit-migration-backup' ); ?></h3>
					<p><?php esc_html_e( 'Paste the connection key from the MoveKit screen of the other site.', 'movekit-migration-backup' ); ?></p>
					<form id="movekit-connect" class="movekit-key-row">
						<label class="screen-reader-text" for="movekit-connect-key"><?php esc_html_e( 'Connection key', 'movekit-migration-backup' ); ?></label>
						<input type="text" id="movekit-connect-key" class="code" autocomplete="off" spellcheck="false" placeholder="movekit1:…" required>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect', 'movekit-migration-backup' ); ?></button>
					</form>
				</div>
				<div class="movekit-card">
					<h3><?php esc_html_e( 'This site\'s connection key', 'movekit-migration-backup' ); ?></h3>
					<p><?php esc_html_e( 'Paste this into another site to let it push to or pull from this site. Anyone with the key can replace or copy this site, so keep it private.', 'movekit-migration-backup' ); ?></p>
					<?php if ( $local ) : ?>
						<p class="movekit-note"><?php esc_html_e( 'This is a local site, which a live site cannot reach. To move it, paste the live site\'s key under Connect a site here instead.', 'movekit-migration-backup' ); ?></p>
					<?php endif; ?>
					<div class="movekit-key-row">
						<label class="screen-reader-text" for="movekit-key"><?php esc_html_e( 'This site\'s connection key', 'movekit-migration-backup' ); ?></label>
						<input type="password" id="movekit-key" class="code" readonly value="">
						<button type="button" class="button" id="movekit-key-show"><?php esc_html_e( 'Show', 'movekit-migration-backup' ); ?></button>
						<button type="button" class="button" id="movekit-key-copy"><?php esc_html_e( 'Copy', 'movekit-migration-backup' ); ?></button>
					</div>
					<p class="movekit-key-foot">
						<button type="button" class="button-link movekit-delete" id="movekit-key-regenerate"><?php esc_html_e( 'Regenerate key', 'movekit-migration-backup' ); ?></button>
					</p>
				</div>
			</div>

			<table class="widefat striped movekit-table movekit-remotes">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Connected site', 'movekit-migration-backup' ); ?></th>
						<th><?php esc_html_e( 'Connected on', 'movekit-migration-backup' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'movekit-migration-backup' ); ?></span></th>
					</tr>
				</thead>
				<tbody id="movekit-remotes"><tr><td colspan="3"><?php esc_html_e( 'Loading…', 'movekit-migration-backup' ); ?></td></tr></tbody>
			</table>
			<p class="movekit-changed">
				<label><input type="checkbox" id="movekit-changed-only" checked> <?php esc_html_e( 'Send only files that changed', 'movekit-migration-backup' ); ?></label>
				<span class="description"><?php esc_html_e( 'Files that are already the same on both sites are left out, so a second push or pull is much faster. The database is always sent in full.', 'movekit-migration-backup' ); ?></span>
			</p>
			<p class="movekit-changed">
				<label><input type="checkbox" id="movekit-remote-media"> <?php esc_html_e( 'Show missing media from the other site', 'movekit-migration-backup' ); ?></label>
				<span class="description"><?php esc_html_e( 'When you pull without Media, uploads are not copied. Instead this site shows them from the other site, so images still appear and nothing is downloaded. The browsers of your visitors then load those images from the other site.', 'movekit-migration-backup' ); ?></span>
			</p>
			<p class="description"><?php esc_html_e( 'Push replaces the other site with this one. Pull replaces this site with the other one. Each site keeps its own address, connection keys survive the transfer, and the replaced site can be put back with Undo for a day.', 'movekit-migration-backup' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Stream a backup, with Range support so browsers can resume downloads.
	 */
	public static function download() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to download backups.', 'movekit-migration-backup' ), 403 );
		}
		check_admin_referer( 'movekit_download' );
		$name = isset( $_GET['name'] ) ? sanitize_file_name( wp_unslash( $_GET['name'] ) ) : '';
		try {
			$path = MoveKit_Storage::backup_path( $name );
		} catch ( MoveKit_Exception $e ) {
			wp_die( esc_html( $e->text() ), 400 );
		}
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'Backup not found.', 'movekit-migration-backup' ), 404 );
		}

		$size  = filesize( $path );
		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $range && preg_match( '/bytes=(\d*)-(\d*)/', $range, $m ) ) {
			if ( '' !== $m[1] ) {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			} elseif ( '' !== $m[2] ) {
				$start = max( 0, $size - (int) $m[2] );
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( "Content-Range: bytes $start-$end/$size" );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		// A multi-GB download can outlast max_execution_time on slow connections
		// and on Windows, where waiting on the network counts toward the limit.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Streaming a large backup file.
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Accel-Buffering: no' );
		MoveKit_Storage::stream( $path, $start, $end - $start + 1 );
		exit;
	}
}
