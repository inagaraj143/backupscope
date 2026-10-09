<?php
namespace BackupScope\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Job log inside the private working directory. Paths outside the site are redacted and
 * nothing that looks like a credential is ever written.
 */
final class Logger {

	private $file;

	public function __construct( $file ) {
		$this->file = $file;
	}

	public function info( $message, array $context = array() ) {
		$this->write( 'INFO', $message, $context );
	}

	public function warning( $message, array $context = array() ) {
		$this->write( 'WARN', $message, $context );
	}

	public function error( $message, array $context = array() ) {
		$this->write( 'ERROR', $message, $context );
	}

	private function write( $level, $message, array $context ) {
		$line = gmdate( 'Y-m-d H:i:s' ) . " [$level] " . self::redact( $message );
		if ( $context ) {
			$line .= ' ' . self::redact( (string) wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		@file_put_contents( $this->file, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
	}

	public static function redact( $text ) {
		$text  = (string) $text;
		$roots = array( Paths::normalize( ABSPATH ), Paths::normalize( dirname( ABSPATH ) ) );
		foreach ( $roots as $i => $root ) {
			$text = str_ireplace( array( $root, str_replace( '/', '\\', $root ) ), 0 === $i ? '[site]' : '[site-parent]', $text );
		}
		// Database credentials only. Authentication keys and salts are never read by BackupScope,
		// so they cannot appear in a log.
		foreach ( array( 'DB_PASSWORD', 'DB_USER', 'DB_HOST' ) as $const ) {
			if ( defined( $const ) && '' !== (string) constant( $const ) && strlen( (string) constant( $const ) ) > 3 ) {
				$text = str_replace( (string) constant( $const ), '[redacted]', $text );
			}
		}
		return $text;
	}
}
