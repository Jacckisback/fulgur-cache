<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands: wp fulgur <command>
 */
class Cli {

	/**
	 * Clears the cache (everything, or one URL).
	 *
	 * ## OPTIONS
	 *
	 * [--url=<url>]
	 * : Specific URL to clear.
	 *
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc ) {
		if ( ! empty( $assoc['url'] ) ) {
			Page_Cache::purge_url( $assoc['url'] );
			\WP_CLI::success( 'Cache cleared for ' . $assoc['url'] );
			return;
		}
		Page_Cache::purge_all();
		\WP_CLI::success( 'All cache cleared.' );
	}

	/**
	 * Preloads every page of the site right now.
	 */
	public function preload() {
		Preload::schedule_full();
		$total = count( (array) get_option( Preload::QUEUE, array() ) );
		$bar   = \WP_CLI\Utils\make_progress_bar( 'Preloading', $total );
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
		\WP_CLI::success( 'Preload complete.' );
	}

	/**
	 * Shows the cache status and diagnostics.
	 */
	public function status() {
		$usage = Page_Cache::usage();
		\WP_CLI::log( sprintf( 'Cached pages: %d (%s)', $usage['pages'], size_format( $usage['size'] ) ) );
		\WP_CLI::log( 'Profile: ' . Settings::get( 'profile' ) );
		$rows = array();
		foreach ( Diagnostics::run() as $check ) {
			$rows[] = array(
				'status' => $check['status'],
				'check'  => $check['title'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'check' ) );
	}

	/**
	 * Applies a profile: safe, balanced or turbo.
	 *
	 * ## OPTIONS
	 *
	 * <profile>
	 * : safe | balanced | turbo
	 */
	public function profile( $args ) {
		Settings::apply_profile( $args[0] );
		\WP_CLI::success( 'Profile applied: ' . $args[0] );
	}

	/**
	 * Converts every media library image to WebP/AVIF.
	 */
	public function images() {
		$offset = 0;
		$total  = Images::total_images();
		$bar    = \WP_CLI\Utils\make_progress_bar( 'Converting images', $total );
		do {
			$res = Images::bulk( $offset, 10 );
			for ( $i = $offset; $i < $res['offset']; $i++ ) {
				$bar->tick();
			}
			$offset = $res['offset'];
		} while ( ! $res['done'] );
		$bar->finish();
		\WP_CLI::success( 'Images converted.' );
	}

	/**
	 * Cleans the database.
	 */
	public function db() {
		$removed = Database::clean( array_keys( Database::tasks() ) );
		\WP_CLI::success( $removed . ' items removed.' );
	}
}
