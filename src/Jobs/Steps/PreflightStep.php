<?php
namespace BackupScope\Jobs\Steps;

use BackupScope\Database\Exporter;
use BackupScope\Jobs\Job;
use BackupScope\Jobs\StepInterface;
use BackupScope\Support\UserError;

defined( 'ABSPATH' ) || exit;

final class PreflightStep implements StepInterface {

	public function id() {
		return 'preflight';
	}

	public function label() {
		return __( 'Checking your server', 'backupscope' );
	}

	public function status() {
		return 'scanning';
	}

	public function run( Job $job, $deadline ) {
		if ( ! wp_is_writable( $job->dir ) ) {
			throw new UserError( 'storage_unwritable', esc_html__( 'BackupScope cannot write to its storage folder. Please ask your host to allow PHP to write to the uploads folder.', 'backupscope' ) );
		}
		if ( ! empty( $job->state['selection']['database'] ) && ! Exporter::supported() ) {
			throw new UserError( 'db_driver', esc_html__( 'BackupScope can only back up MySQL or MariaDB databases connected through mysqli.', 'backupscope' ) );
		}
		$job->state['data']['env'] = array(
			'php'        => PHP_VERSION,
			'int_size'   => PHP_INT_SIZE,
			'ziparchive' => class_exists( 'ZipArchive' ),
			'zlib'       => function_exists( 'deflate_init' ),
			'max_exec'   => (int) ini_get( 'max_execution_time' ),
			'memory'     => ini_get( 'memory_limit' ),
		);
		$job->log->info( 'Preflight', $job->state['data']['env'] );
		return true;
	}
}
