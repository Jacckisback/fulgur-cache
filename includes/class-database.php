<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Database cleanup (manual or weekly).
 */
class Database {

	const HOOK = 'fulgur_db_clean';

	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'auto_clean' ) );
	}

	public static function tasks() {
		return array(
			'revisions'      => __( 'Old post revisions', 'fulgur-cache' ),
			'auto_drafts'    => __( 'Auto-drafts', 'fulgur-cache' ),
			'trashed_posts'  => __( 'Trashed posts', 'fulgur-cache' ),
			'spam_comments'  => __( 'Spam comments', 'fulgur-cache' ),
			'trash_comments' => __( 'Trashed comments', 'fulgur-cache' ),
			'transients'     => __( 'Expired transients', 'fulgur-cache' ),
			'orphan_meta'    => __( 'Orphaned metadata', 'fulgur-cache' ),
			'optimize'       => __( 'Table optimization', 'fulgur-cache' ),
		);
	}

	public static function counts() {
		global $wpdb;
		$keep = (int) Settings::get( 'db_revisions_keep' );
		return array(
			'revisions'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ) - self::kept_revisions( $keep ),
			'auto_drafts'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'trashed_posts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'spam_comments'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			'trash_comments' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ),
			'transients'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) ),
			'orphan_meta'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" ),
			'optimize'       => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s AND DATA_FREE > 0', DB_NAME, $wpdb->esc_like( $wpdb->prefix ) . '%' ) ),
		);
	}

	private static function kept_revisions( $keep ) {
		global $wpdb;
		if ( $keep <= 0 ) {
			return 0;
		}
		$per_post = $wpdb->get_col( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent" );
		$kept     = 0;
		foreach ( $per_post as $n ) {
			$kept += min( (int) $n, $keep );
		}
		return $kept;
	}

	public static function clean( array $tasks ) {
		global $wpdb;
		$removed = 0;
		foreach ( $tasks as $task ) {
			switch ( $task ) {
				case 'revisions':
					$keep    = (int) Settings::get( 'db_revisions_keep' );
					$parents = $wpdb->get_col( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision'" );
					foreach ( $parents as $parent ) {
						$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC LIMIT 100000 OFFSET %d", $parent, $keep ) );
						foreach ( $ids as $id ) {
							if ( wp_delete_post_revision( (int) $id ) ) {
								++$removed;
							}
						}
					}
					break;
				case 'auto_drafts':
					foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' LIMIT 1000" ) as $id ) {
						$removed += wp_delete_post( (int) $id, true ) ? 1 : 0;
					}
					break;
				case 'trashed_posts':
					foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT 1000" ) as $id ) {
						$removed += wp_delete_post( (int) $id, true ) ? 1 : 0;
					}
					break;
				case 'spam_comments':
				case 'trash_comments':
					$status = 'spam_comments' === $task ? 'spam' : 'trash';
					foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s LIMIT 5000", $status ) ) as $id ) {
						$removed += wp_delete_comment( (int) $id, true ) ? 1 : 0;
					}
					break;
				case 'transients':
					if ( function_exists( 'delete_expired_transients' ) ) {
						delete_expired_transients( true );
					}
					break;
				case 'orphan_meta':
					$removed += (int) $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );
					break;
				case 'optimize':
					$tables = $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s AND DATA_FREE > 0', DB_NAME, $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
					foreach ( $tables as $table ) {
						$wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $table ) . '`' );
					}
					break;
			}
		}
		Stats::increment( 'db_cleaned', $removed );
		return $removed;
	}

	public static function auto_clean() {
		if ( Settings::get( 'db_auto_clean' ) ) {
			self::clean( array( 'revisions', 'auto_drafts', 'spam_comments', 'trash_comments', 'transients' ) );
		}
	}
}
