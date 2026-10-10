<?php
namespace BackupScope\Admin;

use BackupScope\Support\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Free plugin only: "Schedule & Restore (Pro)", "Upgrade" and "More Tools". They describe what
 * BackupScope Pro and the author's other plugins do; nothing in the free plugin depends on them.
 */
final class ProPages {

	private static function open( $title, $placement, $class = '' ) {
		if ( ! Capability::check() ) {
			wp_die( esc_html__( 'You are not allowed to manage backups.', 'backupscope' ) );
		}
		echo '<div class="wrap bsp-wrap' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '"><div class="bsp-title-row"><h1>' . esc_html( $title ) . '</h1>';
		Promo::title_actions( $placement );
		echo '</div>';
	}

	private static function cta( $placement, $label = '' ) {
		echo '<a class="button button-primary button-hero" href="' . esc_url( Promo::pro_url( $placement ) ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $label ? $label : __( 'See pricing and plans', 'backupscope' ) ) . '</a>';
	}

	/** Pro capabilities: title, short lead, details. */
	private static function capabilities() {
		return array(
			'schedule' => array(
				'icon'  => 'dashicons-calendar-alt',
				'title' => __( 'Scheduled backups', 'backupscope' ),
				'lead'  => __( 'Backups that run without you.', 'backupscope' ),
				'items' => array(
					__( 'Daily, weekly or monthly, at a quiet time in your site’s time zone.', 'backupscope' ),
					__( 'Full backup or your own selection of folders and the database.', 'backupscope' ),
					__( 'A missed run is noticed and reported, and can run when the site is next visited.', 'backupscope' ),
				),
			),
			'restore' => array(
				'icon'  => 'dashicons-backup',
				'title' => __( 'Restore with a safety net', 'backupscope' ),
				'lead'  => __( 'Back to a known good state from the dashboard.', 'backupscope' ),
				'items' => array(
					__( 'Restore everything, only the files or only the database.', 'backupscope' ),
					__( 'The current site is backed up first, so you can always go back.', 'backupscope' ),
					__( 'The database is switched over in one step at the end; the site keeps working until then.', 'backupscope' ),
				),
			),
			'offsite' => array(
				'icon'  => 'dashicons-cloud-upload',
				'title' => __( 'Off-site copies', 'backupscope' ),
				'lead'  => __( 'A copy that survives the server.', 'backupscope' ),
				'items' => array(
					__( 'Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces and other S3-compatible storage.', 'backupscope' ),
					__( 'Large backups upload in parts that resume after an interruption.', 'backupscope' ),
					__( 'Keep or remove the copy on this server after upload.', 'backupscope' ),
				),
			),
			'history' => array(
				'icon'  => 'dashicons-list-view',
				'title' => __( 'History and retention', 'backupscope' ),
				'lead'  => __( 'More than the latest backup.', 'backupscope' ),
				'items' => array(
					__( 'Every backup in one list: download, lock or delete.', 'backupscope' ),
					__( 'Keep the last N backups and/or the last N days.', 'backupscope' ),
					__( 'Locked backups and the newest good backup are never removed.', 'backupscope' ),
				),
			),
			'email' => array(
				'icon'  => 'dashicons-email-alt',
				'title' => __( 'Email notifications', 'backupscope' ),
				'lead'  => __( 'Know when something needs your attention.', 'backupscope' ),
				'items' => array(
					__( 'An email when a backup fails, a schedule is missed, an off-site copy fails or a backup completes.', 'backupscope' ),
					__( 'Editable email templates with a live preview and a test email; works with your SMTP plugin.', 'backupscope' ),
					__( 'Optional secure download link: password-protected, expiring, limited downloads. Backups are never attached.', 'backupscope' ),
				),
			),
			'storage' => array(
				'icon'  => 'dashicons-chart-pie',
				'title' => __( 'Storage analytics and monitoring', 'backupscope' ),
				'lead'  => __( 'See what uses your disk space, and know before it runs out.', 'backupscope' ),
				'items' => array(
					__( 'Media, plugins, themes, folders and database tables, with the largest files.', 'backupscope' ),
					__( 'Automatic analysis daily, weekly or monthly, with growth over time.', 'backupscope' ),
					__( 'Email alerts before the server disk or your hosting plan fills up, plus an optional storage summary email.', 'backupscope' ),
				),
			),
			'cleanup' => array(
				'icon'  => 'dashicons-trash',
				'title' => __( 'Media review and safe cleanup', 'backupscope' ),
				'lead'  => __( 'Free space without guessing.', 'backupscope' ),
				'items' => array(
					__( 'Media with no detected references, with previews.', 'backupscope' ),
					__( 'Inactive plugins and themes, old archives, cache and large logs.', 'backupscope' ),
					__( 'Everything goes to a Cleanup Trash first and can be restored for 30 days.', 'backupscope' ),
				),
			),
			'engine'  => array(
				'icon'  => 'dashicons-database',
				'title' => __( 'Everything in Free, and more', 'backupscope' ),
				'lead'  => __( 'The same reliable backup engine.', 'backupscope' ),
				'items' => array(
					__( 'Full and custom backups, built for large sites and verified before they count.', 'backupscope' ),
					__( 'Works on its own: it replaces this plugin and keeps your backups and settings.', 'backupscope' ),
					__( 'Background backups that keep running after you close the page.', 'backupscope' ),
				),
			),
			'support' => array(
				'icon'  => 'dashicons-sos',
				'title' => __( 'Updates and support', 'backupscope' ),
				'lead'  => __( 'Looked after for as long as your licence runs.', 'backupscope' ),
				'items' => array(
					__( 'Updates install from your WordPress dashboard, like any other plugin.', 'backupscope' ),
					__( 'Email support from the developer.', 'backupscope' ),
					__( 'If you do not renew, nothing is deleted or locked: your backups stay downloadable.', 'backupscope' ),
				),
			),
		);
	}

