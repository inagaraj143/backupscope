<?php
namespace BackupScope\Support;

defined( 'ABSPATH' ) || exit;

final class Paths {

	/** Normalizes slashes and removes a trailing slash (keeps roots like "C:/" and "/"). */
	public static function normalize( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '#/+#', '/', $path );
		if ( strlen( $path ) > 1 && '/' === substr( $path, -1 ) && ! preg_match( '#^[A-Za-z]:/$#', $path ) ) {
			$path = substr( $path, 0, -1 );
		}
		return $path;
	}

	public static function real( $path ) {
		$real = realpath( $path );
		return false === $real ? null : self::normalize( $real );
	}

	/** True when $path is $base or inside it. Both are compared after normalization. */
	public static function is_within( $path, $base ) {
		$path = self::normalize( $path );
		$base = self::normalize( $base );
		if ( self::case_insensitive() ) {
			$path = strtolower( $path );
			$base = strtolower( $base );
		}
		return $path === $base || 0 === strpos( $path, rtrim( $base, '/' ) . '/' );
	}

	/** Relative path of $path under $base, or null when it is not inside. */
	public static function relative( $path, $base ) {
		if ( ! self::is_within( $path, $base ) ) {
			return null;
		}
		$path = self::normalize( $path );
		$base = rtrim( self::normalize( $base ), '/' );
		return ltrim( (string) substr( $path, strlen( $base ) ), '/' );
	}

	public static function case_insensitive() {
		return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
	}

	/** Recursively deletes a directory we own. Never follows symlinks. */
	public static function delete_tree( $dir ) {
		if ( is_link( $dir ) || is_file( $dir ) ) {
			wp_delete_file( $dir );
			return ! file_exists( $dir ) && ! is_link( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		$items = scandir( $dir );
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			self::delete_tree( $dir . '/' . $item );
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an empty folder the plugin created; WP_Filesystem is not initialised here.
	}
}
