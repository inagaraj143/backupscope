<?php
namespace BackupScope\Admin;

use BackupScope\Storage\BackupRepository;
use BackupScope\Storage\StorageManager;
use BackupScope\Support\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Free plugin only: review and upgrade links, the Pro pages and the earned notices.
 * Never registered when the engine runs inside BackupScope Pro (see Plugin::boot).
 *
 * Kept within WordPress.org guideline 11: everything shows on BackupScope's own screens only,
 * nothing is locked, each notice appears after real use, one at a time, and stays dismissed.
 */
final class Promo {

	const PRICING    = 'https://backupscope.pro/pricing';
	const REVIEW_URL = 'https://wordpress.org/support/plugin/backupscope/reviews/#new-post';

	const FEATURES_SLUG = 'backupscope-schedule-restore';
	const UPGRADE_SLUG  = 'backupscope-upgrade';
	const TOOLS_SLUG    = 'backupscope-more-tools';

	/** Successful backups made with the free plugin (site-wide). */
	const COUNT_OPTION = 'instabackup_successful_backups';
	const DISMISSED    = 'instabackup_dismissed_notices';
	const NONCE        = 'backupscope_promo';

	/** Last day of the launch prices shown on the Upgrade page (inclusive, UTC). */
	const LAUNCH_ENDS = '2026-11-10';

	/** Days without a backup after which the screen points out scheduled backups. */
	const STALE_DAYS = 7;

