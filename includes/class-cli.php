<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Commandes WP-CLI : wp fulgur <commande>
 */
class Cli {

	/**
	 * Vide le cache (tout, ou une URL).
	 *
	 * ## OPTIONS
	 *
	 * [--url=<url>]
	 * : URL précise à vider.
	 *
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc ) {
		if ( ! empty( $assoc['url'] ) ) {
			Page_Cache::purge_url( $assoc['url'] );
			\WP_CLI::success( 'Cache vidé pour ' . $assoc['url'] );
			return;
		}
		Page_Cache::purge_all();
		\WP_CLI::success( 'Tout le cache a été vidé.' );
	}

	/**
	 * Précharge toutes les pages du site immédiatement.
	 */
	public function preload() {
		Preload::schedule_full();
		$total = count( (array) get_option( Preload::QUEUE, array() ) );
		$bar   = \WP_CLI\Utils\make_progress_bar( 'Préchargement', $total );
		while ( get_option( Preload::QUEUE ) ) {
			$before = count( (array) get_option( Preload::QUEUE, array() ) );
			Preload::run();
			$after = count( (array) get_option( Preload::QUEUE, array() ) );
			for ( $i = $after; $i < $before; $i++ ) {
				$bar->tick();
			}
		}
		$bar->finish();
		wp_clear_scheduled_hook( Preload::HOOK );
		\WP_CLI::success( 'Préchargement terminé.' );
	}

	/**
	 * Affiche l'état du cache et le diagnostic.
	 */
	public function status() {
		$usage = Page_Cache::usage();
		\WP_CLI::log( sprintf( 'Pages en cache : %d (%s)', $usage['pages'], size_format( $usage['size'] ) ) );
		\WP_CLI::log( 'Profil : ' . Settings::get( 'profile' ) );
		$rows = array();
		foreach ( Diagnostics::run() as $check ) {
			$rows[] = array(
				'statut'      => $check['status'],
				'vérification' => $check['title'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'statut', 'vérification' ) );
	}

	/**
	 * Applique un profil : safe, balanced ou turbo.
	 *
	 * ## OPTIONS
	 *
	 * <profile>
	 * : safe | balanced | turbo
	 */
	public function profile( $args ) {
		Settings::apply_profile( $args[0] );
		\WP_CLI::success( 'Profil appliqué : ' . $args[0] );
	}

	/**
	 * Convertit toutes les images de la médiathèque en WebP/AVIF.
	 */
	public function images() {
		$offset = 0;
		$total  = Images::total_images();
		$bar    = \WP_CLI\Utils\make_progress_bar( 'Conversion des images', $total );
		do {
			$res = Images::bulk( $offset, 10 );
			for ( $i = $offset; $i < $res['offset']; $i++ ) {
				$bar->tick();
			}
			$offset = $res['offset'];
		} while ( ! $res['done'] );
		$bar->finish();
		\WP_CLI::success( 'Images converties.' );
	}

	/**
	 * Nettoie la base de données.
	 */
	public function db() {
		$removed = Database::clean( array_keys( Database::tasks() ) );
		\WP_CLI::success( $removed . ' éléments supprimés.' );
	}
}
