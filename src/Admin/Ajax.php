<?php
namespace InstaBackup\Admin;

use InstaBackup\Jobs\JobManager;
use InstaBackup\Storage\ExposureTest;
use InstaBackup\Support\Capability;
use InstaBackup\Support\UserError;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX API for the admin screen (the Free "AJAX runner"). Every operation checks the capability
 * and the nonce, and only accepts IDs from strict allow-lists, never paths.
 */
final class Ajax {

	const NONCE = 'instabackup_api';
	const OPS   = array( 'status', 'start', 'confirm', 'step', 'cancel', 'delete', 'skipped', 'dismiss', 'exposure' );

	public function register() {
		add_action( 'wp_ajax_instabackup', array( $this, 'handle' ) );
	}

	public function handle() {
		if ( ! Capability::check() ) {
			wp_send_json_error( array( 'code' => 'forbidden', 'message' => __( 'You are not allowed to manage backups.', 'backupscope' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'bad_nonce', 'message' => __( 'Your session expired. Please reload the page.', 'backupscope' ) ), 403 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		if ( ! in_array( $op, self::OPS, true ) ) {
			wp_send_json_error( array( 'code' => 'bad_op', 'message' => __( 'Unknown request.', 'backupscope' ) ), 400 );
		}

		$manager = new JobManager();
		$status  = new Status( $manager );
		$job_id  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		try {
			switch ( $op ) {
				case 'status':
					$manager->sweep_orphans();
					wp_send_json_success( $status->build() );
					break;

				case 'start':
					$raw       = isset( $_POST['selection'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['selection'] ) ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification -- verified in handle(); values are allow-listed after decoding.
					$selection = JobManager::sanitize_selection( is_array( $raw ) ? $raw : array() );
					$job       = $manager->start( $selection );
					wp_send_json_success( $status->build( $job ) );
					break;

				case 'confirm':
					$exclude = isset( $_POST['exclude'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['exclude'] ) ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification -- verified in handle(); each key is validated against the scan.
					$with_db = ! isset( $_POST['database'] ) || '1' === sanitize_key( wp_unslash( $_POST['database'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
					wp_send_json_success( $status->build( $manager->confirm( $job_id, is_array( $exclude ) ? $exclude : array(), $with_db ) ) );
					break;

				case 'step':
					wp_send_json_success( $status->build( $manager->step( $job_id ) ) );
					break;

				case 'cancel':
					wp_send_json_success( $status->build( $manager->cancel( $job_id ) ) );
					break;

				case 'dismiss':
					$job = \InstaBackup\Jobs\Job::load( $manager->storage(), $job_id );
					if ( $job && $job->is_terminal() ) {
						$job->state['dismissed'] = true;
						$job->save();
					}
					wp_send_json_success( $status->build() );
					break;

				case 'delete':
					$id = isset( $_POST['backup'] ) ? sanitize_key( wp_unslash( $_POST['backup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
					if ( $manager->active_job() ) {
						throw new UserError( 'busy', esc_html__( 'Wait for the running backup to finish before deleting.', 'backupscope' ) );
					}
					if ( ! $manager->repository()->get( $id ) || ! $manager->repository()->delete( $id ) ) {
						throw new UserError( 'delete_failed', esc_html__( 'The backup could not be deleted.', 'backupscope' ) );
					}
					wp_send_json_success( $status->build() );
					break;

				case 'skipped':
					wp_send_json_success( array( 'items' => $this->skipped( $manager, $job_id ) ) );
					break;

				case 'exposure':
					wp_send_json_success( ( new ExposureTest( $manager->storage() ) )->run() );
					break;
			}
		} catch ( UserError $e ) {
			wp_send_json_error( array( 'code' => $e->error_code(), 'message' => wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) ), 'busy' === $e->error_code() ? 409 : 400 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'code' => 'unexpected', 'message' => __( 'BackupScope ran into an unexpected error.', 'backupscope' ) ), 500 );
		}
	}

	private function skipped( JobManager $manager, $job_id ) {
		$job = \InstaBackup\Jobs\Job::load( $manager->storage(), $job_id );
		if ( ! $job || ! is_file( $job->path( 'skipped.jsonl' ) ) ) {
			return array();
		}
		$items = array();
		$fh    = fopen( $job->path( 'skipped.jsonl' ), 'rb' ); // phpcs:ignore
		while ( $fh && count( $items ) < 500 && false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$item = json_decode( $line, true );
			if ( is_array( $item ) ) {
				$items[] = array( 'path' => (string) $item['path'], 'reason' => sanitize_key( $item['reason'] ) );
			}
		}
		if ( $fh ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
			fclose( $fh );
		}
		return $items;
	}
}
