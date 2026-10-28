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
				$checks[] = self::item( 'dropin', 'ok', 'Moteur de cache actif', 'Les pages sont servies avant le chargement de WordPress.' );
			} elseif ( is_file( Installer::dropin_path() ) && ! Installer::dropin_is_ours() ) {
				$checks[] = self::item( 'dropin', 'error', 'Un autre plugin de cache est installé', 'Le fichier wp-content/advanced-cache.php appartient à un autre plugin. Désactive-le puis clique sur « Réparer ».', 'repair' );
			} elseif ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
				$checks[] = self::item( 'dropin', 'error', 'WP_CACHE n\'est pas activé', 'Ajoute define( \'WP_CACHE\', true ); en haut de wp-config.php, ou clique sur « Réparer » si le fichier est modifiable.', 'repair' );
			} else {
				$checks[] = self::item( 'dropin', 'warning', 'Moteur de cache non chargé', 'Clique sur « Réparer » pour réinstaller le drop-in.', 'repair' );
			}
		}

		// Droits d'écriture.
		wp_mkdir_p( FULGUR_CACHE_DIR );
		$checks[] = is_writable( FULGUR_CACHE_DIR )
			? self::item( 'writable', 'ok', 'Dossier de cache accessible en écriture', FULGUR_CACHE_DIR )
			: self::item( 'writable', 'error', 'Dossier de cache non modifiable', 'Donne les droits d\'écriture (755) à ' . FULGUR_CACHE_DIR );

		// Permaliens.
		$checks[] = get_option( 'permalink_structure' )
			? self::item( 'permalinks', 'ok', 'Permaliens optimisés', 'Les URL propres permettent le cache statique.' )
			: self::item( 'permalinks', 'error', 'Permaliens « simples »', 'Réglages → Permaliens : choisis « Titre de la publication ». Sans cela, le cache ne peut pas fonctionner.' );

		// Serveur web.
		if ( Installer::is_apache() ) {
			$htaccess = get_home_path() . '.htaccess';
			$has      = is_file( $htaccess ) && false !== strpos( (string) file_get_contents( $htaccess ), '# BEGIN ' . Installer::HTACCESS_MARKER );
			$checks[] = $has
				? self::item( 'server', 'ok', 'Service statique Apache/LiteSpeed', 'Les pages en cache sont servies sans exécuter PHP.' )
				: self::item( 'server', 'warning', 'Règles .htaccess absentes', 'Le cache fonctionne via PHP, mais les règles .htaccess le rendraient encore plus rapide. Clique sur « Réparer » ou rends .htaccess modifiable.', 'repair' );
		} else {
			$checks[] = self::item( 'server', 'warning', 'Serveur Nginx détecté', 'Le cache fonctionne déjà via PHP (très rapide). Pour servir les pages sans PHP, copie la configuration Nginx de l\'onglet Outils.', 'nginx' );
		}

		// Compression.
		$checks[] = function_exists( 'brotli_compress' )
			? self::item( 'brotli', 'ok', 'Compression Brotli disponible', 'Pages ~20 % plus légères qu\'en gzip.' )
			: self::item( 'brotli', 'info', 'Brotli indisponible, gzip utilisé', 'Optionnel : demande à ton hébergeur l\'extension PHP « brotli ».' );

		// Images.
		$checks[] = Images::supports( 'webp' )
			? self::item( 'webp', 'ok', 'Conversion WebP disponible', Images::supports( 'avif' ) ? 'AVIF également disponible.' : 'AVIF indisponible sur ce serveur (Imagick ou PHP 8.1+ avec libavif requis).' )
			: self::item( 'webp', 'warning', 'Conversion WebP impossible', 'Ni GD avec WebP, ni Imagick ne sont disponibles. Contacte ton hébergeur.' );

		// Cache objet.
		$checks[] = wp_using_ext_object_cache()
			? self::item( 'object', 'ok', 'Cache objet persistant actif', 'Redis/Memcached accélère l\'administration et les pages non cachées.' )
			: self::item( 'object', 'info', 'Pas de cache objet persistant', 'Si ton hébergeur propose Redis, active-le : l\'admin et WooCommerce seront nettement plus rapides.' );

		// PHP.
		$checks[] = version_compare( PHP_VERSION, '8.1', '>=' )
			? self::item( 'php', 'ok', 'PHP ' . PHP_VERSION, 'Version moderne et rapide.' )
			: self::item( 'php', 'warning', 'PHP ' . PHP_VERSION . ' est ancien', 'Passe en PHP 8.2 ou plus chez ton hébergeur : jusqu\'à 30 % de gain sur les pages non cachées.' );

		// Conflits.
		$active    = (array) get_option( 'active_plugins', array() );
		$conflicts = array();
		foreach ( self::CONFLICTS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$conflicts[] = $name;
			}
		}
		$checks[] = $conflicts
			? self::item( 'conflicts', 'error', 'Plugin(s) en conflit : ' . implode( ', ', $conflicts ), 'Deux plugins de cache se gênent et ralentissent le site. Désactive-les.' )
			: self::item( 'conflicts', 'ok', 'Aucun plugin en conflit', '' );

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
