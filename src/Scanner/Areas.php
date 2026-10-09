<?php
namespace InstaBackup\Scanner;

use InstaBackup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the site's real folders to the backup areas the user can select (plan §5.3) and to
 * archive roots (plan §12.4). Paths come from WordPress constants, never from user input.
 */
final class Areas {

	const IDS = array( 'core', 'plugins', 'themes', 'uploads', 'other_content', 'root_other' );

	const CORE_ROOT_FILES = array(
		'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-blog-header.php',
		'wp-comments-post.php', 'wp-config.php', 'wp-config-sample.php', 'wp-cron.php',
		'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php',
		'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php', '.htaccess', '.user.ini', 'web.config',
	);

	private $abspath;
	private $content;
	private $plugins = array();
	private $themes  = array();
	private $uploads;

	public function __construct() {
		$this->abspath = Paths::real( ABSPATH );
		$this->content = Paths::real( WP_CONTENT_DIR );
		foreach ( array( WP_PLUGIN_DIR, defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' ) as $dir ) {
			$real = $dir ? Paths::real( $dir ) : null;
			if ( $real ) {
				$this->plugins[] = $real;
			}
		}
		$theme_dirs = isset( $GLOBALS['wp_theme_directories'] ) ? (array) $GLOBALS['wp_theme_directories'] : array( get_theme_root() );
		foreach ( $theme_dirs as $dir ) {
			$real = Paths::real( $dir );
			if ( $real ) {
				$this->themes[] = $real;
			}
		}
		$uploads       = wp_upload_dir( null, false );
		$this->uploads = empty( $uploads['basedir'] ) ? null : Paths::real( $uploads['basedir'] );
	}

	public static function labels() {
		return array(
			'core'          => __( 'WordPress core & config', 'backupscope' ),
			'plugins'       => __( 'Plugins', 'backupscope' ),
			'themes'        => __( 'Themes', 'backupscope' ),
			'uploads'       => __( 'Uploads', 'backupscope' ),
			'other_content' => __( 'Other wp-content folders', 'backupscope' ),
			'root_other'    => __( 'Other files in site root', 'backupscope' ),
		);
	}

	public static function hints() {
		return array(
			'core'          => __( 'wp-admin, wp-includes, root files (never wp-config.php)', 'backupscope' ),
			'plugins'       => 'wp-content/plugins, mu-plugins',
			'themes'        => 'wp-content/themes',
			'uploads'       => 'wp-content/uploads',
			'other_content' => __( 'languages, custom folders…', 'backupscope' ),
			'root_other'    => __( 'non-WordPress files and folders', 'backupscope' ),
		);
	}

	public static function sanitize( $areas ) {
		return array_values( array_intersect( self::IDS, array_map( 'sanitize_key', (array) $areas ) ) );
	}

	/**
	 * Archive roots. Folders inside another root are walked as part of it.
	 *
	 * @return array[] Each: path, archive (prefix inside the ZIP), role, type (dir|file).
	 */
	public function roots() {
		$roots      = array(
			array( 'path' => $this->abspath, 'archive' => 'files/wordpress/', 'role' => 'abspath', 'type' => 'dir' ),
		);
		$candidates = array( 'wp-content' => $this->content, 'uploads' => $this->uploads );
		foreach ( $this->plugins as $i => $dir ) {
			$candidates[ 0 === $i ? 'plugins' : 'mu-plugins' ] = $dir;
		}
		foreach ( $this->themes as $i => $dir ) {
			$candidates[ 0 === $i ? 'themes' : 'themes-' . $i ] = $dir;
		}
		foreach ( $candidates as $role => $dir ) {
			if ( ! $dir || $this->inside_any( $dir, $roots ) ) {
				continue;
			}
			$roots[] = array( 'path' => $dir, 'archive' => 'files/external/' . $role . '/', 'role' => $role, 'type' => 'dir' );
		}

		// wp-config.php may live one level above ABSPATH.
		$above = Paths::normalize( dirname( $this->abspath ) ) . '/wp-config.php';
		if ( ! is_file( $this->abspath . '/wp-config.php' ) && is_file( $above ) && ! is_file( dirname( $above ) . '/wp-settings.php' ) ) {
			$roots[] = array( 'path' => $above, 'archive' => 'files/external/wp-config/wp-config.php', 'role' => 'wp-config', 'type' => 'file' );
		}
		return $roots;
	}

	/** Area of a file or folder, or null when it is outside every known root. */
	public function classify( $path, $is_dir ) {
		if ( $this->uploads && Paths::is_within( $path, $this->uploads ) ) {
			return 'uploads';
		}
		foreach ( $this->plugins as $dir ) {
			if ( Paths::is_within( $path, $dir ) ) {
				return 'plugins';
			}
		}
		foreach ( $this->themes as $dir ) {
			if ( Paths::is_within( $path, $dir ) ) {
				return 'themes';
			}
		}
		if ( $this->content && Paths::is_within( $path, $this->content ) ) {
			return 'other_content';
		}
		if ( 'wp-config.php' === basename( $path ) && Paths::normalize( dirname( $path ) ) === Paths::normalize( dirname( $this->abspath ) ) ) {
			return 'core';
		}
		$rel = Paths::relative( $path, $this->abspath );
		if ( null === $rel ) {
			return null;
		}
		if ( '' === $rel ) {
			return 'root_other';
		}
		$first = explode( '/', $rel, 2 )[0];
		if ( 'wp-admin' === $first || 'wp-includes' === $first ) {
			return 'core';
		}
		if ( false === strpos( $rel, '/' ) && ! $is_dir && in_array( strtolower( $rel ), self::CORE_ROOT_FILES, true ) ) {
			return 'core';
		}
		return 'root_other';
	}

	/** Folders that must be walked for each area, used to decide whether to descend. */
	public function anchors( array $areas ) {
		$map     = array(
			'core'          => array( $this->abspath ),
			'plugins'       => $this->plugins,
			'themes'        => $this->themes,
			'uploads'       => $this->uploads ? array( $this->uploads ) : array(),
			'other_content' => $this->content ? array( $this->content ) : array(),
			'root_other'    => array( $this->abspath ),
		);
		$anchors = array();
		foreach ( $areas as $area ) {
			$anchors = array_merge( $anchors, $map[ $area ] );
		}
		return array_values( array_unique( $anchors ) );
	}

	/** Folder used to group a file in the summary: the area's base folder plus one level. */
	public function group_dir( $area, $path ) {
		$bases = array(
			'uploads'       => $this->uploads ? array( $this->uploads ) : array(),
			'plugins'       => $this->plugins,
			'themes'        => $this->themes,
			'other_content' => array( $this->content ),
			'core'          => array( $this->abspath ),
			'root_other'    => array( $this->abspath ),
		);
		foreach ( $bases[ $area ] as $base ) {
			$rel = $base ? Paths::relative( $path, $base ) : null;
			if ( null !== $rel ) {
				$first = explode( '/', $rel, 2 );
				return count( $first ) > 1 ? $base . '/' . $first[0] : $base;
			}
		}
		return Paths::normalize( dirname( $path ) );
	}

	public function abspath() {
		return $this->abspath;
	}

	public function content_dir() {
		return $this->content;
	}

	public function uploads_dir() {
		return $this->uploads;
	}

	private function inside_any( $dir, array $roots ) {
		foreach ( $roots as $root ) {
			if ( Paths::is_within( $dir, $root['path'] ) ) {
				return true;
			}
		}
		return false;
	}
}
