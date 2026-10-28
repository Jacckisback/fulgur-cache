<?php
/**
 * Désinstallation : supprime réglages, cache et configuration.
 * Les variantes WebP/AVIF des images sont conservées (elles ne gênent pas et évitent une reconversion).
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'fulgur_settings', 'fulgur_stats', 'fulgur_version', 'fulgur_preload_queue', 'fulgur_image_queue' ) as $option ) {
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
