<?php
namespace InstaBackup\Database;

use InstaBackup\Support\UserError;

defined( 'ABSPATH' ) || exit;

/**
 * Resumable, batched SQL export through WordPress's own mysqli connection (plan §13).
 * No mysqldump, no shell. Output: instabackup-sql/1.
 */
final class Exporter {

	const FORMAT        = 'instabackup-sql/1';
	const FOOTER        = '-- BackupScope export complete';
	const MAX_STATEMENT = 1000000;
	const BATCH_BYTES   = 8000000;
	const MAX_ROWS      = 5000;
	const CHUNK_VALUE   = 262144; // Values above this are appended in chunks when a row is too big.
	const BINARY_TYPES  = array( 'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' );

	/** @var \mysqli */
	private $dbh;
	private $file;

	public function __construct( $file ) {
		global $wpdb;
		if ( ! isset( $wpdb->dbh ) || ! $wpdb->dbh instanceof \mysqli ) {
			throw new UserError( 'db_driver', esc_html__( 'BackupScope can only back up MySQL or MariaDB databases connected through mysqli.', 'backupscope' ) );
		}
		$this->dbh  = $wpdb->dbh;
		$this->file = $file;
	}

	public static function supported() {
		global $wpdb;
		return isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli;
	}

	/**
	 * Tables, size estimate and non-prefixed tables. Tables of other WordPress installs sharing
	 * the database (a longer prefix with its own options/posts/usermeta tables) are left out.
	 */
	public static function discover( array $extra_tables = array() ) {
		global $wpdb;
		$prefix = $wpdb->base_prefix;
		$rows   = $wpdb->get_results( 'SELECT TABLE_NAME AS n, TABLE_TYPE AS t, DATA_LENGTH AS d FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', ARRAY_A ); // phpcs:ignore WordPress.DB
		$names  = wp_list_pluck( (array) $rows, 'n' );

		$foreign = array();
		foreach ( $names as $name ) {
			if ( 0 === strpos( $name, $prefix ) && preg_match( '/^(.+_)options$/', $name, $m ) && $m[1] !== $prefix
				&& in_array( $m[1] . 'posts', $names, true ) && in_array( $m[1] . 'usermeta', $names, true ) ) {
				$foreign[] = $m[1];
			}
		}

		$tables = array();
		$sizes  = array();
		$views  = array();
		$other  = array();
		$bytes  = 0;
		foreach ( (array) $rows as $row ) {
			$name     = $row['n'];
			$prefixed = 0 === strpos( $name, $prefix );
			foreach ( $foreign as $f ) {
				if ( 0 === strpos( $name, $f ) ) {
					$prefixed = false;
				}
			}
			$include = $prefixed || in_array( $name, $extra_tables, true );
			if ( ! $prefixed && 'VIEW' !== $row['t'] ) {
				$other[] = array( 'name' => $name, 'bytes' => (int) $row['d'] );
			}
			if ( ! $include ) {
				continue;
			}
			if ( 'VIEW' === $row['t'] ) {
				$views[] = $name;
			} else {
				$tables[]       = $name;
				$sizes[ $name ] = (int) $row['d'];
				$bytes         += (int) $row['d'];
			}
		}
		sort( $tables );
		sort( $views );
		arsort( $sizes );
		$list = array();
		foreach ( $sizes as $name => $size ) {
			$list[] = array( 'name' => $name, 'bytes' => $size );
		}
		return array(
			'sizes'           => $list,
			'tables'          => $tables,
			'views'           => $views,
			'estimated_bytes' => $bytes,
			'non_prefixed'    => $other,
			'other_installs'  => $foreign,
		);
	}

	public function init_state( array $extra_tables ) {
		$found = self::discover( $extra_tables );
		file_put_contents( $this->file, '' ); // phpcs:ignore
		return array(
			'tables'    => $found['tables'],
			'views'     => $found['views'],
			'ti'        => 0,
			'phase'     => 'header',
			'last'      => null,
			'offset'    => 0,
			'committed' => 0,
			'rows'      => array(),
			'counts'    => array(),
			'triggers'  => array(),
			'done'      => false,
		);
	}

