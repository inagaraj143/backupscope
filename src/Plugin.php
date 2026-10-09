<?php
namespace BackupScope;

use BackupScope\Admin\AdminPage;
use BackupScope\Admin\Ajax;
use BackupScope\Admin\PluginsScreenNotice;
use BackupScope\Http\DownloadHandler;
use BackupScope\Jobs\JobManager;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together. Free contains the full engine; Pro extends it through the
 * documented hooks and interfaces (see the development plan, section 26).
 */
final class Plugin {

	public static function boot() {
		if ( is_multisite() ) {
			add_action( 'admin_notices', array( __CLASS__, 'multisite_notice' ) );
			return;
		}

		( new AdminPage() )->register();
		( new Ajax() )->register();
		( new DownloadHandler() )->register();
		( new PluginsScreenNotice() )->register();

		// Review and upgrade links, the Pro pages and More Tools: free plugin only. Inside
		// BackupScope Pro the same engine runs without them.
		if ( ! defined( 'INSTABACKUP_PRO_FILE' ) ) {
			( new Admin\Promo() )->register();
		}

		/**
		 * Fires after BackupScope has loaded. Pro hooks in here.
		 *
		 * @param int $engine_api Engine API version.
		 */
		do_action( 'instabackup_loaded', INSTABACKUP_ENGINE_API );
	}

	public static function on_activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'BackupScope does not support Multisite networks yet. Please activate it on a single-site installation.', 'backupscope' ),
				esc_html__( 'Plugin activation', 'backupscope' ),
				array( 'back_link' => true )
			);
		}
		$settings = get_option( 'instabackup_settings' );
		if ( ! is_array( $settings ) ) {
			add_option( 'instabackup_settings', array( 'version' => INSTABACKUP_VERSION, 'schema' => 1 ), '', 'yes' );
		}
	}

	public static function on_deactivate() {
		// Nothing is deleted on deactivation. Stop any running job so the lock is not left behind.
		if ( ! is_multisite() ) {
			( new JobManager() )->abandon_on_deactivate();
		}
	}

	public static function multisite_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'BackupScope does not support Multisite yet, so backups are disabled on this site.', 'backupscope' ) . '</p></div>';
	}
}