	/** @var string[] */
	private $hooks = array();

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'wp_ajax_backupscope_dismiss_notice', array( $this, 'dismiss' ) );
		add_action( 'instabackup_backup_registered', array( __CLASS__, 'count_backup' ) );
		add_action( 'instabackup_admin_title_actions', array( __CLASS__, 'title_actions' ) );
		add_action( 'instabackup_admin_after_app', array( $this, 'status_card' ) );
		add_filter( 'instabackup_admin_promo', array( __CLASS__, 'script_data' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( INSTABACKUP_FILE ), array( __CLASS__, 'plugin_links' ), 20 );
	}

	/** Pro link with campaign tags, so each placement can be measured. */
	public static function pro_url( $placement, $url = self::PRICING ) {
		return add_query_arg(
			array(
				'utm_source'   => 'wporg-plugin',
				'utm_medium'   => sanitize_key( $placement ),
				'utm_campaign' => 'free-to-pro',
			),
			$url
		);
	}

	public static function page_url( $slug ) {
		return admin_url( 'admin.php?page=' . $slug );
	}

	// ------------------------------------------------------------------ menu and assets

	public function menu() {
		$cap = Capability::name();
		// The main screen keeps its slug; its submenu entry gets a clearer name.
		add_submenu_page( AdminPage::SLUG, __( 'BackupScope', 'backupscope' ), __( 'Backup now', 'backupscope' ), $cap, AdminPage::SLUG );
		// Named for what someone is looking for, with "(Pro)" so it never promises a free feature.
		$this->hooks[] = (string) add_submenu_page( AdminPage::SLUG, __( 'Schedule & Restore', 'backupscope' ), __( 'Schedule & Restore (Pro)', 'backupscope' ), $cap, self::FEATURES_SLUG, array( ProPages::class, 'features' ) );
		$this->hooks[] = (string) add_submenu_page( AdminPage::SLUG, __( 'Upgrade to BackupScope Pro', 'backupscope' ), __( 'Upgrade', 'backupscope' ), $cap, self::UPGRADE_SLUG, array( ProPages::class, 'upgrade' ) );
		$this->hooks[] = (string) add_submenu_page( AdminPage::SLUG, __( 'More Tools', 'backupscope' ), __( 'More Tools', 'backupscope' ), $cap, self::TOOLS_SLUG, array( ProPages::class, 'more_tools' ) );
	}

	/** On every BackupScope screen: the small stylesheet and script for these elements. */
	public function assets( $hook ) {
		if ( ! self::on_our_screen() ) {
			return;
		}
		$ver = INSTABACKUP_VERSION . '.' . (int) filemtime( INSTABACKUP_DIR . 'assets/promo.css' );
		wp_enqueue_style( 'backupscope-promo', plugins_url( 'assets/promo.css', INSTABACKUP_FILE ), array( 'dashicons' ), $ver );
		wp_enqueue_script( 'backupscope-promo', plugins_url( 'assets/promo.js', INSTABACKUP_FILE ), array(), $ver, true );
		wp_add_inline_script(
			'backupscope-promo',
			'window.BackupScopePromo = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE ),
				)
			) . ';',
			'before'
		);
		if ( in_array( $hook, $this->hooks, true ) && current_user_can( 'install_plugins' ) ) {
			add_thickbox(); // More Tools opens WordPress's own plugin-install dialog.
		}
	}

	/** True on BackupScope's own admin screens (and only there). */
	public static function on_our_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen check only.
		return AdminPage::SLUG === $page || 0 === strpos( $page, AdminPage::SLUG . '-' );
	}

	// ------------------------------------------------------------------ header row and Plugins screen

	/**
	 * "Pro · Upgrade" and a quieter "Leave a review", next to each BackupScope page title.
	 * Always there, so a review never depends on catching a one-time notice.
	 */
	public static function title_actions( $placement = 'header' ) {
		echo '<div class="bsp-title-actions">';
		echo '<a class="bsp-title-link bsp-title-link--pro" href="' . esc_url( self::page_url( self::UPGRADE_SLUG ) ) . '"><span class="bsp-badge">' . esc_html__( 'Pro', 'backupscope' ) . '</span>' . esc_html__( 'Upgrade', 'backupscope' ) . '</a>';
		echo '<a class="bsp-title-link" href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span>' . esc_html__( 'Leave a review', 'backupscope' ) . '</a>';
		echo '</div>';
	}

	/** Plugins screen row: Review and Go Pro, after the existing "Backups" link. */
	public static function plugin_links( $links ) {
		$links[] = '<a href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Review', 'backupscope' ) . '</a>';
		$links[] = '<a href="' . esc_url( self::page_url( self::UPGRADE_SLUG ) ) . '" style="color:#2271b1;font-weight:600">' . esc_html__( 'Go Pro', 'backupscope' ) . '</a>';
		return $links;
	}

	// ------------------------------------------------------------------ earned notices

	public static function count_backup() {
		update_option( self::COUNT_OPTION, self::backup_count() + 1, false );
	}

	public static function backup_count() {
		return (int) get_option( self::COUNT_OPTION, 0 );
	}

	/**
	 * Notice key => successful backups before it shows. Highest first, so the most-earned
	 * message wins; one undismissed lower notice can never hide a later one.
	 */
	public static function thresholds() {
		return array(
			'offsite'  => 10,
			'review'   => 5,
			'schedule' => 3,
		);
	}

	private static function dismissed() {
		return (array) get_user_meta( get_current_user_id(), self::DISMISSED, true );
	}

	public function dismiss() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! Capability::check() ) {
			wp_send_json_error( null, 403 );
		}
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( ! isset( self::thresholds()[ $key ] ) ) {
			wp_send_json_error( null, 400 );
		}
		$dismissed   = self::dismissed();
		$dismissed[] = $key;
		update_user_meta( get_current_user_id(), self::DISMISSED, array_values( array_unique( $dismissed ) ) );
		wp_send_json_success();
	}

	/** At most one notice, on BackupScope screens only, after real use. */
	public function notices() {
		if ( ! self::on_our_screen() || ! Capability::check() ) {
			return;
		}
		$count     = self::backup_count();
		$dismissed = self::dismissed();
		foreach ( self::thresholds() as $key => $min ) {
			if ( $count < $min || in_array( $key, $dismissed, true ) ) {
				continue;
			}
			self::render_notice( $key, $count );
			return;
		}
	}

	private static function render_notice( $key, $count ) {
		switch ( $key ) {
			case 'review':
				/* translators: %d: number of backups */
				$body = sprintf( __( 'You have made %d backups with BackupScope. If it has been useful, a short review helps other people find it.', 'backupscope' ), $count );
				$cta  = '<a class="button button-primary" href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Leave a review', 'backupscope' ) . '</a>';
				break;
			case 'offsite':
				/* translators: %d: number of backups */
				$body = sprintf( __( '%d backups so far, all on this server. If the server fails, they go with it. BackupScope Pro copies each backup to S3-compatible storage automatically.', 'backupscope' ), $count );
				$cta  = '<a class="button button-primary" href="' . esc_url( self::page_url( self::UPGRADE_SLUG ) ) . '">' . esc_html__( 'See off-site backups', 'backupscope' ) . '</a>';
				break;
			case 'schedule':
			default:
				/* translators: %d: number of backups */
				$body = sprintf( __( '%d backups, each started by hand. BackupScope Pro runs them for you: daily, weekly or monthly, at a quiet time.', 'backupscope' ), $count );
				$cta  = '<a class="button" href="' . esc_url( self::page_url( self::FEATURES_SLUG ) ) . '">' . esc_html__( 'See scheduled backups', 'backupscope' ) . '</a>';
				break;
		}
		echo '<div class="notice notice-info is-dismissible bsp-notice" data-bsp-notice="' . esc_attr( $key ) . '"><p>' . esc_html( $body ) . '</p><p>' . wp_kses_post( $cta ) . '</p></div>';
	}

	// ------------------------------------------------------------------ in-screen Pro status

	/**
	 * Under the backup screen: what is and is not covered right now, each gap with a Pro link.
	 * Factual for this site (last backup age), never a lock on anything Free does.
	 */
	public function status_card() {
		$latest = null;
		try {
			$latest = ( new BackupRepository( new StorageManager() ) )->current();
		} catch ( \Throwable $e ) {
			$latest = null;
		}
		$age = $latest && ! empty( $latest['created_at'] ) ? (int) floor( ( time() - (int) $latest['created_at'] ) / DAY_IN_SECONDS ) : null;

		echo '<section class="bsp-status" aria-label="' . esc_attr__( 'Backup coverage', 'backupscope' ) . '">';
		echo '<h2>' . esc_html__( 'Backup coverage', 'backupscope' ) . '</h2>';
		if ( null !== $age && $age >= self::STALE_DAYS ) {
			/* translators: %d: number of days */
			echo '<p class="bsp-stale"><span class="dashicons dashicons-clock" aria-hidden="true"></span>' . esc_html( sprintf( _n( 'Your last backup was %d day ago.', 'Your last backup was %d days ago.', $age, 'backupscope' ), $age ) ) . ' ' . esc_html__( 'BackupScope Pro can run them for you on a schedule.', 'backupscope' ) . '</p>';
		}
		echo '<ul class="bsp-coverage">';
		$rows = array(
			array( __( 'Backups', 'backupscope' ), null === $age ? __( 'None yet', 'backupscope' ) : __( 'Manual, on this server', 'backupscope' ), true, '' ),
			array( __( 'Schedule', 'backupscope' ), __( 'Off', 'backupscope' ), false, self::FEATURES_SLUG ),
			array( __( 'Off-site copy', 'backupscope' ), __( 'None', 'backupscope' ), false, self::UPGRADE_SLUG ),
			array( __( 'Restore', 'backupscope' ), __( 'By hand', 'backupscope' ), false, self::FEATURES_SLUG ),
		);
		foreach ( $rows as $r ) {
			echo '<li class="' . ( $r[2] ? 'is-ok' : 'is-gap' ) . '"><span class="bsp-coverage-label">' . esc_html( $r[0] ) . '</span><span class="bsp-coverage-value">' . esc_html( $r[1] ) . '</span>';
			if ( '' !== $r[3] ) {
				echo '<a href="' . esc_url( self::page_url( $r[3] ) ) . '"><span class="bsp-badge">' . esc_html__( 'Pro', 'backupscope' ) . '</span></a>';
			}
			echo '</li>';
		}
		echo '</ul></section>';
	}

	/** Data for admin.js (the restore line on the backup screen). */
	public static function script_data() {
		return array(
			'featuresUrl' => self::page_url( self::FEATURES_SLUG ),
			'restorePro'  => __( 'Restoring from the dashboard, after a safety backup of the current site, is part of BackupScope Pro.', 'backupscope' ),
			'seePro'      => __( 'See Schedule & Restore', 'backupscope' ),
			'doneHint'    => __( 'Need this backup back one day? BackupScope Pro restores it with a safety backup first, and can run backups for you on a schedule.', 'backupscope' ),
		);
	}

	/** Whether the launch prices are still on today. */
	public static function launch_on() {
		return time() < strtotime( self::LAUNCH_ENDS . ' 23:59:59 UTC' );
	}
}
