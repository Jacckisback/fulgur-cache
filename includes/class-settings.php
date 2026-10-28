<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Options, valeurs par défaut et profils prêts à l'emploi.
 */
class Settings {

	const OPTION = 'fulgur_settings';

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			// Cache de page.
			'page_cache'           => true,
			'cache_mobile'         => false, // Cache séparé mobile (thèmes non responsive uniquement).
			'cache_lifespan'       => 10,    // Heures, 0 = illimité.
			'cache_404'            => false,
			'ignored_query_params' => "utm_source\nutm_medium\nutm_campaign\nutm_term\nutm_content\nutm_id\ngclid\nfbclid\nmsclkid\ndclid\nyclid\n_ga\nmc_cid\nmc_eid\nref",
			'exclude_urls'         => "/cart/\n/checkout/\n/my-account/\n/panier/\n/commande/\n/mon-compte/\n/carrito/\n/finalizar-compra/\n/mi-cuenta/",
			'exclude_cookies'      => '',
			'exclude_user_agents'  => '',
			'browser_cache'        => true,

			// HTML.
			'minify_html'          => true,

			// CSS.
			'minify_css'           => true,
			'font_display_swap'    => true,
			'google_fonts'         => 'swap', // off | swap | local.

			// JavaScript.
			'defer_js'             => false,
			'defer_exclusions'     => '',
			'delay_js'             => false,
			'delay_timeout'        => 0, // Secondes, 0 = uniquement à l'interaction.
			'delay_exclusions'     => "fulgur-\nrecaptcha\ncookie\nconsent\ngtag/js\ngoogletagmanager",

			// Médias.
			'lazy_images'          => true,
			'lazy_iframes'         => true,
			'youtube_facade'       => true,
			'eager_images'         => 3, // Images en haut de page jamais différées.
			'image_dimensions'     => true,
			'next_gen_images'      => true,
			'image_quality'        => 78,
			'image_format'         => 'webp', // webp (remplacement direct) | avif (<picture> AVIF + WebP).

			// Préchargement.
			'preload_cache'        => true,
			'speculation'          => 'prerender', // off | prefetch | prerender.
			'speculation_eagerness'=> 'moderate',
			'instant_fallback'     => true,
			'preconnect_domains'   => '',
			'early_hints'          => true,

			// Allègement de WordPress.
			'disable_emojis'       => true,
			'disable_embeds'       => true,
			'remove_jquery_migrate'=> false,
			'heartbeat'            => 'reduce', // default | reduce | disable_front | disable.
			'disable_xmlrpc'       => true,
			'clean_head'           => true,
			'dashicons_visitors'   => true,
			'wc_cart_fragments'    => true,

			// Base de données.
			'db_auto_clean'        => false,
			'db_revisions_keep'    => 5,

			// CDN.
			'cdn_enabled'          => false,
			'cdn_url'              => '',
			'cdn_exclusions'       => '.php',
			'cloudflare_token'     => '',
			'cloudflare_zone'      => '',

			// Interface.
			'profile'              => 'balanced',
			'onboarding_done'      => false,
		);
	}

	/**
	 * Profils appliqués en un clic.
	 */
	public static function profiles() {
		return array(
			'safe'     => array(
				'label'       => 'Sûr',
				'description' => 'Cache + compression + médias. Zéro risque de casse, compatible avec 100 % des thèmes.',
				'gain'        => '+40 à 60 % de vitesse',
				'values'      => array(
					'page_cache'      => true,
					'minify_html'     => true,
					'minify_css'      => false,
					'defer_js'        => false,
					'delay_js'        => false,
					'lazy_images'     => true,
					'lazy_iframes'    => true,
					'youtube_facade'  => false,
					'next_gen_images' => true,
					'speculation'     => 'prefetch',
					'google_fonts'    => 'swap',
					'heartbeat'       => 'reduce',
				),
			),
			'balanced' => array(
				'label'       => 'Équilibré',
				'description' => 'Recommandé. Ajoute minification CSS, report du JavaScript, façades YouTube et pré-rendu instantané.',
				'gain'        => '+60 à 80 % de vitesse',
				'values'      => array(
					'page_cache'      => true,
					'minify_html'     => true,
					'minify_css'      => true,
					'defer_js'        => true,
					'delay_js'        => false,
					'lazy_images'     => true,
					'lazy_iframes'    => true,
					'youtube_facade'  => true,
					'next_gen_images' => true,
					'speculation'     => 'prerender',
					'google_fonts'    => 'swap',
					'heartbeat'       => 'reduce',
				),
			),
			'turbo'    => array(
				'label'       => 'Turbo',
				'description' => 'Performance maximale : JavaScript chargé à la première interaction, polices Google hébergées localement (RGPD).',
				'gain'        => 'Score PageSpeed 90+ visé',
				'values'      => array(
					'page_cache'      => true,
					'minify_html'     => true,
					'minify_css'      => true,
					'defer_js'        => true,
					'delay_js'        => true,
					'lazy_images'     => true,
					'lazy_iframes'    => true,
					'youtube_facade'  => true,
					'next_gen_images' => true,
					'speculation'     => 'prerender',
					'google_fonts'    => 'local',
					'heartbeat'       => 'disable_front',
				),
			),
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Fusionne, nettoie et enregistre les réglages.
	 *
	 * @param array $values Nouvelles valeurs (partielles).
	 * @return array Réglages complets.
	 */
	public static function update( array $values ) {
		$defaults = self::defaults();
		$current  = self::all();
		$old      = $current;

		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$current[ $key ] = self::sanitize( $value, $defaults[ $key ] );
		}

		update_option( self::OPTION, $current, true );
		self::$cache = $current;

		do_action( 'fulgur_settings_updated', $current, $old );

		return $current;
	}

	public static function apply_profile( $profile ) {
		$profiles = self::profiles();
		if ( ! isset( $profiles[ $profile ] ) ) {
			return self::all();
		}
		$values            = $profiles[ $profile ]['values'];
		$values['profile'] = $profile;
		return self::update( $values );
	}

	private static function sanitize( $value, $default ) {
		if ( is_bool( $default ) ) {
			return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}
		if ( is_int( $default ) ) {
			return max( 0, (int) $value );
		}
		$value = is_scalar( $value ) ? (string) $value : '';
		return str_contains( $value, "\n" ) || strlen( $value ) > 200
			? sanitize_textarea_field( $value )
			: sanitize_text_field( $value );
	}

	/**
	 * Transforme un réglage multiligne en tableau propre.
	 */
	public static function lines( $key ) {
		$raw = (string) self::get( $key );
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw ) ) ) );
	}

	public static function reset_cache() {
		self::$cache = null;
	}
}
