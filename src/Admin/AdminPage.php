<?php
namespace InstaBackup\Admin;

use InstaBackup\Jobs\JobManager;
use InstaBackup\Jobs\Steps\ArchiveStep;
use InstaBackup\Scanner\Areas;
use InstaBackup\Support\Capability;

defined( 'ABSPATH' ) || exit;

/** The single BackupScope admin page (plan §23). Assets load only here. */
final class AdminPage {

	const SLUG = 'backupscope';

	private $hook = '';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		$this->hook = add_menu_page(
			__( 'BackupScope', 'backupscope' ),
			__( 'BackupScope', 'backupscope' ),
			Capability::name(),
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-backup',
			80
		);
	}

	public function assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		// Version by file time so browsers never keep an outdated screen after an update.
		$css = INSTABACKUP_VERSION . '.' . (int) filemtime( INSTABACKUP_DIR . 'assets/admin.css' );
		$js  = INSTABACKUP_VERSION . '.' . (int) filemtime( INSTABACKUP_DIR . 'assets/admin.js' );
		wp_enqueue_style( 'instabackup-admin', plugins_url( 'assets/admin.css', INSTABACKUP_FILE ), array( 'dashicons' ), $css );
		wp_enqueue_script( 'instabackup-admin', plugins_url( 'assets/admin.js', INSTABACKUP_FILE ), array(), $js, true );

		$initial = array();
		try {
			$initial = ( new Status( new JobManager() ) )->build();
		} catch ( \Throwable $e ) {
			$initial = array( 'job' => null, 'backup' => null, 'others' => array( 'count' => 0, 'bytes' => 0 ), 'storage' => array( 'label' => '', 'exposed' => null, 'method' => '' ), 'error' => $e->getMessage() );
		}

		$areas = array();
		foreach ( Areas::labels() as $id => $label ) {
			$areas[] = array( 'id' => $id, 'label' => $label, 'hint' => Areas::hints()[ $id ] );
		}

		wp_add_inline_script(
			'instabackup-admin',
			'window.BackupScope = ' . wp_json_encode(
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( Ajax::NONCE ),
					'status'      => $initial,
					'areas'       => $areas,
					'threshold'   => ArchiveStep::threshold(),
					'restoreHelp' => 'https://backupscope.pro/docs/restore',
					'keepOnUninstall' => defined( 'INSTABACKUP_KEEP_BACKUPS_ON_UNINSTALL' ) && INSTABACKUP_KEEP_BACKUPS_ON_UNINSTALL,
					'i18n'        => $this->strings(),
				)
			) . ';',
			'before'
		);
	}

	public function render() {
		if ( ! Capability::check() ) {
			wp_die( esc_html__( 'You are not allowed to manage backups.', 'backupscope' ) );
		}
		echo '<div class="wrap instabackup-wrap">';
		echo '<h1>' . esc_html__( 'BackupScope', 'backupscope' ) . '</h1>';
		echo '<div id="instabackup-app" aria-live="polite"><p>' . esc_html__( 'Loading…', 'backupscope' ) . '</p></div>';
		echo '<noscript><div class="notice notice-error"><p>' . esc_html__( 'BackupScope needs JavaScript to run backups.', 'backupscope' ) . '</p></div></noscript>';
		echo '</div>';
	}

	private function strings() {
		return array(
			'intro'            => __( 'Back up your WordPress files and database as a downloadable ZIP.', 'backupscope' ),
			'full'             => __( 'Full Backup', 'backupscope' ),
			'fullHint'         => __( 'Files + database (recommended)', 'backupscope' ),
			'custom'           => __( 'Custom Backup', 'backupscope' ),
			'customHint'       => __( 'Choose what to include', 'backupscope' ),
			'database'         => __( 'Database', 'backupscope' ),
			'databaseHint'     => __( 'posts, pages, settings, users', 'backupscope' ),
			'scan'             => __( 'Scan Site', 'backupscope' ),
			'noRestore'        => __( 'Restore is not included in the Free version.', 'backupscope' ),
			'restoreLink'      => __( 'How to restore manually', 'backupscope' ),
			'selectOne'        => __( 'Select at least one item to back up.', 'backupscope' ),
			'scanning'         => __( 'Scanning your site…', 'backupscope' ),
			/* translators: 1: number of files, 2: size */
			'found'            => __( '%1$s files · %2$s found', 'backupscope' ),
			'scanComplete'     => __( 'Scan Complete', 'backupscope' ),
			'files'            => __( 'Files', 'backupscope' ),
			/* translators: 1: number of files, 2: size */
			'filesCount'       => __( '%1$s files · %2$s', 'backupscope' ),
			/* translators: 1: number of tables, 2: size */
			'dbCount'          => __( '%1$s tables · ~%2$s', 'backupscope' ),
			/* translators: %s: size */
			'estimate'         => __( 'Estimated backup size: up to ~%s', 'backupscope' ),
			/* translators: %s: free disk space */
			'diskOk'           => __( 'Disk space: OK (~%s free)', 'backupscope' ),
			'diskUnknown'      => __( 'Free space could not be checked on this server.', 'backupscope' ),
			/* translators: %s: number of items */
			'excluded'         => __( 'Excluded: %s items', 'backupscope' ),
			/* translators: %s: file name(s), e.g. wp-config.php */
			'secretNote'       => __( 'Not included, for security: %s. It holds your site’s security keys, so BackupScope never puts it in a backup. When you restore, keep your existing wp-config.php.', 'backupscope' ),
			'view'             => __( 'view', 'backupscope' ),
			/* translators: %s: folder path */
			'nested'           => __( 'Other WordPress installation found: %s (excluded)', 'backupscope' ),
			/* translators: %s: number of items */
			'unreadable'       => __( '%s items could not be read and will be skipped', 'backupscope' ),
			'batchedNote'      => __( 'This is a large backup, so it will be created step by step.', 'backupscope' ),
			'createBackup'     => __( 'Create Backup', 'backupscope' ),
			'changeSelection'  => __( 'Change selection', 'backupscope' ),
			'creating'         => __( 'Creating backup…', 'backupscope' ),
			'keepOpen'         => __( 'Keep this page open. If you close it, you can resume later.', 'backupscope' ),
			'cancelBackup'     => __( 'Cancel backup', 'backupscope' ),
			'cancelling'       => __( 'Cancelling…', 'backupscope' ),
			'confirmCancel'    => __( 'Cancel this backup? Temporary files will be removed. Your current backup is not affected.', 'backupscope' ),
			/* translators: 1: tables done, 2: total tables */
			'tables'           => __( '%1$s / %2$s tables', 'backupscope' ),
			/* translators: 1: files done, 2: total files, 3: size done, 4: total size */
			'filesProgress'    => __( '%1$s / %2$s files · %3$s / %4$s', 'backupscope' ),
			/* translators: 1: size checked, 2: total size */
			'verifyProgress'   => __( '%1$s / %2$s checked', 'backupscope' ),
			'zipWorking'       => __( 'Creating ZIP archive… (this can take several minutes)', 'backupscope' ),
			/* translators: %s: percentage */
			'zipFraction'      => __( 'Writing ZIP archive… %s', 'backupscope' ),
			'fallback'         => __( 'Switching to step-by-step mode for this server…', 'backupscope' ),
			/* translators: %s: elapsed time */
			'elapsed'          => __( 'Elapsed: %s', 'backupscope' ),
			'skippedStep'      => __( 'not selected', 'backupscope' ),
			'complete'         => __( 'Backup Complete', 'backupscope' ),
			/* translators: %s: number of files */
			'completeWarn'     => __( 'Backup completed with %s skipped files', 'backupscope' ),
			'review'           => __( 'review', 'backupscope' ),
			'currentBackup'    => __( 'Current backup', 'backupscope' ),
			'created'          => __( 'Created', 'backupscope' ),
			'contents'         => __( 'Contents', 'backupscope' ),
			'size'             => __( 'Size', 'backupscope' ),
			'verified'         => __( 'Verified', 'backupscope' ),
			'filesAndDb'       => __( 'Files + database', 'backupscope' ),
			'filesOnly'        => __( 'Files only', 'backupscope' ),
			'dbOnly'           => __( 'Database only', 'backupscope' ),
			'download'         => __( 'Download Backup', 'backupscope' ),
			'delete'           => __( 'Delete Backup', 'backupscope' ),
			'confirmDelete'    => __( 'Delete this backup permanently?', 'backupscope' ),
			'uninstallNote'    => __( 'Backups are deleted when BackupScope is deleted. Download your backup first if you want to keep it.', 'backupscope' ),
			/* translators: 1: number of backups, 2: total size */
			'others'           => __( '%1$s more backups created by BackupScope Pro (%2$s) are stored on this server.', 'backupscope' ),
			'newBackup'        => __( 'Create a new backup', 'backupscope' ),
			'newBackupNote'    => __( 'Your current backup is replaced only after the new one is created and verified.', 'backupscope' ),
			'failed'           => __( 'Backup Failed', 'backupscope' ),
			'previousSafe'     => __( 'Your previous backup is still available.', 'backupscope' ),
			'tryAgain'         => __( 'Try again', 'backupscope' ),
			'downloadLog'      => __( 'Download log', 'backupscope' ),
			'interrupted'      => __( 'A backup was interrupted.', 'backupscope' ),
			'resume'           => __( 'Resume backup', 'backupscope' ),
			'cancelCleanup'    => __( 'Cancel and clean up', 'backupscope' ),
			'connectionLost'   => __( 'Lost connection to the server. The backup is paused.', 'backupscope' ),
			'retry'            => __( 'Retry', 'backupscope' ),
			'exposed'          => __( 'Your backups folder can be reached from the internet on this server. Backup file names are random and hard to guess, but for full protection define BACKUPSCOPE_STORAGE_DIR in wp-config.php (a folder outside your website), or block the folder in your Nginx configuration.', 'backupscope' ),
			/* translators: %s: storage location description */
			'storedIn'         => __( 'Stored in: %s', 'backupscope' ),
			'done'             => __( 'Done', 'backupscope' ),
			'skippedTitle'     => __( 'Skipped files', 'backupscope' ),
			'nowProcessing'    => __( 'Now processing:', 'backupscope' ),
			'chooseContents'   => __( 'Choose what to include', 'backupscope' ),
			'chooseHint'       => __( 'Everything is included. Untick any folder or the database to leave it out; the estimate updates as you go.', 'backupscope' ),
			'selected'         => __( 'Selected:', 'backupscope' ),
			'diskFree'         => __( 'Free disk space', 'backupscope' ),
			'notEnough'        => __( 'Not enough', 'backupscope' ),
			'ok'               => __( 'OK', 'backupscope' ),
			'unknown'          => __( 'Could not be checked', 'backupscope' ),
			'autoExcluded'     => __( 'Excluded automatically', 'backupscope' ),
			'hide'             => __( 'hide', 'backupscope' ),
			'preview'          => __( 'What will be in your backup', 'backupscope' ),
			'viewContents'     => __( 'View backup contents', 'backupscope' ),
			'rootFiles'        => __( '(files in site root)', 'backupscope' ),
			/* translators: %s: number of files */
			'filesN'           => __( '%s files', 'backupscope' ),
			/* translators: %s: number of tables */
			'tablesCount'      => __( '%s tables', 'backupscope' ),
			/* translators: %s: number of rows */
			'rowsCount'        => __( '%s rows', 'backupscope' ),
			'reasons'          => array(
				'symlink'               => __( 'symlink (not followed)', 'backupscope' ),
				'unreadable'            => __( 'could not be read', 'backupscope' ),
				'permission_denied'     => __( 'permission denied', 'backupscope' ),
				'vanished'              => __( 'deleted during backup', 'backupscope' ),
				'changed_during_backup' => __( 'changed during backup', 'backupscope' ),
				'timeout'               => __( 'too slow to read on this server', 'backupscope' ),
				'backup_plugin'         => __( 'another backup plugin', 'backupscope' ),
				'cache'                 => __( 'cache', 'backupscope' ),
				'temporary'             => __( 'temporary files', 'backupscope' ),
				'vcs_tooling'           => __( 'development files', 'backupscope' ),
				'nested_install'        => __( 'other WordPress installation', 'backupscope' ),
				'instabackup'           => __( 'BackupScope storage', 'backupscope' ),
				'custom'                => __( 'excluded by a filter', 'backupscope' ),
				'server_files'          => __( 'server verification files', 'backupscope' ),
				'security_keys'         => __( 'holds your security keys: never backed up', 'backupscope' ),
			),
		);
	}
}
