<?php
namespace BackupScope\Jobs\Steps;

use BackupScope\Archive\CompressionPolicy;
use BackupScope\Archive\StreamingZipWriter;
use BackupScope\Archive\ZipArchiveWriter;
use BackupScope\Jobs\Job;
use BackupScope\Jobs\StepInterface;
use BackupScope\Manifest\Manifest;
use BackupScope\Storage\StorageManager;
use BackupScope\Support\SecretFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the archive. Engine choice (plan §11): estimated size <= threshold (default 5 GB) uses
 * ZipArchive in one request; larger uses the streaming writer across many requests. If the
 * single path dies (timeout/fatal) or fails, the job switches to batched once, automatically.
 */
final class ArchiveStep implements StepInterface {

	const DEFAULT_THRESHOLD = 5368709120; // 5 GiB
	const SINGLE_MAX_FILE   = 1073741824; // 1 GiB: larger files force the batched path.
	const MAX_ENTRY_TRIES   = 3;
	const SINGLE_LEASE      = 1800; // A single-path request may run long; others wait unless it is known dead.

	public function id() {
		return 'archive';
	}

	public function label() {
		return __( 'Adding files to the backup', 'backupscope' );
	}

	public function status() {
		return 'archiving';
	}

	public static function threshold() {
		$value = defined( 'INSTABACKUP_BATCH_THRESHOLD' ) ? (int) INSTABACKUP_BATCH_THRESHOLD : self::DEFAULT_THRESHOLD;
		/**
		 * Filters the size (bytes) above which the batched ZIP path is used.
		 * The threshold only selects the archive strategy; there is no size limit.
		 *
		 * @param int $bytes
		 */
		return (int) apply_filters( 'instabackup_batch_threshold_bytes', $value );
	}

	public function run( Job $job, $deadline ) {
		$st = &$job->state['step_state'];
		if ( empty( $st ) ) {
			$st = $this->init( $job );
		}

		if ( 'single' === $st['engine'] && ! empty( $st['single_started'] ) && ! empty( $job->state['died_last'] ) ) {
			$this->fall_back( $job, $st, 'single path request did not finish (timeout or fatal error)' );
		}

		$done = 'single' === $st['engine'] ? $this->run_single( $job, $st ) : $this->run_batched( $job, $st, $deadline );

		$job->state['progress'] = array(
			'files_done'  => $st['files_done'],
			'files_total' => $st['files_total'],
			'bytes_done'  => $st['bytes_done'],
			'bytes_total' => $st['bytes_total'],
			'engine'      => $st['engine'],
			'current'     => isset( $st['current'] ) ? $st['current'] : '',
		);
		if ( $done ) {
			$job->state['data']['archive'] = array(
				'file'          => $st['archive'],
				'entries'       => $st['entries'],
				'engine'        => $st['engine'],
				'fallback_used' => $st['fallback_used'],
				'files_done'    => $st['files_done'],
				'bytes_done'    => $st['bytes_done'],
				'skipped'       => $st['skipped'],
				'backup_id'     => $st['backup_id'],
			);
			$job->log->info( 'Archive complete', array( 'engine' => $st['engine'], 'entries' => $st['entries'], 'size' => filesize( $st['archive'] ) ) );
		}
		return $done;
	}

