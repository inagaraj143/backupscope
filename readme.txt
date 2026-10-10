=== BackupScope ===
Contributors: nagarajdev
Tags: backup, database backup, website backup, full backup, files backup
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WordPress backup plugin: back up your files and database to a downloadable ZIP. Scan first, see the size, and every backup is verified.

== Description ==

BackupScope is a free WordPress backup plugin that saves your website files and database into a single ZIP archive you can download. Make a full WordPress backup with one click, or choose exactly what to include. No account, no sign-up and no external service.

**How it works**

1. Choose **Full Backup** (files + database) or **Custom Backup**.
2. BackupScope scans your site first and shows how many files there are and how big the backup will be.
3. Click **Create Backup**. Large WordPress sites are processed in resumable steps, so server time limits do not stop the backup.
4. The archive is verified before it is kept. Then download it.

**Features**

* **Full WordPress backup:** your files and your database in one ZIP.
* **Custom backup:** pick the database, WordPress core, plugins, themes, uploads or other folders, and untick any folder you do not need.
* **Scan before backup:** see the file count and the estimated size before anything starts.
* **Built for large sites:** the scan, the database export and large archives run in short, resumable steps. Archives above 5 GB use a streaming ZIP64 engine.
* **No mysqldump needed:** the database is exported through WordPress's own database connection.
* **Backup verification:** every archive is checked before it replaces your previous backup.
* **Protected storage:** backups are kept in a protected folder with random file names and can only be downloaded by a logged-in administrator.
* **Simple management:** download or delete your backup from the BackupScope screen.

**Privacy and security**

* Your database is in the backup, so keep downloaded copies somewhere safe.
* `wp-config.php` is never included, because it holds your site's security keys. The scan screen tells you this before each backup.
* BackupScope makes no requests to external services and collects no data. The only network request is a check against your own site that the backups folder is not publicly reachable.

**What BackupScope Free does not do:** one-click restore, scheduled backups, off-site storage or email notifications. You can restore a backup by hand (see the FAQ).

= Why upgrade to BackupScope Pro? =

