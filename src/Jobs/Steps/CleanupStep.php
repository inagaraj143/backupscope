<?php
namespace InstaBackup\Jobs\Steps;

use InstaBackup\Jobs\Job;
use InstaBackup\Jobs\StepInterface;

defined( 'ABSPATH' ) || exit;

/** Removes working files. The job log and skipped-files list stay until the next job starts. */
final class CleanupStep implements StepInterface {

	const KEEP = array( 'job.json', 'job.log', 'skipped.jsonl', 'index.php', 'index.html' );

	public function id() {
		return 'cleanup';
	}

	public function label() {
		return __( 'Cleaning up', 'backupscope' );
	}

	public function status() {
		return 'finalizing';
	}

	public function run( Job $job, $deadline ) {
		self::remove_working_files( $job->dir );
		return true;
	}

	public static function remove_working_files( $dir ) {
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name || in_array( $name, self::KEEP, true ) ) {
				continue;
			}
			if ( is_file( $dir . '/' . $name ) ) {
				@unlink( $dir . '/' . $name ); // phpcs:ignore
			}
		}
	}
}
