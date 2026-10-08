<?php
namespace InstaBackup\Jobs;

use InstaBackup\Storage\StorageManager;
use InstaBackup\Support\JsonFile;
use InstaBackup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * A job's state. Stored as job.json in the job's private working folder (file-based so that a
 * Pro database restore can replace wp_options without losing the job, plan §8.1).
 * Large data (scan index, SQL dump, partial archive) lives in sibling files.
 */
final class Job {

	/** @var array */
	public $state;
	public $dir;
	public $log;

	public function __construct( array $state, $dir ) {
		$this->state = $state;
		$this->dir   = $dir;
		$this->log   = new Logger( $dir . '/job.log' );
	}

	public static function create( StorageManager $storage, array $selection, array $steps ) {
		$id    = StorageManager::new_job_id();
		$dir   = $storage->job_dir( $id );
		$state = array(
			'id'          => $id,
			'type'        => 'backup',
			'purpose'     => 'manual',
			'status'      => 'scanning',
			'selection'   => $selection,
			'steps'       => $steps,
			'step_index'  => 0,
			'step_state'  => array(),
			'step_result' => array(),
			'data'        => array(),
			'progress'    => array(),
			'confirmed'   => false,
			'budget'      => self::default_budget(),
			'failures'    => 0,
			'slice_open'  => null,
			'died_last'   => false,
			'warnings'    => array(),
			'error'       => null,
			'created_at'  => time(),
			'updated_at'  => time(),
		);
		$job   = new self( $state, $dir );
		$job->save();
		return $job;
	}

	public static function load( StorageManager $storage, $id ) {
		if ( ! preg_match( StorageManager::JOB_ID_REGEX, (string) $id ) ) {
			return null;
		}
		$dir   = $storage->work_root() . '/' . $id;
		$state = JsonFile::read( $dir . '/job.json' );
		return $state ? new self( $state, $dir ) : null;
	}

	public function id() {
		return $this->state['id'];
	}

	public function save() {
		$this->state['updated_at'] = time();
		JsonFile::write( $this->dir . '/job.json', $this->state );
	}

	public function current_step() {
		return isset( $this->state['steps'][ $this->state['step_index'] ] ) ? $this->state['steps'][ $this->state['step_index'] ] : null;
	}

	public function is_terminal() {
		return in_array( $this->state['status'], array( 'completed', 'completed_with_warnings', 'failed', 'cancelled' ), true );
	}

	public function path( $name ) {
		return $this->dir . '/' . $name;
	}

	public static function default_budget() {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = $max > 0 ? min( 20, max( 5, (int) floor( $max * 0.5 ) ) ) : 20;
		/**
		 * Filters the seconds of work per request.
		 *
		 * @param int $budget
		 */
		return (int) apply_filters( 'instabackup_batch_budget', $budget );
	}
}
