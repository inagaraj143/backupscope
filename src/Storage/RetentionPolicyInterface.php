<?php
namespace InstaBackup\Storage;

defined( 'ABSPATH' ) || exit;

/** Decides which backups to delete after a new backup has been verified and registered. */
interface RetentionPolicyInterface {

	/**
	 * @param array[] $backups All backups (sidecar data), newest first.
	 * @param array   $new     The backup that was just registered.
	 * @return string[] Backup IDs to delete.
	 */
	public function to_delete( array $backups, array $new );
}
