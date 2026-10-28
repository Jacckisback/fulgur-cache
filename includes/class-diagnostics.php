<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Vérifications automatiques : chaque problème est expliqué avec sa solution.
 */
class Diagnostics {

	const CONFLICTS = array(
		'wp-rocket/wp-rocket.php'                     => 'WP Rocket',
		'w3-total-cache/w3-total-cache.php'           => 'W3 Total Cache',
		'wp-super-cache/wp-cache.php'                 => 'WP Super Cache',
		'wp-fastest-cache/wpFastestCache.php'         => 'WP Fastest Cache',
		'litespeed-cache/litespeed-cache.php'         => 'LiteSpeed Cache',
		'sg-cachepress/sg-cachepress.php'             => 'SiteGround Optimizer',
		'autoptimize/autoptimize.php'                 => 'Autoptimize',
		'hummingbird-performance/wp-hummingbird.php'  => 'Hummingbird',
		'breeze/breeze.php'                           => 'Breeze',
		'cache-enabler/cache-enabler.php'             => 'Cache Enabler',
		'comet-cache/comet-cache.php'                 => 'Comet Cache',
		'swift-performance-lite/performance.php'      => 'Swift Performance',
		'nitropack/main.php'                          => 'NitroPack',
		'perfmatters/perfmatters.php'                 => 'Perfmatters',
		'flying-press/flying-press.php'               => 'FlyingPress',
	);

	/**
	 * @return array[] Liste de { id, status: ok|warning|error, title, detail }
	 */
	public static function run() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$checks = array();
		$s      = Settings::all();

