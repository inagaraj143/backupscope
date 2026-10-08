<?php
namespace InstaBackup\Jobs\Steps;

use InstaBackup\Jobs\Job;
use InstaBackup\Jobs\StepInterface;

defined( 'ABSPATH' ) || exit;

/** Holds the job after the scan until the user clicks Create Backup (plan D1). */
final class AwaitConfirmationStep implements StepInterface {

	public function id() {
		return 'await';
	}

	public function label() {
		return __( 'Reviewing scan results', 'backupscope' );
	}

	public function status() {
		return 'scanned';
	}

	public function run( Job $job, $deadline ) {
		return ! empty( $job->state['confirmed'] );
	}
}
