<?php
/**
 * Plugin Name:       Fulgur Cache
 * Plugin URI:        https://gustinai.com/fulgur-cache
 * Description:       Cache de page statique, optimisation HTML/CSS/JS, images AVIF/WebP, préchargement intelligent (Speculation Rules) et nettoyage de base de données. Un clic pour un site ultra-rapide.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Thierry Gustin
 * Author URI:        https://gustinai.com
 * License:           GPL-2.0-or-later
 * Text Domain:       fulgur-cache
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'FULGUR_VERSION', '1.0.0' );
define( 'FULGUR_FILE', __FILE__ );
define( 'FULGUR_DIR', plugin_dir_path( __FILE__ ) );
define( 'FULGUR_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'FULGUR_CACHE_DIR' ) ) {
	define( 'FULGUR_CACHE_DIR', WP_CONTENT_DIR . '/cache/fulgur' );
}
if ( ! defined( 'FULGUR_CONFIG_DIR' ) ) {
	define( 'FULGUR_CONFIG_DIR', WP_CONTENT_DIR . '/fulgur-config' );
}

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Fulgur\\' ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class, 7 ) ) );
		$file = FULGUR_DIR . 'includes/class-' . $name . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Fulgur\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Fulgur\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Fulgur\\Plugin', 'instance' ) );
