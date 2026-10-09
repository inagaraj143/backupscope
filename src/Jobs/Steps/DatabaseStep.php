<?php
namespace BackupScope\Jobs\Steps;

use BackupScope\Database\Exporter;
use BackupScope\Jobs\Job;
use BackupScope\Jobs\StepInterface;

defined( 'ABSPATH' ) || exit;

final class DatabaseStep implements StepInterface {

	public function id() {
		return 'database';
	}

	public function label() {
		return __( 'Exporting database', 'backupscope' );
	}

	public function status() {
		return 'exporting_database';
	}

	public function run( Job $job, $deadline ) {
		if ( empty( $job->state['selection']['database'] ) ) {
			$job->state['step_result']['database'] = 'skipped';
			return true;
		}
		$exporter = new Exporter( $job->path( 'database.sql' ) );
		$st       = &$job->state['step_state'];
		if ( empty( $st ) ) {
			$st = $exporter->init_state( $job->state['selection']['extra_tables'] );
			$job->log->info( 'Database export started', array( 'tables' => count( $st['tables'] ) ) );
		}
		$done = $exporter->run( $st, $deadline );

		$current                = isset( $st['tables'][ $st['ti'] ] ) ? $st['tables'][ $st['ti'] ] : '';
		$job->state['progress'] = array(
			'tables_done'  => min( $st['ti'], count( $st['tables'] ) ),
			'tables_total' => count( $st['tables'] ),
			'table'        => $done ? '' : $current,
		);
		if ( ! $done ) {
			return false;
		}

		$tables = array();
		foreach ( $st['tables'] as $t ) {
			$tables[] = array( 'name' => $t, 'rows' => (int) $st['rows'][ $t ], 'rows_at_start' => (int) $st['counts'][ $t ] );
			if ( (int) $st['rows'][ $t ] !== (int) $st['counts'][ $t ] ) {
				$job->log->info( 'Row count changed during export (live table)', array( 'table' => $t ) );
			}
		}
		$job->state['data']['database'] = array(
			'tables'       => $tables,
			'views'        => $st['views'],
			'triggers'     => $st['triggers'],
			'not_included' => $exporter->not_included(),
			'bytes'        => (int) filesize( $job->path( 'database.sql' ) ),
			'server'       => $exporter->server_info(),
		);
		$job->log->info( 'Database export complete', array( 'bytes' => $job->state['data']['database']['bytes'] ) );
		return true;
	}
}
