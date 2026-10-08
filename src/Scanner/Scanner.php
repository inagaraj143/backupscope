<?php
namespace InstaBackup\Scanner;

use InstaBackup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * The one reusable file scanner (plan §9). Iterative and resumable: the folder stack lives in a
 * file between requests, and each run() stops at the deadline.
 *
 * Profiles:
 *  - backup:    applies exclusions, writes the scan index used by the archive step.
 *  - analytics: also walks excluded folders (tagged), writes aggregates only (used by Pro).
 */
final class Scanner {

	const LARGEST_KEEP = 50;
	const EXCLUDED_KEEP = 200;

	private $areas;
	private $exclusions;
	private $dir;

	public function __construct( Areas $areas, Exclusions $exclusions, $work_dir ) {
		$this->areas      = $areas;
		$this->exclusions = $exclusions;
		$this->dir        = $work_dir;
	}

	public function init_state( array $selected_areas, $profile = 'backup' ) {
		$roots = $this->areas->roots();
		$stack = array();
		foreach ( array_reverse( $roots, true ) as $i => $root ) {
			$stack[] = array( $root['path'], $i, null );
		}
		file_put_contents( $this->dir . '/scan-stack.json', wp_json_encode( $stack ) ); // phpcs:ignore
		file_put_contents( $this->dir . '/scan-index.jsonl', '' ); // phpcs:ignore
		file_put_contents( $this->dir . '/skipped.jsonl', '' ); // phpcs:ignore

		$totals = array();
		foreach ( Areas::IDS as $id ) {
			$totals[ $id ] = array( 'count' => 0, 'bytes' => 0 );
		}
		return array(
			'profile'  => $profile,
			'selected' => array_values( $selected_areas ),
			'roots'    => $roots,
			'anchors'  => $this->areas->anchors( $selected_areas ),
			'files'    => 0,
			'bytes'    => 0,
			'dirs'     => 0,
			'areas'    => $totals,
			'groups'   => array(),
			'ext'      => array(),
			'largest'  => array(),
			'excluded' => array(),
			'nested'   => array(),
			'skipped'  => 0,
			'current'  => '',
			'done'     => false,
		);
	}

