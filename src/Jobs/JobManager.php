<?php
namespace InstaBackup\Jobs;

use InstaBackup\Database\Exporter;
use InstaBackup\Jobs\Steps\ArchiveStep;
use InstaBackup\Jobs\Steps\AwaitConfirmationStep;
use InstaBackup\Jobs\Steps\CleanupStep;
use InstaBackup\Jobs\Steps\DatabaseStep;
use InstaBackup\Jobs\Steps\FinalizeStep;
use InstaBackup\Jobs\Steps\PreflightStep;
use InstaBackup\Jobs\Steps\ScanStep;
use InstaBackup\Jobs\Steps\VerifyStep;
use InstaBackup\Scanner\Areas;
use InstaBackup\Storage\BackupRepository;
use InstaBackup\Storage\StorageManager;
use InstaBackup\Support\Logger;
use InstaBackup\Support\Paths;
use InstaBackup\Support\UserError;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the job lifecycle (plan §8): create, lock, run one slice, persist, detect dead requests,
 * resume, cancel, fail, clean up. The runner (AJAX in Free, background in Pro) only calls step().
 */
final class JobManager {

	const STALE_SECONDS   = 180;
	const SCAN_TTL        = 1800;
	const MAX_FAILURES    = 5;
	const MIN_BUDGET      = 5;
	const CANCEL_OPTION   = 'instabackup_cancel';
	const LAST_JOB_OPTION = 'instabackup_last_job';

	private $storage;
	private $repository;
	private $lock;

	public function __construct( ?StorageManager $storage = null, ?BackupRepository $repository = null ) {
		$this->storage    = $storage ? $storage : new StorageManager();
		$this->repository = $repository ? $repository : new BackupRepository( $this->storage );
		$this->lock       = new Lock();
	}

	/** @return StepInterface[] */
	public function steps() {
		$steps = array(
			new PreflightStep(),
			new ScanStep( $this->storage ),
			new AwaitConfirmationStep(),
			new DatabaseStep(),
			new ArchiveStep(),
			new VerifyStep(),
			new FinalizeStep( $this->storage, $this->repository ),
			new CleanupStep(),
		);
		/**
		 * Filters the step pipeline. Pro inserts steps such as remote upload or notifications.
		 *
		 * @param StepInterface[] $steps
		 */
		$filtered = apply_filters( 'instabackup_job_steps', $steps );
		$out      = array();
		foreach ( (array) $filtered as $step ) {
			if ( $step instanceof StepInterface ) {
				$out[ $step->id() ] = $step;
			}
		}
		return $out;
	}

	/**
	 * Step IDs of a new backup job. Steps listed by instabackup_standalone_steps (e.g. Pro's
	 * restore and analytics jobs) run only in jobs created for them, never in backups.
	 */
	public function backup_step_ids() {
		$standalone = (array) apply_filters( 'instabackup_standalone_steps', array() );
		return array_values( array_diff( array_keys( $this->steps() ), $standalone ) );
	}

	/**
	 * Starts a job with its own step list (engine API 2). Used by add-ons for restore and
	 * analytics jobs, which share the lock, slices, resume and cancel with backups.
	 */
	public function start_custom( array $steps, array $selection, $purpose ) {
		$this->clear_garbage_lock();
		if ( $this->lock->read() ) {
			throw new UserError( 'busy', esc_html__( 'Another backup is already running.', 'backupscope' ) );
		}
		$this->delete_old_jobs();
		$job                   = Job::create( $this->storage, $selection, array_values( $steps ) );
		$job->state['purpose'] = $purpose;
		$job->state['type']    = $purpose;
		$job->state['status']  = 'preparing';
		$job->state['confirmed'] = true;
		$job->save();
		if ( ! $this->lock->acquire( $job->id() ) ) {
			Paths::delete_tree( $job->dir );
			throw new UserError( 'busy', esc_html__( 'Another backup is already running.', 'backupscope' ) );
		}
		update_option( self::LAST_JOB_OPTION, $job->id(), false );
		delete_option( self::CANCEL_OPTION );
		return $job;
	}

	public static function sanitize_selection( array $input ) {
		$mode = isset( $input['mode'] ) && 'custom' === $input['mode'] ? 'custom' : 'full';
		if ( 'full' === $mode ) {
			return array( 'mode' => 'full', 'areas' => Areas::IDS, 'database' => true, 'extra_tables' => array() );
		}
		$areas    = Areas::sanitize( isset( $input['areas'] ) ? $input['areas'] : array() );
		$database = ! empty( $input['database'] );
		$extra    = array();
		if ( $database && ! empty( $input['extra_tables'] ) && Exporter::supported() ) {
			$known = wp_list_pluck( Exporter::discover()['non_prefixed'], 'name' );
			$extra = array_values( array_intersect( $known, array_map( 'strval', (array) $input['extra_tables'] ) ) );
		}
		if ( ! $areas && ! $database ) {
			throw new UserError( 'empty_selection', esc_html__( 'Select at least one item to back up.', 'backupscope' ) );
		}
		return array( 'mode' => 'custom', 'areas' => $areas, 'database' => $database, 'extra_tables' => $extra );
	}

