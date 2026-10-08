=== BackupScope ===
Contributors: nagarajdev
Tags: backup, database backup, site backup, zip, files backup
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up your WordPress files and database to a private ZIP with one click. Scan first, see the size, download when it's done.

== Description ==

BackupScope creates a backup of your WordPress files and database as a single ZIP file that you can download.

1. Choose **Full Backup** (files + database) or **Custom Backup**.
2. BackupScope scans your site and shows how many files there are and how big the backup will be.
3. Click **Create Backup**. Large sites are backed up step by step, so server time limits are not a problem.
4. Every backup is verified before it is kept. Download it when it's done.

**Private by default.** Backups are stored in a protected folder, `wp-content/uploads/backupscope/`, under random file names, and can only be downloaded by a logged-in administrator. Backup files contain your database and `wp-config.php` (with its authentication keys and salts removed), so keep downloaded copies safe.

**What BackupScope Free does not do:** restore, scheduled backups, cloud storage, or e-mail notifications. You can restore a backup manually (see the FAQ).

BackupScope makes no requests to external services and collects no data. The only network request is a check against your own site to make sure the backups folder is not publicly reachable.

= BackupScope Pro =

Need more? [BackupScope Pro](https://backupscope.pro) adds:

* Scheduled backups (daily, weekly, monthly)
* One-click restore with an automatic safety backup
* Off-site copies to S3-compatible storage (Amazon S3, Cloudflare R2, Backblaze B2, Wasabi and more)
* Backup history with retention rules
* E-mail notifications
* Storage Analytics: see what is using your disk space

BackupScope Free stays fully functional on its own.

== Frequently Asked Questions ==

= Does BackupScope back up the database? =

Yes. Full Backup includes your files and your database. Custom Backup lets you choose.

= Can I restore a backup with BackupScope Free? =

Not with one click. BackupScope Free creates and verifies your backup. To restore manually:

1. Unzip the backup on your computer.
2. Upload the contents of `files/wordpress/` to your web root (and any folders under `files/external/`, as listed in `manifest.json`).
3. Import `database/database.sql` into an empty database with phpMyAdmin, Adminer or the `mysql` command line tool.
4. Check the database settings in `wp-config.php` if you moved to a new host.
5. If the domain changed, use a serialization-aware search and replace tool (for example `wp search-replace`), not a plain text replace.

= Does the backup contain my security keys? =

No. The authentication keys and salts in `wp-config.php` (`AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_KEY` and the four salts) are replaced with WordPress's placeholder text in the copy that goes into the backup. Your live `wp-config.php` is never changed. A restored site still works, because WordPress then uses random salts stored in the database; everyone simply has to log in again. The keys are removed in memory while the backup is written, never in a copy on disk. If BackupScope cannot be sure every key was removed (for example when a key is built from several pieces), it leaves `wp-config.php` out of the backup and lists it as skipped, with the reason. You can also paste fresh keys from https://api.wordpress.org/secret-key/1.1/salt/ into the restored `wp-config.php`.

= Where are backups stored? Are they safe? =

In `wp-content/uploads/backupscope/`. The folder is protected from direct web access (`.htaccess` and `web.config` deny rules plus empty index files), and every backup file has a long random name. Downloads require an administrator login. On Nginx servers BackupScope checks whether the folder can be reached from the internet and warns you if it can. You can choose your own folder outside the website by adding `define( 'BACKUPSCOPE_STORAGE_DIR', '/path/outside/your/site' );` to `wp-config.php`.

= Why does it scan before backing up? =

So you can see how many files and how much space the backup needs before it starts, and so BackupScope can choose the best method for your server.

= My site is very large. Will it work? =

Yes. Backups larger than 5 GB are created step by step so they don't hit server time limits. Keep the page open while it runs. If you get interrupted, you can resume.

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
* The authentication keys and salts in wp-config.php are never stored in backups.