The free plugin is a complete, unrestricted backup tool: full or custom backups, verified, ready to download. [BackupScope Pro](https://backupscope.pro/pricing) is for when backups should look after themselves, and when you also want to see and control what fills your server:

* **Scheduled backups:** daily, weekly or monthly at a quiet time you choose, in your site's time zone, as a full backup or your own selection of folders and the database. Backups run in the background and keep going after you close the page. A missed run is noticed and reported, and it can start on the next visit to the site or from a private server cron address when WP-Cron is unreliable.
* **Restore with a safety net:** restore everything, only the files or only the database, from a backup on the server or off-site. Before anything is overwritten, a safety backup of the current site is made, so you can always go back. The database is imported into separate tables and switched over in one step at the end, so the site keeps working while the restore runs. Your current `wp-config.php` and BackupScope's own settings are kept.
* **Off-site copies:** send every backup to Amazon S3 or S3-compatible storage such as Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces or MinIO, so a copy survives if your server fails. Large archives upload in parts, each part is retried, and an interrupted upload resumes. Access keys are stored encrypted (or defined in `wp-config.php`), the connection status shows when it was last tested, and you choose whether to keep the copy on your server.
* **Backup history and retention:** every backup in one list, with download, lock and delete. Keep the last N backups and/or the backups from the last N days; locked backups and the newest good backup are never removed, and off-site copies follow their own rule.
* **Email notifications:** an email when a backup fails, a scheduled backup is missed, an off-site copy fails or a backup completes. Every email is an editable template with a live preview and a test button, and it is sent through your SMTP plugin if you use one. Backup files are never attached. Instead, an email can carry a secure download link: protected by a separate download password, expiring after 48 hours and 3 downloads by default, locked after 5 wrong passwords, HTTPS only, and revocable at any time.
* **Storage Analytics:** see what uses your disk space: media, plugins, themes, WordPress core, the database, stored backups and other folders, with tabs for every plugin, theme, folder, database table and file type, and the largest files. A "worth reviewing" list estimates the space you could free, and each analysis shows what changed since the last one. Analysing never changes anything.
* **Storage monitoring:** automatic analysis daily, weekly or monthly. BackupScope Pro watches the server disk and your hosting plan limit, and emails you once when usage reaches your warning or critical level, before uploads or backups start failing. An optional summary email lists the largest areas and the change since the previous analysis, and a Growth over time chart shows how the largest items grow.
* **Media review:** find Media Library items with no detected reference anywhere, and files in uploads that are not in the Media Library. Post content, custom fields, page builder data (including Elementor), settings, widgets, every database table and theme files are checked. Preview each item, filter by type and extension, and mark items to keep.
* **Safe cleanup:** review inactive plugins and themes, other backup plugins' archives, cache, temporary files and large log files. Everything you clean goes to a Cleanup Trash first and can be put back where it was for 30 days. Your active theme, its parent and one default theme are always kept, and large logs are emptied with a copy kept in the trash.

Pro also includes everything in the free plugin and works on its own: when you activate it, it replaces BackupScope Free and keeps your backups and settings. Updates install from your WordPress dashboard and come with email support from the developer. If you stop renewing, nothing is deleted or locked: your backups stay available to download and restore.

Plans cover 2 sites, 5 sites or unlimited sites, with the same features in every plan. [See BackupScope Pro features and plans](https://backupscope.pro/pricing)

== Frequently Asked Questions ==

= Is BackupScope free? =

Yes. BackupScope Free creates, verifies and lets you download full backups of your files and database. It has no time limit and needs no account.

= What does BackupScope Pro add? =

Scheduled backups, restore with a safety backup, off-site copies to S3-compatible storage, backup history and retention, email notifications with secure download links, Storage Analytics with automatic monitoring and alerts, Media review and Safe Cleanup. See "Why upgrade to BackupScope Pro?" above for details.

= Do I need the free plugin to use Pro? =

No. BackupScope Pro includes the same backup engine and works on its own. When you activate Pro, it switches the free plugin off and keeps your backups and settings, so you can delete the free plugin afterwards.

= What happens if my Pro licence expires? =

Nothing is deleted or locked. Your existing backups stay available to download and restore. Scheduled backups, off-site uploads, updates and support stop until you renew.

= Does BackupScope back up the database? =

Yes. Full Backup includes your files and your database. Custom Backup lets you choose.

= Can I restore a backup with BackupScope Free? =

Not with one click. BackupScope Free creates and verifies your backup. To restore manually:

1. Unzip the backup on your computer.
2. Upload the contents of `files/wordpress/` to your web root (and any folders under `files/external/`, as listed in `manifest.json`).
3. Import `database/database.sql` into an empty database with phpMyAdmin, Adminer or the `mysql` command line tool.
4. Keep your existing `wp-config.php`. On a new host, create one from `wp-config-sample.php` with the new database details and fresh keys from https://api.wordpress.org/secret-key/1.1/salt/.
5. If the domain changed, use a serialization-aware search and replace tool (for example `wp search-replace`), not a plain text replace.

= Does the backup contain my security keys? =

No. `wp-config.php` is never put into a backup, wherever it is stored, so its authentication keys and salts never leave your server. Other PHP files in the WordPress folder that mention a key or salt (for example a separate salts file loaded by `wp-config.php`) are left out too. BackupScope only checks file names and text for this; it never reads, copies or changes the key values. Each file left out is listed in the backup's `manifest.json` under `files.excluded`. When you restore, keep your existing `wp-config.php`, or create a new one from `wp-config-sample.php`.

= Where are backups stored? Are they safe? =

In `wp-content/uploads/backupscope/`. The folder is protected from direct web access (`.htaccess` and `web.config` deny rules plus empty index files), and every backup file has a long random name. Downloads require an administrator login. Some servers, such as Nginx, ignore those rules, so after each backup BackupScope checks whether the folder can be reached from the internet and warns you if it can. You can choose your own folder outside the website by adding `define( 'BACKUPSCOPE_STORAGE_DIR', '/path/outside/your/site' );` to `wp-config.php`.

= Why does it scan before backing up? =

So you can see how many files and how much space the backup needs before it starts, and so BackupScope can choose the best method for your server.

= My site is very large. Will it work? =

Yes. The work is split into short, resumable steps, so server time limits do not stop it, and archives above 5 GB are written with a streaming ZIP64 engine. Keep the page open while it runs; if it is interrupted, you can resume. Your server still needs enough free disk space for the archive.

= What happens if a backup fails? =

Temporary files are removed and your previous backup is kept.

= How many backups are kept? =

The latest one. Your previous backup is deleted only after the new backup has been created and verified.

= What happens when I delete the plugin? =

Stored backups are deleted too, because they contain sensitive data. Download your backup first. To keep backup files when deleting the plugin, add `define( 'BACKUPSCOPE_KEEP_BACKUPS_ON_UNINSTALL', true );` to `wp-config.php`.

= Does it work on Multisite? =

Not in this version.

== Changelog ==

= 1.0.2 =
* New: BackupScope Storage Insights on the More Tools page. It is a free, read-only plugin from WordPress.org that shows what uses your disk space, and installs through WordPress's own installer.
* Improved: the More Tools cards sit in one row on wide screens.

= 1.0.1 =
* New: "Schedule & Restore (Pro)", "Upgrade" and "More Tools" pages, and a Backup coverage card on the backup screen.
* New: review and upgrade links next to the page title and on the Plugins screen.
* New: occasional tips after 3, 5 and 10 backups; each shows once on BackupScope screens and stays dismissed.
* Developer: PHP namespace renamed from InstaBackup to BackupScope. Settings, stored backups and hooks are unchanged.

= 1.0.0 =
* Initial release.
* Full Backup (files + database) and Custom Backup.
* Scan before backup with file count, size and a selectable contents preview.
* Large sites are backed up step by step; resumable after a refresh.
* Every backup is verified before it is kept.
* Private storage, random file names and admin-only downloads.
* wp-config.php and other files with the authentication keys and salts are never stored in backups.
