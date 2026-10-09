<?php
namespace BackupScope\Storage;

use BackupScope\Support\Paths;
use BackupScope\Support\UserError;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and protects the private storage directory (plan §16).
 *
 * Order: the BACKUPSCOPE_STORAGE_DIR constant (a folder the site owner chose, outside WordPress)
 * → wp-content/uploads/backupscope/ (protected). Existing installs keep the folder they recorded.
 */
final class StorageManager {

	const OPTION           = 'instabackup_storage';
	/** Default folder name inside the uploads directory: the plugin slug. */
	const FOLDER           = 'backupscope';
	const ARCHIVE_PATTERN  = '/^backupscope-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]{32}\.zip$/';
	const BACKUP_ID_REGEX  = '/^b_[a-f0-9]{16}$/';
	const JOB_ID_REGEX     = '/^j_[a-f0-9]{16}$/';

	/** @var array|null */
	private $info;

	/** Base directory, created and protected on first use. */
	public function base() {
		$info = $this->info();
		if ( $info && is_dir( $info['path'] ) && wp_is_writable( $info['path'] ) ) {
			return $info['path'];
		}
		$info = $this->create();
		update_option( self::OPTION, $info, false );
		$this->info = $info;
		return $info['path'];
	}

	public function info() {
		if ( null === $this->info ) {
			$info       = get_option( self::OPTION );
			$this->info = is_array( $info ) && ! empty( $info['path'] ) ? $info : null;
		}
		return $this->info;
	}

	public function backups_dir() {
		return $this->ensure_sub( 'backups' );
	}

	public function work_root() {
		return $this->ensure_sub( 'work' );
	}

	public function job_dir( $job_id ) {
		if ( ! preg_match( self::JOB_ID_REGEX, (string) $job_id ) ) {
			throw new UserError( 'invalid_job', esc_html__( 'Invalid backup job.', 'backupscope' ) );
		}
		$dir = $this->work_root() . '/' . $job_id;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new UserError( 'storage_unwritable', esc_html__( 'BackupScope could not create its working folder. Please ask your host to allow PHP to write to the uploads folder.', 'backupscope' ) );
		}
		return $dir;
	}

	/** Human label for the UI. Never the full path. */
	public function label() {
		$info = $this->info();
		if ( ! $info ) {
			return '';
		}
		if ( 'constant' === $info['method'] ) {
			return __( 'Custom private folder (BACKUPSCOPE_STORAGE_DIR)', 'backupscope' );
		}
		if ( 'outside' === $info['method'] ) {
			return __( 'Private folder outside your website root', 'backupscope' );
		}
		return 'uploads' === $info['method']
			? __( 'Protected folder in uploads (backupscope)', 'backupscope' )
			: __( 'Protected folder in wp-content', 'backupscope' );
	}

	public function method() {
		$info = $this->info();
		return $info ? $info['method'] : '';
	}

	public function new_archive_name() {
		return 'backupscope-' . wp_date( 'Y-m-d-His' ) . '-' . bin2hex( random_bytes( 16 ) ) . '.zip';
	}

	public static function new_backup_id() {
		return 'b_' . bin2hex( random_bytes( 8 ) );
	}

	public static function new_job_id() {
		return 'j_' . bin2hex( random_bytes( 8 ) );
	}

	/** Free space in bytes, or null when the host does not tell us. */
	public function free_space() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( $this->base() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $free ? null : (int) $free;
	}

	public function save_exposure( array $result ) {
		$info             = $this->info();
		$info['exposure'] = $result;
		update_option( self::OPTION, $info, false );
		$this->info = $info;
	}

	private function ensure_sub( $name ) {
		$dir = $this->base() . '/' . $name;
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				throw new UserError( 'storage_unwritable', esc_html__( 'BackupScope could not create its storage folder. Please ask your host to allow PHP to write to the uploads folder.', 'backupscope' ) );
			}
			self::protect( $dir, false );
		}
		return $dir;
	}

	private function create() {
		$candidates = array();

		if ( defined( 'INSTABACKUP_STORAGE_DIR' ) && INSTABACKUP_STORAGE_DIR ) {
			$dir = Paths::normalize( INSTABACKUP_STORAGE_DIR );
			if ( ! path_is_absolute( $dir ) || Paths::is_within( $dir, ABSPATH ) ) {
				throw new UserError( 'storage_constant_invalid', esc_html__( 'BACKUPSCOPE_STORAGE_DIR must be an absolute path outside your WordPress folder.', 'backupscope' ) );
			}
			$candidates[] = array( $dir, 'constant' );
		} else {
			// Default: a folder named after the plugin in the uploads directory, resolved at runtime.
			// It is protected below (deny-all .htaccess / web.config, index files); backup and job
			// file names are random, and the exposure test warns if the web server still serves it.
			$uploads = wp_upload_dir( null, false );
			if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$candidates[] = array( Paths::normalize( $uploads['basedir'] ) . '/' . self::FOLDER, 'uploads' );
			}
		}

		foreach ( $candidates as $candidate ) {
			list( $dir, $method ) = $candidate;
			if ( ! is_dir( $dir ) && ! @wp_mkdir_p( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}
			if ( ! wp_is_writable( $dir ) ) {
				continue;
			}
			self::protect( $dir, true );
			return array(
				'path'     => Paths::normalize( $dir ),
				'method'   => $method,
				'created'  => time(),
				'exposure' => null,
			);
		}

		throw new UserError( 'storage_unwritable', esc_html__( 'BackupScope could not create a private storage folder. Please ask your host to allow PHP to write to the uploads folder.', 'backupscope' ) );
	}

	/** Defence in depth. Nginx ignores these files; random names and the exposure test cover it. */
	public static function protect( $dir, $root ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);
		if ( $root ) {
			$files['.htaccess']  = "# BackupScope: deny all web access\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\nOptions -Indexes\n";
			$files['web.config'] = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n    <security>\n      <requestFiltering>\n        <hiddenSegments>\n          <add segment=\"backups\" />\n          <add segment=\"work\" />\n        </hiddenSegments>\n      </requestFiltering>\n    </security>\n  </system.webServer>\n</configuration>\n";
		}
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore
			}
		}
	}
}
