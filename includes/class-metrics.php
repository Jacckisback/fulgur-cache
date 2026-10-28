<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Mesures de charge légères : pages servies depuis le cache (échantillonnées par le drop-in)
 * et pages générées par WordPress (durée, mémoire). Conservées 7 jours, par heure.
 */
class Metrics {

	const OPTION = 'fulgur_metrics';

	public static function dir() {
		return FULGUR_CACHE_DIR . '/stats';
	}

	/**
	 * Appelée à chaque page générée (cache manquant).
	 */
	public static function record_miss( $ms ) {
		$data = (array) get_option( self::OPTION, array() );
		$hour = gmdate( 'YmdH' );
		if ( ! isset( $data[ $hour ] ) ) {
			$data[ $hour ] = array( 'miss' => 0, 'ms' => 0, 'mem' => 0 );
		}
		++$data[ $hour ]['miss'];
		$data[ $hour ]['ms']  += (int) $ms;
		$data[ $hour ]['mem']  = max( $data[ $hour ]['mem'], (int) round( memory_get_peak_usage( true ) / 1048576 ) );
		ksort( $data );
		$data = array_slice( $data, -168, null, true );
		update_option( self::OPTION, $data, false );
	}

	/**
	 * Synthèse des 7 derniers jours.
	 */
	public static function summary() {
		$data   = (array) get_option( self::OPTION, array() );
		$hits   = self::hits();
		$hours  = array_unique( array_merge( array_keys( $data ), array_keys( $hits ) ) );
		sort( $hours );
		$total_hits = array_sum( $hits );
		$total_miss = 0;
		$total_ms   = 0;
		$peak_hour  = 0;
		$peak_mem   = 0;
		foreach ( $hours as $h ) {
			$miss        = isset( $data[ $h ] ) ? $data[ $h ]['miss'] : 0;
			$total_miss += $miss;
			$total_ms   += isset( $data[ $h ] ) ? $data[ $h ]['ms'] : 0;
			$peak_mem    = max( $peak_mem, isset( $data[ $h ] ) ? $data[ $h ]['mem'] : 0 );
			$peak_hour   = max( $peak_hour, $miss + ( $hits[ $h ] ?? 0 ) );
		}
		$total = $total_hits + $total_miss;
		return array(
			'period_hours'           => count( $hours ),
			'requests_php_measured'  => $total,
			'cache_hits_estimated'   => $total_hits,
			'cache_misses'           => $total_miss,
			'hit_ratio_percent'      => $total ? round( $total_hits * 100 / $total, 1 ) : null,
			'avg_generation_ms'      => $total_miss ? (int) round( $total_ms / $total_miss ) : null,
			'peak_requests_per_hour' => $peak_hour,
			'peak_memory_mb'         => $peak_mem,
			'note'                   => __( 'Pages served directly by .htaccess/Nginx (without PHP) are not counted.', 'fulgur-cache' ),
		);
	}

	/**
	 * Le drop-in écrit 1 octet pour 1 page servie sur 10 : on multiplie par 10.
	 */
	private static function hits() {
		$out = array();
		foreach ( glob( self::dir() . '/hits-*.log' ) ?: array() as $file ) {
			if ( preg_match( '/hits-(\d{10})\.log$/', $file, $m ) ) {
				if ( filemtime( $file ) < time() - 7 * DAY_IN_SECONDS ) {
					@unlink( $file );
					continue;
				}
				$out[ $m[1] ] = (int) filesize( $file ) * 10;
			}
		}
		return $out;
	}
}