	// ------------------------------------------------------------------ commands

	public function start( array $selection ) {
		$this->clear_garbage_lock();
		if ( $this->lock->read() ) {
			throw new UserError( 'busy', esc_html__( 'Another backup is already running.', 'backupscope' ) );
		}
		$this->delete_old_jobs();
		$job = Job::create( $this->storage, $selection, $this->backup_step_ids() );
		if ( ! $this->lock->acquire( $job->id() ) ) {
			Paths::delete_tree( $job->dir );
			throw new UserError( 'busy', esc_html__( 'Another backup is already running.', 'backupscope' ) );
		}
		update_option( self::LAST_JOB_OPTION, $job->id(), false );
		delete_option( self::CANCEL_OPTION );
		$job->log->info( 'Job created', array( 'selection' => $selection, 'version' => INSTABACKUP_VERSION ) );
		/** Fires when a backup job starts. */
		do_action( 'instabackup_job_started', $job->id(), $selection );
		return $job;
	}

	/** User clicked Create Backup after reviewing the scan. */
	/**
	 * @param string   $job_id
	 * @param string[] $excluded_groups Folder groups the user unticked (validated against the scan).
	 * @param bool     $database        False when the user unticked the database.
	 */
	public function confirm( $job_id, array $excluded_groups = array(), $database = true ) {
		$job = $this->require_job( $job_id );
		if ( 'scanned' !== $job->state['status'] ) {
			return $job;
		}
		$known    = (array) $job->state['data']['scan']['directories'];
		$excluded = array();
		foreach ( $excluded_groups as $group ) {
			if ( is_string( $group ) && array_key_exists( $group, $known ) ) {
				$excluded[] = $group;
			}
		}
		$job->state['selection']['excluded_groups'] = array_values( array_unique( $excluded ) );
		if ( ! $database ) {
			$job->state['selection']['database'] = false;
		}
		$left = 0;
		foreach ( $known as $group => $info ) {
			if ( ! in_array( $group, $excluded, true ) ) {
				$left += (int) $info['count'];
			}
		}
		if ( 0 === $left && empty( $job->state['selection']['database'] ) ) {
			throw new UserError( 'empty_selection', esc_html__( 'Select at least one item to back up.', 'backupscope' ) );
		}
		$check = $this->disk_check( $job );
		if ( false === $check['ok'] ) {
			throw new UserError( 'disk_space', esc_html( $check['message'] ) );
		}
		if ( PHP_INT_SIZE < 8 && $check['required'] > 2147483647 ) {
			throw new UserError( 'php_32bit', esc_html__( 'This server runs 32-bit PHP, which cannot create backups larger than 2 GB. Choose fewer items with Custom Backup or ask your host for 64-bit PHP.', 'backupscope' ) );
		}
		if ( time() - (int) $job->state['data']['scan_at'] > self::SCAN_TTL ) {
			$job->state['step_index'] = array_search( 'scan', $job->state['steps'], true );
			$job->state['step_state'] = array();
			$job->log->info( 'Scan results expired; rescanning before backup' );
		}
		$job->state['confirmed'] = true;
		$job->state['status']    = 'preparing';
		$job->save();
		$this->lock->heartbeat( $job->id() ); // Reviewing the scan can take minutes; not "interrupted".
		$job->log->info( 'Backup confirmed', array( 'excluded_groups' => count( $excluded ), 'database' => (bool) $job->state['selection']['database'] ) );
		return $job;
	}

