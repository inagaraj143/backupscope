<?php
namespace InstaBackup\Jobs\Steps;

use InstaBackup\Archive\Verifier;
use InstaBackup\Jobs\Job;
use InstaBackup\Jobs\StepInterface;

defined( 'ABSPATH' ) || exit;

final class VerifyStep implements StepInterface {

	public function id() {
		return 'verify';
	}

	public function label() {
		return __( 'Verifying backup', 'backupscope' );
	}

	public function status() {
		return 'verifying';
	}

	public function run( Job $job, $deadline ) {
		$archive = $job->state['data']['archive'];
		$st      = &$job->state['step_state'];
		if ( empty( $st ) ) {
			$required = array( 'manifest.json' );
			if ( isset( $job->state['data']['database'] ) ) {
				$required[] = 'database/database.sql';
			}
			/**
			 * Filters the verification level: 'full' re-reads every entry and checks its CRC-32,
			 * 'structure' only checks the central directory and required entries.
			 */
			$level = 'structure' === apply_filters( 'instabackup_verify_level', 'full' ) ? 'structure' : 'full';
			$st    = Verifier::init_state( $archive['file'], $archive['entries'], $required, $level );
		}
		$done = ( new Verifier( $st ) )->run( $deadline );

		$job->state['progress'] = array(
			'bytes_checked' => $st['bytes'],
			'bytes_total'   => (int) filesize( $archive['file'] ),
			'level'         => $st['level'],
		);
		if ( $done ) {
			$job->log->info( 'Verification passed', array( 'entries' => $archive['entries'], 'level' => $st['level'] ) );
		}
		return $done;
	}
}
