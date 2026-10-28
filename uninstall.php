<?php
/**
 * Uninstall: removes settings, cache and configuration.
 * WebP/AVIF image variants are kept (they do no harm and avoid converting again).
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'fulgur_settings', 'fulgur_stats', 'fulgur_version', 'fulgur_preload_queue', 'fulgur_image_queue', 'fulgur_metrics', 'fulgur_secrets', 'fulgur_ai_job', 'fulgur_ai_report', 'fulgur_ai_backup', 'fulgur_ai_history' ) as $option ) {
	delete_option( $option );
}

$fulgur_dirs = array( WP_CONTENT_DIR . '/cache/fulgur', WP_CONTENT_DIR . '/fulgur-config' );
foreach ( $fulgur_dirs as $fulgur_dir ) {
	if ( ! is_dir( $fulgur_dir ) ) {
		continue;
	}
	$fulgur_it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $fulgur_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $fulgur_it as $fulgur_item ) {
		$fulgur_item->isDir() ? @rmdir( $fulgur_item->getPathname() ) : @unlink( $fulgur_item->getPathname() );
	}
	@rmdir( $fulgur_dir );
}