	private static function cards( array $keys ) {
		$all = self::capabilities();
		echo '<div class="bsp-cards">';
		foreach ( $keys as $key ) {
			$c = $all[ $key ];
			echo '<section class="bsp-card"><div class="bsp-card-head"><span class="dashicons ' . esc_attr( $c['icon'] ) . '" aria-hidden="true"></span><div><h2>' . esc_html( $c['title'] ) . '</h2><p class="bsp-lead">' . esc_html( $c['lead'] ) . '</p></div></div><ul>';
			foreach ( $c['items'] as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}
			echo '</ul></section>';
		}
		echo '</div>';
	}

	// ------------------------------------------------------------------ Schedule & Restore (Pro)

	public static function features() {
		self::open( __( 'Schedule & Restore', 'backupscope' ), 'features-header' );
		echo '<div class="bsp-intro"><span class="bsp-badge">' . esc_html__( 'Pro', 'backupscope' ) . '</span><p>' . esc_html__( 'Scheduled backups and restore are part of BackupScope Pro. This page explains what they do. The free plugin keeps making full, verified backups whether or not you upgrade.', 'backupscope' ) . '</p></div>';
		self::cards( array( 'schedule', 'restore', 'email' ) );
		echo '<section class="bsp-panel"><h2>' . esc_html__( 'What you can do today with the free plugin', 'backupscope' ) . '</h2>';
		echo '<p>' . esc_html__( 'Make a backup by hand from the Backup now screen and download it. Every backup is a standard ZIP with your files and a database file, so you can restore it by hand with the guide.', 'backupscope' ) . ' <a href="https://backupscope.pro/docs/restore" target="_blank" rel="noopener">' . esc_html__( 'Read the restore guide', 'backupscope' ) . ' →</a></p></section>';
		echo '<div class="bsp-cta-row">';
		self::cta( 'features-page', __( 'See BackupScope Pro', 'backupscope' ) );
		echo ' <a class="button button-hero" href="' . esc_url( Promo::page_url( Promo::UPGRADE_SLUG ) ) . '">' . esc_html__( 'Everything Pro adds', 'backupscope' ) . '</a></div>';
		echo '</div>';
	}

	// ------------------------------------------------------------------ Upgrade