	/** @return bool True when the scan is complete. */
	public function run( array &$s, $deadline ) {
		$stack = json_decode( (string) file_get_contents( $this->dir . '/scan-stack.json' ), true ); // phpcs:ignore
		$stack = is_array( $stack ) ? $stack : array();
		$index = fopen( $this->dir . '/scan-index.jsonl', 'ab' ); // phpcs:ignore
		$skip  = fopen( $this->dir . '/skipped.jsonl', 'ab' ); // phpcs:ignore

		while ( $stack && microtime( true ) < $deadline ) {
			list( $path, $root_i, $excluded ) = array_pop( $stack );
			$root = $s['roots'][ $root_i ];

			if ( 'file' === $root['type'] ) {
				$this->add_file( $s, $index, $skip, $path, $root_i, $excluded );
				continue;
			}

			$handle = @opendir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $handle ) {
				$this->skip( $s, $skip, $path, 'unreadable' );
				continue;
			}
			$s['dirs']++;
			$s['current'] = $this->display( $path );
			$subdirs = array();
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$child = $path . '/' . $name;
				if ( is_link( $child ) ) {
					if ( null === $excluded && $this->wanted( $child, false, $s ) ) {
						$this->skip( $s, $skip, $child, 'symlink' );
					}
					continue;
				}
				if ( is_dir( $child ) ) {
					$reason = null === $excluded ? $this->exclusions->dir_reason( $child ) : $excluded;
					if ( null !== $reason && null === $excluded ) {
						$this->note_excluded( $s, $child, $reason );
						if ( 'analytics' !== $s['profile'] ) {
							continue;
						}
					}
					if ( null !== $reason || $this->should_descend( $child, $s ) ) {
						$subdirs[] = array( $child, $root_i, $reason );
					}
					continue;
				}
				$this->add_file( $s, $index, $skip, $child, $root_i, $excluded );
			}
			closedir( $handle );
			// Push in reverse so folders are visited in directory order.
			for ( $i = count( $subdirs ) - 1; $i >= 0; $i-- ) {
				$stack[] = $subdirs[ $i ];
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $index );
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Streams multi-GB backup files; WP_Filesystem cannot read/write in chunks.
		fclose( $skip );
		file_put_contents( $this->dir . '/scan-stack.json', wp_json_encode( $stack ) ); // phpcs:ignore
		$s['done'] = ! $stack;
		return $s['done'];
	}

	/** Versioned summary (plan §9.2). Pro Storage Analytics reads the same structure. */
	public function summary( array $s ) {
		arsort( $s['ext'] );
		$groups = $s['groups'];
		uasort(
			$groups,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);
		$largest = $s['largest'];
		usort(
			$largest,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);
		return array(
			'summary_version' => 1,
			'profile'         => $s['profile'],
			'selection'       => $s['selected'],
			'completed_at'    => time(),
			'files'           => array( 'count' => $s['files'], 'bytes' => $s['bytes'] ),
			'areas'           => $s['areas'],
			'directories'     => $groups,
			'extensions'      => $s['ext'],
			'largest_files'   => array_slice( $largest, 0, self::LARGEST_KEEP ),
			'excluded'        => $s['excluded'],
			'nested_installs' => $s['nested'],
			'skipped'         => array( 'count' => $s['skipped'] ),
		);
	}

	private function add_file( array &$s, $index, $skip, $path, $root_i, $excluded ) {
		$area = $this->areas->classify( $path, false );
		if ( null === $area ) {
			return;
		}
		if ( null === $excluded ) {
			if ( ! in_array( $area, $s['selected'], true ) ) {
				return;
			}
			$reason = $this->exclusions->file_reason( basename( $path ) );
			if ( null !== $reason ) {
				return;
			}
		}
		if ( ! is_readable( $path ) ) {
			$this->skip( $s, $skip, $path, 'permission_denied' );
			return;
		}
		$size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size ) {
			$this->skip( $s, $skip, $path, 'unreadable' );
			return;
		}

		if ( null !== $excluded ) {
			// Analytics profile: measure excluded folders but keep them out of the area totals.
			$this->grow_excluded( $s, $path, (int) $size );
			return;
		}

		$root = $s['roots'][ $root_i ];
		$rel  = 'file' === $root['type'] ? '' : Paths::relative( $path, $root['path'] );
		// Group key = folder relative to the site root ('' = root files). Used for the contents
		// preview and for folders the user unticks before creating the backup.
		$group = $this->display( $this->areas->group_dir( $area, $path ) );
		if ( 'backup' === $s['profile'] ) {
			fwrite( $index, wp_json_encode( array( $area, $root_i, $rel, (int) $size, (int) $mtime, $group ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore
		}

		$s['files']++;
		$s['bytes']                  += $size;
		$s['areas'][ $area ]['count']++;
		$s['areas'][ $area ]['bytes'] += $size;

		if ( ! isset( $s['groups'][ $group ] ) ) {
			$s['groups'][ $group ] = array( 'area' => $area, 'count' => 0, 'bytes' => 0 );
		}
		$s['groups'][ $group ]['count']++;
		$s['groups'][ $group ]['bytes'] += $size;

		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$ext = '' === $ext || strlen( $ext ) > 10 ? '(none)' : $ext;
		if ( ! isset( $s['ext'][ $ext ] ) ) {
			$s['ext'][ $ext ] = array( 'count' => 0, 'bytes' => 0 );
		}
		$s['ext'][ $ext ]['count']++;
		$s['ext'][ $ext ]['bytes'] += $size;

		if ( $size > 0 ) {
			$s['largest'][] = array( 'path' => $this->display( $path ), 'bytes' => (int) $size );
			if ( count( $s['largest'] ) > 2 * self::LARGEST_KEEP ) {
				usort(
					$s['largest'],
					static function ( $a, $b ) {
						return $b['bytes'] <=> $a['bytes'];
					}
				);
				$s['largest'] = array_slice( $s['largest'], 0, self::LARGEST_KEEP );
			}
		}
	}

	private function wanted( $path, $is_dir, array $s ) {
		$area = $this->areas->classify( $path, $is_dir );
		return null !== $area && in_array( $area, $s['selected'], true );
	}

	private function should_descend( $dir, array $s ) {
		if ( $this->wanted( $dir, true, $s ) ) {
			return true;
		}
		foreach ( $s['anchors'] as $anchor ) {
			if ( Paths::is_within( $anchor, $dir ) ) {
				return true;
			}
		}
		return false;
	}

	private function note_excluded( array &$s, $path, $reason ) {
		if ( 'nested_install' === $reason ) {
			$s['nested'][] = $this->display( $path );
		}
		if ( count( $s['excluded'] ) < self::EXCLUDED_KEEP ) {
			$s['excluded'][] = array( 'path' => $this->display( $path ), 'reason' => $reason, 'bytes' => null );
		}
	}

	private function grow_excluded( array &$s, $path, $size ) {
		foreach ( $s['excluded'] as &$item ) {
			if ( 0 === strpos( $this->display( $path ), $item['path'] . '/' ) ) {
				$item['bytes'] = (int) $item['bytes'] + $size;
				return;
			}
		}
	}

	private function skip( array &$s, $handle, $path, $reason ) {
		$s['skipped']++;
		fwrite( $handle, wp_json_encode( array( 'path' => $this->display( $path ), 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore
	}

	/** Path for humans: relative to the site root when possible. Never an absolute server path. */
	public function display( $path ) {
		$rel = Paths::relative( $path, $this->areas->abspath() );
		if ( null !== $rel ) {
			return $rel;
		}
		$rel = Paths::relative( $path, dirname( $this->areas->abspath() ) );
		return null !== $rel ? '../' . $rel : basename( $path );
	}
}