	/** Runs one slice of work. Safe to call repeatedly; never runs two slices at once. */
	public function step( $job_id ) {
		$job = $this->require_job( $job_id );
		if ( $job->is_terminal() || 'scanned' === $job->state['status'] ) {
			return $job;
		}
		$budget = max( self::MIN_BUDGET, (int) $job->state['budget'] );
		if ( null === $this->lock->read() ) {
			$this->lock->acquire( $job->id() ); // Lock row lost (e.g. cleared by a cache flush): re-take it.
		}
		// died.flag is written by the shutdown handler when the previous request hit a fatal error
		// (e.g. max_execution_time), so its lease can be ignored safely.
		$dead = is_file( $job->path( 'died.flag' ) );
		if ( ! $this->lock->lease( $job->id(), 2 * $budget + 30, $dead ) ) {
			$job->state['busy'] = true;
			return $job;
		}
		unset( $job->state['busy'] );
		if ( $dead ) {
			@unlink( $job->path( 'died.flag' ) ); // phpcs:ignore
		}

		if ( $job->state['slice_open'] ) {
			// The previous request never finished (timeout, fatal error, killed process).
			$job->state['failures']++;
			$job->state['died_last'] = true;
			$job->state['budget']    = max( self::MIN_BUDGET, (int) floor( $budget / 2 ) );
			$budget                  = $job->state['budget'];
			$job->log->warning( 'Previous request did not finish; resuming with a smaller batch', array( 'failures' => $job->state['failures'], 'budget' => $budget ) );
			if ( $job->state['failures'] >= self::MAX_FAILURES ) {
				$this->fail( $job, 'timeout', __( 'The server stopped the backup several times in a row (time or memory limit). Please try again; if it keeps failing, ask your host to raise the PHP time limit.', 'backupscope' ), 'consecutive request failures' );
				return $job;
			}
		} else {
			$job->state['died_last'] = false;
		}
		$job->state['slice_open'] = time();
		$job->save();

		$this->guard_fatal( $job );
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 2 * $budget + 30 ); // phpcs:ignore
		}
		$deadline = microtime( true ) + $budget;
		$steps    = $this->steps();

		try {
			while ( true ) {
				if ( $this->cancel_requested( $job ) ) {
					$this->do_cancel( $job );
					return $job;
				}
				$id = $job->current_step();
				if ( null === $id ) {
					$this->complete( $job );
					break;
				}
				if ( ! isset( $steps[ $id ] ) ) {
					throw new UserError( 'step_missing', esc_html__( 'A backup step is missing. Please update BackupScope and its add-ons.', 'backupscope' ), $id );
				}
				$step                  = $steps[ $id ];
				$job->state['status']  = $step->status();
				if ( ! $step->run( $job, $deadline ) ) {
					break;
				}
				if ( ! isset( $job->state['step_result'][ $id ] ) ) {
					$job->state['step_result'][ $id ] = 'done';
				}
				$job->state['step_index']++;
				$job->state['step_state'] = array();
				$job->state['died_last']  = false;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}
			$job->state['failures'] = 0;
		} catch ( UserError $e ) {
			$this->fail( $job, $e->error_code(), $e->getMessage(), $e->detail() );
			return $job;
		} catch ( \Throwable $e ) {
			$this->fail( $job, 'unexpected', __( 'BackupScope ran into an unexpected error. Your previous backup is unchanged.', 'backupscope' ), get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
			return $job;
		}

		$job->state['slice_open'] = null;
		$job->save();
		if ( ! $job->is_terminal() ) {
			$this->lock->end_lease( $job->id() );
		}
		return $job;
	}

	public function cancel( $job_id ) {
		$job  = $this->require_job( $job_id );
		$lock = $this->lock->read();
		if ( $job->is_terminal() ) {
			return $job;
		}
		if ( $lock && $lock['job'] === $job->id() && (int) $lock['lease'] > time() && ! $this->is_stale( $lock ) ) {
			update_option( self::CANCEL_OPTION, $job->id(), false );
			$job->state['cancelling'] = true;
			return $job;
		}
		$this->do_cancel( $job );
		return $job;
	}

	public function abandon_on_deactivate() {
		$lock = $this->lock->read();
		if ( ! $lock ) {
			return;
		}
		$job = Job::load( $this->storage, $lock['job'] );
		if ( $job && ! $job->is_terminal() ) {
			$this->do_cancel( $job );
		}
		$this->lock->force_release();
	}

	// ------------------------------------------------------------------ status

	public function active_job() {
		$lock = $this->lock->read();
		if ( ! $lock ) {
			return null;
		}
		$job = Job::load( $this->storage, $lock['job'] );
		return ( $job && ! $job->is_terminal() ) ? $job : null;
	}

	public function last_job() {
		$id = get_option( self::LAST_JOB_OPTION );
		return $id ? Job::load( $this->storage, $id ) : null;
	}

	public function is_paused( Job $job ) {
		if ( $job->is_terminal() || 'scanned' === $job->state['status'] ) {
			return false;
		}
		$lock = $this->lock->read();
		return ! $lock || $this->is_stale( $lock );
	}

	public function disk_check( Job $job ) {
		$scan     = $job->state['data']['scan'];
		$db       = $scan['database'] ? (int) $scan['database']['estimated_bytes'] : 0;
		$required = (int) ceil( ( (int) $scan['files']['bytes'] + 1.2 * $db ) * 1.1 ) + 10485760;
		$free     = $this->storage->free_space();
		if ( null === $free ) {
			return array( 'ok' => null, 'required' => $required, 'free' => null, 'message' => '' );
		}
		$ok = $free >= $required;
		return array(
			'ok'       => $ok,
			'required' => $required,
			'free'     => $free,
			'message'  => $ok ? '' : sprintf(
				/* translators: 1: required size, 2: available size */
				__( 'Not enough disk space to safely create this backup (needs ~%1$s, ~%2$s available). Free up space or delete the current backup first.', 'backupscope' ),
				size_format( $required, 1 ),
				size_format( $free, 1 )
			),
		);
	}

	public function storage() {
		return $this->storage;
	}

	public function lock() {
		return $this->lock;
	}

	public function repository() {
		return $this->repository;
	}

	// ------------------------------------------------------------------ internals

	private function complete( Job $job ) {
		$skipped               = isset( $job->state['data']['archive']['skipped'] ) ? (int) $job->state['data']['archive']['skipped'] : 0;
		$job->state['status']  = $skipped > 0 ? 'completed_with_warnings' : 'completed';
		$job->state['slice_open'] = null;
		$job->state['finished_at'] = time();
		$job->save();
		$job->log->info( 'Job completed', array( 'status' => $job->state['status'] ) );
		$this->lock->release( $job->id() );
		delete_option( self::CANCEL_OPTION );
		/** Fires when a backup job finished successfully. */
		do_action( 'instabackup_job_completed', $job->id(), $job->state['data'] );
	}

	private function fail( Job $job, $code, $message, $detail ) {
		$job->log->error( 'Job failed: ' . $code, array( 'detail' => Logger::redact( $detail ) ) );
		CleanupStep::remove_working_files( $job->dir );
		$job->state['status']      = 'failed';
		$job->state['error']       = array( 'code' => $code, 'message' => wp_specialchars_decode( $message, ENT_QUOTES ) );
		$job->state['slice_open']  = null;
		$job->state['finished_at'] = time();
		$job->save();
		$this->lock->release( $job->id() );
		delete_option( self::CANCEL_OPTION );
		/** Fires when a backup job failed. */
		do_action( 'instabackup_job_failed', $job->id(), $job->state['error'] );
	}

	private function do_cancel( Job $job ) {
		CleanupStep::remove_working_files( $job->dir );
		$job->state['status']      = 'cancelled';
		$job->state['slice_open']  = null;
		$job->state['finished_at'] = time();
		unset( $job->state['cancelling'] );
		$job->save();
		$job->log->info( 'Job cancelled' );
		$this->lock->release( $job->id() );
		delete_option( self::CANCEL_OPTION );
	}

	private function cancel_requested( Job $job ) {
		wp_cache_delete( self::CANCEL_OPTION, 'options' );
		return get_option( self::CANCEL_OPTION ) === $job->id();
	}

	private function is_stale( array $lock ) {
		return time() - (int) $lock['heartbeat'] > self::STALE_SECONDS && (int) $lock['lease'] < time();
	}

	private function require_job( $job_id ) {
		$job = Job::load( $this->storage, $job_id );
		if ( ! $job ) {
			throw new UserError( 'invalid_job', esc_html__( 'This backup job no longer exists. Please start again.', 'backupscope' ) );
		}
		return $job;
	}

	/** A lock whose job is gone or already finished is garbage. */
	private function clear_garbage_lock() {
		$lock = $this->lock->read();
		if ( ! $lock ) {
			return;
		}
		$job = Job::load( $this->storage, $lock['job'] );
		if ( ! $job || $job->is_terminal() ) {
			$this->lock->force_release();
		}
	}

	/** Previous jobs' folders (logs included) are removed when a new job starts. */
	private function delete_old_jobs() {
		$root = $this->storage->work_root();
		foreach ( (array) glob( $root . '/j_*', GLOB_ONLYDIR ) as $dir ) {
			Paths::delete_tree( $dir );
		}
	}

	/** Orphaned working folders older than a day (no active job) are removed. */
	public function sweep_orphans() {
		if ( $this->lock->read() ) {
			return;
		}
		$keep = get_option( self::LAST_JOB_OPTION );
		foreach ( (array) glob( $this->storage->work_root() . '/j_*', GLOB_ONLYDIR ) as $dir ) {
			if ( basename( $dir ) !== $keep && filemtime( $dir ) < time() - DAY_IN_SECONDS ) {
				Paths::delete_tree( $dir );
			}
		}
	}

	private function guard_fatal( Job $job ) {
		$dir = $job->dir;
		register_shutdown_function(
			static function () use ( $dir ) {
				$e = error_get_last();
				if ( $e && in_array( $e['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_PARSE ), true ) ) {
					( new Logger( $dir . '/job.log' ) )->error( 'Request ended with a fatal error', array( 'message' => $e['message'], 'file' => basename( $e['file'] ), 'line' => $e['line'] ) );
					@file_put_contents( $dir . '/died.flag', (string) time() ); // phpcs:ignore
				}
			}
		);
	}
}
