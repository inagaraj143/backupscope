<?php
namespace BackupScope\Scanner;

use BackupScope\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Default exclusions in one place (plan §10). Excluded folders are not walked at all.
 */
final class Exclusions {

	const CONTENT_BACKUP_DIRS = array( 'updraft', 'ai1wm-backups', 'backups-dup-lite', 'backups-dup-pro', 'wpvividbackups', 'backup-db', 'backupwordpress', 'backwpup-temp' );
	const CONTENT_CACHE_DIRS  = array( 'cache', 'et-cache', 'litespeed' );
	const CONTENT_TEMP_DIRS   = array( 'upgrade', 'upgrade-temp-backup' );
	const ANY_DEPTH_DIRS      = array( '.git', '.svn', '.hg', 'node_modules' );
	const TEMP_SUFFIXES       = array( '.tmp', '.temp', '.swp', '~' );

	/** @var array<string,string> normalized absolute path => reason */
	private $paths = array();
	private $uploads;
	private $abspath;

	public function __construct( Areas $areas, $storage_dir ) {
		$content       = $areas->content_dir();
		$this->uploads = $areas->uploads_dir();
		$this->abspath = $areas->abspath();

		// The scanner walks real paths, so compare against the real storage path too.
		$real                                       = Paths::real( $storage_dir );
		$this->paths[ self::key( $storage_dir ) ] = 'instabackup';
		if ( $real ) {
			$this->paths[ self::key( $real ) ] = 'instabackup';
		}
		foreach ( self::CONTENT_BACKUP_DIRS as $dir ) {
			$this->paths[ self::key( $content . '/' . $dir ) ] = 'backup_plugin';
		}
		foreach ( self::CONTENT_CACHE_DIRS as $dir ) {
			$this->paths[ self::key( $content . '/' . $dir ) ] = 'cache';
		}
		// Server/domain verification files (ACME challenges, security.txt, app links).
		$this->paths[ self::key( $this->abspath . '/.well-known' ) ] = 'server_files';
		foreach ( self::CONTENT_TEMP_DIRS as $dir ) {
			$this->paths[ self::key( $content . '/' . $dir ) ] = 'temporary';
		}

		/**
		 * Filters extra absolute folder paths to exclude from backups and scans.
		 *
		 * @param array<string,string> $paths Absolute path => reason.
		 */
		$extra = apply_filters( 'instabackup_exclusions', array() );
		foreach ( (array) $extra as $path => $reason ) {
			$this->paths[ self::key( $path ) ] = sanitize_key( $reason ) ? sanitize_key( $reason ) : 'custom';
		}
	}

	/** Reason a folder is excluded, or null. */
	public function dir_reason( $path ) {
		$key = self::key( $path );
		if ( isset( $this->paths[ $key ] ) ) {
			return $this->paths[ $key ];
		}
		$name = basename( $path );
		if ( in_array( $name, self::ANY_DEPTH_DIRS, true ) ) {
			return 'vcs_tooling';
		}
		if ( $this->uploads && 0 === strpos( $name, 'backwpup-' ) && self::key( dirname( $path ) ) === self::key( $this->uploads ) ) {
			return 'backup_plugin';
		}
		if ( ( 'backupscope' === $name || 0 === strpos( $name, 'backupscope-' ) ) && is_file( $path . '/.htaccess' ) && is_dir( $path . '/backups' ) ) {
			return 'instabackup'; // Storage folder of another install or an older storage location.
		}
		if ( self::key( $path ) !== self::key( $this->abspath ) && is_dir( $path . '/wp-includes' )
			&& ( is_file( $path . '/wp-config.php' ) || is_file( $path . '/wp-load.php' ) ) ) {
			return 'nested_install';
		}
		return null;
	}

	/** Reason a file is excluded, or null. */
	public function file_reason( $name ) {
		foreach ( self::TEMP_SUFFIXES as $suffix ) {
			if ( substr( $name, -strlen( $suffix ) ) === $suffix ) {
				return 'temporary';
			}
		}
		if ( preg_match( '/^instabackup-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]{32}\.zip$/', $name ) ) {
			return 'instabackup';
		}
		return null;
	}

	private static function key( $path ) {
		$key = Paths::normalize( $path );
		return Paths::case_insensitive() ? strtolower( $key ) : $key;
	}
}