	/** @return bool True when the export is complete. */
	public function run( array &$s, $deadline ) {
		$f = fopen( $this->file, 'c+b' ); // phpcs:ignore
		if ( ! $f ) {
			throw new UserError( 'storage_unwritable', esc_html__( 'BackupScope could not write the database export.', 'backupscope' ) );
		}
		ftruncate( $f, $s['committed'] );
		fseek( $f, 0, SEEK_END );

		while ( ! $s['done'] && microtime( true ) < $deadline ) {
			switch ( $s['phase'] ) {
				case 'header':
					$charset = $this->dbh->character_set_name();
					$this->write( $f, "-- BackupScope database export\n-- Format: " . self::FORMAT . "\nSET NAMES $charset;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n" );
					$s['phase'] = 'schema';
					break;

				case 'schema':
					if ( $s['ti'] >= count( $s['tables'] ) ) {
						$s['phase'] = 'views';
						break;
					}
					$t      = $s['tables'][ $s['ti'] ];
					$create = $this->query( 'SHOW CREATE TABLE ' . self::id( $t ) )->fetch_row();
					$this->write( $f, 'DROP TABLE IF EXISTS ' . self::id( $t ) . ";\n" . $create[1] . ";\n" );
					$s['counts'][ $t ] = (int) $this->query( 'SELECT COUNT(*) FROM ' . self::id( $t ) )->fetch_row()[0];
					$s['rows'][ $t ]   = 0;
					$s['phase']        = 'rows';
					$s['last']         = null;
					$s['offset']       = 0;
					break;

				case 'rows':
					$this->rows_batch( $f, $s );
					break;

				case 'views':
					foreach ( $s['views'] as $v ) {
						$create = $this->query( 'SHOW CREATE VIEW ' . self::id( $v ) )->fetch_row();
						$this->write( $f, 'DROP VIEW IF EXISTS ' . self::id( $v ) . ";\n" . self::strip_definer( $create[1] ) . ";\n" );
					}
					$s['phase'] = 'triggers';
					break;

				case 'triggers':
					$res = $this->query( 'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()' );
					while ( $row = $res->fetch_row() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
						if ( ! in_array( $row[1], $s['tables'], true ) ) {
							continue;
						}
						$trigger = $this->query( 'SHOW CREATE TRIGGER ' . self::id( $row[0] ) )->fetch_assoc();
						$this->write( $f, 'DROP TRIGGER IF EXISTS ' . self::id( $row[0] ) . ";\nDELIMITER ;;\n" . self::strip_definer( $trigger['SQL Original Statement'] ) . ";;\nDELIMITER ;\n" );
						$s['triggers'][] = $row[0];
					}
					$this->write( $f, "\nSET FOREIGN_KEY_CHECKS = 1;\n" . self::FOOTER . "\n" );
					$s['done'] = true;
					break;
			}
		}

		fflush( $f );
		$s['committed'] = ftell( $f );
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $f );
		return $s['done'];
	}

	/** Counts of routines/events that are not exported in V1 (listed in the manifest). */
	public function not_included() {
		$out = array( 'procedures' => 0, 'functions' => 0, 'events' => 0 );
		$res = $this->query( "SELECT ROUTINE_TYPE, COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() GROUP BY ROUTINE_TYPE" );
		while ( $row = $res->fetch_row() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$out[ 'PROCEDURE' === $row[0] ? 'procedures' : 'functions' ] = (int) $row[1];
		}
		$events = @$this->dbh->query( 'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()' ); // phpcs:ignore
		if ( $events ) {
			$out['events'] = (int) $events->fetch_row()[0];
		}
		return $out;
	}

	public function server_info() {
		return $this->dbh->server_info;
	}

	private function rows_batch( $f, array &$s ) {
		$t       = $s['tables'][ $s['ti'] ];
		$esc     = $this->dbh->real_escape_string( $t );
		$cols    = array();
		$binary  = array();
		$res     = $this->query( "SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$esc' ORDER BY ORDINAL_POSITION" );
		while ( $c = $res->fetch_assoc() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( false !== stripos( $c['EXTRA'], 'GENERATED' ) ) {
				continue; // Generated columns cannot be inserted.
			}
			$type = strtolower( $c['DATA_TYPE'] );
			$cols[] = $c['COLUMN_NAME'];
			// mysqlnd returns BIT as a decimal string ("5"), so it is written as an integer (spike S5).
			$binary[ $c['COLUMN_NAME'] ] = 'bit' === $type ? 'bit' : in_array( $type, self::BINARY_TYPES, true );
		}

		$pk  = array();
		$res = $this->query( 'SHOW KEYS FROM ' . self::id( $t ) . " WHERE Key_name = 'PRIMARY'" );
		while ( $k = $res->fetch_assoc() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$pk[ (int) $k['Seq_in_index'] ] = $k['Column_name'];
		}
		ksort( $pk );
		$pk = array_values( $pk );

		$avg   = (int) $this->query( "SELECT AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$esc'" )->fetch_row()[0];
		$limit = max( 1, min( self::MAX_ROWS, intdiv( self::BATCH_BYTES, max( 1, $avg ) ) ) );

		$col_list = implode( ', ', array_map( array( __CLASS__, 'id' ), $cols ) );
		$sql      = "SELECT $col_list FROM " . self::id( $t );
		if ( $pk ) {
			$keys = implode( ', ', array_map( array( __CLASS__, 'id' ), $pk ) );
			if ( null !== $s['last'] ) {
				$vals = array();
				foreach ( $s['last'] as $v ) {
					$vals[] = null === $v ? 'NULL' : "'" . $this->dbh->real_escape_string( $v ) . "'";
				}
				$sql .= " WHERE ($keys) > (" . implode( ', ', $vals ) . ')';
			}
			$sql .= " ORDER BY $keys LIMIT $limit";
		} else {
			$sql .= " LIMIT $limit OFFSET " . (int) $s['offset']; // No usable key: rows changing meanwhile can shift.
		}

		$res = $this->dbh->query( $sql, MYSQLI_USE_RESULT );
		if ( false === $res ) {
			throw new UserError( 'db_query', esc_html__( 'BackupScope could not read a database table.', 'backupscope' ), esc_html( $t . ': ' . $this->dbh->error ) );
		}
		$head = 'INSERT INTO ' . self::id( $t ) . " ($col_list) VALUES\n";
		$buf  = '';
		$n    = 0;
		$last = null;
		while ( $row = $res->fetch_assoc() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$vals = array();
			foreach ( $cols as $c ) {
				$vals[ $c ] = $this->literal( $row[ $c ], $binary[ $c ] );
			}
			$tuple   = '(' . implode( ',', $vals ) . ')';
			$appends = array();
			if ( strlen( $tuple ) > self::MAX_STATEMENT && $pk ) {
				// One row bigger than a statement: insert it with big values emptied, then append
				// the values in chunks so imports work with a default max_allowed_packet (spike S5).
				foreach ( $cols as $c ) {
					if ( null !== $row[ $c ] && strlen( $vals[ $c ] ) > self::CHUNK_VALUE && 'bit' !== $binary[ $c ] && ! in_array( $c, $pk, true ) ) {
						$vals[ $c ]    = "''";
						$appends[ $c ] = $row[ $c ];
					}
				}
				$tuple = '(' . implode( ',', $vals ) . ')';
			}
			if ( '' !== $buf && ( strlen( $buf ) + strlen( $tuple ) > self::MAX_STATEMENT || $appends ) ) {
				$this->write( $f, $head . $buf . ";\n" );
				$buf = '';
			}
			$buf .= ( '' === $buf ? '' : ",\n" ) . $tuple;
			if ( $appends ) {
				$this->write( $f, $head . $buf . ";\n" );
				$buf = '';
				$this->write_appends( $f, $t, $pk, $row, $appends, $binary );
			}
			$n++;
			if ( $pk ) {
				$last = array();
				foreach ( $pk as $key ) {
					$last[] = $row[ $key ];
				}
			}
		}
		$res->free();
		if ( '' !== $buf ) {
			$this->write( $f, $head . $buf . ";\n" );
		}
		$s['rows'][ $t ] += $n;

		if ( $n < $limit ) {
			$this->write( $f, "\n" );
			$s['ti']++;
			$s['phase']  = 'schema';
			$s['last']   = null;
			$s['offset'] = 0;
		} elseif ( $pk ) {
			$s['last'] = $last;
		} else {
			$s['offset'] += $n;
		}
	}

	private function literal( $v, $kind ) {
		if ( null === $v ) {
			return 'NULL';
		}
		if ( 'bit' === $kind ) {
			return ctype_digit( (string) $v ) ? (string) $v : '0x' . bin2hex( $v );
		}
		if ( true === $kind ) {
			return '' === $v ? "''" : '0x' . bin2hex( $v );
		}
		return "'" . $this->dbh->real_escape_string( $v ) . "'";
	}

	private function write_appends( $f, $table, array $pk, array $row, array $appends, array $binary ) {
		$where = array();
		foreach ( $pk as $key ) {
			$where[] = self::id( $key ) . ' = ' . $this->literal( $row[ $key ], $binary[ $key ] );
		}
		$where = implode( ' AND ', $where );
		foreach ( $appends as $col => $value ) {
			$len = strlen( $value );
			for ( $pos = 0; $pos < $len; ) {
				// Text is cut on UTF-8 character boundaries; binary can be cut anywhere.
				$end = min( $len, $pos + self::CHUNK_VALUE );
				if ( true !== $binary[ $col ] ) {
					while ( $end > $pos + 1 && $end < $len && 0x80 === ( ord( $value[ $end ] ) & 0xC0 ) ) {
						$end--; // Do not start the next chunk on a UTF-8 continuation byte.
					}
				}
				$chunk = substr( $value, $pos, $end - $pos );
				$pos   = $end;
				$this->write( $f, 'UPDATE ' . self::id( $table ) . ' SET ' . self::id( $col ) . ' = CONCAT(' . self::id( $col ) . ', ' . $this->literal( $chunk, $binary[ $col ] ) . ") WHERE $where;\n" );
			}
		}
	}

	private function write( $f, $data ) {
		$len = strlen( $data );
		if ( fwrite( $f, $data ) !== $len ) { // phpcs:ignore
			throw new UserError( 'disk_full', esc_html__( 'The server ran out of disk space while exporting the database.', 'backupscope' ) );
		}
	}

	private function query( $sql ) {
		$res = $this->dbh->query( $sql );
		if ( false === $res ) {
			throw new UserError( 'db_query', esc_html__( 'BackupScope could not read the database structure.', 'backupscope' ), esc_html( $this->dbh->error ) );
		}
		return $res;
	}

	public static function id( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private static function strip_definer( $sql ) {
		return (string) preg_replace( '/\sDEFINER\s*=\s*`[^`]*`@`[^`]*`/i', '', $sql );
	}
}
