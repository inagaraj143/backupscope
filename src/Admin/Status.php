<?php
namespace BackupScope\Admin;

use BackupScope\Jobs\Job;
use BackupScope\Jobs\JobManager;
use BackupScope\Jobs\Steps\ArchiveStep;
use BackupScope\Scanner\Areas;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the JSON the admin screen renders. Only user-safe data: no absolute paths, no SQL.
 */
final class Status {

	const HIDDEN_STEPS = array( 'await', 'cleanup' );

	private $manager;

	public function __construct( JobManager $manager ) {
		$this->manager = $manager;
	}

	public function build( ?Job $job = null ) {
		if ( null === $job ) {
			$job = $this->manager->active_job();
			if ( null === $job ) {
				$last = $this->manager->last_job();
				// On page load only an unacknowledged failure is shown again. A finished backup is
				// shown in the backup card, with the options to start a new one.
				$job  = ( $last && 'failed' === $last->state['status'] && empty( $last->state['dismissed'] ) ) ? $last : null;
			}
		}
		$storage = $this->manager->storage();
		$info    = $storage->info();

		return array(
			'job'     => $job ? $this->job( $job ) : null,
			'backup'  => $this->backup(),
			'others'  => $this->other_backups(),
			'storage' => array(
				'label'   => $storage->label(),
				'exposed' => isset( $info['exposure']['exposed'] ) ? $info['exposure']['exposed'] : null,
				'method'  => $storage->method(),
			),
		);
	}

	private function job( Job $job ) {
		$s     = $job->state;
		$steps = array();
		$index = 0;
		foreach ( $this->manager->steps() as $id => $step ) {
			if ( in_array( $id, self::HIDDEN_STEPS, true ) ) {
				$index++;
				continue;
			}
			$pos = array_search( $id, $s['steps'], true );
			if ( false === $pos ) {
				continue;
			}
			if ( $pos < $s['step_index'] ) {
				$state = isset( $s['step_result'][ $id ] ) && 'skipped' === $s['step_result'][ $id ] ? 'skipped' : 'done';
			} elseif ( $pos === $s['step_index'] && ! in_array( $s['status'], array( 'failed', 'cancelled' ), true ) ) {
				$state = 'active';
			} else {
				$state = 'pending';
			}
			$steps[] = array( 'id' => $id, 'label' => $step->label(), 'state' => $state );
			$index++;
		}

		$progress = $s['progress'];
		if ( 'archiving' === $s['status'] && is_file( $job->path( 'progress.json' ) ) ) {
			$p = json_decode( (string) file_get_contents( $job->path( 'progress.json' ) ), true ); // phpcs:ignore
			if ( is_array( $p ) ) {
				$progress['single_fraction'] = (float) $p['fraction'];
			}
		}

		$out = array(
			'id'         => $s['id'],
			'status'     => $s['status'],
			'paused'     => $this->manager->is_paused( $job ),
			'busy'       => ! empty( $s['busy'] ),
			'cancelling' => ! empty( $s['cancelling'] ),
			'selection'  => $s['selection'],
			'steps'      => $steps,
			'progress'   => $progress,
			'error'      => $s['error'],
			'skipped'    => isset( $s['data']['archive']['skipped'] ) ? (int) $s['data']['archive']['skipped'] : ( isset( $s['data']['scan'] ) ? (int) $s['data']['scan']['skipped']['count'] : 0 ),
			'updated_at' => (int) $s['updated_at'],
			'started_at' => (int) $s['created_at'],
			'server_time' => time(),
			'has_log'    => is_file( $job->path( 'job.log' ) ),
			// Raw URLs (not wp_nonce_url(), which HTML-escapes "&"): the JS escapes them when rendering.
			'log_url'    => add_query_arg( array( 'action' => 'instabackup_download_log', 'job' => $s['id'], '_wpnonce' => wp_create_nonce( 'instabackup_log_' . $s['id'] ) ), admin_url( 'admin-post.php' ) ),
		);

		if ( isset( $s['data']['scan'] ) ) {
			$out['scan'] = $this->scan( $job );
		}
		return $out;
	}

	private function scan( Job $job ) {
		$scan  = $job->state['data']['scan'];
		$areas = array();
		foreach ( Areas::labels() as $id => $label ) {
			if ( in_array( $id, $job->state['selection']['areas'], true ) ) {
				$areas[] = array( 'id' => $id, 'label' => $label, 'count' => $scan['areas'][ $id ]['count'], 'bytes' => $scan['areas'][ $id ]['bytes'] );
			}
		}
		$db       = $scan['database'];
		$estimate = (int) $scan['files']['bytes'] + ( $db ? (int) $db['estimated_bytes'] : 0 );
		$disk     = $this->manager->disk_check( $job );
		return array(
			'files'          => $scan['files'],
			'areas'          => $areas,
			'database'       => $db ? array( 'tables' => $db['tables'], 'bytes' => $db['estimated_bytes'], 'non_prefixed' => $db['non_prefixed'], 'list' => isset( $db['list'] ) ? $db['list'] : array() ) : null,
			'tree'           => array_slice( (array) $scan['directories'], 0, 1000, true ),
			'estimate'       => $estimate,
			'engine'         => $estimate > ArchiveStep::threshold() ? 'batched' : 'single',
			'disk'           => array( 'ok' => $disk['ok'], 'required' => $disk['required'], 'free' => $disk['free'], 'message' => $disk['message'] ),
			'excluded'       => array_slice( $scan['excluded'], 0, 50 ),
			'excluded_count' => count( $scan['excluded'] ),
			'nested'         => $scan['nested_installs'],
			'skipped'        => $scan['skipped']['count'],
			'largest'        => array_slice( $scan['largest_files'], 0, 5 ),
			'is_32bit'       => PHP_INT_SIZE < 8,
		);
	}

	private function backup() {
		$backup = $this->manager->repository()->current();
		if ( ! $backup ) {
			return null;
		}
		return array(
			'id'           => $backup['id'],
			'created_at'   => (int) $backup['created_at'],
			'created'      => wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), (int) $backup['created_at'] ),
			'size'         => (int) $backup['size'],
			'type'         => $backup['type'],
			'database'     => ! empty( $backup['database']['included'] ),
			'files'        => ! empty( $backup['selection']['areas'] ),
			'files_count'  => (int) $backup['files_count'],
			'skipped'      => (int) $backup['skipped_count'],
			'verified'     => ! empty( $backup['verified'] ),
			'status'       => $backup['status'],
			'tree'         => isset( $backup['tree'] ) ? $backup['tree'] : array(),
			'tables'       => isset( $backup['tables'] ) ? $backup['tables'] : array(),
			'db_bytes'     => isset( $backup['database']['bytes'] ) ? (int) $backup['database']['bytes'] : 0,
			'download_url' => add_query_arg( array( 'action' => 'instabackup_download', 'backup' => $backup['id'], '_wpnonce' => wp_create_nonce( 'instabackup_download_' . $backup['id'] ) ), admin_url( 'admin-post.php' ) ),
		);
	}

	private function other_backups() {
		$current = $this->manager->repository()->current();
		$count   = 0;
		$bytes   = 0;
		foreach ( $this->manager->repository()->all() as $backup ) {
			if ( $current && $backup['id'] === $current['id'] ) {
				continue;
			}
			$count++;
			$bytes += (int) $backup['size'];
		}
		return array( 'count' => $count, 'bytes' => $bytes );
	}
}
