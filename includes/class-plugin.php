<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

require_once FULGUR_DIR . 'includes/serve.php';

/**
 * Point d'entrée : charge les modules et gère le cycle de vie.
 */
class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		\Fulgur_Serve::config( FULGUR_CONFIG_DIR );

		new Purge();
		new Preload();
		new Images();
		new Database();
		new Tweaks();
		new Rest();
		new Ai();
		Admin::register_admin_bar();

		if ( ! is_admin() ) {
			new Page_Cache();
		} else {
			new Admin();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'fulgur', __NAMESPACE__ . '\\Cli' );
		}

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'fulgur_purge_expired', array( 'Fulgur\\Page_Cache', 'purge_expired' ) );
		add_action( 'fulgur_settings_updated', array( $this, 'settings_updated' ), 5, 2 );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['fulgur_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Toutes les 5 minutes (Fulgur)',
		);
		return $schedules;
	}

	public static function activate() {
		require_once FULGUR_DIR . 'includes/serve.php';
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );

		if ( false === get_option( Settings::OPTION ) ) {
			// Première installation : profil Équilibré par défaut.
			Settings::apply_profile( 'balanced' );
		}
		Installer::install();
		self::schedule_events();
		update_option( 'fulgur_version', FULGUR_VERSION );
		set_transient( 'fulgur_activation_redirect', 1, 60 );
	}

	public static function deactivate() {
		Installer::uninstall();
		Page_Cache::purge_all();
		foreach ( array( 'fulgur_purge_expired', 'fulgur_process_images', Database::HOOK, Preload::HOOK, Ai::HOOK, Ai::AUTOPILOT ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	private static function schedule_events() {
		if ( ! wp_next_scheduled( 'fulgur_purge_expired' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'fulgur_purge_expired' );
		}
		if ( ! wp_next_scheduled( 'fulgur_process_images' ) ) {
			wp_schedule_event( time() + 120, 'fulgur_5min', 'fulgur_process_images' );
		}
		if ( ! wp_next_scheduled( Database::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', Database::HOOK );
		}
	}

	/**
	 * Réglages modifiés : on régénère la config du drop-in et les règles serveur.
	 */
	public function settings_updated( $new, $old ) {
		Installer::write_config();
		Ai::schedule_autopilot();
		$server_keys = array( 'page_cache', 'cache_mobile', 'browser_cache', 'exclude_urls', 'exclude_cookies' );
		foreach ( $server_keys as $key ) {
			if ( $new[ $key ] !== $old[ $key ] ) {
				Installer::write_htaccess();
				break;
			}
		}
		if ( $new['page_cache'] && ! $old['page_cache'] ) {
			Installer::write_dropin();
			Installer::set_wp_cache( true );
		}
	}

	public function maybe_upgrade() {
		if ( get_option( 'fulgur_version' ) !== FULGUR_VERSION ) {
			Installer::install();
			self::schedule_events();
			update_option( 'fulgur_version', FULGUR_VERSION );
		}
	}
}
