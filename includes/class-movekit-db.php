<?php
/**
 * Thin wrapper over the mysqli connection WordPress already opened.
 *
 * Raw mysqli is used instead of $wpdb->query() because wpdb rewrites
 * non-ASCII queries against the table charset and escapes "%" signs,
 * both of which would corrupt a database dump.
 *
 * All values are escaped with mysqli_real_escape_string on that connection.
 * Each mysqli call carries its own phpcs:ignore with this reason.
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

class MoveKit_DB {

	public static function link() {
		global $wpdb;
		$link = $wpdb->dbh;
		if ( ! ( $link instanceof mysqli ) ) {
			throw new MoveKit_Exception( esc_html__( 'MoveKit needs the mysqli database driver.', 'movekit-migration-backup' ) );
		}
		return $link;
	}

	public static function session_setup() {
		self::query( 'SET NAMES utf8mb4' );
		self::query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		self::query( 'SET SESSION foreign_key_checks = 0' );
		self::query( 'SET SESSION unique_checks = 0' );
	}

	/**
	 * Run a statement, throwing with the server's error on failure.
	 */
	public static function query( $sql ) {
		$link   = self::link();
		$result = mysqli_query( $link, $sql ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- See the file comment: wpdb would alter dump statements.
		if ( false === $result ) {
			if ( in_array( mysqli_errno( $link ), array( 1153, 2006 ), true ) && strlen( $sql ) > 1048576 ) { // phpcs:ignore WordPress.DB.RestrictedFunctions -- See the file comment: wpdb would alter dump statements.
				throw new MoveKit_Exception( esc_html( self::packet_message( strlen( $sql ) ) ) );
			}
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: 1: database error, 2: start of the query. */ __( 'Database error: %1$s (in: %2$s)', 'movekit-migration-backup' ), mysqli_error( $link ), self::snippet( $sql ) ) ) ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- See the file comment: wpdb would alter dump statements.
		}
		return $result;
	}

	/**
	 * @return array[] Rows as numeric arrays (MYSQLI_NUM) or associative (MYSQLI_ASSOC).
	 */
	public static function rows( $sql, $mode = MYSQLI_ASSOC ) {
		$result = self::query( $sql );
		$rows   = array();
		if ( $result instanceof mysqli_result ) {
			while ( $row = mysqli_fetch_array( $result, $mode ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, WordPress.DB.RestrictedFunctions -- Reads the result of query().
				$rows[] = $row;
			}
			mysqli_free_result( $result ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- See the file comment: wpdb would alter dump statements.
		}
		return $rows;
	}

	/**
	 * Run a query and hand each row to $each without holding the whole
	 * result in memory. No other query may run until it returns.
	 *
	 * @param string   $sql  Query.
	 * @param callable $each Called with each row as a numeric array.
	 * @return int Rows read.
	 */
	public static function each_row( $sql, callable $each ) {
		$link   = self::link();
		$result = mysqli_query( $link, $sql, MYSQLI_USE_RESULT ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- See the file comment: wpdb would alter dump statements.
		if ( false === $result ) {
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: 1: database error, 2: start of the query. */ __( 'Database error: %1$s (in: %2$s)', 'movekit-migration-backup' ), mysqli_error( $link ), self::snippet( $sql ) ) ) ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- See the file comment: wpdb would alter dump statements.
		}
		$count = 0;
		try {
			while ( $row = mysqli_fetch_row( $result ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, WordPress.DB.RestrictedFunctions -- Streams rows one at a time.
				$each( $row );
				++$count;
			}
		} finally {
			mysqli_free_result( $result ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- See the file comment: wpdb would alter dump statements.
		}
		if ( mysqli_errno( $link ) ) { // phpcs:ignore WordPress.DB.RestrictedFunctions -- See the file comment: wpdb would alter dump statements.
			throw new MoveKit_Exception( esc_html( sprintf( /* translators: 1: database error, 2: start of the query. */ __( 'Database error: %1$s (in: %2$s)', 'movekit-migration-backup' ), mysqli_error( $link ), self::snippet( $sql ) ) ) ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- See the file comment: wpdb would alter dump statements.
		}
		return $count;
	}

	/**
	 * Compare a row's key with values: "(a, b) > ('1', 'x')", or with "="
	 * an exact match on every key column.
	 *
	 * @param string[] $columns Key columns.
	 * @param array    $values  Values in the same order.
	 * @param string   $op      >, <= or =.
	 * @return string SQL condition.
	 */
	public static function key_compare( array $columns, array $values, $op ) {
		$cols = array_map( array( __CLASS__, 'ident' ), $columns );
		$vals = array();
		foreach ( array_values( $values ) as $v ) {
			$vals[] = "'" . self::esc( $v ) . "'";
		}
		if ( '=' === $op ) {
			$parts = array();
			foreach ( $cols as $i => $c ) {
				$parts[] = "$c = {$vals[ $i ]}";
			}
			return implode( ' AND ', $parts );
		}
		if ( 1 === count( $cols ) ) {
			return "{$cols[0]} $op {$vals[0]}";
		}
		return '(' . implode( ', ', $cols ) . ") $op (" . implode( ', ', $vals ) . ')';
	}

	public static function value( $sql ) {
		$rows = self::rows( $sql, MYSQLI_NUM );
		return isset( $rows[0][0] ) ? $rows[0][0] : null;
	}

	public static function esc( $value ) {
		return mysqli_real_escape_string( self::link(), (string) $value ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- See the file comment: wpdb would alter dump statements.
	}

	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	public static function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Base tables whose names start with $prefix.
	 */
	public static function tables( $prefix ) {
		$rows = self::rows( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'", MYSQLI_NUM );
		$all  = array();
		foreach ( $rows as $row ) {
			$all[] = $row[0];
		}
		// Another WordPress site in the same database whose prefix starts with
		// this one (wp_ and wp_shop_) is not part of this site.
		$others = array();
		foreach ( $all as $name ) {
			if ( 0 === strpos( $name, $prefix ) && 'options' === substr( $name, -7 ) ) {
				$other = substr( $name, 0, -7 );
				if ( strlen( $other ) > strlen( $prefix ) && in_array( $other . 'posts', $all, true ) && in_array( $other . 'usermeta', $all, true ) ) {
					$others[] = $other;
				}
			}
		}
		$out = array();
		foreach ( $all as $name ) {
			if ( 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			foreach ( $others as $other ) {
				if ( 0 === strpos( $name, $other ) ) {
					continue 2;
				}
			}
			$out[] = $name;
		}
		sort( $out );
		return $out;
	}

	/**
	 * @return array[] Columns as Field/Type/Key/... from SHOW COLUMNS.
	 */
	public static function columns( $table ) {
		return self::rows( 'SHOW COLUMNS FROM ' . self::ident( $table ) );
	}

	/**
	 * Primary key column names, in key order.
	 */
	public static function primary_key( $table ) {
		$rows = self::rows( 'SHOW KEYS FROM ' . self::ident( $table ) . " WHERE Key_name = 'PRIMARY'" );
		usort(
			$rows,
			function ( $a, $b ) {
				return $a['Seq_in_index'] - $b['Seq_in_index'];
			}
		);
		return array_map(
			function ( $r ) {
				return $r['Column_name'];
			},
			$rows
		);
	}

	public static function is_integer_type( $type ) {
		return (bool) preg_match( '/^(tiny|small|medium|big)?int\b/i', $type );
	}

	public static function is_binary_type( $type ) {
		return (bool) preg_match( '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit)\b/i', $type );
	}

	public static function is_text_type( $type ) {
		return (bool) preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext)\b/i', $type );
	}

	/**
	 * Explain a statement the database refused for its size.
	 *
	 * @param int $needed Size of the statement in bytes.
	 * @return string
	 */
	private static function packet_message( $needed ) {
		$limit = 0;
		try {
			$limit = (int) self::value( 'SELECT @@max_allowed_packet' );
		} catch ( MoveKit_Exception $e ) {
			unset( $e ); // The connection may be gone; the size is optional.
		}
		$need = size_format( (int) ( $needed * 1.25 ) + 1048576, 0 );
		if ( ! $limit ) {
			return sprintf(
				/* translators: 1: size of the data, 2: size to raise the limit to. */
				__( 'The database on this site refused a row of about %1$s because it is bigger than its max_allowed_packet setting. Raise max_allowed_packet to at least %2$s in the MySQL or MariaDB settings (ask your host if you cannot), then try again.', 'movekit-migration-backup' ),
				size_format( $needed, 1 ),
				$need
			);
		}
		return sprintf(
			/* translators: 1: size of the data, 2: the database's current limit, 3: size to raise the limit to. */
			__( 'The database on this site refused a row of about %1$s because it is bigger than its max_allowed_packet setting (%2$s). Raise max_allowed_packet to at least %3$s in the MySQL or MariaDB settings (ask your host if you cannot), then try again.', 'movekit-migration-backup' ),
			size_format( $needed, 1 ),
			size_format( $limit, 0 ),
			$need
		);
	}

	private static function snippet( $sql ) {
		$sql = preg_replace( '/\s+/', ' ', $sql );
		return strlen( $sql ) > 160 ? substr( $sql, 0, 160 ) . '...' : $sql;
	}
}