	private function init( Job $job ) {
		$scan    = $job->state['data']['scan'];
		$db      = isset( $job->state['data']['database'] ) ? (int) $job->state['data']['database']['bytes'] : 0;
		$excluded = self::excluded( $job );
		$files    = (int) $scan['files']['count'];
		$fbytes   = (int) $scan['files']['bytes'];
		foreach ( $excluded as $group => $unused ) {
			if ( isset( $scan['directories'][ $group ] ) ) {
				$files  -= (int) $scan['directories'][ $group ]['count'];
				$fbytes -= (int) $scan['directories'][ $group ]['bytes'];
			}
		}
		$total   = $fbytes + $db;
		$largest = isset( $scan['largest_files'][0] ) ? (int) $scan['largest_files'][0]['bytes'] : 0;

		$engine = 'single';
		$reason = 'size within threshold';
		if ( ! ZipArchiveWriter::available() ) {
			$engine = 'batched';
			$reason = 'ZipArchive not available';
		} elseif ( $total > self::threshold() ) {
			$engine = 'batched';
			$reason = 'size above threshold';
		} elseif ( $largest > (int) apply_filters( 'instabackup_single_path_max_file', self::SINGLE_MAX_FILE ) ) {
			$engine = 'batched';
			$reason = 'very large single file';
		}
		$job->log->info( 'Engine selected', array( 'engine' => $engine, 'reason' => $reason, 'bytes' => $total, 'threshold' => self::threshold() ) );

		return array(
			'engine'         => $engine,
			'fallback_used'  => false,
			// Separate files: a single-path request that is still running after a fallback can
			// never overwrite the batched archive.
			'archive'        => $job->path( 'single' === $engine ? 'backup-single.zip' : 'backup.zip' ),
			'backup_id'      => StorageManager::new_backup_id(),
			'index_off'      => 0,
			'files_done'     => 0,
			'files_total'    => $files,
			'bytes_done'     => 0,
			'bytes_total'    => $total,
			'skipped'        => (int) $scan['skipped']['count'],
			'secrets_excluded' => array(),
			'extras'         => null,
			'entries'        => 0,
			'writer'         => null,
			'single_started' => 0,
		);
	}

	private function fall_back( Job $job, array &$st, $why ) {
		$job->log->warning( 'Switching to batched path', array( 'reason' => $why ) );
		@unlink( $st['archive'] ); // phpcs:ignore
		$st['archive']        = $job->path( 'backup.zip' );
		$st['engine']         = 'batched';
		$st['fallback_used']  = true;
		$st['single_started'] = 0;
		$st['index_off']      = 0;
		$st['files_done']     = 0;
		$st['bytes_done']     = 0;
		$st['extras']         = null;
		$st['writer']         = null;
		$st['skipped']        = (int) $job->state['data']['scan']['skipped']['count'];
		$this->truncate_skipped_to_scan( $job );
		$job->state['progress']['fallback'] = true;
	}

	// ---------------------------------------------------------------- single path

	private function run_single( Job $job, array &$st ) {
		$st['single_started'] = time();
		$job->save(); // Persist before the long request so a timeout is detected next time.
		( new \BackupScope\Jobs\Lock() )->lease( $job->id(), self::SINGLE_LEASE, true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::SINGLE_LEASE ); // phpcs:ignore -- may be ignored by the host; then the fallback handles it.
		}

		$writer = new ZipArchiveWriter();
		$result = $writer->build(
			$st['archive'],
			$this->single_entries( $job, $st ),
			static function ( $fraction ) use ( $job, $st ) {
				// Runs inside ZipArchive::close(); keep it cheap.
				static $last = 0;
				if ( microtime( true ) - $last < 2 ) {
					return;
				}
				$last = microtime( true );
				file_put_contents( $job->path( 'progress.json' ), wp_json_encode( array( 'fraction' => $fraction, 'bytes_total' => $st['bytes_total'], 'at' => time() ) ) ); // phpcs:ignore
				( new \BackupScope\Jobs\Lock() )->heartbeat( $job->id() );
			}
		);
		@unlink( $job->path( 'progress.json' ) ); // phpcs:ignore

