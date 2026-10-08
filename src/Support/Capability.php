<?php
namespace InstaBackup\Support;

defined( 'ABSPATH' ) || exit;

final class Capability {

	public static function name() {
		/**
		 * Filters the capability required for every BackupScope action.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return (string) apply_filters( 'instabackup_capability', 'manage_options' );
	}

	public static function check() {
		return current_user_can( self::name() );
	}
}
