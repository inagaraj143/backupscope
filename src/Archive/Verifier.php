<?php
namespace InstaBackup\Archive;

use InstaBackup\Support\UserError;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies a finished archive (plan §15): parses the central directory (Zip64-aware), checks the
 * entry count and required entries, and re-reads every entry to check its CRC-32.
 *
 * Resumable: STORE entries can pause anywhere; DEFLATE entries pause only at sync points written
 * by StreamingZipWriter (a fresh raw inflate context can start there).
 */
final class Verifier {

	const CHUNK = 1048576;

	private $s;
	private $fh;

	public static function init_state( $archive, $expected_entries, array $required, $level = 'full' ) {
		return array(
			'archive'  => $archive,
			'hints'    => is_file( $archive . '.hints' ) ? $archive . '.hints' : '',
			'expected' => (int) $expected_entries,
			'required' => array_values( $required ),
			'found'    => array(),
			'level'    => $level,
			'cd'       => null,
			'cursor'   => null,
			'index'    => 0,
			'entry'    => null,
			'bytes'    => 0,
			'done'     => false,
		);
	}

	public function __construct( array &$state ) {
		$this->s = &$state;
	}

	/** @return bool True when verification finished successfully. Throws on failure. */
	public function run( $deadline ) {
		$s        = &$this->s;
		$this->fh = @fopen( $s['archive'], 'rb' ); // phpcs:ignore
		if ( ! $this->fh ) {
			$this->fail( 'archive missing' );
		}
		if ( null === $s['cd'] ) {
			$s['cd']     = $this->read_end_records();
			$s['cursor'] = $s['cd']['offset'];
			if ( $s['cd']['entries'] !== $s['expected'] ) {
				$this->fail( 'entry count ' . $s['cd']['entries'] . ' != expected ' . $s['expected'] );
			}
		}
		$hints = $this->load_hints();

		while ( microtime( true ) < $deadline ) {
			if ( null === $s['entry'] ) {
				if ( $s['index'] >= $s['cd']['entries'] ) {
					break;
				}
				$s['entry'] = $this->read_central_record();
				$s['index']++;
				if ( in_array( $s['entry']['name'], $s['required'], true ) ) {
					$s['found'][] = $s['entry']['name'];
				}
				if ( 'full' !== $s['level'] || $s['entry']['encrypted'] ) {
					$s['entry'] = null;
				}
				continue;
			}
			if ( $this->verify_entry( $s['entry'], isset( $hints[ $s['index'] - 1 ] ) ? $hints[ $s['index'] - 1 ] : array(), $deadline ) ) {
				$s['entry'] = null;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $this->fh );

		if ( $s['index'] >= $s['cd']['entries'] && null === $s['entry'] ) {
			$missing = array_diff( $s['required'], $s['found'] );
			if ( $missing ) {
				$this->fail( 'missing entries: ' . implode( ', ', $missing ) );
			}
			$s['done'] = true;
		}
		return $s['done'];
	}

	private function verify_entry( array &$e, array $points, $deadline ) {
		if ( ! isset( $e['data_off'] ) ) {
			fseek( $this->fh, $e['hdr_off'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
			$lh = fread( $this->fh, 30 );
			if ( strlen( $lh ) < 30 || 0x04034b50 !== unpack( 'V', $lh )[1] ) {
				$this->fail( 'bad local header for ' . $e['name'] );
			}
			$l             = unpack( 'vname/vextra', substr( $lh, 26, 4 ) );
			$e['data_off'] = $e['hdr_off'] + 30 + $l['name'] + $l['extra'];
			$e['coff']     = 0;
			$e['uoff']     = 0;
			$e['crc']      = 0;
		}

		if ( 0 === $e['method'] ) {
			fseek( $this->fh, $e['data_off'] + $e['coff'] );
			$hash = hash_init( 'crc32b' );
			$len  = 0;
			while ( $e['coff'] < $e['csize'] && microtime( true ) < $deadline ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
				$data = fread( $this->fh, (int) min( self::CHUNK, $e['csize'] - $e['coff'] ) );
				if ( false === $data || '' === $data ) {
					$this->fail( 'truncated entry ' . $e['name'] );
				}
				hash_update( $hash, $data );
				$e['coff']      += strlen( $data );
				$len            += strlen( $data );
				$this->s['bytes'] += strlen( $data );
			}
			$e['crc']  = Crc32::combine( $e['crc'], (int) hexdec( hash_final( $hash ) ), $len );
			$e['uoff'] = $e['coff'];
		} elseif ( 8 === $e['method'] ) {
			// Inflate segment by segment; segments end at the writer's sync points.
			$ends = array();
			foreach ( $points as $p ) {
				if ( $p[0] > $e['coff'] ) {
					$ends[] = $p[0];
				}
			}
			$ends[] = $e['csize'];
			foreach ( $ends as $end ) {
				if ( $e['coff'] > 0 && microtime( true ) >= $deadline ) {
					break;
				}
				$this->inflate_segment( $e, $end );
			}
		} else {
			$this->fail( 'unsupported compression method ' . $e['method'] . ' in ' . $e['name'] );
		}

		if ( $e['coff'] < $e['csize'] ) {
			return false;
		}
		if ( $e['crc'] !== $e['crc_expected'] || $e['uoff'] !== $e['usize'] ) {
			$this->fail( 'CRC/size mismatch for ' . $e['name'] );
		}
		return true;
	}

	private function inflate_segment( array &$e, $end ) {
		$ctx  = inflate_init( ZLIB_ENCODING_RAW );
		$hash = hash_init( 'crc32b' );
		$len  = 0;
		fseek( $this->fh, $e['data_off'] + $e['coff'] );
		while ( $e['coff'] < $end ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
			$data = fread( $this->fh, (int) min( self::CHUNK, $end - $e['coff'] ) );
			if ( false === $data || '' === $data ) {
				$this->fail( 'truncated entry ' . $e['name'] );
			}
			$e['coff'] += strlen( $data );
			$out        = @inflate_add( $ctx, $data, $e['coff'] >= $e['csize'] ? ZLIB_FINISH : ZLIB_SYNC_FLUSH ); // phpcs:ignore
			if ( false === $out ) {
				$this->fail( 'corrupt data in ' . $e['name'] );
			}
			hash_update( $hash, $out );
			$len             += strlen( $out );
			$this->s['bytes'] += strlen( $data );
		}
		$e['crc']   = Crc32::combine( $e['crc'], (int) hexdec( hash_final( $hash ) ), $len );
		$e['uoff'] += $len;
	}

	private function read_end_records() {
		$size = $this->file_size();
		$tail = min( $size, 65557 );
		fseek( $this->fh, $size - $tail );
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		$buf = fread( $this->fh, $tail );
		$pos = strrpos( $buf, pack( 'V', 0x06054b50 ) );
		if ( false === $pos ) {
			$this->fail( 'end of central directory not found' );
		}
		$eocd = unpack( 'Vsig/vdisk/vcddisk/vdisk_entries/ventries/Vcd_size/Vcd_offset', substr( $buf, $pos, 20 ) );
		$out  = array( 'entries' => $eocd['entries'], 'offset' => $eocd['cd_offset'], 'size' => $eocd['cd_size'] );

		if ( $pos >= 20 && 0x07064b50 === unpack( 'V', substr( $buf, $pos - 20, 4 ) )[1] ) {
			$loc = unpack( 'Vsig/Vdisk/Poffset/Vdisks', substr( $buf, $pos - 20, 20 ) );
			fseek( $this->fh, $loc['offset'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
			$z = fread( $this->fh, 56 );
			if ( 0x06064b50 !== unpack( 'V', $z )[1] ) {
				$this->fail( 'bad zip64 end record' );
			}
			$z64 = unpack( 'Vsig/Psize/vmade/vneed/Vdisk/Vcddisk/Pdisk_entries/Pentries/Pcd_size/Pcd_offset', $z );
			$out = array( 'entries' => $z64['entries'], 'offset' => $z64['cd_offset'], 'size' => $z64['cd_size'] );
		}
		return $out;
	}

	private function read_central_record() {
		fseek( $this->fh, $this->s['cursor'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		$h = fread( $this->fh, 46 );
		if ( strlen( $h ) < 46 || 0x02014b50 !== unpack( 'V', $h )[1] ) {
			$this->fail( 'bad central directory record' );
		}
		$r     = unpack( 'Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vname/vextra/vcomment/vdisk/vint/Vext/Voffset', $h );
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		$name  = $r['name'] ? fread( $this->fh, $r['name'] ) : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		$extra = $r['extra'] ? fread( $this->fh, $r['extra'] ) : '';
		$this->s['cursor'] += 46 + $r['name'] + $r['extra'] + $r['comment'];

		$usize = $r['usize'];
		$csize = $r['csize'];
		$off   = $r['offset'];
		for ( $p = 0; $p + 4 <= strlen( $extra ); ) {
			$f = unpack( 'vid/vlen', substr( $extra, $p, 4 ) );
			if ( 0x0001 === $f['id'] ) {
				$z = substr( $extra, $p + 4, $f['len'] );
				$q = 0;
				if ( 0xFFFFFFFF === $usize ) {
					$usize = unpack( 'P', substr( $z, $q, 8 ) )[1];
					$q    += 8;
				}
				if ( 0xFFFFFFFF === $csize ) {
					$csize = unpack( 'P', substr( $z, $q, 8 ) )[1];
					$q    += 8;
				}
				if ( 0xFFFFFFFF === $off ) {
					$off = unpack( 'P', substr( $z, $q, 8 ) )[1];
				}
			}
			$p += 4 + $f['len'];
		}
		return array(
			'name'         => $name,
			'method'       => $r['method'],
			'crc_expected' => $r['crc'],
			'csize'        => $csize,
			'usize'        => $usize,
			'hdr_off'      => $off,
			'encrypted'    => (bool) ( $r['flags'] & 1 ),
		);
	}

	private function load_hints() {
		$hints = array();
		if ( ! $this->s['hints'] || ! is_file( $this->s['hints'] ) ) {
			return $hints;
		}
		$fh = fopen( $this->s['hints'], 'rb' ); // phpcs:ignore
		while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$p = json_decode( $line, true );
			if ( is_array( $p ) && 4 === count( $p ) ) {
				$hints[ $p[0] ][] = array( $p[1], $p[2], $p[3] );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $fh );
		return $hints;
	}

	private function file_size() {
		$stat = fstat( $this->fh );
		return (int) $stat['size'];
	}

	private function fail( $detail ) {
		if ( $this->fh ) {
			@fclose( $this->fh ); // phpcs:ignore
		}
		throw new UserError( 'verify_failed', esc_html__( 'The backup could not be verified, so it was discarded. Your previous backup is unchanged.', 'backupscope' ), esc_html( $detail ) );
	}
}
