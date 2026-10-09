<?php
namespace BackupScope\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Files that hold the site's authentication keys and salts. They never go into a backup.
 *
 * - Every wp-config.php is left out, wherever it is. It is not edited or sanitized: a copy
 *   without keys cannot be guaranteed (keys can be commented out, split up or built in code).
 * - Any other PHP file directly in the WordPress folder, or next to a wp-config.php that lives
 *   one level up, is left out when its text mentions one of the key names (for example a
 *   separate salts file that wp-config.php includes). This is a plain text search: no key value
 *   is ever read, evaluated or copied.
 *
 * The scan shows these files under "Excluded automatically"; the archive step checks again, and
 * the manifest lists them under files.excluded. A site restores normally: keep the existing
 * wp-config.php, or create one from wp-config-sample.php with new keys.
 */
final class SecretFiles {

	/** Reason recorded for every file left out by this rule. */
	const REASON = 'security_keys';

	/** Names of the eight authentication keys and salts. Only the names are used. */
	const KEY_NAMES = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );

	/** Root-level PHP files larger than this are not configuration files and are not searched. */
	const SCAN_MAX = 1048576;

	/**
	 * @param string $path       Absolute path of the file.
	 * @param bool   $root_level True when the file is directly in the WordPress folder, or next
	 *                           to a wp-config.php one level above it.
	 * @return bool True when the file must stay out of the backup.
	 */
	public static function is_secret( $path, $root_level ) {
		$base = strtolower( basename( (string) $path ) );
		if ( 'wp-config.php' === $base ) {
			return true;
		}
		if ( ! $root_level || ! preg_match( '/\.(php\d?|phtml|inc)$/', $base ) ) {
			return false;
		}
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size <= 0 || $size > self::SCAN_MAX || ! is_readable( $path ) ) {
			return false;
		}
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- searched for key names only; never written anywhere.
		foreach ( self::KEY_NAMES as $name ) {
			if ( false !== stripos( $text, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/** True for a file directly in the WordPress folder or in the folder above it. */
	public static function is_root_level( $path ) {
		$dir = Paths::normalize( dirname( (string) $path ) );
		return Paths::normalize( ABSPATH ) === $dir || Paths::normalize( dirname( ABSPATH ) ) === $dir;
	}
}
