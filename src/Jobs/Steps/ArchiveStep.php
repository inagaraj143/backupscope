<?php
namespace InstaBackup\Jobs\Steps;

use InstaBackup\Archive\CompressionPolicy;
use InstaBackup\Archive\StreamingZipWriter;
use InstaBackup\Archive\ZipArchiveWriter;
use InstaBackup\Jobs\Job;
use InstaBackup\Jobs\StepInterface;
use InstaBackup\Manifest\Manifest;
use InstaBackup\Storage\StorageManager;

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
		( new \InstaBackup\Jobs\Lock() )->lease( $job->id(), self::SINGLE_LEASE, true );
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
				( new \InstaBackup\Jobs\Lock() )->heartbeat( $job->id() );
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
			if ( self::is_config( $name ) ) {
				// Archived from memory without its keys, or not at all. Never the original file.
				$config = self::sanitized_config( $path );
				if ( false === $config ) {
					$this->record_skip( $job, $st, $name, self::CONFIG_SKIP );
					continue;
				}
				$st['files_done']++;
				yield array( $name, null, false, $config );
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
				if ( self::is_config( $name ) ) {
					// Archived from memory without its keys, or not at all. Never the original file.
					$st['bytes_done'] += (int) $row[3];
					$config = self::sanitized_config( $path );
					if ( false === $config ) {
						$this->record_skip( $job, $st, $name, self::CONFIG_SKIP );
						continue;
					}
					$writer->add_string( $name, $config, (int) @filemtime( $path ), false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$st['files_done']++;
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

	// ---------------------------------------------------------------- wp-config.php

	/** The eight authentication keys and salts defined in wp-config.php. */
	const AUTH_SECRETS = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );

	/** WordPress's own placeholder value for keys and salts. */
	const KEY_PLACEHOLDER = 'put your unique phrase here';

	/** Skip reason recorded when a wp-config.php cannot be archived safely. */
	const CONFIG_SKIP = 'config_not_sanitized';

	/** Every file named wp-config.php (the site's, one above the root, or a nested copy). */
	private static function is_config( $name ) {
		return 'wp-config.php' === basename( $name );
	}

	/**
	 * The contents of a wp-config.php with its authentication keys and salts replaced by
	 * WordPress's placeholder, built in memory: nothing is written to disk, and the result goes
	 * straight into the archive. Returns false when the file cannot be read, or when the result
	 * cannot be shown to be free of key values (for example a key built from several strings or
	 * declared with "const"). The caller then leaves wp-config.php out of the backup and records
	 * why; the original file is never archived.
	 *
	 * A restored site still works: with the placeholder, WordPress falls back to random salts
	 * stored in the database (wp_salt()), and existing logins end.
	 */
	public static function sanitized_config( $path ) {
		$source = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- read into memory, never copied.
		if ( false === $source ) {
			return false;
		}
		$names = implode( '|', self::AUTH_SECRETS );
		$out   = preg_replace(
			'/(define\s*\(\s*([\'"])(?:' . $names . ')\2\s*,\s*)([\'"])(?:\\\\.|(?!\3).)*\3/s',
			'$1\'' . self::KEY_PLACEHOLDER . '\'',
			$source
		);
		if ( null === $out ) {
			return false;
		}
		// Prove it: every definition of a key must now be exactly the placeholder.
		foreach ( self::AUTH_SECRETS as $secret ) {
			if ( preg_match( '/\bconst\s+' . $secret . '\b/', $out ) ) {
				return false;
			}
			if ( preg_match_all( '/define\s*\(\s*([\'"])' . $secret . '\1\s*,/', $out, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $hit ) {
					$rest = substr( $out, $hit[1] + strlen( $hit[0] ), 120 );
					if ( ! preg_match( '/^\s*\'' . self::KEY_PLACEHOLDER . '\'\s*\)/', $rest ) ) {
						return false;
					}
				}
			}
		}
		return $out;
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

	/** After a fallback, keep only the skips recorded by the scan. */
	private function truncate_skipped_to_scan( Job $job ) {
		$keep  = (int) $job->state['data']['scan']['skipped']['count'];
		$lines = file( $job->path( 'skipped.jsonl' ) );
		file_put_contents( $job->path( 'skipped.jsonl' ), implode( '', array_slice( (array) $lines, 0, $keep ) ) ); // phpcs:ignore
	}
}
