<?php
namespace InstaBackup\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * One resumable stage of a job. run() does work until it is finished (returns true) or the
 * deadline passes (returns false); its resume state lives in $job->state['step_state'].
 * Pro adds steps (remote upload, notifications) with the instabackup_job_steps filter.
 */
interface StepInterface {

	/** Stable ID, e.g. 'archive'. */
	public function id();

	/** Label for the progress list. */
	public function label();

	/** Job status shown while this step runs, e.g. 'archiving'. */
	public function status();

	/**
	 * @param Job   $job
	 * @param float $deadline microtime(true) value to stop at.
	 * @return bool True when the step is complete.
	 */
	public function run( Job $job, $deadline );
}
