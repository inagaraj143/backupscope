<?php
namespace BackupScope\Manifest;

use BackupScope\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * manifest.json, backup format v1 (plan §14). Readers must ignore unknown fields;
 * format_version changes only on breaking layout changes.
 * Never contains absolute paths, credentials or user emails.
 */
final class Manifest {

	const FORMAT = 'instabackup-backup';

	public static function build( Job $job, array $archive ) {
		global $wpdb, $wp_version;
		$sel  = $job->state['selection'];
		$scan = isset( $job->state['data']['scan'] ) ? $job->state['data']['scan'] : array();
		$db   = isset( $job->state['data']['database'] ) ? $job->state['data']['database'] : null;

		$roots = array();
		foreach ( (array) ( isset( $job->state['data']['roots'] ) ? $job->state['data']['roots'] : array() ) as $root ) {
			$roots[] = array( 'archive_path' => $root['archive'], 'role' => $root['role'], 'type' => $root['type'] );
		}

		$excluded = array();
		foreach ( (array) ( isset( $scan['excluded'] ) ? $scan['excluded'] : array() ) as $item ) {
			$excluded[] = array( 'path' => $item['path'], 'reason' => $item['reason'] );
		}
		// wp-config.php and other files with the security keys: never in a backup.
		$listed = wp_list_pluck( $excluded, 'path' );
		foreach ( (array) ( isset( $archive['secrets_excluded'] ) ? $archive['secrets_excluded'] : array() ) as $path => $reason ) {
			if ( ! in_array( (string) $path, $listed, true ) ) { // Usually already listed by the scan.
				$excluded[] = array( 'path' => (string) $path, 'reason' => $reason );
			}
		}

		return array(
			'format'         => self::FORMAT,
			'format_version' => INSTABACKUP_BACKUP_FORMAT,
			'backup_id'      => $archive['backup_id'],
			'created_at'     => gmdate( 'c' ),
			'created_by'     => array(
				'plugin'  => 'backupscope',
				'version' => INSTABACKUP_VERSION,
				'edition' => apply_filters( 'instabackup_edition', 'free' ),
			),
			'type'           => self::type( $sel ),
			'purpose'        => $job->state['purpose'],
			'selection'      => array(
				'mode'         => $sel['mode'],
				'areas'        => $sel['areas'],
				'database'     => (bool) $sel['database'],
				'extra_tables' => $sel['extra_tables'],
				'excluded_folders' => isset( $sel['excluded_groups'] ) ? array_values( (array) $sel['excluded_groups'] ) : array(),
			),
			'site'           => array(
				'site_url'     => site_url(),
				'home_url'     => home_url(),
				'wp_version'   => $wp_version,
				'php_version'  => PHP_VERSION,
				'db_server'    => $db ? $db['server'] : null,
				'table_prefix' => $wpdb->base_prefix,
				'charset'      => $wpdb->charset,
				'collate'      => $wpdb->collate,
				'multisite'    => is_multisite(),
				'locale'       => get_locale(),
			),
			'files'          => array(
				'included'       => (bool) $sel['areas'],
				'roots'          => $roots,
				'count'          => (int) $archive['files_done'],
				'bytes'          => (int) $archive['bytes_done'],
				'skipped_count'  => (int) $archive['skipped'],
				'skipped_report' => $archive['skipped'] ? 'backupscope/skipped-files.json' : null,
				'excluded'       => $excluded,
			),
			'database'       => $db ? array(
				'included'     => true,
				'archive_path' => 'database/database.sql',
				'sql_format'   => 'instabackup-sql/1',
				'tables'       => $db['tables'],
				'views'        => $db['views'],
				'triggers'     => $db['triggers'],
				'not_included' => $db['not_included'],
				'bytes'        => $db['bytes'],
			) : array( 'included' => false ),
			'engine'         => array(
				'path'          => $archive['engine'],
				'fallback_used' => ! empty( $archive['fallback_used'] ),
			),
			'encryption'     => null,
		);
	}

	public static function type( array $sel ) {
		if ( 'full' === $sel['mode'] && $sel['database'] && empty( $sel['excluded_groups'] ) ) {
			return 'full';
		}
		if ( ! $sel['areas'] ) {
			return 'database';
		}
		return $sel['database'] ? 'custom' : 'files';
	}
}
