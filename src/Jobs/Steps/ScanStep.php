<?php
namespace InstaBackup\Jobs\Steps;

use InstaBackup\Database\Exporter;
use InstaBackup\Jobs\Job;
use InstaBackup\Jobs\StepInterface;
use InstaBackup\Scanner\Areas;
use InstaBackup\Scanner\Exclusions;
use InstaBackup\Scanner\Scanner;
use InstaBackup\Storage\StorageManager;

defined( 'ABSPATH' ) || exit;

final class ScanStep implements StepInterface {

	private $storage;

	public function __construct( StorageManager $storage ) {
		$this->storage = $storage;
	}

	public function id() {
		return 'scan';
	}

	public function label() {
		return __( 'Scanning files', 'backupscope' );
	}

	public function status() {
		return 'scanning';
	}

	public function run( Job $job, $deadline ) {
		$areas   = new Areas();
		$scanner = new Scanner( $areas, new Exclusions( $areas, $this->storage->base() ), $job->dir );
		$st      = &$job->state['step_state'];
		if ( empty( $st ) ) {
			$st = $scanner->init_state( $job->state['selection']['areas'] );
			$job->log->info( 'Scan started', array( 'areas' => $job->state['selection']['areas'] ) );
		}
		if ( $job->state['selection']['areas'] ) {
			$done = $scanner->run( $st, $deadline );
		} else {
			$done = true; // Database-only backup.
		}

		$job->state['progress'] = array( 'files_found' => $st['files'], 'bytes_found' => $st['bytes'], 'dirs' => $st['dirs'], 'current' => isset( $st['current'] ) ? $st['current'] : '' );
		if ( ! $done ) {
			return false;
		}

		$summary = $scanner->summary( $st );
		$db      = null;
		if ( ! empty( $job->state['selection']['database'] ) ) {
			$found = Exporter::discover( $job->state['selection']['extra_tables'] );
			$db    = array(
				'tables'          => count( $found['tables'] ),
				'views'           => count( $found['views'] ),
				'estimated_bytes' => $found['estimated_bytes'],
				'list'            => array_slice( $found['sizes'], 0, 300 ),
				'non_prefixed'    => $found['non_prefixed'],
			);
		}
		$summary['database'] = $db;
		$job->state['data']['scan']    = $summary;
		$job->state['data']['roots']   = $st['roots'];
		$job->state['data']['scan_at'] = time();
		update_option( 'instabackup_scan_summary', $summary, false );
		$job->log->info( 'Scan complete', array( 'files' => $summary['files'], 'skipped' => $summary['skipped'], 'excluded' => count( $summary['excluded'] ) ) );

		/** Fires when a scan finishes. Pro Storage Analytics can reuse the summary. */
		do_action( 'instabackup_scan_completed', $summary, $job->id() );
		return true;
	}
}