		if ( ! $result['ok'] ) {
			$this->fall_back( $job, $st, $result['error'] );
			return false;
		}
		$st['entries']    = $result['entries'];
		$st['bytes_done'] = $st['bytes_total'];
		return true;
	}

	/** Generator: index entries, then database, skipped report and manifest. */
	private function single_entries( Job $job, array &$st ) {
		$roots    = $job->state['data']['roots'];
		$excluded = self::excluded( $job );
		$index    = fopen( $job->path( 'scan-index.jsonl' ), 'rb' ); // phpcs:ignore
		while ( $index && false !== ( $line = fgets( $index ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row[5], $excluded[ $row[5] ] ) ) {
				continue; // Folder unticked by the user.
			}
			list( $name, $path ) = $this->resolve( $roots, $row );
			if ( ! is_readable( $path ) ) {
				$this->record_skip( $job, $st, $name, 'vanished' );
				continue;
			}
			$st['bytes_done'] += (int) $row[3];
			$secret = self::secret_reason( $name, $path );
			if ( '' !== $secret ) {
				// Never archived: wp-config.php and other files with the security keys.
				$this->record_secret_exclusion( $job, $st, $name, $secret );
				continue;
			}
			$st['files_done']++;
			yield array( $name, $path, CompressionPolicy::store( $name, $row[3] ) );
		}
		if ( $index ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
			fclose( $index );
		}
		foreach ( $this->extras( $job, $st ) as $extra ) {
			yield array( $extra[0], $extra[1], CompressionPolicy::store( $extra[0], filesize( $extra[1] ) ) );
		}
	}

	// ---------------------------------------------------------------- batched path

	private function run_batched( Job $job, array &$st, $deadline ) {
		if ( null === $st['writer'] ) {
			$st['writer'] = StreamingZipWriter::init_state( $st['archive'] );
		}
		$writer = new StreamingZipWriter( $st['writer'] );
		$writer->open();

		// A request died while writing the same entry several times: skip that file.
		if ( ! empty( $job->state['died_last'] ) && $writer->has_current() ) {
			$st['writer']['cur']['tries']++;
			if ( $st['writer']['cur']['tries'] >= self::MAX_ENTRY_TRIES ) {
				$cur = $writer->current();
				$writer->abort_current();
				$this->record_skip( $job, $st, $cur['name'], 'timeout' );
				$st['bytes_done'] += max( 0, $cur['size'] - $cur['src_off'] );
			}
		}

		$roots    = $job->state['data']['roots'];
		$excluded = self::excluded( $job );
		$index    = fopen( $job->path( 'scan-index.jsonl' ), 'rb' ); // phpcs:ignore
		fseek( $index, $st['index_off'] );
		$done = false;

		while ( microtime( true ) < $deadline ) {
			if ( $writer->has_current() ) {
				$before = $writer->current();
				$result = $writer->work( $deadline );
				$after  = $writer->current();
				$st['bytes_done'] += ( $after ? $after['src_off'] : $before['size'] ) - $before['src_off'];
				if ( 'partial' === $result ) {
					break;
				}
				if ( 'done' === $result ) {
					if ( ! $this->is_extra( $before['name'] ) ) {
						$st['files_done']++;
					}
				} else {
					$this->record_skip( $job, $st, $before['name'], $result );
					$st['bytes_done'] += $before['size'] - $before['src_off'];
				}
				continue;
			}

			$line = fgets( $index );
			if ( false !== $line ) {
				$st['index_off'] = ftell( $index );
				$row             = json_decode( $line, true );
				if ( ! is_array( $row ) || isset( $row[5], $excluded[ $row[5] ] ) ) {
					continue;
				}
				list( $name, $path ) = $this->resolve( $roots, $row );
				if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
					$this->record_skip( $job, $st, $name, 'vanished' );
					$st['bytes_done'] += (int) $row[3];
					continue;
				}
				$secret = self::secret_reason( $name, $path );
				if ( '' !== $secret ) {
					// Never archived: wp-config.php and other files with the security keys.
					$st['bytes_done'] += (int) $row[3];
					$this->record_secret_exclusion( $job, $st, $name, $secret );
					continue;
				}
				$size = (int) @filesize( $path ); // phpcs:ignore
				$writer->begin( $name, $path, (int) @filemtime( $path ), $size, CompressionPolicy::store( $name, $size ) ); // phpcs:ignore
				$st['current'] = preg_replace( '#^files/(wordpress|external)/#', '', $name );
				// Progress uses real bytes: adjust the total if the file changed since the scan.
				$st['bytes_total'] += $size - (int) $row[3];
				continue;
			}

			if ( null === $st['extras'] ) {
				$st['extras'] = $this->extras( $job, $st );
			}
			if ( $st['extras'] ) {
				$extra = array_shift( $st['extras'] );
				$size  = (int) filesize( $extra[1] );
				$writer->begin( $extra[0], $extra[1], time(), $size, CompressionPolicy::store( $extra[0], $size ) );
				$st['current'] = $extra[0];
				continue;
			}

			$writer->finalize();
			$st['entries'] = $writer->entries();
			$done          = true;
			break;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $index );
		$writer->commit();
		return $done;
	}

	// ---------------------------------------------------------------- shared

	/** Folder groups the user unticked, as a lookup set. */
	private static function excluded( Job $job ) {
		$groups = isset( $job->state['selection']['excluded_groups'] ) ? (array) $job->state['selection']['excluded_groups'] : array();
		return array_fill_keys( $groups, true );
	}

	private function resolve( array $roots, array $row ) {
		$root = $roots[ (int) $row[1] ];
		if ( 'file' === $root['type'] ) {
			return array( $root['archive'], $root['path'] );
		}
		return array( $root['archive'] . $row[2], $root['path'] . '/' . $row[2] );
	}

	// ---------------------------------------------------------------- files with security keys

	/**
	 * SecretFiles::REASON when the file must stay out of the backup (wp-config.php, or a
	 * root-level PHP file that mentions a key name), otherwise ''. The scan already leaves these
	 * out; this check makes sure nothing slips through. See Support\SecretFiles.
	 */
	public static function secret_reason( $name, $path ) {
		// Directly in the WordPress folder, or next to a wp-config.php that lives one level up.
		$root_level = (bool) preg_match( '#^files/(wordpress|external/wp-config)/[^/]+$#', $name );
		return SecretFiles::is_secret( $path, $root_level ) ? SecretFiles::REASON : '';
	}

	private function is_extra( $name ) {
		return in_array( $name, array( 'database/database.sql', 'backupscope/skipped-files.json', 'manifest.json' ), true );
	}

	/** Database dump, skipped-files report, and the manifest (last, so counts are final). */
	private function extras( Job $job, array &$st ) {
		$extras = array();
		if ( isset( $job->state['data']['database'] ) && is_file( $job->path( 'database.sql' ) ) ) {
			$extras[] = array( 'database/database.sql', $job->path( 'database.sql' ) );
		}
		if ( $st['skipped'] > 0 ) {
			$items = array();
			$fh    = fopen( $job->path( 'skipped.jsonl' ), 'rb' ); // phpcs:ignore
			while ( $fh && false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				$item = json_decode( $line, true );
				if ( is_array( $item ) ) {
					$items[] = $item;
				}
			}
			if ( $fh ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
				fclose( $fh );
			}
			file_put_contents( $job->path( 'skipped-files.json' ), wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore
			$extras[] = array( 'backupscope/skipped-files.json', $job->path( 'skipped-files.json' ) );
		}
		$manifest = Manifest::build( $job, $st );
		file_put_contents( $job->path( 'manifest.json' ), wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore
		$job->state['data']['manifest'] = $manifest;
		$extras[]                       = array( 'manifest.json', $job->path( 'manifest.json' ) );
		return $extras;
	}

	private function record_skip( Job $job, array &$st, $name, $reason ) {
		$st['skipped']++;
		$display = preg_replace( '#^files/(wordpress|external)/#', '', $name );
		file_put_contents( $job->path( 'skipped.jsonl' ), wp_json_encode( array( 'path' => $display, 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND ); // phpcs:ignore
		$job->log->warning( 'Skipped file', array( 'path' => $display, 'reason' => $reason ) );
	}

	/**
	 * A file left out on purpose because it holds the security keys. Listed in the manifest
	 * (files.excluded), not as a skipped file: it is expected, so the backup has no warning.
	 */
	private function record_secret_exclusion( Job $job, array &$st, $name, $reason ) {
		$display                             = preg_replace( '#^files/(wordpress|external)/#', '', $name );
		$st['secrets_excluded'][ $display ] = $reason;
		$job->log->info( 'Left out of the backup on purpose (security keys)', array( 'path' => $display ) );
	}

	/** After a fallback, keep only the skips recorded by the scan. */
	private function truncate_skipped_to_scan( Job $job ) {
		$keep  = (int) $job->state['data']['scan']['skipped']['count'];
		$lines = file( $job->path( 'skipped.jsonl' ) );
		file_put_contents( $job->path( 'skipped.jsonl' ), implode( '', array_slice( (array) $lines, 0, $keep ) ) ); // phpcs:ignore
	}
}
