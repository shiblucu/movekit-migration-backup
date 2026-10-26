<?php
/**
 * Replaces URLs and paths in database values, including inside PHP
 * serialized data, without ever unserializing (so no objects are created).
 *
 * Serialized strings are walked token by token and every s:N:"..." length
 * is recomputed after replacement. Values that are not valid serialized
 * data get a plain replacement.
 *
 * An old address only matches where it ends: http://old.test must not
 * change http://old.test.example.org or http://old.test/wp-guide when the
 * old address is http://old.test/wp.
 *
 * @package MoveKit
 */

defined( 'ABSPATH' ) || exit;

class MoveKit_Search_Replace {

	/** @var array from => to */
	private $map;

	/** @var string[] */
	private $needles;

	/** @var string Regex matching any needle that ends at a boundary. */
	private $pattern;

	public function __construct( array $map ) {
		$map           = array_filter(
			$map,
			function ( $to, $from ) {
				return '' !== (string) $from && (string) $from !== (string) $to;
			},
			ARRAY_FILTER_USE_BOTH
		);
		$this->map     = $map;
		$this->needles = array_map( 'strval', array_keys( $map ) );
		$sorted        = $this->needles;
		// Longest first, so a longer address wins over one it starts with.
		usort(
			$sorted,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$quoted        = array_map(
			function ( $n ) {
				return preg_quote( $n, '~' );
			},
			$sorted
		);
		$this->pattern = $quoted ? '~(?:' . implode( '|', $quoted ) . ')' . self::BOUNDARY . '~' : '';
	}

	/**
	 * What may follow a match: anything but a character that would continue
	 * a host name or path segment (letters, digits, _ and -), a dot that
	 * starts another host label, or a port number.
	 */
	const BOUNDARY = '(?![A-Za-z0-9_\-]|\.[A-Za-z0-9]|:[0-9])';

	/**
	 * Build the replacement pairs for moving from one site to another.
	 *
	 * @param array $pairs Plain from => to pairs (URLs and paths).
	 */
	public static function for_migration( array $pairs ) {
		$map = array();
		foreach ( $pairs as $from => $to ) {
			$from = (string) $from;
			$to   = (string) $to;
			if ( strlen( $from ) < 4 || $from === $to ) {
				continue; // Too short to replace safely, or nothing to do.
			}
			$variants = array( $from => $to );
			if ( preg_match( '#^https?://#i', $from ) ) {
				// The other scheme of the old URL, and protocol-relative links.
				$other              = preg_match( '#^https://#i', $from ) ? 'http://' . substr( $from, 8 ) : 'https://' . substr( $from, 7 );
				$variants[ $other ] = $to;
				$variants[ preg_replace( '#^https?:#i', '', $from ) ] = preg_replace( '#^https?:#i', '', $to );
			}
			foreach ( $variants as $f => $t ) {
				$map[ $f ] = $t;
				// JSON-escaped slashes, as stored by the block editor and many plugins.
				$map[ str_replace( '/', '\\/', $f ) ] = str_replace( '/', '\\/', $t );
				// URL-encoded, as found in query strings.
				$map[ rawurlencode( $f ) ] = rawurlencode( $t );
			}
		}
		return new self( $map );
	}

	public function needles() {
		return $this->needles;
	}

	public function is_empty() {
		return ! $this->map;
	}

	/**
	 * Replace in one value.
	 */
	public function replace( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! $this->contains_needle( $value ) ) {
			return $value;
		}
		if ( self::looks_serialized( $value ) ) {
			try {
				$pos  = 0;
				$out  = $this->walk( $value, $pos, 0 );
				$tail = (string) substr( $value, $pos );
				// Some plugins store serialized data with a trailing newline.
				if ( '' === trim( $tail ) ) {
					return $out . $tail;
				}
			} catch ( UnexpectedValueException $e ) {
				// Not valid serialized data: a plain replacement is safe.
				return $this->replace_plain( $value );
			}
		}
		return $this->replace_plain( $value );
	}

