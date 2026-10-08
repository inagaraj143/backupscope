<?php
namespace InstaBackup\Support;

defined( 'ABSPATH' ) || exit;

/** Atomic JSON file read/write (temp file + rename). */
final class JsonFile {

	public static function read( $file ) {
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : null;
	}

	public static function write( $file, array $data ) {
		$tmp  = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( false === $json || false === @file_put_contents( $tmp, $json ) ) { // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore
			// Windows cannot rename over an existing file in some PHP versions.
			@unlink( $file ); // phpcs:ignore
			if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore
				@unlink( $tmp ); // phpcs:ignore
				return false;
			}
		}
		return true;
	}
}
