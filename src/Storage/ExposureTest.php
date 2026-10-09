<?php
namespace BackupScope\Storage;

use BackupScope\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Checks whether the backups folder can be downloaded from the internet (plan §16.3).
 * Only makes a loopback request to this site.
 */
final class ExposureTest {

	private $storage;

	public function __construct( StorageManager $storage ) {
		$this->storage = $storage;
	}

	/** @return array{checked:int, exposed:bool|null, reason:string} */
	public function run() {
		$dir = $this->storage->backups_dir();
		$url = $this->public_url_for( $dir );
		if ( null === $url ) {
			$result = array( 'checked' => time(), 'exposed' => false, 'reason' => 'no_public_url' );
			$this->storage->save_exposure( $result );
			return $result;
		}

		$token  = bin2hex( random_bytes( 16 ) );
		$name   = 'ib-canary-' . bin2hex( random_bytes( 8 ) ) . '.txt';
		$canary = $dir . '/' . $name;
		if ( false === @file_put_contents( $canary, $token ) ) { // phpcs:ignore
			return array( 'checked' => time(), 'exposed' => null, 'reason' => 'canary_failed' );
		}

		$response = wp_remote_get(
			$url . '/' . $name,
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		@unlink( $canary ); // phpcs:ignore

		if ( is_wp_error( $response ) ) {
			$result = array( 'checked' => time(), 'exposed' => null, 'reason' => 'loopback_failed' );
		} else {
			$exposed = 200 === (int) wp_remote_retrieve_response_code( $response )
				&& false !== strpos( (string) wp_remote_retrieve_body( $response ), $token );
			$result  = array( 'checked' => time(), 'exposed' => $exposed, 'reason' => $exposed ? 'served' : 'blocked' );
		}
		$this->storage->save_exposure( $result );
		return $result;
	}

	private function public_url_for( $dir ) {
		$content = Paths::relative( $dir, WP_CONTENT_DIR );
		if ( null !== $content ) {
			return untrailingslashit( content_url( $content ) );
		}
		$site = Paths::relative( $dir, ABSPATH );
		if ( null !== $site ) {
			return untrailingslashit( site_url( $site ) );
		}
		return null;
	}
}
