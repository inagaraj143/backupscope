<?php
namespace InstaBackup\Archive;

use InstaBackup\Support\UserError;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only, resumable ZIP writer for the batched path (plan §12.1, spikes S1/S2).
 *
 * - open() truncates the archive to the last committed offset, so a request that died
 *   mid-write leaves nothing behind.
 * - DEFLATE continues across requests: each request ends the entry's data with
 *   ZLIB_FULL_FLUSH (byte-aligned, no back-references) and the next request starts a new raw
 *   deflate context. The flush must be passed with real data: PHP returns nothing for
 *   deflate_add($ctx, '', ZLIB_FULL_FLUSH) and the pending data is lost (spike S1).
 * - CRC-32 continues across requests through Crc32::combine().
 * - Local headers are patched in place when an entry finishes (no data descriptors).
 * - Zip64 for entries >= ~4 GiB, offsets >= 4 GiB and more than 65,535 entries.
 * - Each mid-entry flush point is written to a hints file so the verifier can resume too.
 */
final class StreamingZipWriter {

	const CHUNK        = 1048576;
	const ZIP64_LIMIT  = 0xF0000000;
	const MAX32        = 0xFFFFFFFF;

	private $s;
	private $zip;
	private $cd;
	private $hints;

	public static function init_state( $archive ) {
		foreach ( array( $archive, $archive . '.cd', $archive . '.hints' ) as $file ) {
			file_put_contents( $file, '' ); // phpcs:ignore
		}
		return array(
			'archive'   => $archive,
			'committed' => 0,
			'cd_size'   => 0,
			'hint_size' => 0,
			'entries'   => 0,
			'cur'       => null,
			'final'     => false,
		);
	}

	public function __construct( array &$state ) {
		$this->s = &$state;
	}

	public function open() {
		$this->zip   = fopen( $this->s['archive'], 'c+b' ); // phpcs:ignore
		$this->cd    = fopen( $this->s['archive'] . '.cd', 'c+b' ); // phpcs:ignore
		$this->hints = fopen( $this->s['archive'] . '.hints', 'c+b' ); // phpcs:ignore
		if ( ! $this->zip || ! $this->cd || ! $this->hints ) {
			throw new UserError( 'storage_unwritable', esc_html__( 'BackupScope could not open the backup archive for writing.', 'backupscope' ) );
		}
		ftruncate( $this->zip, $this->s['committed'] );
		ftruncate( $this->cd, $this->s['cd_size'] );
		ftruncate( $this->hints, $this->s['hint_size'] );
		fseek( $this->zip, 0, SEEK_END );
		fseek( $this->cd, 0, SEEK_END );
		fseek( $this->hints, 0, SEEK_END );
	}