		// Drop-in.
		if ( $s['page_cache'] ) {
			if ( Installer::dropin_is_ours() && defined( 'FULGUR_ADVANCED_CACHE' ) ) {
				$checks[] = self::item( 'dropin', 'ok', __( 'Cache engine active', 'fulgur-cache' ), __( 'Pages are served before WordPress loads.', 'fulgur-cache' ) );
			} elseif ( is_file( Installer::dropin_path() ) && ! Installer::dropin_is_ours() ) {
				$checks[] = self::item( 'dropin', 'error', __( 'Another caching plugin is installed', 'fulgur-cache' ), __( 'The wp-content/advanced-cache.php file belongs to another plugin. Deactivate it, then click “Repair”.', 'fulgur-cache' ), 'repair' );
			} elseif ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
				$checks[] = self::item( 'dropin', 'error', __( 'WP_CACHE is not enabled', 'fulgur-cache' ), __( 'Add define( \'WP_CACHE\', true ); at the top of wp-config.php, or click “Repair” if the file is writable.', 'fulgur-cache' ), 'repair' );
			} else {
				$checks[] = self::item( 'dropin', 'warning', __( 'Cache engine not loaded', 'fulgur-cache' ), __( 'Click “Repair” to reinstall the drop-in.', 'fulgur-cache' ), 'repair' );
			}
		}

		// Droits d'écriture.
		wp_mkdir_p( FULGUR_CACHE_DIR );
		$checks[] = is_writable( FULGUR_CACHE_DIR )
			? self::item( 'writable', 'ok', __( 'Cache folder is writable', 'fulgur-cache' ), FULGUR_CACHE_DIR )
			: self::item( 'writable', 'error', __( 'Cache folder is not writable', 'fulgur-cache' ), sprintf( __( 'Give write permissions (755) to %s', 'fulgur-cache' ), FULGUR_CACHE_DIR ) );

		// Permaliens.
		$checks[] = get_option( 'permalink_structure' )
			? self::item( 'permalinks', 'ok', __( 'Pretty permalinks enabled', 'fulgur-cache' ), __( 'Clean URLs make static caching possible.', 'fulgur-cache' ) )
			: self::item( 'permalinks', 'error', __( '“Plain” permalinks', 'fulgur-cache' ), __( 'Settings → Permalinks: choose “Post name”. Without it, the cache cannot work.', 'fulgur-cache' ) );

		// Serveur web.
		if ( Installer::is_apache() ) {
			$htaccess = get_home_path() . '.htaccess';
			$has      = is_file( $htaccess ) && false !== strpos( (string) file_get_contents( $htaccess ), '# BEGIN ' . Installer::HTACCESS_MARKER );
			$checks[] = $has
				? self::item( 'server', 'ok', __( 'Static serving via Apache/LiteSpeed', 'fulgur-cache' ), __( 'Cached pages are served without running PHP.', 'fulgur-cache' ) )
				: self::item( 'server', 'warning', __( '.htaccess rules missing', 'fulgur-cache' ), __( 'The cache works through PHP, but .htaccess rules would make it even faster. Click “Repair” or make .htaccess writable.', 'fulgur-cache' ), 'repair' );
		} else {
			$checks[] = self::item( 'server', 'warning', __( 'Nginx server detected', 'fulgur-cache' ), __( 'The cache already works through PHP (very fast). To serve pages without PHP, copy the Nginx configuration from the Tools tab.', 'fulgur-cache' ), 'nginx' );
		}

		// Compression.
		$checks[] = function_exists( 'brotli_compress' )
			? self::item( 'brotli', 'ok', __( 'Brotli compression available', 'fulgur-cache' ), __( 'Pages ~20% smaller than with gzip.', 'fulgur-cache' ) )
			: self::item( 'brotli', 'info', __( 'Brotli unavailable, using gzip', 'fulgur-cache' ), __( 'Optional: ask your host for the “brotli” PHP extension.', 'fulgur-cache' ) );

		// Images.
		$checks[] = Images::supports( 'webp' )
			? self::item( 'webp', 'ok', __( 'WebP conversion available', 'fulgur-cache' ), Images::supports( 'avif' ) ? __( 'AVIF is available too.', 'fulgur-cache' ) : __( 'AVIF unavailable on this server (Imagick or PHP 8.1+ with libavif required).', 'fulgur-cache' ) )
			: self::item( 'webp', 'warning', __( 'WebP conversion unavailable', 'fulgur-cache' ), __( 'Neither GD with WebP nor Imagick is available. Contact your host.', 'fulgur-cache' ) );

		// Cache objet.
		$checks[] = wp_using_ext_object_cache()
			? self::item( 'object', 'ok', __( 'Persistent object cache active', 'fulgur-cache' ), __( 'Redis/Memcached speeds up the admin and uncached pages.', 'fulgur-cache' ) )
			: self::item( 'object', 'info', __( 'No persistent object cache', 'fulgur-cache' ), __( 'If your host offers Redis, enable it: the admin and WooCommerce will be much faster.', 'fulgur-cache' ) );

		// PHP.
		$checks[] = version_compare( PHP_VERSION, '8.1', '>=' )
			? self::item( 'php', 'ok', 'PHP ' . PHP_VERSION, __( 'Modern and fast version.', 'fulgur-cache' ) )
			: self::item( 'php', 'warning', sprintf( __( 'PHP %s is outdated', 'fulgur-cache' ), PHP_VERSION ), __( 'Switch to PHP 8.2 or later with your host: up to 30% faster uncached pages.', 'fulgur-cache' ) );

		// Conflits.
		$active    = (array) get_option( 'active_plugins', array() );
		$conflicts = array();
		foreach ( self::CONFLICTS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$conflicts[] = $name;
			}
		}
		$checks[] = $conflicts
			? self::item( 'conflicts', 'error', sprintf( __( 'Conflicting plugin(s): %s', 'fulgur-cache' ), implode( ', ', $conflicts ) ), __( 'Two caching plugins get in each other\'s way and slow the site down. Deactivate them.', 'fulgur-cache' ) )
			: self::item( 'conflicts', 'ok', __( 'No conflicting plugins', 'fulgur-cache' ), '' );

		return $checks;
	}

	private static function item( $id, $status, $title, $detail, $action = '' ) {
		return compact( 'id', 'status', 'title', 'detail', 'action' );
	}

	public static function repair() {
		Installer::install();
		return self::run();
	}

	/**
	 * Mesure du temps de réponse serveur (TTFB) sans cache puis avec cache.
	 */
	public static function speed_test() {
		$url    = home_url( '/' );
		$args   = array(
			'timeout'    => 20,
			'user-agent' => 'Mozilla/5.0 (compatible; FulgurSpeedTest/1.0)',
			'sslverify'  => false,
		);
		$before = self::time_request( add_query_arg( 'fulgur_bypass', wp_rand(), $url ), $args );

		// S'assure que la page est en cache, puis mesure.
		self::time_request( $url, $args );
		$after = self::time_request( $url, $args );

		if ( $before && $after ) {
			Stats::set( 'speed_before', $before );
			Stats::set( 'speed_after', $after );
		}
		return array(
			'before' => $before,
			'after'  => $after,
		);
	}

	private static function time_request( $url, $args ) {
		$start = microtime( true );
		$res   = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			return 0;
		}
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
