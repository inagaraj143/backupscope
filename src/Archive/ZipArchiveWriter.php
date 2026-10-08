<?php
namespace InstaBackup\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Single ZIP path (plan §12.2): adds every file, then one close() in a single request.
 * libzip does the real work inside close(), so the job engine records that this request
 * started; if it dies, the job falls back to the batched path (plan §11.4).
 */
final class ZipArchiveWriter {

	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * @param string   $file     Target archive (must not exist).
	 * @param iterable $entries  Each: array( name, path, store ).
	 * @param callable $progress Receives a 0..1 float while libzip writes (PHP 8+ only).
	 * @return array{ok:bool, entries:int, error:string}
	 */
	public function build( $file, $entries, $progress = null ) {
		$zip  = new \ZipArchive();
		$open = $zip->open( $file, \ZipArchive::CREATE | \ZipArchive::EXCL );
		if ( true !== $open ) {
			return array( 'ok' => false, 'entries' => 0, 'error' => 'open failed: ' . $open );
		}
		$count = 0;
		foreach ( $entries as $entry ) {
			list( $name, $path, $store ) = $entry;
			// An entry with a fourth element is data held in memory (e.g. wp-config.php without its keys).
			$added = isset( $entry[3] ) ? $zip->addFromString( $name, $entry[3] ) : $zip->addFile( $path, $name );
			if ( ! $added ) {
				continue;
			}
			$zip->setCompressionIndex( $zip->numFiles - 1, $store ? \ZipArchive::CM_STORE : \ZipArchive::CM_DEFLATE );
			$count++;
		}
		if ( $progress && method_exists( $zip, 'registerProgressCallback' ) ) {
			$zip->registerProgressCallback( 0.02, $progress );
		}
		$ok = @$zip->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $ok ) {
			@unlink( $file ); // phpcs:ignore
			return array( 'ok' => false, 'entries' => $count, 'error' => 'close failed: ' . $zip->getStatusString() );
		}
		return array( 'ok' => true, 'entries' => $count, 'error' => '' );
	}
}
