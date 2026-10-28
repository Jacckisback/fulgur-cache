<?php
/**
 * Fulgur Cache — drop-in généré automatiquement. Ne pas modifier.
 * FULGUR_DROPIN
 */
defined( 'ABSPATH' ) || exit;

define( 'FULGUR_ADVANCED_CACHE', true );

$fulgur_serve = '{{SERVE_FILE}}';
if ( is_readable( $fulgur_serve ) ) {
	require_once $fulgur_serve;
	Fulgur_Serve::serve( '{{CONFIG_DIR}}' );
}
unset( $fulgur_serve );
