<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress cleanup: everything loaded for nothing on every page.
 */
class Tweaks {

	/** @var array */
	private $s;

	public function __construct() {
		$this->s = Settings::all();

		if ( $this->s['disable_emojis'] ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'emoji_svg_url', '__return_false' );
			add_filter(
				'wp_resource_hints',
				static function ( $urls, $type ) {
					return 'dns-prefetch' === $type ? array_filter( $urls, static function ( $u ) {
						return false === strpos( is_array( $u ) ? (string) ( $u['href'] ?? '' ) : (string) $u, 's.w.org' );
					} ) : $urls;
				},
				10,
				2
			);
		}

		if ( $this->s['disable_embeds'] ) {
			add_action(
				'init',
				static function () {
					remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
					remove_action( 'wp_head', 'wp_oembed_add_host_js' );
				},
				9999
			);
			add_action(
				'wp_footer',
				static function () {
					wp_dequeue_script( 'wp-embed' );
				}
			);
		}

		if ( $this->s['remove_jquery_migrate'] ) {
			add_action(
				'wp_default_scripts',
				static function ( $scripts ) {
					if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
						$scripts->registered['jquery']->deps = array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) );
					}
				}
			);
		}

		if ( 'default' !== $this->s['heartbeat'] ) {
			add_action( 'init', array( $this, 'heartbeat' ), 1 );
			add_filter( 'heartbeat_settings', array( $this, 'heartbeat_settings' ) );
		}

		if ( $this->s['disable_xmlrpc'] ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter(
				'wp_headers',
				static function ( $headers ) {
					unset( $headers['X-Pingback'] );
					return $headers;
				}
			);
		}

		if ( $this->s['clean_head'] ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'wp_head', 'wp_generator' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}

		if ( $this->s['dashicons_visitors'] ) {
			add_action(
				'wp_enqueue_scripts',
				static function () {
					if ( ! is_user_logged_in() && ! is_admin_bar_showing() ) {
						wp_dequeue_style( 'dashicons' );
						wp_deregister_style( 'dashicons' );
					}
				},
				100
			);
		}

		if ( $this->s['wc_cart_fragments'] ) {
			// The cart-fragments script fires an uncacheable AJAX request on every page.
			add_action(
				'wp_enqueue_scripts',
				static function () {
					if ( function_exists( 'is_woocommerce' ) && ! is_woocommerce() && ! is_cart() && ! is_checkout() ) {
						wp_dequeue_script( 'wc-cart-fragments' );
					}
				},
				999
			);
		}

		// Native WordPress 6.8+ Speculation Rules.
		add_filter( 'wp_speculation_rules_configuration', array( $this, 'core_speculation' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'core_speculation_exclusions' ) );
	}

	public function heartbeat() {
		$mode = $this->s['heartbeat'];
		if ( 'disable' === $mode || ( 'disable_front' === $mode && ! is_admin() ) ) {
			// The editor keeps the heartbeat (post locking, autosaves).
			global $pagenow;
			if ( is_admin() && in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
				return;
			}
			wp_deregister_script( 'heartbeat' );
		}
	}

	public function heartbeat_settings( $settings ) {
		$settings['interval'] = 60;
		return $settings;
	}

	public function core_speculation( $config ) {
		$mode = $this->s['speculation'];
		if ( 'off' === $mode ) {
			return null;
		}
		return array(
			'mode'      => $mode,
			'eagerness' => $this->s['speculation_eagerness'],
		);
	}

	public function core_speculation_exclusions( $paths ) {
		return array_merge( (array) $paths, Optimizer::speculation_exclusions( false ) );
	}
}
