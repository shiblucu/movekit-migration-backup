<?php
/**
 * Dumps the site's tables to a SQL file in resumable batches.
 *
 * Every statement is written on a single line ending in ";\n" (the escaping
 * turns embedded newlines into \n), so the importer can replay the dump one
 * line at a time and resume from any byte offset.
 *
 * Backups are read and written in pieces with plain PHP file functions:
 * WP_Filesystem has no seek/append/stream operations and would need whole
 * multi-GB files in memory.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

class MoveKit_DB_Exporter {

	const BATCH_ROWS    = 1000;
	const MAX_STATEMENT = 524288;

	/**
	 * Initial state for a dump of $tables into $sql_file.
	 */
	public static function init( $sql_file, array $tables ) {
		return array(
			'sql_file' => $sql_file,
			'tables'   => array_values( $tables ),
			'ti'       => 0,
			'schema'   => false,
			'cursor'   => null,
			'offset'   => 0,
			'written'  => 0,
			'rows'     => 0,
		);
	}

	/**
	 * Export until done or $deadline (unix time). Returns true when finished.
	 */
	public static function run( array &$state, $deadline ) {
		MoveKit_DB::session_setup();
		$fh = @fopen( $state['sql_file'], 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The result is checked below.
		if ( ! $fh ) {
			throw new MoveKit_Exception( esc_html__( 'Could not write the database export file.', 'movekit-migration-backup' ) );
		}
		ftruncate( $fh, $state['written'] );
		fseek( $fh, 0, SEEK_END );

		$meta  = null;
		$count = count( $state['tables'] );
		while ( $state['ti'] < $count && microtime( true ) < $deadline ) {
			$table = $state['tables'][ $state['ti'] ];
			if ( null === $meta || $meta['table'] !== $table ) {
				$meta = self::table_meta( $table );
			}

			if ( ! $state['schema'] ) {
				$create = MoveKit_DB::rows( 'SHOW CREATE TABLE ' . MoveKit_DB::ident( $table ), MYSQLI_NUM );
				$create = str_replace( array( "\r\n", "\n", "\r" ), ' ', $create[0][1] );
				self::write( $fh, 'DROP TABLE IF EXISTS ' . MoveKit_DB::ident( $table ) . ";\n" );
				self::write( $fh, $create . ";\n" );
				$state['schema'] = true;
			}

			$written = self::export_batch( $fh, $meta, $state );
			if ( ! $written ) {
				++$state['ti'];
				$state['schema']  = false;
				$state['cursor']  = null;
				$state['offset']  = 0;
				$state['written'] = self::tell( $fh );
				continue;
			}
			$state['rows']   += $written;
			$state['written'] = self::tell( $fh );
		}
		fclose( $fh );
		return $state['ti'] >= count( $state['tables'] );
	}

	private static function table_meta( $table ) {
		$columns = MoveKit_DB::columns( $table );
		$pk      = MoveKit_DB::primary_key( $table );
		$names   = array();
		$binary  = array();
		$bits    = array();
		$select  = array();
		foreach ( $columns as $col ) {
			// Generated columns are computed by the server and refuse values.
			if ( preg_match( '/\b(VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', $col['Extra'] ) ) {
				continue;
			}
			$is_bit  = (bool) preg_match( '/^bit\b/i', $col['Type'] );
			$names[] = $col['Field'];
			$bits[]  = $is_bit;
			// BIT values arrive as text digits, so read them as numbers.
			$select[] = $is_bit ? MoveKit_DB::ident( $col['Field'] ) . '+0 AS ' . MoveKit_DB::ident( $col['Field'] ) : MoveKit_DB::ident( $col['Field'] );
			$binary[] = ! $is_bit && MoveKit_DB::is_binary_type( $col['Type'] );
		}
		$key_index = array();
		foreach ( $pk as $c ) {
			$i = array_search( $c, $names, true );
			if ( false === $i ) {
				$key_index = array();
				break;
			}
			$key_index[] = $i;
		}
		return array(
			'table'     => $table,
			'names'     => $names,
			'binary'    => $binary,
			'bit'       => $bits,
			'select'    => implode( ',', $select ),
			'pk'        => $key_index ? $pk : array(),
			'key_index' => $key_index,
			'col_list'  => implode( ',', array_map( array( 'MoveKit_DB', 'ident' ), $names ) ),
		);
	}

	/**
	 * Write the next batch of rows as INSERT statements, reading them one
	 * at a time so large rows (page builder content) cannot exhaust memory.
	 * Tables with a primary key are read in key order from where the last
	 * batch stopped, which stays fast on large tables.
	 *
	 * @return int Rows written; 0 when the table is done.
	 */
	private static function export_batch( $fh, array $meta, array &$state ) {
		$sql = 'SELECT ' . $meta['select'] . ' FROM ' . MoveKit_DB::ident( $meta['table'] );
		if ( $meta['pk'] ) {
			if ( null !== $state['cursor'] ) {
				$sql .= ' WHERE ' . MoveKit_DB::key_compare( $meta['pk'], (array) $state['cursor'], '>' );
			}
			$sql .= ' ORDER BY ' . implode( ',', array_map( array( 'MoveKit_DB', 'ident' ), $meta['pk'] ) ) . ' LIMIT ' . self::BATCH_ROWS;
		} else {
			$sql .= ' LIMIT ' . (int) $state['offset'] . ', ' . self::BATCH_ROWS;
		}

		$prefix = 'INSERT INTO ' . MoveKit_DB::ident( $meta['table'] ) . ' (' . $meta['col_list'] . ') VALUES ';
		$buf    = '';
		$last   = null;
		$count  = MoveKit_DB::each_row(
			$sql,
			function ( $row ) use ( $fh, $meta, $prefix, &$buf, &$last ) {
				$vals = array();
				foreach ( $row as $i => $v ) {
					if ( null === $v ) {
						$vals[] = 'NULL';
					} elseif ( $meta['bit'][ $i ] && ctype_digit( (string) $v ) ) {
						$vals[] = (string) $v;
					} elseif ( $meta['binary'][ $i ] ) {
						$vals[] = '' === $v ? "''" : '0x' . bin2hex( $v );
					} else {
						$vals[] = "'" . MoveKit_DB::esc( $v ) . "'";
					}
				}
				$tuple = '(' . implode( ',', $vals ) . ')';
				unset( $vals );
				if ( '' !== $buf && strlen( $buf ) + strlen( $tuple ) > self::MAX_STATEMENT ) {
					self::write( $fh, $prefix . $buf . ";\n" );
					$buf = '';
				}
				$buf .= ( '' === $buf ? '' : ',' ) . $tuple;
				$last = array();
				foreach ( $meta['key_index'] as $i ) {
					$last[] = $row[ $i ];
				}
			}
		);
		if ( '' !== $buf ) {
			self::write( $fh, $prefix . $buf . ";\n" );
		}
		if ( $meta['pk'] ) {
			$state['cursor'] = $last;
		} else {
			$state['offset'] += $count;
		}
		return $count;
	}

	private static function write( $fh, $bytes ) {
		if ( fwrite( $fh, $bytes ) !== strlen( $bytes ) ) {
			throw new MoveKit_Exception( esc_html__( 'Writing the database export failed. The disk may be full.', 'movekit-migration-backup' ) );
		}
	}

	private static function tell( $fh ) {
		fflush( $fh );
		return ftell( $fh );
	}
}
