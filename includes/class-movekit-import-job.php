<?php
/**
 * Restores a backup over the current site.
 *
 * Order matters for safety. The whole archive is checksum-verified first,
 * and the database is imported into temporary tables and rewritten there.
 * Files are written to a staging folder. Only after all that succeeds are
 * the staged files moved in (the files they replace are kept aside) and the
 * tables swapped with one atomic RENAME. If either step fails, the files
 * are put back, so the site is left as it was. What was replaced is kept
 * for a day so the restore can be undone (see MoveKit_Undo).
 *
 * Backups are read and written in pieces with plain PHP file functions:
 * WP_Filesystem has no seek/append/stream operations and would need whole
 * multi-GB files in memory.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

class MoveKit_Import_Job extends MoveKit_Job {

	const TMP_PREFIX  = '_mvk';
	const OLD_PREFIX  = '_mvo';
	const GONE_PREFIX = '_mvx';

	/**
	 * Auto-generated constraint names, which RENAME TABLE renames along
	 * with the table.
	 */
	const AUTO_CONSTRAINT = '/_(ibfk|chk)_\d+$/';

	/**
	 * @param string $archive Absolute path to a .movekit file.
	 */
	public static function start( $archive ) {
		global $wpdb;
		if ( ! is_file( $archive ) ) {
			throw new MoveKit_Exception( esc_html__( 'Backup file not found.', 'movekit-migration-backup' ) );
		}
		if ( is_multisite() ) {
			throw new MoveKit_Exception( esc_html__( 'Multisite networks are not supported yet.', 'movekit-migration-backup' ) );
		}
		$check = self::preflight( $archive );
		if ( $check['errors'] ) {
			throw new MoveKit_Exception( esc_html( implode( ' ', $check['errors'] ) ) );
		}
		return self::create(
			array(
				'archive' => $archive,
				'target'  => array(
					'home'         => untrailingslashit( get_option( 'home' ) ),
					'siteurl'      => untrailingslashit( get_option( 'siteurl' ) ),
					'abspath'      => untrailingslashit( str_replace( '\\', '/', ABSPATH ) ),
					'content_dir'  => untrailingslashit( str_replace( '\\', '/', WP_CONTENT_DIR ) ),
					'content_url'  => untrailingslashit( content_url() ),
					'uploads_dir'  => untrailingslashit( str_replace( '\\', '/', wp_upload_dir( null, false )['basedir'] ) ),
					'table_prefix' => $wpdb->prefix,
					'blog_public'  => (string) get_option( 'blog_public' ),
				),
				'pos'     => 8,
				'tables'  => array(),
			)
		);
	}

	/**
	 * Problems to know about before restoring a backup here.
	 *
	 * @param string $archive Absolute path to a .movekit file.
	 * @return array errors (stop), warnings (ask first).
	 */
	public static function preflight( $archive ) {
		global $wp_version;
		$errors   = array();
		$warnings = array();
		try {
			$reader   = new MoveKit_Archive_Reader( $archive );
			$manifest = $reader->manifest();
			$size     = $reader->size();
			$reader->close();
		} catch ( MoveKit_Exception $e ) {
			return array(
				'errors'   => array( $e->text() ),
				'warnings' => array(),
			);
		}
		if ( ! empty( $manifest['site']['multisite'] ) ) {
			$errors[] = __( 'Backups of multisite networks are not supported yet.', 'movekit-migration-backup' );
		}
		$files = isset( $manifest['files']['bytes'] ) ? (int) $manifest['files']['bytes'] : 0;
		// The database is unpacked to a file and then loaded into temporary
		// tables; files are staged before they are moved in.
		$error = MoveKit_Storage::space_problem( 2 * max( 0, $size - $files ) + $files );
		if ( $error ) {
			$errors[] = $error;
		}
		$site = isset( $manifest['site'] ) ? $manifest['site'] : array();
		return array(
			'errors'   => $errors,
			'warnings' => self::version_warnings( $site, $wp_version, PHP_VERSION ),
		);
	}

	/**
	 * Warn when the source site ran newer WordPress or PHP than this one.
	 *
	 * @param array  $source wp_version, php_version of the source site.
	 * @param string $wp     The destination's WordPress version.
	 * @param string $php    The destination's PHP version.
	 * @param string $where  The destination's address; this site by default.
	 * @return string[]
	 */
	public static function version_warnings( array $source, $wp, $php, $where = '' ) {
		$where = '' === $where ? home_url() : $where;
		$out   = array();
		$min   = function ( $v ) {
			return implode( '.', array_slice( explode( '.', (string) $v ), 0, 2 ) );
		};
		if ( ! empty( $source['wp_version'] ) && version_compare( $min( $source['wp_version'] ), $min( $wp ), '>' ) ) {
			$out[] = sprintf(
				/* translators: 1: WordPress version of the source site, 2: destination site address, 3: its WordPress version. */
				__( 'The copy comes from WordPress %1$s, but %2$s runs WordPress %3$s. Update WordPress there first, or the restored site may ask for a database update.', 'movekit-migration-backup' ),
				$source['wp_version'],
				$where,
				$wp
			);
		}
		if ( ! empty( $source['php_version'] ) && version_compare( $min( $source['php_version'] ), $min( $php ), '>' ) ) {
			$out[] = sprintf(
				/* translators: 1: PHP version of the source site, 2: destination site address, 3: its PHP version. */
				__( 'The copy comes from a server with PHP %1$s, but %2$s runs PHP %3$s. Plugins or themes that need the newer PHP may stop working there. Upgrade PHP on that server first if you can.', 'movekit-migration-backup' ),
				$min( $source['php_version'] ),
				$where,
				$min( $php )
			);
		}
		return $out;
	}

	protected function phases() {
		return array( 'verify', 'extract_database', 'database', 'prefix', 'replace', 'files', 'swap', 'finish' );
	}

	protected function progress() {
		$s    = $this->state;
		$size = isset( $s['summary'] ) ? max( 1, $s['summary']['manifest_offset'] ) : 1;
		switch ( $this->phases()[ $s['phase'] ] ) {
			case 'verify':
				return 15 * $s['pos'] / $size;
			case 'extract_database':
				return 15;
			case 'database':
				return 16 + 29 * $s['db_pos'] / max( 1, $s['db_size'] );
			case 'prefix':
				return 45;
			case 'replace':
				return 45 + 10 * $s['rt'] / max( 1, count( $s['tables'] ) );
			case 'files':
				return 55 + 43 * ( $s['pos'] - $s['files_start'] ) / max( 1, $s['summary']['manifest_offset'] - $s['files_start'] );
			default:
				return 99;
		}
	}

	/**
	 * Database phases may run in a later request than the import itself,
	 * so the connection settings are applied at the start of every step,
	 * including early ones that can run on into the database phase.
	 */
	protected function before_step() {
		MoveKit_DB::session_setup();
	}

	/* ---------------------------------------------------------------- verify */

	protected function run_verify( $deadline ) {
		$reader = new MoveKit_Archive_Reader( $this->state['archive'] );
		if ( ! isset( $this->state['summary'] ) ) {
			$this->message( __( 'Checking backup file', 'movekit-migration-backup' ) );
			$this->state['summary']  = $reader->summary();
			$this->state['manifest'] = $reader->manifest();
			$this->state['counted']  = 0;
			$m                       = $this->state['manifest'];
			if ( empty( $m['format'] ) || MoveKit_Archive::FORMAT !== (int) $m['format'] ) {
				throw new MoveKit_Exception( esc_html__( 'This backup was made by a newer version of MoveKit. Update the plugin and try again.', 'movekit-migration-backup' ) );
			}
			if ( ! empty( $m['site']['multisite'] ) ) {
				throw new MoveKit_Exception( esc_html__( 'Backups of multisite networks are not supported yet.', 'movekit-migration-backup' ) );
			}
		}
		$end = $this->state['summary']['end_offset'];
		while ( $this->state['pos'] < $end && microtime( true ) < $deadline ) {
			$rec                    = $reader->read( $this->state['pos'] );
			$this->state['pos']     = $rec['next'];
			$this->state['counted'] = $this->state['counted'] + 1;
		}
		$reader->close();
		if ( $this->state['pos'] < $end ) {
			return false;
		}
		if ( $this->state['pos'] !== $end || $this->state['counted'] + 1 !== (int) $this->state['summary']['records'] ) {
			throw new MoveKit_Exception( esc_html__( 'The backup file is damaged: its contents do not match its index.', 'movekit-migration-backup' ) );
		}
		$this->state['pos']     = 8;
		$this->state['db_file'] = $this->dir() . '/database.sql';
		$this->message( __( 'Unpacking database', 'movekit-migration-backup' ) );
		return true;
	}

	/* ------------------------------------------------------ extract database */

	protected function run_extract_database( $deadline ) {
		$reader = new MoveKit_Archive_Reader( $this->state['archive'] );
		$fh     = fopen( $this->state['db_file'], 8 === $this->state['pos'] ? 'wb' : 'cb' );
		$limit  = $this->state['summary']['manifest_offset'];
		$done   = false;
		while ( microtime( true ) < $deadline ) {
			if ( $this->state['pos'] >= $limit ) {
				$done = true;
				break;
			}
			$rec = $reader->read( $this->state['pos'] );
			if ( MoveKit_Archive::TYPE_DB !== $rec['type'] ) {
				$done = true;
				break;
			}
			fseek( $fh, $rec['offset'] );
			if ( fwrite( $fh, $rec['data'] ) !== strlen( $rec['data'] ) ) {
				throw new MoveKit_Exception( esc_html__( 'Unpacking the database failed. The disk may be full.', 'movekit-migration-backup' ) );
			}
			$this->state['pos'] = $rec['next'];
		}
		fclose( $fh );
		$reader->close();
		if ( $done ) {
			$this->state['files_start'] = $this->state['pos'];
			$this->state['db_pos']      = 0;
			$this->state['db_size']     = filesize( $this->state['db_file'] );
			$this->state['collations']  = $this->supported_collations();
			$this->message( __( 'Importing database', 'movekit-migration-backup' ) );
		}
		return $done;
	}

	/* -------------------------------------------------------------- database */

	protected function run_database( $deadline ) {
		$fh = fopen( $this->state['db_file'], 'rb' );
		fseek( $fh, $this->state['db_pos'] );
		MoveKit_DB::query( 'START TRANSACTION' );
		while ( microtime( true ) < $deadline ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			$next = ftell( $fh );
			$sql  = rtrim( $line, "\r\n" );
			if ( '' !== $sql && 0 !== strpos( $sql, '--' ) ) {
				MoveKit_DB::query( $this->rewrite_statement( $sql ) );
			}
			$this->state['db_pos'] = $next;
		}
		MoveKit_DB::query( 'COMMIT' );
		fclose( $fh );
		if ( $this->state['db_pos'] < $this->state['db_size'] ) {
			return false;
		}
		if ( ! $this->state['tables'] && in_array( 'database', MoveKit_Parts::of( $this->state['manifest'] ), true ) ) {
			throw new MoveKit_Exception( esc_html__( 'The backup contains no database tables.', 'movekit-migration-backup' ) );
		}
		@unlink( $this->state['db_file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A leftover temporary file is harmless.
		$this->state['rt']      = 0;
		$this->state['rcursor'] = null;
		$this->message( __( 'Updating URLs and paths', 'movekit-migration-backup' ) );
		return true;
	}

	/**
	 * Point a dump statement at the temporary table and fix up anything the
	 * destination server cannot handle.
	 */
	private function rewrite_statement( $sql ) {
		if ( ! preg_match( '/^(DROP TABLE IF EXISTS |CREATE TABLE |INSERT INTO )`((?:[^`]|``)+)`/', $sql, $m ) ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: detail. */ __( 'Unexpected statement in the backup database: %s', 'movekit-migration-backup' ), substr( $sql, 0, 80 ) ) ) );
		}
		$rest  = substr( $sql, strlen( $m[0] ) );
		$shape = array(
			'DROP TABLE IF EXISTS ' => '/^;$/',
			'CREATE TABLE '         => '/^ \(.*;$/s',
			'INSERT INTO '          => '/^ \(`(?:[^`]|``)+`(?:,\s*`(?:[^`]|``)+`)*\) VALUES \(.*;$/s',
		);
		if ( ! preg_match( $shape[ $m[1] ], $rest ) ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: detail. */ __( 'Unexpected statement in the backup database: %s', 'movekit-migration-backup' ), substr( $sql, 0, 80 ) ) ) );
		}
		$tmp = $this->tmp_name( str_replace( '``', '`', $m[2] ) );
		$sql = $m[1] . MoveKit_DB::ident( $tmp ) . $rest;

		if ( 'CREATE TABLE ' === $m[1] ) {
			$sql = $this->rename_constraints( $sql, $this->state['tables'][ $tmp ] );
			$sql = preg_replace_callback(
				'/REFERENCES `((?:[^`]|``)+)`/',
				function ( $r ) {
					return 'REFERENCES ' . MoveKit_DB::ident( $this->tmp_name( str_replace( '``', '`', $r[1] ) ) );
				},
				$sql
			);
			$sql = $this->fix_collations( $sql );
			// MariaDB-only table options that MySQL rejects.
			$sql = preg_replace( '/\s(PAGE_CHECKSUM|TRANSACTIONAL)=\d+/i', '', $sql );
		}
		return $sql;
	}

	/**
	 * Constraint names must be unique in the whole database, and the live
	 * table still uses them. Named foreign keys get a temporary name, and
	 * their own name back after the switch. Other constraint names are
	 * dropped, so the server generates them; it renames those along with
	 * the table.
	 *
	 * @param string $sql   CREATE TABLE statement.
	 * @param string $live  Final table name.
	 * @return string
	 */
	private function rename_constraints( $sql, $live ) {
		return preg_replace_callback(
			'/CONSTRAINT `((?:[^`]|``)+)` (FOREIGN KEY|CHECK)/',
			function ( $c ) use ( $live ) {
				$name = str_replace( '``', '`', $c[1] );
				if ( 'CHECK' === $c[2] || preg_match( self::AUTO_CONSTRAINT, $name ) ) {
					return $c[2];
				}
				$tmp                                 = self::TMP_PREFIX . substr( md5( $live . '|' . $name ), 0, 20 );
				$this->state['fks'][ $live ][ $tmp ] = $name;
				return 'CONSTRAINT ' . MoveKit_DB::ident( $tmp ) . ' ' . $c[2];
			},
			$sql
		);
	}

	/**
	 * Name of the temporary table for a source table, recording the final name.
	 */
	public function tmp_name( $source_table ) {
		$src = $this->state['manifest']['site']['table_prefix'];
		if ( 0 !== strpos( $source_table, $src ) ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: detail. */ __( 'Unexpected table in backup: %s', 'movekit-migration-backup' ), $source_table ) ) );
		}
		$final = $this->state['target']['table_prefix'] . substr( $source_table, strlen( $src ) );
		$tmp   = self::TMP_PREFIX . $final;
		if ( strlen( $tmp ) > 64 || strlen( self::OLD_PREFIX . $final ) > 64 ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: detail. */ __( 'Table name too long to restore safely: %s', 'movekit-migration-backup' ), $final ) ) );
		}
		$this->state['tables'][ $tmp ] = $final;
		return $tmp;
	}

	private function supported_collations() {
		$out = array();
		foreach ( MoveKit_DB::rows( 'SHOW COLLATION', MYSQLI_NUM ) as $row ) {
			$out[] = $row[0];
		}
		return $out;
	}

	/**
	 * Swap collations this server lacks (e.g. MySQL 8's utf8mb4_0900_ai_ci
	 * on MariaDB) for the closest one it has.
	 */
	private function fix_collations( $sql ) {
		$supported = $this->state['collations'];
		return preg_replace_callback(
			'/\b(utf8mb4|utf8mb3|utf8)_\w+/i',
			function ( $m ) use ( $supported ) {
				$name = strtolower( $m[0] );
				if ( in_array( $name, $supported, true ) ) {
					return $m[0];
				}
				$fallbacks = 'utf8mb4' === strtolower( $m[1] )
					? array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' )
					: array( 'utf8mb3_general_ci', 'utf8_general_ci' );
				foreach ( $fallbacks as $fb ) {
					if ( in_array( $fb, $supported, true ) ) {
						return $fb;
					}
				}
				return $m[0];
			},
			$sql
		);
	}

	/* ---------------------------------------------------------------- prefix */

	/**
	 * Option and user meta keys that embed the table prefix.
	 */
	protected function run_prefix( $deadline ) {
		$src = $this->state['manifest']['site']['table_prefix'];
		$dst = $this->state['target']['table_prefix'];
		if ( $src !== $dst ) {
			global $wpdb;
			$options  = self::TMP_PREFIX . $dst . 'options';
			$usermeta = self::TMP_PREFIX . $dst . 'usermeta';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rewrites the restored copy before it goes live; table names are built from the table prefix.
			if ( isset( $this->state['tables'][ $options ] ) ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM `$options` WHERE option_name = %s", $dst . 'user_roles' ) );
				$wpdb->query( $wpdb->prepare( "UPDATE `$options` SET option_name = %s WHERE option_name = %s", $dst . 'user_roles', $src . 'user_roles' ) );
			}
			if ( isset( $this->state['tables'][ $usermeta ] ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `$usermeta` SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s",
						$dst,
						strlen( $src ) + 1,
						$wpdb->esc_like( $src ) . '%'
					)
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return true;
	}

	/* --------------------------------------------------------------- replace */

	private function replacer() {
		$src   = $this->state['manifest']['site'];
		$dst   = $this->state['target'];
		$pairs = array();
		foreach ( array( 'home', 'siteurl', 'content_url', 'abspath', 'content_dir', 'uploads_dir' ) as $key ) {
			if ( isset( $src[ $key ], $dst[ $key ] ) ) {
				$pairs[ $src[ $key ] ] = $dst[ $key ];
			}
		}
		return MoveKit_Search_Replace::for_migration( $pairs );
	}

	protected function run_replace( $deadline ) {
		$sr     = $this->replacer();
		$tables = array_keys( $this->state['tables'] );
		$count  = count( $tables );
		while ( ! $sr->is_empty() && $this->state['rt'] < $count && microtime( true ) < $deadline ) {
			$table = $tables[ $this->state['rt'] ];
			if ( $this->replace_in_table( $table, $sr, $deadline ) ) {
				++$this->state['rt'];
				$this->state['rcursor'] = null;
			}
		}
		if ( ! $sr->is_empty() && $this->state['rt'] < count( $tables ) ) {
			return false;
		}

		$options = self::TMP_PREFIX . $this->state['target']['table_prefix'] . 'options';
		if ( isset( $this->state['tables'][ $options ] ) ) {
			// Whatever the data said, the site addresses are the destination's.
			foreach ( array( 'home', 'siteurl' ) as $opt ) {
				$this->set_option( $options, $opt, $this->state['target'][ $opt ] );
			}
			$this->keep_search_engines( $options );
		}
		$this->state['pos'] = $this->state['files_start'];
		$this->message( __( 'Restoring files', 'movekit-migration-backup' ) );
		return true;
	}

	/**
	 * A local copy usually has "Discourage search engines" ticked. Keep it
	 * from switching a live site that search engines may index to noindex.
	 *
	 * @param string $options Temporary options table.
	 */
	private function keep_search_engines( $options ) {
		global $wpdb;
		$host = (string) wp_parse_url( $this->state['target']['home'], PHP_URL_HOST );
		if ( '1' !== $this->state['target']['blog_public'] || MoveKit_Connection::is_local_host( $host ) ) {
			return;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads the restored copy before it goes live. Temporary table name built from the table prefix.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$options` WHERE option_name = %s", 'blog_public' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '0' === $value ) {
			$this->set_option( $options, 'blog_public', '1' );
			$this->log( __( 'The copy asked search engines not to index the site. This live site kept its setting, so search engines can still index it. Change it under Settings, Reading.', 'movekit-migration-backup' ) );
		}
	}

	/**
	 * Set an option in an options table that is not (yet) the live one.
	 *
	 * @param string $options Table name.
	 * @param string $name    Option name.
	 * @param string $value   Value.
	 */
	private function set_option( $options, $name, $value ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Writes the restored copy before it goes live; the options API works on the live table. Temporary table name built from the table prefix.
		$wpdb->query( $wpdb->prepare( "UPDATE `$options` SET option_value = %s WHERE option_name = %s", $value, $name ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Replace addresses in one table, in batches of rows of at most a few MB
	 * so page builder content cannot run PHP out of memory.
	 *
	 * @return bool True when the table is finished.
	 */
	private function replace_in_table( $table, MoveKit_Search_Replace $sr, $deadline ) {
		$cols = array();
		foreach ( MoveKit_DB::columns( $table ) as $c ) {
			if ( MoveKit_DB::is_text_type( $c['Type'] ) ) {
				$cols[] = $c['Field'];
			}
		}
		if ( ! $cols ) {
			return true;
		}
		$pk = MoveKit_DB::primary_key( $table );
		$t  = MoveKit_DB::ident( $table );

		$likes = array();
		foreach ( $cols as $c ) {
			foreach ( $sr->needles() as $n ) {
				$likes[] = MoveKit_DB::ident( $c ) . " LIKE '%" . MoveKit_DB::esc( MoveKit_DB::esc_like( $n ) ) . "%'";
			}
		}
		$where = '(' . implode( ' OR ', $likes ) . ')';

		if ( ! $pk ) {
			return $this->replace_without_key( $t, $cols, $where, $sr );
		}

		$keys  = implode( ',', array_map( array( 'MoveKit_DB', 'ident' ), $pk ) );
		$size  = implode(
			' + ',
			array_map(
				function ( $c ) {
					return 'COALESCE(LENGTH(' . MoveKit_DB::ident( $c ) . '), 0)';
				},
				$cols
			)
		);
		$fetch = implode( ',', array_map( array( 'MoveKit_DB', 'ident' ), array_unique( array_merge( $pk, $cols ) ) ) );
		while ( microtime( true ) < $deadline ) {
			$after = null === $this->state['rcursor'] ? '' : MoveKit_DB::key_compare( $pk, $this->state['rcursor'], '>' ) . ' AND ';
			// Sizes first, so one batch never holds more than about 8 MB of text.
			$sizes = MoveKit_DB::rows( "SELECT $keys, $size AS mvk_size FROM $t WHERE $after$where ORDER BY $keys LIMIT 500" );
			if ( ! $sizes ) {
				return true;
			}
			$total = 0;
			$last  = null;
			foreach ( $sizes as $row ) {
				if ( null !== $last && $total + $row['mvk_size'] > 8 * MB_IN_BYTES ) {
					break;
				}
				$total += $row['mvk_size'];
				$last   = $row;
			}
			$upto = MoveKit_DB::key_compare( $pk, self::key_values( $pk, $last ), '<=' );
			$rows = MoveKit_DB::rows( "SELECT $fetch FROM $t WHERE $after$upto AND $where ORDER BY $keys" );
			// One commit per batch instead of per row is much faster on InnoDB.
			MoveKit_DB::query( 'START TRANSACTION' );
			foreach ( $rows as $row ) {
				$this->update_row( $t, $row, $cols, $sr, MoveKit_DB::key_compare( $pk, self::key_values( $pk, $row ), '=' ) );
			}
			MoveKit_DB::query( 'COMMIT' );
			$this->state['rcursor'] = self::key_values( $pk, $last );
		}
		return false;
	}

	/**
	 * Key column values of a row, in key order.
	 *
	 * @param string[] $pk  Key columns.
	 * @param array    $row Row.
	 * @return array
	 */
	private static function key_values( array $pk, array $row ) {
		$out = array();
		foreach ( $pk as $c ) {
			$out[] = $row[ $c ];
		}
		return $out;
	}

	/**
	 * Tables without a primary key are rare (and small): one pass.
	 */
	private function replace_without_key( $t, array $cols, $where, MoveKit_Search_Replace $sr ) {
		foreach ( MoveKit_DB::rows( "SELECT * FROM $t WHERE $where" ) as $row ) {
			$conds = array();
			foreach ( array_keys( $row ) as $c ) {
				$conds[] = null === $row[ $c ]
					? MoveKit_DB::ident( $c ) . ' IS NULL'
					: MoveKit_DB::ident( $c ) . " = '" . MoveKit_DB::esc( $row[ $c ] ) . "'";
			}
			$this->update_row( $t, $row, $cols, $sr, implode( ' AND ', $conds ) . ' LIMIT 1' );
		}
		return true;
	}

	private function update_row( $t, array $row, array $cols, MoveKit_Search_Replace $sr, $where ) {
		$sets = array();
		foreach ( $cols as $c ) {
			$new = $sr->replace( $row[ $c ] );
			if ( $new !== $row[ $c ] ) {
				$sets[] = MoveKit_DB::ident( $c ) . " = '" . MoveKit_DB::esc( $new ) . "'";
			}
		}
		if ( $sets ) {
			MoveKit_DB::query( "UPDATE $t SET " . implode( ', ', $sets ) . " WHERE $where" );
		}
	}

	/* ----------------------------------------------------------------- files */

	protected function run_files( $deadline ) {
		$reader   = new MoveKit_Archive_Reader( $this->state['archive'] );
		$root     = $this->stage_dir();
		$excluded = MoveKit_File_Scanner::excluded_paths();
		$limit    = $this->state['summary']['manifest_offset'];

		while ( $this->state['pos'] < $limit && microtime( true ) < $deadline ) {
			$rec                = $reader->read( $this->state['pos'] );
			$this->state['pos'] = $rec['next'];
			if ( MoveKit_Archive::TYPE_FILE !== $rec['type'] && MoveKit_Archive::TYPE_DIR !== $rec['type'] ) {
				continue;
			}
			$rel = self::safe_path( $rec['path'] );
			if ( null === $rel ) {
				$this->log( sprintf( /* translators: %s: detail. */ __( 'Skipped unsafe path in backup: %s', 'movekit-migration-backup' ), $rec['path'] ) );
				continue;
			}
			if ( MoveKit_File_Scanner::is_excluded( $rel, $excluded ) ) {
				continue;
			}
			$path = $root . '/' . $rel;
			if ( MoveKit_Archive::TYPE_DIR === $rec['type'] ) {
				wp_mkdir_p( $path );
				$this->staged( 'D', $rel );
				continue;
			}
			if ( $this->write_chunk( $path, $rec ) ) {
				$this->staged( 'F', $rel );
			}
		}
		$reader->close();
		if ( $this->state['pos'] < $limit ) {
			return false;
		}
		$this->message( __( 'Switching to the restored site', 'movekit-migration-backup' ) );
		return true;
	}

	/**
	 * Restored files wait here until everything else has succeeded, so an
	 * interrupted restore never leaves the site half old and half new.
	 */
	private function stage_dir() {
		return $this->dir() . '/stage';
	}

	/**
	 * Note a fully written file or folder in the staging list.
	 *
	 * @param string $type F or D.
	 * @param string $rel  Path relative to wp-content.
	 * @throws MoveKit_Exception When the list cannot be written.
	 */
	private function staged( $type, $rel ) {
		if ( false === file_put_contents( $this->dir() . '/staged.txt', $type . "\t" . $rel . "\n", FILE_APPEND ) ) {
			throw new MoveKit_Exception( esc_html__( 'Could not record restored files. The disk may be full.', 'movekit-migration-backup' ) );
		}
	}

	/**
	 * Move every staged file into place. Each file it replaces is moved into
	 * the undo folder first, and every change is noted before it is made,
	 * so a failure part way can put everything back. Runs to the end in one
	 * request: renames are quick, and the site should spend as little time
	 * as possible between old and new. Files already moved by an earlier,
	 * interrupted attempt are skipped.
	 *
	 * @throws MoveKit_Exception When a file cannot be put in place; the
	 *                           files already moved are put back first.
	 */
	private function place_files() {
		$list = $this->dir() . '/staged.txt';
		if ( ! is_file( $list ) ) {
			return;
		}
		$stage = $this->stage_dir();
		$root  = $this->state['target']['content_dir'];
		$fh    = fopen( $list, 'rb' );
		try {
			while ( true ) {
				$line = fgets( $fh );
				if ( false === $line ) {
					break;
				}
				$parts = explode( "\t", rtrim( $line, "\n" ), 2 );
				if ( 2 !== count( $parts ) ) {
					continue;
				}
				list( $type, $rel ) = $parts;
				$path               = $root . '/' . $rel;
				if ( 'D' === $type ) {
					if ( ! is_dir( $path ) ) {
						MoveKit_Undo::note( 'M', $rel );
						wp_mkdir_p( $path );
					}
					continue;
				}
				$from = $stage . '/' . $rel . '.movekit-part';
				if ( ! file_exists( $from ) ) {
					continue;
				}
				if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
					throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: folder path. */ __( 'Could not create folder %s', 'movekit-migration-backup' ), dirname( $path ) ) ) );
				}
				if ( file_exists( $path ) || is_link( $path ) ) {
					$old = MoveKit_Undo::old_path( $rel );
					MoveKit_Undo::note( 'R', $rel );
					if ( ( ! is_dir( dirname( $old ) ) && ! wp_mkdir_p( dirname( $old ) ) ) || ! MoveKit_Undo::move( $path, $old ) ) {
						throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: file path. */ __( 'Could not replace %s. Check folder permissions.', 'movekit-migration-backup' ), $path ) . self::last_error() ) );
					}
				} else {
					MoveKit_Undo::note( 'N', $rel );
				}
				if ( ! MoveKit_Undo::move( $from, $path ) ) {
					throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: file path. */ __( 'Could not replace %s. Check folder permissions.', 'movekit-migration-backup' ), $path ) . self::last_error() ) );
				}
			}
		} catch ( MoveKit_Exception $e ) {
			fclose( $fh );
			$this->put_files_back();
			throw $e;
		}
		fclose( $fh );
		MoveKit_Storage::rrmdir( $stage );
	}

	/**
	 * Undo place_files() after a failure, and say so in the log.
	 */
	private function put_files_back() {
		$failed = MoveKit_Undo::rollback_files( $this->state['target']['content_dir'] );
		MoveKit_Undo::discard();
		if ( $failed ) {
			/* translators: %d: number of files. */
			$this->log( sprintf( _n( '%d replaced file could not be put back. It is in the movekit-backups folder.', '%d replaced files could not be put back. They are in the movekit-backups folder.', $failed, 'movekit-migration-backup' ), $failed ) );
		}
	}

	/**
	 * Write one record's data into its .movekit-part file in the staging folder.
	 *
	 * @return bool Whether the file is complete.
	 */
	private function write_chunk( $path, array $rec ) {
		$part = $path . '.movekit-part';
		if ( 0 === $rec['offset'] ) {
			if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
				throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: folder path. */ __( 'Could not create folder %s', 'movekit-migration-backup' ), dirname( $path ) ) ) );
			}
			$fh = @fopen( $part, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
		} else {
			$fh = @fopen( $part, 'cb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
		}
		if ( ! $fh ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: file path. */ __( 'Could not write %s. Check folder permissions.', 'movekit-migration-backup' ), $path ) ) );
		}
		fseek( $fh, $rec['offset'] );
		if ( fwrite( $fh, $rec['data'] ) !== strlen( $rec['data'] ) ) {
			fclose( $fh );
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: %s: file path. */ __( 'Writing %s failed. The disk may be full.', 'movekit-migration-backup' ), $path ) ) );
		}
		fclose( $fh );

		if ( $rec['offset'] + strlen( $rec['data'] ) < $rec['filesize'] ) {
			return false;
		}
		// The finished file keeps its .movekit-part name until it is moved
		// into place: some hosts (WP Engine) refuse new .php files here.
		if ( $rec['mtime'] ) {
			@touch( $part, $rec['mtime'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Keeping the original date is optional.
		}
		// The source's permissions are not copied: Docker and Windows report
		// 0777, which some hosts refuse to run. New files get this server's
		// default, or WordPress's own setting when it has one.
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			@chmod( $part, FS_CHMOD_FILE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort; some hosts ignore it.
		}
		return true;
	}

	/**
	 * PHP's message for the file operation that just failed, for error reports.
	 *
	 * @return string " (message)", or "" when there is none.
	 */
	private static function last_error() {
		$error = error_get_last();
		return $error ? ' (' . $error['message'] . ')' : '';
	}

	/**
	 * Normalize an archive path, or null if it could escape wp-content.
	 */
	public static function safe_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		if ( '' === $path || false !== strpos( $path, "\0" ) || '/' === $path[0] || preg_match( '#^[A-Za-z]:#', $path ) ) {
			return null;
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $p ) {
			if ( '' === $p || '.' === $p ) {
				continue;
			}
			if ( '..' === $p ) {
				return null;
			}
			$parts[] = $p;
		}
		return $parts ? implode( '/', $parts ) : null;
	}

	/* ------------------------------------------------------------------ swap */

	protected function run_swap( $deadline ) {
		if ( empty( $this->state['undo_started'] ) ) {
			// Only the latest restore can be undone; its _mvo tables would clash.
			MoveKit_Undo::discard();
			$this->state['undo_started'] = true;
			$this->save();
		}
		if ( empty( $this->state['swapped'] ) ) {
			$this->place_files();
			$this->swap_tables();
		}

		$prefix = $this->state['target']['table_prefix'];
		if ( $this->state['tables'] ) {
			self::keep_plugin_active( $prefix );
			$this->clear_rewrite_rules( $prefix );
		}
		$old_fks = $this->restore_constraint_names( $this->state['old_tables'] );
		try {
			MoveKit_Undo::save(
				array(
					'from'       => $this->state['manifest']['site']['home'],
					'old_tables' => $this->state['old_tables'],
					'new_tables' => $this->state['new_tables'],
					'old_fks'    => $old_fks,
				)
			);
		} catch ( MoveKit_Exception $e ) {
			$this->log( $e->text() );
		}
		MoveKit_Cache::after_restore( $prefix );
		// After the cache flush, so the restored site's own schedule is the one updated.
		$undo = MoveKit_Undo::info();
		MoveKit_Undo::schedule_tidy( $undo ? $undo['until'] : time() + DAY_IN_SECONDS );
		return true;
	}

	/**
	 * Swap the live tables for the restored ones in one RENAME. The live
	 * tables become _mvo tables, kept for undo.
	 *
	 * @throws MoveKit_Exception When the database refuses; the files are put back first.
	 */
	private function swap_tables() {
		$old_tables = array();
		$new_tables = array();
		if ( $this->state['tables'] ) {
			$renames = array();
			$live    = MoveKit_DB::tables( $this->state['target']['table_prefix'] );
			foreach ( $this->state['tables'] as $tmp => $final ) {
				if ( in_array( $final, $live, true ) ) {
					MoveKit_DB::query( 'DROP TABLE IF EXISTS ' . MoveKit_DB::ident( self::OLD_PREFIX . $final ) );
					$renames[]    = MoveKit_DB::ident( $final ) . ' TO ' . MoveKit_DB::ident( self::OLD_PREFIX . $final );
					$old_tables[] = $final;
				} else {
					$new_tables[] = $final;
				}
				$renames[] = MoveKit_DB::ident( $tmp ) . ' TO ' . MoveKit_DB::ident( $final );
			}
			try {
				// One statement, so the switch is all-or-nothing.
				MoveKit_DB::query( 'RENAME TABLE ' . implode( ', ', $renames ) );
			} catch ( MoveKit_Exception $e ) {
				$this->put_files_back();
				throw $e;
			}
		}
		$this->state['old_tables'] = $old_tables;
		$this->state['new_tables'] = $new_tables;
		$this->state['swapped']    = true;
		// Saved at once: if this request dies now, a retry must not swap again.
		$this->save();
	}

	/**
	 * Give named foreign keys their own names back. The kept old tables
	 * still hold those names, so theirs are removed first and remembered,
	 * for an undo to put back.
	 *
	 * @param string[] $old_tables Tables that were replaced.
	 * @return array[] table, name, definition of each removed constraint.
	 */
	private function restore_constraint_names( array $old_tables ) {
		$fks = empty( $this->state['fks'] ) ? array() : $this->state['fks'];
		if ( ! $fks ) {
			return array();
		}
		$wanted = array();
		foreach ( $fks as $names ) {
			foreach ( $names as $orig ) {
				$wanted[ $orig ] = true;
			}
		}
		$removed = array();
		foreach ( $old_tables as $final ) {
			$old = self::OLD_PREFIX . $final;
			foreach ( self::foreign_keys( $old ) as $name => $definition ) {
				if ( ! isset( $wanted[ $name ] ) ) {
					continue;
				}
				try {
					MoveKit_DB::query( 'ALTER TABLE ' . MoveKit_DB::ident( $old ) . ' DROP FOREIGN KEY ' . MoveKit_DB::ident( $name ) );
					$removed[] = array(
						'table'      => $final,
						// After an undo the referenced tables have their own names again.
						'definition' => str_replace( 'REFERENCES `' . self::OLD_PREFIX, 'REFERENCES `', $definition ),
						'name'       => $name,
					);
				} catch ( MoveKit_Exception $e ) {
					continue;
				}
			}
		}
		foreach ( $fks as $final => $names ) {
			$current = self::foreign_keys( $final );
			foreach ( $names as $tmp => $orig ) {
				if ( ! isset( $current[ $tmp ] ) ) {
					continue;
				}
				try {
					MoveKit_DB::query( 'ALTER TABLE ' . MoveKit_DB::ident( $final ) . ' DROP FOREIGN KEY ' . MoveKit_DB::ident( $tmp ) );
					MoveKit_DB::query( 'ALTER TABLE ' . MoveKit_DB::ident( $final ) . ' ADD CONSTRAINT ' . MoveKit_DB::ident( $orig ) . ' ' . $current[ $tmp ] );
				} catch ( MoveKit_Exception $e ) {
					/* translators: 1: constraint name, 2: table name. */
					$this->log( sprintf( __( 'The foreign key %1$s on table %2$s kept a temporary name. The site works the same.', 'movekit-migration-backup' ), $orig, $final ) );
				}
			}
		}
		return $removed;
	}

	/**
	 * Named foreign keys of a table.
	 *
	 * @param string $table Table name.
	 * @return string[] name => "FOREIGN KEY (...) REFERENCES ..." definition.
	 */
	private static function foreign_keys( $table ) {
		$create = MoveKit_DB::rows( 'SHOW CREATE TABLE ' . MoveKit_DB::ident( $table ), MYSQLI_NUM );
		$out    = array();
		if ( isset( $create[0][1] ) && preg_match_all( '/CONSTRAINT `((?:[^`]|``)+)` (FOREIGN KEY [^\n]*?),?\n/', $create[0][1] . "\n", $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $fk ) {
				$out[ str_replace( '``', '`', $fk[1] ) ] = $fk[2];
			}
		}
		return $out;
	}

	/**
	 * The restored site's saved permalink rules point at the old site.
	 *
	 * @param string $prefix Table prefix.
	 */
	private function clear_rewrite_rules( $prefix ) {
		global $wpdb;
		$options = $prefix . 'options';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options cache still holds the old site's values. Table name built from the table prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `$options` WHERE option_name = %s", 'rewrite_rules' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The restored database has the source site's active plugin list; make
	 * sure this plugin stays on so the restore can finish and be repeated.
	 *
	 * @param string $prefix Table prefix of the live tables.
	 */
	public static function keep_plugin_active( $prefix ) {
		global $wpdb;
		$options = $prefix . 'options';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options API and its cache still hold the old site's values; the table name is built from the table prefix.
		$raw    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$options` WHERE option_name = %s", 'active_plugins' ) );
		$active = null === $raw ? array() : @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- maybe_unserialize() would allow objects.
		if ( ! is_array( $active ) ) {
			$active = array();
		}
		if ( ! in_array( MOVEKIT_BASENAME, $active, true ) ) {
			$active[] = MOVEKIT_BASENAME;
			$value    = serialize( array_values( $active ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The format WordPress stores.
			if ( null === $raw ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO `$options` (option_name, option_value, autoload) VALUES (%s, %s, 'yes')", 'active_plugins', $value ) );
			} else {
				$wpdb->query( $wpdb->prepare( "UPDATE `$options` SET option_value = %s WHERE option_name = %s", $value, 'active_plugins' ) );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ---------------------------------------------------------------- finish */

	protected function run_finish( $deadline ) {
		$m                     = $this->state['manifest'];
		$this->state['result'] = array(
			'from'     => $m['site']['home'],
			'to'       => $this->state['target']['home'],
			'tables'   => count( $this->state['tables'] ),
			'files'    => $m['files']['count'],
			'parts'    => MoveKit_Parts::of( $m ),
			'database' => (bool) $this->state['tables'],
			'undo'     => MoveKit_Undo::info(),
		);
		$this->message(
			$this->state['tables']
				? __( 'Restore complete. Log in with the username and password from the site you restored.', 'movekit-migration-backup' )
				: __( 'Restore complete.', 'movekit-migration-backup' )
		);
		return true;
	}

	protected function on_fail() {
		if ( empty( $this->state['swapped'] ) ) {
			foreach ( array_keys( $this->state['tables'] ) as $tmp ) {
				try {
					MoveKit_DB::query( 'DROP TABLE IF EXISTS ' . MoveKit_DB::ident( $tmp ) );
				} catch ( Exception $e ) {
					// Best effort: a leftover temporary table is harmless.
					continue;
				}
			}
		}
		@unlink( $this->dir() . '/database.sql' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A leftover temporary file is harmless.
		if ( empty( $this->state['swapped'] ) ) {
			if ( ! empty( $this->state['undo_started'] ) ) {
				$this->put_files_back();
			}
			MoveKit_Storage::rrmdir( $this->stage_dir() );
		}
	}
}
