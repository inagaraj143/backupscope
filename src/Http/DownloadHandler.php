<?php
namespace BackupScope\Http;

use BackupScope\Jobs\Job;
use BackupScope\Storage\BackupRepository;
use BackupScope\Storage\StorageManager;
use BackupScope\Support\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated, streamed downloads with HTTP Range support (plan §18, spike S6).
 * Backups are never served from a public URL.
 */
final class DownloadHandler {

	const CHUNK = 1048576;

	public function register() {
		add_action( 'admin_post_instabackup_download', array( $this, 'download_backup' ) );
		add_action( 'admin_post_instabackup_download_log', array( $this, 'download_log' ) );
	}

	public function download_backup() {
		$id = isset( $_GET['backup'] ) ? sanitize_key( wp_unslash( $_GET['backup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked below.
		$this->authorize( 'instabackup_download_' . $id );
		$repo   = new BackupRepository( new StorageManager() );
		$backup = $repo->get( $id );
		$path   = $repo->archive_path( $id );
		if ( ! $backup || ! $path || ! in_array( $backup['status'], array( 'completed', 'completed_with_warnings' ), true ) ) {
			wp_die( esc_html__( 'This backup is no longer available.', 'backupscope' ), '', array( 'response' => 404 ) );
		}
		$name = 'backupscope-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . wp_date( 'Y-m-d-His', (int) $backup['created_at'] ) . '.zip';
		$this->stream( $path, $name, 'application/zip' );
	}

	public function download_log() {
		$id = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked below.
		$this->authorize( 'instabackup_log_' . $id );
		$job = Job::load( new StorageManager(), $id );
		if ( ! $job || ! is_file( $job->path( 'job.log' ) ) ) {
			wp_die( esc_html__( 'This log is no longer available.', 'backupscope' ), '', array( 'response' => 404 ) );
		}
		$this->stream( $job->path( 'job.log' ), 'backupscope-log-' . $id . '.txt', 'text/plain; charset=utf-8' );
	}

	private function authorize( $action ) {
		if ( ! is_user_logged_in() || ! Capability::check() ) {
			wp_die( esc_html__( 'You are not allowed to download backups.', 'backupscope' ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), $action ) ) {
			wp_die( esc_html__( 'This download link has expired. Please reload the BackupScope page.', 'backupscope' ), '', array( 'response' => 403 ) );
		}
	}

	private function stream( $path, $filename, $type ) {
		$size = (int) filesize( $path );

		// Clear every output buffer and disable compression so the bytes go out untouched.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( ini_get( 'zlib.output_compression' ) ) {
			@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore
		}
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}
		ignore_user_abort( false );
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close();
		}

		$start  = 0;
		$end    = $size - 1;
		$status = 200;
		$range  = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
			if ( '' === $m[1] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				if ( '' !== $m[2] ) {
					$end = min( (int) $m[2], $size - 1 );
				}
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$status = 206;
		}

		status_header( $status );
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( 206 === $status ) {
			header( "Content-Range: bytes $start-$end/$size" );
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			exit;
		}

		$fh = fopen( $path, 'rb' ); // phpcs:ignore
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! connection_aborted() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
			$chunk = fread( $fh, (int) min( self::CHUNK, $left ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput -- binary file stream.
			flush();
			$left -= strlen( $chunk );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $fh );
		exit;
	}
}
