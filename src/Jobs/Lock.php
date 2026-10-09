<?php
namespace BackupScope\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * One active job per site, plus a per-request "slice" lease so two requests never run the same
 * job at once (plan §8.5).
 *
 * add_option() is not atomic (it uses INSERT ... ON DUPLICATE KEY UPDATE), so the lock row is
 * written with INSERT IGNORE and changed with compare-and-swap UPDATEs. Reads bypass the object
 * cache for the same reason.
 */
final class Lock {

	const OPTION = 'instabackup_lock';

	/** @return array|null {job, heartbeat, lease} */
	public function read() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$val = null === $raw ? null : json_decode( $raw, true );
		return is_array( $val ) ? $val : null;
	}

	public function acquire( $job_id ) {
		global $wpdb;
		$value = wp_json_encode( array( 'job' => $job_id, 'heartbeat' => time(), 'lease' => 0 ) );
		$rows  = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->flush_cache();
		return 1 === (int) $rows;
	}

	/** Takes over a lock whose owner stopped sending heartbeats. */
	public function take_over( array $current, $job_id ) {
		return $this->swap( $current, array( 'job' => $job_id, 'heartbeat' => time(), 'lease' => 0 ) );
	}

	/**
	 * Starts (or extends, when $force) a slice lease; false when another request still holds one.
	 * $force is used when the previous request is known to be dead, or to extend our own lease.
	 */
	public function lease( $job_id, $seconds, $force = false ) {
		$current = $this->read();
		if ( ! $current || $current['job'] !== $job_id ) {
			return false;
		}
		if ( ! $force && (int) $current['lease'] > time() ) {
			return false;
		}
		$next          = $current;
		$next['lease'] = time() + (int) $seconds;
		$next['heartbeat'] = time();
		return $this->swap( $current, $next );
	}

	public function end_lease( $job_id ) {
		$current = $this->read();
		if ( $current && $current['job'] === $job_id ) {
			$next              = $current;
			$next['lease']     = 0;
			$next['heartbeat'] = time();
			$this->swap( $current, $next );
		}
	}

	public function heartbeat( $job_id ) {
		$current = $this->read();
		if ( $current && $current['job'] === $job_id ) {
			$next              = $current;
			$next['heartbeat'] = time();
			$this->swap( $current, $next );
		}
	}

	public function release( $job_id ) {
		global $wpdb;
		$current = $this->read();
		if ( $current && $current['job'] === $job_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION, wp_json_encode( $current ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->flush_cache();
		}
	}

	public function force_release() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->flush_cache();
	}

	private function swap( array $current, array $next ) {
		global $wpdb;
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", wp_json_encode( $next ), self::OPTION, wp_json_encode( $current ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->flush_cache();
		return 1 === (int) $rows;
	}

	private function flush_cache() {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
