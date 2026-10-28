<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Préchargement du cache : le premier visiteur ne tombe jamais sur une page froide.
 */
class Preload {

	const QUEUE = 'fulgur_preload_queue';
	const HOOK  = 'fulgur_preload_run';
	const UA    = 'Mozilla/5.0 (compatible; FulgurPreload/1.0; +https://gustinai.com)';
	const UA_MOBILE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 FulgurPreload/1.0';

	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Toutes les URL publiques du site, des plus récemment modifiées aux plus anciennes.
	 */
	public static function site_urls( $limit = 3000 ) {
		$urls = array( home_url( '/' ) );

		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$ids   = get_posts(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'has_password'           => false,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}

		$taxonomies = get_taxonomies( array( 'public' => true ) );
		$terms      = get_terms(
			array(
				'taxonomy'   => array_values( $taxonomies ),
				'hide_empty' => true,
				'number'     => 500,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		return array_values( array_unique( array_filter( apply_filters( 'fulgur_preload_urls', $urls ) ) ) );
	}

	public static function schedule_full() {
		if ( ! Settings::get( 'preload_cache' ) || ! Settings::get( 'page_cache' ) ) {
			return;
		}
		update_option( self::QUEUE, self::site_urls(), false );
		self::schedule( 5 );
	}

	public static function queue( array $urls ) {
		if ( ! Settings::get( 'preload_cache' ) || ! Settings::get( 'page_cache' ) ) {
			return;
		}
		$queue = (array) get_option( self::QUEUE, array() );
		// Les URL modifiées passent en tête de file.
		$queue = array_values( array_unique( array_merge( $urls, $queue ) ) );
		update_option( self::QUEUE, $queue, false );
		self::schedule( 2 );
	}

	private static function schedule( $delay ) {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
		// Déclenche WP-Cron tout de suite sans attendre un visiteur.
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			spawn_cron();
		}
	}

	public static function run() {
		$queue = (array) get_option( self::QUEUE, array() );
		if ( ! $queue ) {
			return;
		}
		$start  = microtime( true );
		$mobile = (bool) Settings::get( 'cache_mobile' );
		$done   = 0;

		while ( $queue && microtime( true ) - $start < 25 ) {
			$url = array_shift( $queue );
			if ( self::is_cached( $url ) ) {
				continue;
			}
			self::fetch( $url, self::UA );
			if ( $mobile ) {
				self::fetch( $url, self::UA_MOBILE );
			}
			++$done;
			usleep( 150000 ); // Ménage le serveur (hébergements mutualisés).
		}

		update_option( self::QUEUE, $queue, false );
		Stats::increment( 'preloaded', $done );

		if ( $queue ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	private static function fetch( $url, $ua ) {
		wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'blocking'   => true,
				'user-agent' => $ua,
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
				'headers'    => array( 'Accept-Encoding' => 'gzip, br' ),
			)
		);
	}

	public static function is_cached( $url ) {
		$host = Installer::site_host();
		$dir  = \Fulgur_Serve::dir( FULGUR_CACHE_DIR, $host, (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return is_file( $dir . '/index.html' );
	}

	public static function status() {
		$queue = (array) get_option( self::QUEUE, array() );
		return array(
			'pending' => count( $queue ),
			'running' => (bool) wp_next_scheduled( self::HOOK ),
		);
	}
}
