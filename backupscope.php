<?php
/**
 * Plugin Name:       BackupScope
 * Plugin URI:        https://backupscope.pro
 * Description:       Back up your WordPress files and database to a private, downloadable ZIP. Scan first, see the size, download when it's done.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Nagaraj
 * Author URI:        https://twitter.com/Nagaraj_Dev143
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       backupscope
 */

defined( 'ABSPATH' ) || exit;

// BackupScope Pro includes this engine. If Pro already loaded it, there is nothing to do here.
if ( defined( 'INSTABACKUP_FILE' ) ) {
	return;
}

/*
 * Settings site owners can put in wp-config.php use the BACKUPSCOPE_ prefix. The code reads its
 * long-standing internal INSTABACKUP_ names, so each public name is mapped onto its internal one.
 */
( static function () {
	foreach ( array( 'STORAGE_DIR', 'KEEP_BACKUPS_ON_UNINSTALL', 'BATCH_THRESHOLD' ) as $setting ) {
		if ( defined( 'BACKUPSCOPE_' . $setting ) && ! defined( 'INSTABACKUP_' . $setting ) ) {
			define( 'INSTABACKUP_' . $setting, constant( 'BACKUPSCOPE_' . $setting ) );
		}
	}
} )();

define( 'INSTABACKUP_VERSION', '1.0.0' );
define( 'INSTABACKUP_FILE', __FILE__ );
define( 'INSTABACKUP_DIR', __DIR__ . '/' );
define( 'INSTABACKUP_ENGINE_API', 2 );
define( 'INSTABACKUP_BACKUP_FORMAT', 1 );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'InstaBackup\\' ) ) {
			return;
		}
		$file = INSTABACKUP_DIR . 'src/' . str_replace( '\\', '/', substr( $class, 12 ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'InstaBackup\\Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'InstaBackup\\Plugin', 'on_deactivate' ) );

add_action( 'plugins_loaded', array( 'InstaBackup\\Plugin', 'boot' ) );
