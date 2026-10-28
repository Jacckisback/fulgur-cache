<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * API REST utilisée par l'interface d'administration (fulgur/v1).
 */
class Rest {

	const NS = 'fulgur/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		$routes = array(
			'/status'         => array( 'GET', 'status' ),
			'/settings'       => array( 'POST', 'save_settings' ),
			'/profile'        => array( 'POST', 'profile' ),
			'/purge'          => array( 'POST', 'purge' ),
			'/preload'        => array( 'POST', 'preload' ),
			'/database'       => array( 'GET', 'database' ),
			'/database/clean' => array( 'POST', 'database_clean' ),
			'/images/bulk'    => array( 'POST', 'images_bulk' ),
			'/repair'         => array( 'POST', 'repair' ),
			'/speedtest'      => array( 'POST', 'speedtest' ),
			'/cloudflare'     => array( 'POST', 'cloudflare' ),
			'/export'         => array( 'GET', 'export' ),
			'/import'         => array( 'POST', 'import' ),
			'/reset'          => array( 'POST', 'reset' ),
			'/ai/key'         => array( 'POST', 'ai_key' ),
			'/ai/start'       => array( 'POST', 'ai_start' ),
			'/ai/status'      => array( 'GET', 'ai_status' ),
			'/ai/apply'       => array( 'POST', 'ai_apply' ),
			'/ai/undo'        => array( 'POST', 'ai_undo' ),
		);
		foreach ( $routes as $route => $def ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $def[0],
					'callback'            => array( $this, $def[1] ),
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
				)
			);
		}
	}

	public static function state() {
		$usage = Page_Cache::usage();
		return array(
			'settings'    => self::public_settings(),
			'profiles'    => Settings::profiles(),
			'usage'       => $usage,
			'stats'       => Stats::all(),
			'preload'     => Preload::status(),
			'images'      => array(
				'total' => Images::total_images(),
				'queue' => count( (array) get_option( Images::QUEUE, array() ) ),
				'webp'  => Images::supports( 'webp' ),
				'avif'  => Images::supports( 'avif' ),
			),
			'diagnostics' => Diagnostics::run(),
			'nginx'       => Installer::nginx_rules(),
			'home'        => home_url( '/' ),
			'ai'          => Ai::public_state(),
			'css_files'   => count( glob( FULGUR_CACHE_DIR . '/ucss/*.dat' ) ?: array() ),
		);
	}

	/**
	 * Le jeton Cloudflare n'est jamais renvoyé au navigateur.
	 */
	private static function public_settings() {
		$s                     = Settings::all();
		$s['cloudflare_token'] = $s['cloudflare_token'] ? '••••••••' : '';
		return $s;
	}

	public function status() {
		return rest_ensure_response( self::state() );
	}

	public function save_settings( \WP_REST_Request $req ) {
		$values = (array) $req->get_param( 'values' );
		if ( isset( $values['cloudflare_token'] ) && '••••••••' === $values['cloudflare_token'] ) {
			unset( $values['cloudflare_token'] );
		}
		// Toute modification manuelle d'un réglage fait passer en profil « personnalisé ».
		$current = Settings::all();
		foreach ( $values as $key => $value ) {
			if ( ! in_array( $key, array( 'profile', 'onboarding_done' ), true ) && array_key_exists( $key, $current ) && (string) $current[ $key ] !== (string) ( is_bool( $current[ $key ] ) ? (bool) $value : $value ) ) {
				$values['profile'] = 'custom';
				break;
			}
		}
		if ( isset( $values['profile'] ) && 'custom' !== $values['profile'] ) {
			$values['profile'] = $current['profile'];
		}
		Settings::update( $values );
		return rest_ensure_response( self::state() );
	}

	public function profile( \WP_REST_Request $req ) {
		Settings::apply_profile( sanitize_key( (string) $req->get_param( 'profile' ) ) );
		if ( $req->get_param( 'onboarding' ) ) {
			Settings::update( array( 'onboarding_done' => true ) );
		}
		return rest_ensure_response( self::state() );
	}

	public function purge( \WP_REST_Request $req ) {
		$url = esc_url_raw( (string) $req->get_param( 'url' ) );
		if ( $url ) {
			Page_Cache::purge_url( $url );
			Cdn::cloudflare_purge( array( $url ) );
			Preload::queue( array( $url ) );
		} else {
			Page_Cache::purge_all();
			Cdn::cloudflare_purge();
			Preload::schedule_full();
		}
		return rest_ensure_response( self::state() );
	}

	public function preload() {
		Preload::schedule_full();
		return rest_ensure_response( self::state() );
	}

	public function database() {
		return rest_ensure_response(
			array(
				'tasks'  => Database::tasks(),
				'counts' => Database::counts(),
			)
		);
	}

	public function database_clean( \WP_REST_Request $req ) {
		$tasks   = array_intersect( (array) $req->get_param( 'tasks' ), array_keys( Database::tasks() ) );
		$removed = Database::clean( $tasks );
		return rest_ensure_response(
			array(
				'removed' => $removed,
				'tasks'   => Database::tasks(),
				'counts'  => Database::counts(),
			)
		);
	}

	public function images_bulk( \WP_REST_Request $req ) {
		$result = Images::bulk( max( 0, (int) $req->get_param( 'offset' ) ) );
		if ( $result['done'] ) {
			Page_Cache::purge_all();
			Preload::schedule_full();
		}
		return rest_ensure_response( $result );
	}

	public function repair() {
		Diagnostics::repair();
		return rest_ensure_response( self::state() );
	}

	public function speedtest() {
		return rest_ensure_response( Diagnostics::speed_test() );
	}

	public function cloudflare() {
		$result = Cdn::cloudflare_test();
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'zone' => $result ) );
	}

	public function export() {
		$s = Settings::all();
		unset( $s['cloudflare_token'], $s['onboarding_done'] );
		return rest_ensure_response(
			array(
				'plugin'   => 'fulgur-cache',
				'version'  => FULGUR_VERSION,
				'settings' => $s,
			)
		);
	}

	public function import( \WP_REST_Request $req ) {
		$data = $req->get_param( 'data' );
		if ( ! is_array( $data ) || empty( $data['settings'] ) || 'fulgur-cache' !== ( $data['plugin'] ?? '' ) ) {
			return new \WP_Error( 'fulgur_import', 'Fichier de réglages invalide.', array( 'status' => 400 ) );
		}
		Settings::update( (array) $data['settings'] );
		return rest_ensure_response( self::state() );
	}

	public function reset() {
		delete_option( Settings::OPTION );
		Settings::reset_cache();
		Settings::apply_profile( 'balanced' );
		Settings::update( array( 'onboarding_done' => true ) );
		return rest_ensure_response( self::state() );
	}

	public function ai_key( \WP_REST_Request $req ) {
		$key = trim( (string) $req->get_param( 'key' ) );
		$psi = $req->get_param( 'psi_key' );
		if ( '' !== $key ) {
			$ok = Ai::test_key( $key );
			if ( is_wp_error( $ok ) ) {
				return new \WP_Error( $ok->get_error_code(), $ok->get_error_message(), array( 'status' => 400 ) );
			}
			Ai::set_secret( 'anthropic', $key );
		} elseif ( $req->get_param( 'remove' ) ) {
			Ai::set_secret( 'anthropic', '' );
		}
		if ( null !== $psi ) {
			Ai::set_secret( 'psi', trim( (string) $psi ) );
		}
		Ai::schedule_autopilot();
		return rest_ensure_response( self::state() );
	}

	public function ai_start() {
		$job = Ai::start( 'manual' );
		if ( is_wp_error( $job ) ) {
			return new \WP_Error( $job->get_error_code(), $job->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( Ai::public_state() );
	}

	public function ai_status() {
		return rest_ensure_response( Ai::maybe_run_inline() );
	}

	public function ai_apply( \WP_REST_Request $req ) {
		$job = Ai::apply( array_map( 'sanitize_key', (array) $req->get_param( 'keys' ) ) );
		if ( is_wp_error( $job ) ) {
			return new \WP_Error( $job->get_error_code(), $job->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( Ai::public_state() );
	}

	public function ai_undo() {
		Ai::undo();
		return rest_ensure_response( self::state() );
	}
}