	public static function upgrade() {
		self::open( __( 'BackupScope Pro', 'backupscope' ), 'upgrade-header' );
		echo '<p class="bsp-intro-text">' . esc_html__( 'The free plugin is a complete backup tool. Pro is for when backups should look after themselves: on a schedule, copied off-site, restorable from the dashboard, with storage you can see and keep under control.', 'backupscope' ) . '</p>';

		$count = Promo::backup_count();
		if ( $count > 0 ) {
			/* translators: %d: number of backups */
			echo '<p class="bsp-count">' . esc_html( sprintf( _n( 'You have made %d backup with BackupScope, by hand.', 'You have made %d backups with BackupScope, each by hand.', $count, 'backupscope' ), $count ) ) . '</p>';
		}

		self::plans();
		self::cards( array( 'schedule', 'restore', 'offsite', 'history', 'email', 'storage', 'cleanup', 'engine', 'support' ) );

		echo '<section class="bsp-panel"><h2>' . esc_html__( 'Already bought Pro? Start here', 'backupscope' ) . '</h2><ol>';
		echo '<li>' . esc_html__( 'Download the Pro zip from the link in your purchase email.', 'backupscope' ) . '</li>';
		echo '<li>' . esc_html__( 'Go to Plugins › Add New › Upload Plugin, upload it and activate it.', 'backupscope' ) . '</li>';
		echo '<li>' . esc_html__( 'Enter your licence key under BackupScope › Settings › Licence.', 'backupscope' ) . '</li>';
		echo '</ol><p class="bsp-muted">' . esc_html__( 'Pro includes everything in this plugin and switches it off when activated. Your backups and settings are kept.', 'backupscope' ) . '</p></section>';
		echo '</div>';
	}

	/** Plans with the launch offer while it lasts; regular prices after. Prices in USD. */
	private static function plans() {
		$launch = Promo::launch_on();
		$plans  = array(
			array( 'Solo', __( '2 sites', 'backupscope' ), 49, 39, false ),
			array( 'Studio', __( '5 sites', 'backupscope' ), 99, 69, true ),
			array( 'Agency', __( 'Unlimited sites', 'backupscope' ), 199, 99, false ),
		);
		echo '<section class="bsp-plans">';
		if ( $launch ) {
			$end = wp_date( get_option( 'date_format' ), strtotime( Promo::LAUNCH_ENDS . ' 12:00:00 UTC' ) );
			/* translators: %s: date */
			echo '<p class="bsp-offer"><span class="bsp-badge">' . esc_html__( 'Launch offer', 'backupscope' ) . '</span> ' . esc_html( sprintf( __( 'First-year prices until %s. Plans renew at the regular yearly price.', 'backupscope' ), $end ) ) . '</p>';
		}
		echo '<div class="bsp-plan-grid">';
		foreach ( $plans as $p ) {
			echo '<div class="bsp-plan' . ( $p[4] ? ' is-popular' : '' ) . '">';
			if ( $p[4] ) {
				echo '<span class="bsp-popular">' . esc_html__( 'Most popular', 'backupscope' ) . '</span>';
			}
			echo '<h3>' . esc_html( $p[0] ) . '</h3><p class="bsp-muted">' . esc_html( $p[1] ) . '</p><p class="bsp-price">';
			if ( $launch ) {
				echo '<s>$' . esc_html( (string) $p[2] ) . '</s> <strong>$' . esc_html( (string) $p[3] ) . '</strong> <span>' . esc_html__( 'first year', 'backupscope' ) . '</span>';
			} else {
				echo '<strong>$' . esc_html( (string) $p[2] ) . '</strong> <span>' . esc_html__( '/year', 'backupscope' ) . '</span>';
			}
			echo '</p></div>';
		}
		echo '</div><p class="bsp-muted">' . esc_html__( 'Every plan has every Pro feature; only the number of sites differs.', 'backupscope' ) . '</p>';
		echo '<div class="bsp-cta-row">';
		self::cta( 'upgrade-page' );
		echo '</div></section>';
	}

	// ------------------------------------------------------------------ More Tools

