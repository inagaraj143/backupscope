<?php
namespace BackupScope\Jobs\Steps;

use BackupScope\Jobs\Job;
use BackupScope\Jobs\StepInterface;
use BackupScope\Storage\BackupRepository;
use BackupScope\Storage\ExposureTest;
use BackupScope\Storage\StorageManager;
use BackupScope\Support\UserError;

defined( 'ABSPATH' ) || exit;

/**
 * Moves the verified archive into storage, writes its sidecar, then applies the retention policy.
 * The previous backup is deleted only here, after the new one is verified (plan §17).
 */
final class FinalizeStep implements StepInterface {

	private $storage;
	private $repository;

	public function __construct( StorageManager $storage, BackupRepository $repository ) {
		$this->storage    = $storage;
		$this->repository = $repository;
	}

	public function id() {
		return 'finalize';
	}

	public function label() {
		return __( 'Finishing up', 'backupscope' );
	}

	public function status() {
		return 'finalizing';
	}

	public function run( Job $job, $deadline ) {
		$archive  = $job->state['data']['archive'];
		$manifest = $job->state['data']['manifest'];
		$sidecar  = array(
			'id'            => $archive['backup_id'],
			'file'          => $this->storage->new_archive_name(),
			'size'          => (int) filesize( $archive['file'] ),
			'created_at'    => time(),
			'created_by'    => $manifest['created_by'],
			'type'          => $manifest['type'],
			'purpose'       => $job->state['purpose'],
			'selection'     => $manifest['selection'],
			'status'        => $archive['skipped'] > 0 ? 'completed_with_warnings' : 'completed',
			'verified'      => true,
			'files_count'   => $manifest['files']['count'],
			'files_bytes'   => $manifest['files']['bytes'],
			'skipped_count' => $archive['skipped'],
			'database'      => array(
				'included' => $manifest['database']['included'],
				'tables'   => $manifest['database']['included'] ? count( $manifest['database']['tables'] ) : 0,
				'bytes'    => $manifest['database']['included'] ? $manifest['database']['bytes'] : 0,
			),
			'engine'        => $manifest['engine'],
			'job_id'        => $job->id(),
			'format'        => $manifest['format_version'],
			'encrypted'     => false,
			'locations'     => array( 'local' ),
			// Contents preview (folder sizes + tables) so the UI never has to open the ZIP.
			'tree'          => array_slice( array_diff_key( (array) $job->state['data']['scan']['directories'], array_fill_keys( isset( $job->state['selection']['excluded_groups'] ) ? (array) $job->state['selection']['excluded_groups'] : array(), true ) ), 0, 1000, true ),
			'tables'        => $manifest['database']['included'] ? array_slice( $manifest['database']['tables'], 0, 300 ) : array(),
		);

		if ( ! $this->repository->register( $sidecar, $archive['file'] ) ) {
			throw new UserError( 'register_failed', esc_html__( 'BackupScope could not move the finished backup into its storage folder.', 'backupscope' ) );
		}
		$job->state['data']['backup_id'] = $sidecar['id'];
		$job->log->info( 'Backup registered', array( 'id' => $sidecar['id'], 'size' => $sidecar['size'] ) );

		/** Fires after a verified backup was stored. Pro uses it for history/remote upload. */
		do_action( 'instabackup_backup_registered', $sidecar, $job->id() );

		$this->repository->apply_retention( $sidecar );

		if ( 'outside' !== $this->storage->method() && 'constant' !== $this->storage->method() ) {
			$exposure = ( new ExposureTest( $this->storage ) )->run();
			$job->log->info( 'Storage exposure test', $exposure );
		}
		return true;
	}
}