	/** Persists offsets. Call at the end of every request. */
	public function commit() {
		fflush( $this->zip );
		fflush( $this->cd );
		fflush( $this->hints );
		$this->s['committed'] = ftell( $this->zip );
		$this->s['cd_size']   = ftell( $this->cd );
		$this->s['hint_size'] = ftell( $this->hints );
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $this->zip );
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $this->cd );
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $this->hints );
	}

	public function has_current() {
		return null !== $this->s['cur'];
	}

	public function current() {
		return $this->s['cur'];
	}

	public function entries() {
		return $this->s['entries'];
	}

	public function begin( $name, $path, $mtime, $size, $store ) {
		$zip64   = $size >= self::ZIP64_LIMIT;
		$method  = $store ? 0 : 8;
		$hdr_off = ftell( $this->zip );
		$this->put( $this->zip, $this->local_header( $name, $method, $mtime, $zip64 ) );
		$this->s['cur'] = array(
			'name'    => $name,
			'path'    => $path,
			'method'  => $method,
			'zip64'   => $zip64,
			'hdr_off' => $hdr_off,
			'size'    => (int) $size,
			'src_off' => 0,
			'csize'   => 0,
			'crc'     => 0,
			'mtime'   => (int) $mtime,
			'tries'   => 0,
		);
	}

	/**
	 * Writes the current entry until it is complete or the deadline passes.
	 *
	 * @return string 'done', 'partial', or a skip reason ('vanished', 'changed_during_backup').
	 */
	public function work( $deadline ) {
		$c   = &$this->s['cur'];
		$src = @fopen( $c['path'], 'rb' ); // phpcs:ignore
		if ( ! $src ) {
			$this->abort_current();
			return 'vanished';
		}
		if ( $c['src_off'] > 0 ) {
			fseek( $src, $c['src_off'] );
		}
		$ctx       = 8 === $c['method'] ? deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) ) : null;
		$hash      = hash_init( 'crc32b' );
		$slice_len = 0;

		while ( $c['src_off'] < $c['size'] ) {
			$want = (int) min( self::CHUNK, $c['size'] - $c['src_off'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
			$data = fread( $src, $want );
			if ( false === $data || strlen( $data ) !== $want ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
				fclose( $src );
				$this->abort_current();
				return 'changed_during_backup';
			}
			hash_update( $hash, $data );
			$file_done  = $c['src_off'] + $want >= $c['size'];
			$slice_ends = microtime( true ) >= $deadline;
			if ( $ctx ) {
				$flag = $file_done ? ZLIB_FINISH : ( $slice_ends ? ZLIB_FULL_FLUSH : ZLIB_NO_FLUSH );
				$out  = deflate_add( $ctx, $data, $flag );
			} else {
				$out = $data;
			}
			$this->put( $this->zip, $out );
			$c['csize']   += strlen( $out );
			$c['src_off'] += $want;
			$slice_len    += $want;
			if ( $slice_ends && ! $file_done ) {
				break;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $src );

		$c['crc'] = Crc32::combine( $c['crc'], (int) hexdec( hash_final( $hash ) ), $slice_len );

		if ( $c['src_off'] < $c['size'] ) {
			// Mid-entry: record a sync point (the data so far ends on a full flush).
			$this->put( $this->hints, wp_json_encode( array( $this->s['entries'], $c['csize'], $c['src_off'], $c['crc'] ) ) . "\n" );
			return 'partial';
		}

		if ( ! $c['zip64'] && $c['csize'] >= self::MAX32 ) {
			throw new UserError( 'archive_overflow', esc_html__( 'A file grew too large to fit in the backup archive.', 'backupscope' ) );
		}
		$this->patch_local_header( $c );
		$this->put( $this->cd, $this->central_record( $c ) );
		$this->s['entries']++;
		$this->s['cur'] = null;
		return 'done';
	}

	/** Drops the current entry (truncates back to its local header). */
	public function abort_current() {
		if ( null === $this->s['cur'] ) {
			return;
		}
		ftruncate( $this->zip, $this->s['cur']['hdr_off'] );
		fseek( $this->zip, 0, SEEK_END );
		$this->s['cur'] = null;
	}

	/** Appends the central directory and end records. */
	public function finalize() {
		$cd_start = ftell( $this->zip );
		fflush( $this->cd );
		rewind( $this->cd );
		while ( ! feof( $this->cd ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
			$chunk = fread( $this->cd, self::CHUNK );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$this->put( $this->zip, $chunk );
		}
		$cd_end  = ftell( $this->zip );
		$cd_size = $cd_end - $cd_start;
		$n       = $this->s['entries'];
		if ( $n >= 0xFFFF || $cd_start >= self::MAX32 || $cd_size >= self::MAX32 ) {
			$this->put( $this->zip, pack( 'VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $n, $n, $cd_size, $cd_start ) );
			$this->put( $this->zip, pack( 'VVPV', 0x07064b50, 0, $cd_end, 1 ) );
		}
		$this->put( $this->zip, pack( 'VvvvvVVv', 0x06054b50, 0, 0, min( $n, 0xFFFF ), min( $n, 0xFFFF ), min( $cd_size, self::MAX32 ), min( $cd_start, self::MAX32 ), 0 ) );
		$this->s['final'] = true;
	}

	private function put( $handle, $data ) {
		$len = strlen( $data );
		if ( $len && fwrite( $handle, $data ) !== $len ) { // phpcs:ignore
			throw new UserError( 'disk_full', esc_html__( 'The server ran out of disk space while writing the backup archive.', 'backupscope' ) );
		}
	}

	private static function dos_time( $t ) {
		$d    = getdate( $t > 315532800 ? $t : 315532800 );
		$time = ( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | intdiv( $d['seconds'], 2 );
		$date = ( ( $d['year'] - 1980 ) << 9 ) | ( $d['mon'] << 5 ) | $d['mday'];
		return array( $time, $date );
	}

	private function local_header( $name, $method, $mtime, $zip64 ) {
		list( $time, $date ) = self::dos_time( $mtime );
		$extra = $zip64 ? pack( 'vvPP', 0x0001, 16, 0, 0 ) : '';
		return pack( 'VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0x0800, $method, $time, $date, 0, $zip64 ? self::MAX32 : 0, $zip64 ? self::MAX32 : 0, strlen( $name ), strlen( $extra ) )
			. $name . $extra;
	}

	private function patch_local_header( array $c ) {
		fseek( $this->zip, $c['hdr_off'] + 14 );
		if ( $c['zip64'] ) {
			fwrite( $this->zip, pack( 'VVV', $c['crc'], self::MAX32, self::MAX32 ) ); // phpcs:ignore
			fseek( $this->zip, $c['hdr_off'] + 30 + strlen( $c['name'] ) + 4 );
			fwrite( $this->zip, pack( 'PP', $c['size'], $c['csize'] ) ); // phpcs:ignore
		} else {
			fwrite( $this->zip, pack( 'VVV', $c['crc'], $c['csize'], $c['size'] ) ); // phpcs:ignore
		}
		fseek( $this->zip, 0, SEEK_END );
	}

	private function central_record( array $c ) {
		list( $time, $date ) = self::dos_time( $c['mtime'] );
		$z     = '';
		$usize = $c['size'];
		$csize = $c['csize'];
		$off   = $c['hdr_off'];
		if ( $usize >= self::MAX32 ) {
			$z    .= pack( 'P', $usize );
			$usize = self::MAX32;
		}
		if ( $csize >= self::MAX32 ) {
			$z    .= pack( 'P', $csize );
			$csize = self::MAX32;
		}
		if ( $off >= self::MAX32 ) {
			$z  .= pack( 'P', $off );
			$off = self::MAX32;
		}
		$extra = '' === $z ? '' : pack( 'vv', 0x0001, strlen( $z ) ) . $z;
		return pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 45, '' === $extra ? 20 : 45, 0x0800, $c['method'], $time, $date, $c['crc'], $csize, $usize, strlen( $c['name'] ), strlen( $extra ), 0, 0, 0, 0, $off )
			. $c['name'] . $extra;
	}
}