	/**
	 * The author's other plugins, each with its real state on this site. `files` are the plugin
	 * files that count as installed (DocxToPost Free and DocxToWP Pro both use the docxtowp
	 * folder, so the folder alone cannot tell them apart).
	 */
	private static function products() {
		$utm = array( 'utm_source' => 'backupscope-plugin', 'utm_medium' => 'more-tools', 'utm_campaign' => 'cross-promo' );
		return array(
			array(
				'name'  => 'BackupScope Pro',
				'text'  => __( 'Everything in this plugin, plus backups that look after themselves.', 'backupscope' ),
				'pro'   => __( 'Scheduled and off-site backups, restore with a safety backup, history and retention, email notifications, storage analytics with alerts, media review and safe cleanup.', 'backupscope' ),
				'slug'  => 'backupscope-pro',
				'files' => array( 'backupscope-pro/backupscope-pro.php' ),
				'url'   => Promo::pro_url( 'more-tools' ),
				'wporg' => false,
				'icon'  => 'dashicons-shield',
			),
			array(
				'name'  => 'BackupScope Storage Insights',
				'text'  => __( 'See what uses your WordPress disk space.', 'backupscope' ),
				'pro'   => __( 'Media, plugins, themes, folders, database tables and the largest files, measured and shown in one report. Read-only: nothing on your site is changed or deleted.', 'backupscope' ),
				'free'  => true,
				'slug'  => 'backupscope-storage-insights',
				'files' => array( 'backupscope-storage-insights/backupscope-storage-insights.php' ),
				'url'   => 'https://wordpress.org/plugins/backupscope-storage-insights/',
				'wporg' => true,
				'icon'  => 'dashicons-chart-pie',
			),
			array(
				'name'  => 'DocxToWP',
				'text'  => __( 'Word and Markdown files into WordPress, without copy-paste.', 'backupscope' ),
				'pro'   => __( 'Real, editable blocks with headings, lists, tables and formatting kept; images added to the Media Library; a live preview; publish to posts, pages or any custom post type.', 'backupscope' ),
				'free'  => true,
				'slug'  => 'docxtowp',
				'files' => array( 'docxtowp/docxtopost.php' ),
				'url'   => 'https://wordpress.org/plugins/docxtowp/',
				'wporg' => true,
				'icon'  => 'dashicons-media-document',
			),
			array(
				'name'  => 'DocxToWP Pro',
				'text'  => __( 'For a folder of documents rather than one at a time.', 'backupscope' ),
				'pro'   => __( 'Bulk import of up to 100 .docx and .md files, drip publishing on a schedule, Yoast and Rank Math SEO fields, WebP image conversion, and one-click rollback of an import.', 'backupscope' ),
				'slug'  => 'docxtowp-pro',
				'files' => array( 'docxtowp/docxtowp-pro.php', 'docxtowp-pro/docxtowp-pro.php' ),
				'url'   => add_query_arg( $utm, 'https://docxtowp.com/pricing' ),
				'wporg' => false,
				'icon'  => 'dashicons-media-document',
			),
		);
	}

	public static function more_tools() {
		self::open( __( 'More Tools', 'backupscope' ), 'tools-header', 'bsp-wrap--wide' );
		echo '<p class="bsp-intro-text">' . esc_html__( 'Other WordPress plugins from the makers of BackupScope.', 'backupscope' ) . '</p>';
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		echo '<div class="bsp-cards bsp-tools-grid">';
		foreach ( self::products() as $p ) {
			$file = '';
			foreach ( $p['files'] as $f ) {
				if ( isset( $installed[ $f ] ) ) {
					$file = $f;
					break;
				}
			}
			echo '<section class="bsp-card bsp-tool"><div class="bsp-card-head"><span class="dashicons ' . esc_attr( $p['icon'] ) . '" aria-hidden="true"></span><div><h2>' . esc_html( $p['name'] );
			$free = ! empty( $p['free'] );
			echo ' <span class="bsp-badge' . ( $free ? ' bsp-badge--free' : '' ) . '">' . esc_html( $free ? __( 'Free', 'backupscope' ) : __( 'Pro', 'backupscope' ) ) . '</span>';
			echo '</h2><p class="bsp-lead">' . esc_html( $p['text'] ) . '</p></div></div>';
			if ( '' !== $p['pro'] ) {
				echo '<p class="bsp-tool-pro' . ( $free ? ' bsp-tool-pro--free' : '' ) . '">' . esc_html( $p['pro'] ) . '</p>';
			}
			echo '<div class="bsp-tool-actions">';
			if ( '' !== $file && is_plugin_active( $file ) ) {
				echo '<span class="bsp-active">✓ ' . esc_html__( 'Active', 'backupscope' ) . '</span>';
			} elseif ( '' !== $file && current_user_can( 'activate_plugins' ) ) {
				echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ) ) . '">' . esc_html__( 'Activate', 'backupscope' ) . '</a>';
			} elseif ( $p['wporg'] && current_user_can( 'install_plugins' ) ) {
				/* translators: %s: plugin name */
				echo '<a class="button button-primary thickbox open-plugin-details-modal" href="' . esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $p['slug'] ) . '&TB_iframe=true&width=772&height=700' ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Install %s', 'backupscope' ), $p['name'] ) ) . '">' . esc_html__( 'Install', 'backupscope' ) . '</a> ';
				echo '<a class="button" href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Details', 'backupscope' ) . '</a>';
			} else {
				echo '<a class="button" href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Learn more', 'backupscope' ) . '</a>';
			}
			echo '</div></section>';
		}
		echo '</div></div>';
	}
}
