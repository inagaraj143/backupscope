<?php
namespace InstaBackup\Archive;

defined( 'ABSPATH' ) || exit;

/** Already-compressed formats are stored; everything else is deflated (plan §12.3). */
final class CompressionPolicy {

	const STORE_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'mov', 'webm', 'm4v', 'mp3', 'm4a',
		'ogg', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'pdf', 'wpress', 'jar',
	);

	public static function store( $name, $size ) {
		if ( 0 === (int) $size || ! function_exists( 'deflate_init' ) ) {
			return true;
		}
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		return in_array( $ext, self::STORE_EXTENSIONS, true );
	}
}