	/**
	 * Replace every needle that ends at a boundary.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private function replace_plain( $value ) {
		$map = $this->map;
		return preg_replace_callback(
			$this->pattern,
			function ( $m ) use ( $map ) {
				return $map[ $m[0] ];
			},
			$value
		);
	}

	private function contains_needle( $value ) {
		foreach ( $this->needles as $needle ) {
			if ( false !== strpos( $value, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function looks_serialized( $value ) {
		return (bool) preg_match( '/^(?:[aOsibdNCE]:|N;|b:[01];)/', $value );
	}

	/**
	 * Parse one serialized value at $pos and return it rebuilt.
	 */
	private function walk( $s, &$pos, $depth ) {
		if ( $depth > 512 ) {
			throw new UnexpectedValueException( 'too deep' );
		}
		$type = isset( $s[ $pos ] ) ? $s[ $pos ] : '';
		switch ( $type ) {
			case 'N':
				$this->expect( $s, $pos, 'N;' );
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $pos );
				if ( false === $end || ':' !== $s[ $pos + 1 ] ) {
					throw new UnexpectedValueException( 'scalar' );
				}
				$token = substr( $s, $pos, $end - $pos + 1 );
				$pos   = $end + 1;
				return $token;

			case 's':
				$len = $this->length( $s, $pos, 's:' );
				$this->expect( $s, $pos, '"' );
				if ( $pos + $len + 2 > strlen( $s ) ) {
					throw new UnexpectedValueException( 'string' );
				}
				$str  = substr( $s, $pos, $len );
				$pos += $len;
				$this->expect( $s, $pos, '";' );
				$new = $this->replace( $str );
				return 's:' . strlen( $new ) . ':"' . $new . '";';

			case 'a':
				$count = $this->length( $s, $pos, 'a:' );
				$this->expect( $s, $pos, '{' );
				$out = 'a:' . $count . ':{';
				for ( $i = 0; $i < $count * 2; $i++ ) {
					$out .= $this->walk( $s, $pos, $depth + 1 );
				}
				$this->expect( $s, $pos, '}' );
				return $out . '}';

			case 'O':
				$start   = $pos;
				$cls_len = $this->length( $s, $pos, 'O:' );
				$this->expect( $s, $pos, '"' );
				$pos += $cls_len;
				$this->expect( $s, $pos, '":' );
				$count = $this->number( $s, $pos );
				$this->expect( $s, $pos, ':{' );
				$out = substr( $s, $start, $pos - $start );
				for ( $i = 0; $i < $count * 2; $i++ ) {
					$out .= $this->walk( $s, $pos, $depth + 1 );
				}
				$this->expect( $s, $pos, '}' );
				return $out . '}';

			case 'C':
				// Custom-serialized object: opaque payload, kept as-is.
				$start   = $pos;
				$cls_len = $this->length( $s, $pos, 'C:' );
				$this->expect( $s, $pos, '"' );
				$pos += $cls_len;
				$this->expect( $s, $pos, '":' );
				$len = $this->number( $s, $pos );
				$this->expect( $s, $pos, ':{' );
				$pos += $len;
				$this->expect( $s, $pos, '}' );
				return substr( $s, $start, $pos - $start );

			case 'E':
				$len = $this->length( $s, $pos, 'E:' );
				$this->expect( $s, $pos, '"' );
				$token = 'E:' . $len . ':"' . substr( $s, $pos, $len ) . '";';
				$pos  += $len;
				$this->expect( $s, $pos, '";' );
				return $token;
		}
		throw new UnexpectedValueException( 'unknown type' );
	}

	private function length( $s, &$pos, $prefix ) {
		$this->expect( $s, $pos, $prefix );
		$n = $this->number( $s, $pos );
		$this->expect( $s, $pos, ':' );
		return $n;
	}

	private function number( $s, &$pos ) {
		if ( ! preg_match( '/\G\d+/', $s, $m, 0, $pos ) ) {
			throw new UnexpectedValueException( 'number' );
		}
		$pos += strlen( $m[0] );
		return (int) $m[0];
	}

	private function expect( $s, &$pos, $token ) {
		if ( substr( $s, $pos, strlen( $token ) ) !== $token ) {
			throw new UnexpectedValueException( 'unexpected token' );
		}
		$pos += strlen( $token );
	}
}
