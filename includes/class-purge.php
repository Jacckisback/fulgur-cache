<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Purge intelligente : seules les pages réellement impactées par une modification sont vidées,
 * puis immédiatement re-préchargées.
 */
class Purge {

	/** @var string[] */
	private $urls = array();

	/** @var bool */
	private $all = false;

	public function __construct() {
		add_action( 'transition_post_status', array( $this, 'post_status' ), 10, 3 );
		add_action( 'save_post', array( $this, 'post' ), 20 );
		add_action( 'before_delete_post', array( $this, 'post' ) );
		add_action( 'comment_post', array( $this, 'comment' ) );
		add_action( 'edit_comment', array( $this, 'comment' ) );
		add_action( 'wp_set_comment_status', array( $this, 'comment' ) );
		add_action( 'edited_term', array( $this, 'term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'term' ), 10, 3 );

		// Changements globaux → purge complète.
		foreach ( array( 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'update_option_sidebars_widgets', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'update_option_permalink_structure', 'update_option_blogname', 'update_option_blogdescription', 'update_option_site_icon', 'update_option_theme_mods_' . get_option( 'stylesheet' ), 'fulgur_settings_updated' ) as $hook ) {
			add_action( $hook, array( $this, 'everything' ) );
		}

		// WooCommerce : stock et prix.
		add_action( 'woocommerce_product_set_stock', array( $this, 'wc_product' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'wc_product' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'post' ) );

		add_action( 'fulgur_images_converted', array( $this, 'everything' ) );

		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	public function post_status( $new, $old, $post ) {
		if ( 'publish' === $new || 'publish' === $old ) {
			$this->post( $post->ID );
		}
	}

	public function post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( $permalink ) {
			$this->urls[] = $permalink;
			// L'ancienne URL si le slug a changé.
			$this->urls[] = str_replace( '__trashed', '', $permalink );
		}
		$this->urls[] = home_url( '/' );

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page ) {
			$this->urls[] = get_permalink( $posts_page );
		}
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$this->urls[] = $archive;
		}
		$this->urls[] = get_author_posts_url( (int) $post->post_author );

		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			if ( ! is_taxonomy_viewable( $tax ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$this->urls[] = $link;
					}
				}
			}
		}

		// Articles voisins (liens précédent/suivant).
		if ( 'post' === $post->post_type ) {
			$backup          = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			foreach ( array( get_adjacent_post( false, '', true ), get_adjacent_post( false, '', false ) ) as $adj ) {
				if ( $adj ) {
					$this->urls[] = get_permalink( $adj );
				}
			}
			$GLOBALS['post'] = $backup; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}

		$this->urls = apply_filters( 'fulgur_purge_post_urls', $this->urls, $post_id );
	}

	public function comment( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->post( (int) $comment->comment_post_ID );
		}
	}

	public function term( $term_id, $tt_id, $taxonomy ) {
		$link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			$this->urls[] = $link;
		}
		$this->urls[] = home_url( '/' );
	}

	public function wc_product( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$id = method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			$this->post( $id );
		}
	}

	public function everything() {
		$this->all = true;
	}

	/**
	 * Exécution groupée en fin de requête (une seule purge même si 50 hooks se déclenchent).
	 */
	public function flush() {
		if ( $this->all ) {
			Page_Cache::purge_all();
			Cdn::cloudflare_purge();
			Preload::schedule_full();
			$this->all  = false;
			$this->urls = array();
			return;
		}
		if ( ! $this->urls ) {
			return;
		}
		$urls = array_values( array_unique( array_filter( $this->urls ) ) );
		foreach ( $urls as $url ) {
			Page_Cache::purge_url( $url );
		}
		Cdn::cloudflare_purge( $urls );
		Preload::queue( $urls );
		$this->urls = array();
	}
}
