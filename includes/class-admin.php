<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Admin interface, admin bar and quick actions.
 */
class Admin {

	const SLUG = 'fulgur-cache';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
		add_action( 'admin_post_fulgur_purge', array( $this, 'quick_purge' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FULGUR_FILE ), array( $this, 'action_links' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
	}

	/**
	 * The admin bar also shows on the front end, so it is registered globally.
	 */
	public static function register_admin_bar() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
	}

	public function menu() {
		add_menu_page(
			'Fulgur Cache',
			'Fulgur Cache',
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a7aaad" d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>' ),
			80
		);
	}

	public function render() {
		echo '<div class="wrap fulgur-wrap"><div id="fulgur-app"><div class="fulgur-loading">' . esc_html__( 'Loading…', 'fulgur-cache' ) . '</div></div>'
			. '<noscript>' . esc_html__( 'Fulgur Cache needs JavaScript for its interface.', 'fulgur-cache' ) . '</noscript></div>';
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'fulgur-admin', FULGUR_URL . 'assets/admin.css', array(), FULGUR_VERSION );
		wp_enqueue_script( 'fulgur-admin', FULGUR_URL . 'assets/admin.js', array( 'wp-i18n' ), FULGUR_VERSION, true );
		wp_set_script_translations( 'fulgur-admin', 'fulgur-cache', FULGUR_DIR . 'languages' );
		wp_localize_script(
			'fulgur-admin',
			'FulgurData',
			array(
				'rest'    => esc_url_raw( rest_url( Rest::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'state'   => Rest::state(),
				'version' => FULGUR_VERSION,
				'preview' => esc_url_raw( add_query_arg( 'fulgur_preview', '1', home_url( '/' ) ) ),
				'locale'  => str_replace( '_', '-', get_user_locale() ),
			)
		);
	}

	public function activation_redirect() {
		if ( get_transient( 'fulgur_activation_redirect' ) ) {
			delete_transient( 'fulgur_activation_redirect' );
			if ( ! isset( $_GET['activate-multi'] ) && current_user_can( 'manage_options' ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
				exit;
			}
		}
	}

	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'fulgur',
				'title' => '<span class="ab-icon" style="font:normal 18px/1 sans-serif;padding-top:6px">⚡</span>Fulgur',
				'href'  => admin_url( 'admin.php?page=' . self::SLUG ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'fulgur',
				'id'     => 'fulgur-purge-all',
				'title'  => __( 'Clear all cache', 'fulgur-cache' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=fulgur_purge' ), 'fulgur_purge' ),
			)
		);
		if ( ! is_admin() ) {
			$current = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . strtok( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/', '?' );
			$bar->add_node(
				array(
					'parent' => 'fulgur',
					'id'     => 'fulgur-purge-this',
					'title'  => __( 'Clear this page\'s cache', 'fulgur-cache' ),
					'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=fulgur_purge&url=' . rawurlencode( $current ) ), 'fulgur_purge' ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'fulgur',
					'id'     => 'fulgur-preview',
					'title'  => __( 'View this page optimized (test)', 'fulgur-cache' ),
					'href'   => esc_url( add_query_arg( 'fulgur_preview', '1', $current ) ),
				)
			);
		}
		$bar->add_node(
			array(
				'parent' => 'fulgur',
				'id'     => 'fulgur-settings',
				'title'  => __( 'Settings', 'fulgur-cache' ),
				'href'   => admin_url( 'admin.php?page=' . self::SLUG ),
			)
		);
	}

	public function quick_purge() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'fulgur_purge' ) ) {
			wp_die( __( 'You are not allowed to do this.', 'fulgur-cache' ) );
		}
		$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
		if ( $url ) {
			Page_Cache::purge_url( $url );
			Cdn::cloudflare_purge( array( $url ) );
			Preload::queue( array( $url ) );
		} else {
			Page_Cache::purge_all();
			Cdn::cloudflare_purge();
			Preload::schedule_full();
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::SLUG );
		wp_safe_redirect( add_query_arg( 'fulgur_purged', $url ? 'page' : 'all', $back ) );
		exit;
	}

	public function notices() {
		if ( isset( $_GET['fulgur_purged'] ) ) {
			$msg = 'page' === $_GET['fulgur_purged'] ? __( 'Page cache cleared and rebuilt.', 'fulgur-cache' ) : __( 'Cache cleared. Preloading is rebuilding pages in the background.', 'fulgur-cache' );
			echo '<div class="notice notice-success is-dismissible"><p>⚡ ' . esc_html( $msg ) . '</p></div>';
		}
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_' . self::SLUG !== $screen->id && Settings::get( 'page_cache' ) && is_file( Installer::dropin_path() ) && ! Installer::dropin_is_ours() ) {
			echo '<div class="notice notice-error"><p><strong>Fulgur Cache:</strong> ' . esc_html__( 'another caching plugin is active.', 'fulgur-cache' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'View diagnostics', 'fulgur-cache' ) . '</a></p></div>';
		}
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'fulgur-cache' ) . '</a>' );
		return $links;
	}

	public function row_action( $actions, $post ) {
		if ( current_user_can( 'manage_options' ) && 'publish' === $post->post_status ) {
			$url                     = get_permalink( $post );
			$actions['fulgur_purge'] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fulgur_purge&url=' . rawurlencode( $url ) ), 'fulgur_purge' ) ) . '">' . esc_html__( 'Clear cache', 'fulgur-cache' ) . '</a>';
		}
		return $actions;
	}
}
