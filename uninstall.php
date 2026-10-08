<?php
/**
 * Uninstall: backups contain wp-config.php and the full database, so they are removed with the
 * plugin (plan §28). Only BackupScope-named files inside the registered storage folder are
 * deleted. Define BACKUPSCOPE_KEEP_BACKUPS_ON_UNINSTALL as true to keep the archives.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// BackupScope Pro includes this engine and uses the same backups and settings. While Pro is
// installed, deleting the free plugin must not remove anything; Pro cleans up when it is deleted.
// (Pro runs this same file from its bundled engine when Pro itself is deleted; then it proceeds.)
if ( ! defined( 'INSTABACKUP_PRO_UNINSTALLING' ) ) {
	foreach ( array( 'instabackup-pro', 'backupscope-pro' ) as $instabackup_pro_folder ) {
		if ( is_file( WP_PLUGIN_DIR . '/' . $instabackup_pro_folder . '/engine/version.php' ) ) {
			return;
		}
	}
}

if ( defined( 'BACKUPSCOPE_KEEP_BACKUPS_ON_UNINSTALL' ) && ! defined( 'INSTABACKUP_KEEP_BACKUPS_ON_UNINSTALL' ) ) {
	define( 'INSTABACKUP_KEEP_BACKUPS_ON_UNINSTALL', BACKUPSCOPE_KEEP_BACKUPS_ON_UNINSTALL );
}

$instabackup_info = get_option( 'instabackup_storage' );
$instabackup_keep = defined( 'INSTABACKUP_KEEP_BACKUPS_ON_UNINSTALL' ) && INSTABACKUP_KEEP_BACKUPS_ON_UNINSTALL;

if ( is_array( $instabackup_info ) && ! empty( $instabackup_info['path'] ) && is_dir( $instabackup_info['path'] ) ) {
	$instabackup_base = rtrim( str_replace( '\\', '/', $instabackup_info['path'] ), '/' );

	// Working files (job folders) are always removed.
	foreach ( (array) glob( $instabackup_base . '/work/j_*', GLOB_ONLYDIR ) as $instabackup_dir ) {
		foreach ( (array) scandir( $instabackup_dir ) as $instabackup_file ) {
			$instabackup_file = $instabackup_dir . '/' . $instabackup_file;
			if ( is_file( $instabackup_file ) && ! is_link( $instabackup_file ) ) {
				@unlink( $instabackup_file ); // phpcs:ignore
			}
		}
		@rmdir( $instabackup_dir ); // phpcs:ignore
	}

	if ( ! $instabackup_keep ) {
		foreach ( (array) glob( $instabackup_base . '/backups/*' ) as $instabackup_file ) {
			$instabackup_name = basename( $instabackup_file );
			if ( is_file( $instabackup_file ) && ! is_link( $instabackup_file ) && (
				preg_match( '/^backupscope-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]{32}\.zip$/', $instabackup_name ) ||
				preg_match( '/^b_[a-f0-9]{16}\.json$/', $instabackup_name ) ||
				preg_match( '/^ib-canary-[a-f0-9]{16}\.txt$/', $instabackup_name ) ||
				in_array( $instabackup_name, array( 'index.php', 'index.html' ), true )
			) ) {
				@unlink( $instabackup_file ); // phpcs:ignore
			}
		}
		foreach ( array( '/work/index.php', '/work/index.html', '/.htaccess', '/web.config', '/index.php', '/index.html' ) as $instabackup_rel ) {
			if ( is_file( $instabackup_base . $instabackup_rel ) ) {
				@unlink( $instabackup_base . $instabackup_rel ); // phpcs:ignore
			}
		}
		// Folders are removed only when empty: nothing that is not ours is ever deleted.
		@rmdir( $instabackup_base . '/work' ); // phpcs:ignore
		@rmdir( $instabackup_base . '/backups' ); // phpcs:ignore
		if ( ! ( isset( $instabackup_info['method'] ) && 'constant' === $instabackup_info['method'] ) ) {
			@rmdir( $instabackup_base ); // phpcs:ignore
		}
	}
}

foreach ( array( 'instabackup_settings', 'instabackup_storage', 'instabackup_lock', 'instabackup_scan_summary', 'instabackup_last_job', 'instabackup_cancel' ) as $instabackup_option ) {
	delete_option( $instabackup_option );
}
