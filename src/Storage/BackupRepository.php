<?php
namespace BackupScope\Storage;

use BackupScope\Support\JsonFile;
use BackupScope\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Backups are an archive plus a sidecar JSON (<backup_id>.json) in the backups folder.
 * The UI (and Pro history) read sidecars only, never the multi-GB archives.
 */
final class BackupRepository {

	private $storage;

	public function __construct( StorageManager $storage ) {
		$this->storage = $storage;
	}

	/** @return array[] Newest first. */
	public function all() {
		$dir   = $this->storage->backups_dir();
		$items = array();
		foreach ( (array) glob( $dir . '/b_*.json' ) as $file ) {
			$data = JsonFile::read( $file );
			if ( $data && isset( $data['id'], $data['file'] ) && $this->valid_id( $data['id'] ) && is_file( $dir . '/' . $data['file'] ) ) {
				$items[] = $data;
			}
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return (int) $b['created_at'] - (int) $a['created_at'];
			}
		);
		return $items;
	}

	public function current() {
		foreach ( $this->all() as $backup ) {
			if ( in_array( $backup['status'], array( 'completed', 'completed_with_warnings' ), true ) ) {
				return $backup;
			}
		}
		return null;
	}

	public function get( $id ) {
		if ( ! $this->valid_id( $id ) ) {
			return null;
		}
		$data = JsonFile::read( $this->storage->backups_dir() . '/' . $id . '.json' );
		return ( $data && $data['id'] === $id ) ? $data : null;
	}

	/** Absolute archive path for a backup ID, validated against the storage folder. */
	public function archive_path( $id ) {
		$backup = $this->get( $id );
		if ( ! $backup || ! preg_match( StorageManager::ARCHIVE_PATTERN, (string) $backup['file'] ) ) {
			return null;
		}
		$dir  = Paths::real( $this->storage->backups_dir() );
		$path = Paths::real( $this->storage->backups_dir() . '/' . $backup['file'] );
		if ( null === $dir || null === $path || ! Paths::is_within( $path, $dir ) || ! is_file( $path ) ) {
			return null;
		}
		return $path;
	}

	public function register( array $sidecar, $archive_tmp ) {
		$dir = $this->storage->backups_dir();
		if ( ! @rename( $archive_tmp, $dir . '/' . $sidecar['file'] ) ) { // phpcs:ignore
			return false;
		}
		return JsonFile::write( $dir . '/' . $sidecar['id'] . '.json', $sidecar );
	}

	public function delete( $id ) {
		$path = $this->archive_path( $id );
		$dir  = $this->storage->backups_dir();
		$ok   = true;
		if ( $path ) {
			$ok = @unlink( $path ); // phpcs:ignore
		}
		if ( $this->valid_id( $id ) && is_file( $dir . '/' . $id . '.json' ) ) {
			$ok = @unlink( $dir . '/' . $id . '.json' ) && $ok; // phpcs:ignore
		}
		if ( $ok ) {
			/** Fires after a backup was deleted. */
			do_action( 'instabackup_backup_deleted', $id );
		}
		return $ok;
	}

	public function apply_retention( array $new ) {
		/**
		 * Filters the retention policy. Free keeps the latest backup; Pro replaces this.
		 *
		 * @param RetentionPolicyInterface $policy
		 */
		$policy = apply_filters( 'instabackup_retention_policy', new KeepLatest() );
		if ( ! $policy instanceof RetentionPolicyInterface ) {
			$policy = new KeepLatest();
		}
		foreach ( $policy->to_delete( $this->all(), $new ) as $id ) {
			$this->delete( $id );
		}
	}

	public function total_bytes() {
		$total = 0;
		foreach ( $this->all() as $backup ) {
			$total += (int) $backup['size'];
		}
		return $total;
	}

	private function valid_id( $id ) {
		return is_string( $id ) && preg_match( StorageManager::BACKUP_ID_REGEX, $id );
	}
}
