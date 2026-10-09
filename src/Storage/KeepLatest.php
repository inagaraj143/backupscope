<?php
namespace BackupScope\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Free policy: keep the newest successful backup. Only deletes backups created by the Free
 * edition, so backups made by Pro are never removed by Free.
 */
final class KeepLatest implements RetentionPolicyInterface {

	public function to_delete( array $backups, array $new ) {
		$ids = array();
		foreach ( $backups as $backup ) {
			if ( $backup['id'] === $new['id'] ) {
				continue;
			}
			$edition = isset( $backup['created_by']['edition'] ) ? $backup['created_by']['edition'] : 'free';
			if ( 'free' === $edition && empty( $backup['locked'] ) ) {
				$ids[] = $backup['id'];
			}
		}
		return $ids;
	}
}
