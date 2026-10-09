=== BackupScope ===
Contributors: nagarajdev
Tags: backup, database backup, website backup, full backup, files backup
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
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

= BackupScope Pro =

BackupScope Free is a complete backup tool on its own. When you want backups to look after themselves, [BackupScope Pro](https://backupscope.pro/pricing) adds:

* **Scheduled backups:** daily, weekly or monthly, at a quiet time you choose.
* **Restore with a safety net:** restore everything, only files or only the database; the current site is backed up first, so you can always go back.
* **Off-site copies:** send every backup to S3-compatible storage (Amazon S3, Cloudflare R2, Backblaze B2, Wasabi and more), so a copy survives if your server fails.
* **Backup history and retention:** keep the backups you need and remove old ones automatically.
* **Email notifications:** know when a backup fails, a schedule is missed or a backup completes.
* **Storage Analytics:** see what uses your disk space, with automatic analysis and an alert before your disk or hosting plan fills up.

[See BackupScope Pro features and plans](https://backupscope.pro/pricing)

== Frequently Asked Questions ==

= Is BackupScope free? =

Yes. BackupScope Free creates, verifies and lets you download full backups of your files and database. It has no time limit and needs no account.

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

= 1.0.0 =
* Initial release.
* Full Backup (files + database) and Custom Backup.
* Scan before backup with file count, size and a selectable contents preview.
* Large sites are backed up step by step; resumable after a refresh.
* Every backup is verified before it is kept.
* Private storage, random file names and admin-only downloads.
* wp-config.php and other files with the authentication keys and salts are never stored in backups.
