<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Compteurs légers affichés dans le tableau de bord.
 */
class Stats {

	const OPTION = 'fulgur_stats';

	public static function all() {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'pages_cached' => 0,
				'preloaded'    => 0,
				'last_purge'   => 0,
				'db_cleaned'   => 0,
				'speed_before' => 0,
				'speed_after'  => 0,
			)
		);
	}

	public static function increment( $key, $by = 1 ) {
		if ( $by <= 0 ) {
			return;
		}
		$stats         = self::all();
		$stats[ $key ] = (int) $stats[ $key ] + (int) $by;
		update_option( self::OPTION, $stats, false );
	}

	public static function set( $key, $value ) {
		$stats         = self::all();
		$stats[ $key ] = $value;
		update_option( self::OPTION, $stats, false );
	}
}
