<?php
namespace BackupScope\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * zlib's crc32_combine() in PHP (GF(2) matrix method). Lets CRC-32 continue across requests on
 * PHP 7.4, where hash contexts cannot be serialized (spike S2). Needs 64-bit integers.
 */
final class Crc32 {

	private static function times( array $mat, $vec ) {
		$sum = 0;
		$i   = 0;
		while ( $vec ) {
			if ( $vec & 1 ) {
				$sum ^= $mat[ $i ];
			}
			$vec >>= 1;
			$i++;
		}
		return $sum;
	}

	private static function square( array $mat ) {
		$sq = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$sq[ $n ] = self::times( $mat, $mat[ $n ] );
		}
		return $sq;
	}

	/** CRC of A followed by B, from crc(A), crc(B) and len(B). */
	public static function combine( $crc1, $crc2, $len2 ) {
		if ( $len2 <= 0 ) {
			return $crc1;
		}
		$odd = array( 0xEDB88320 );
		$row = 1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[ $n ] = $row;
			$row     <<= 1;
		}
		$even = self::square( $odd );
		$odd  = self::square( $even );
		do {
			$even = self::square( $odd );
			if ( $len2 & 1 ) {
				$crc1 = self::times( $even, $crc1 );
			}
			$len2 >>= 1;
			if ( 0 === $len2 ) {
				break;
			}
			$odd = self::square( $even );
			if ( $len2 & 1 ) {
				$crc1 = self::times( $odd, $crc1 );
			}
			$len2 >>= 1;
		} while ( 0 !== $len2 );
		return ( $crc1 ^ $crc2 ) & 0xFFFFFFFF;
	}
}
