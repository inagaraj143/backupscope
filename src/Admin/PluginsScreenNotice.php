<?php
namespace InstaBackup\Admin;

use InstaBackup\Storage\BackupRepository;
use InstaBackup\Storage\StorageManager;
use InstaBackup\Support\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress cannot show a custom confirmation when a plugin is deleted, so the Plugins screen
 * says up front that deleting BackupScope deletes the stored backup (plan §28).
 */
final class PluginsScreenNotice {

	public function register() {
		add_action( 'after_plugin_row_' . plugin_basename( INSTABACKUP_FILE ), array( $this, 'row' ), 10, 0 );
		add_filter( 'plugin_action_links_' . plugin_basename( INSTABACKUP_FILE ), array( $this, 'links' ) );
	}

	public function links( $links ) {
		if ( Capability::check() ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=backupscope' ) ) . '">' . esc_html__( 'Backups', 'backupscope' ) . '</a>' );
		}
		return $links;
	}

	public function row() {
		if ( ! Capability::check() || ( defined( 'INSTABACKUP_KEEP_BACKUPS_ON_UNINSTALL' ) && INSTABACKUP_KEEP_BACKUPS_ON_UNINSTALL ) ) {
			return;
		}
		$storage = new StorageManager();
		if ( ! $storage->info() ) {
			return;
		}
		$bytes = ( new BackupRepository( $storage ) )->total_bytes();
		if ( $bytes <= 0 ) {
			return;
		}
		printf(
			'<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>%s</p></div></td></tr>',
			esc_html(
				sprintf(
					/* translators: %s: total size of stored backups */
					__( 'Deleting BackupScope also deletes your stored backups (%s). Download them first if you want to keep them.', 'backupscope' ),
					size_format( $bytes, 1 )
				)
			)
		);
	}
}
