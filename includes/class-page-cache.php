<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Captures the generated page, optimizes it and stores it on disk (HTML + gzip + brotli).
 */
class Page_Cache {

	/** @var bool */
	private $cacheable = false;

	/** @var bool */
	private $preview = false;

	public function __construct() {
		// Start as early as possible once the template is chosen.
		add_action( 'template_redirect', array( $this, 'start' ), -9999 );
	}

	public function start() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_robots() || is_trackback() ) {
			return;
		}
		if ( function_exists( 'is_favicon' ) && is_favicon() ) {
			return;
		}

		// Admin preview: ?fulgur_preview=1 applies optimizations without caching.
		$this->preview = isset( $_GET['fulgur_preview'] ) && current_user_can( 'manage_options' );

		$optimize = $this->preview || ( ! is_user_logged_in() && empty( $_GET['fulgur_off'] ) );
		$optimize = (bool) apply_filters( 'fulgur_optimize_page', $optimize );

		$this->cacheable = ! $this->preview && $this->is_cacheable();

		if ( ! $optimize && ! $this->cacheable ) {
			return;
		}

		ob_start( array( $this, 'finish' ) );
	}

	private function is_cacheable() {
		if ( ! Settings::get( 'page_cache' ) || is_user_logged_in() ) {
			return false;
		}
		$cfg = \Fulgur_Serve::config( FULGUR_CONFIG_DIR );
		if ( empty( $cfg ) ) {
			return false;
		}
		if ( '' !== \Fulgur_Serve::bypass_reason( $cfg ) ) {
			return false;
		}
		if ( is_search() || is_preview() || post_password_required() ) {
			return false;
		}
		if ( is_404() && ! Settings::get( 'cache_404' ) ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		return (bool) apply_filters( 'fulgur_is_cacheable', true );
	}

	/**
	 * Output buffer callback.
	 */
	public function finish( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}
		if ( ! $this->is_html_response() ) {
			return $html;
		}

		$started = microtime( true );
		$html    = ( new Optimizer() )->process( $html );

		if ( $this->preview ) {
			return $html . "\n<!-- Fulgur Cache: optimization preview (not cached) -->";
		}

		if ( ! $this->cacheable || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || http_response_code() !== ( is_404() ? 404 : 200 ) ) {
			return $html;
		}

		$html .= sprintf(
			"\n<!-- Fulgur Cache: page optimized in %d ms and cached on %s -->",
			(int) round( ( microtime( true ) - $started ) * 1000 ),
			gmdate( 'Y-m-d H:i:s' ) . ' UTC'
		);

		$this->store( $html );
		Metrics::record_miss( ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 );
		if ( ! headers_sent() ) {
			header( 'X-Fulgur-Cache: MISS' );
		}
		return $html;
	}

	private function is_html_response() {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return false;
			}
			if ( 0 === stripos( $header, 'set-cookie:' ) && apply_filters( 'fulgur_skip_on_set_cookie', false ) ) {
				return false;
			}
		}
		return true;
	}

	private function store( $html ) {
		$cfg  = \Fulgur_Serve::config( FULGUR_CONFIG_DIR );
		$dir  = \Fulgur_Serve::dir( FULGUR_CACHE_DIR );
		$file = $dir . '/' . \Fulgur_Serve::filename( $cfg );

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		Installer::atomic_write( $file, $html );
		if ( function_exists( 'gzencode' ) ) {
			Installer::atomic_write( $file . '.gz', gzencode( $html, 9 ) );
		}
		if ( function_exists( 'brotli_compress' ) ) {
			Installer::atomic_write( $file . '.br', brotli_compress( $html, 11, BROTLI_TEXT ) );
		}

		$links = Optimizer::$early_hints;
		if ( $links && Settings::get( 'early_hints' ) ) {
			Installer::atomic_write( $file . '.headers', wp_json_encode( array( 'Link: ' . implode( ', ', $links ) ) ) );
		} elseif ( is_file( $file . '.headers' ) ) {
			@unlink( $file . '.headers' );
		}

		Stats::increment( 'pages_cached' );
	}

	/* ------------------------------------------------------------------ */
	/* Purge                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Purges a URL (and its paginated pages).
	 */
	public static function purge_url( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $host ) {
			return;
		}
		$host = preg_replace( '/[^a-z0-9.\-]/', '_', strtolower( $host . ( $port ? ':' . $port : '' ) ) );
		$dir  = \Fulgur_Serve::dir( FULGUR_CACHE_DIR, $host, $path );

		foreach ( glob( $dir . '/index*' ) ?: array() as $file ) {
			@unlink( $file );
		}
		if ( is_dir( $dir . '/page' ) ) {
			self::rrmdir( $dir . '/page' );
		}
		do_action( 'fulgur_purged_url', $url );
	}

	public static function purge_all() {
		$host = Installer::site_host();
		self::rrmdir( FULGUR_CACHE_DIR . '/' . $host );
		self::rrmdir( FULGUR_CACHE_DIR . '/min' );
		Stats::set( 'last_purge', time() );
		do_action( 'fulgur_purged_all' );
	}

	/**
	 * Deletes pages older than the configured lifespan.
	 */
	public static function purge_expired() {
		$lifespan = (int) Settings::get( 'cache_lifespan' ) * HOUR_IN_SECONDS;
		$dir      = FULGUR_CACHE_DIR . '/' . Installer::site_host();
		if ( $lifespan <= 0 || ! is_dir( $dir ) ) {
			return 0;
		}
		$count = 0;
		$limit = time() - $lifespan;
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && $file->getMTime() < $limit ) {
				@unlink( $file->getPathname() );
				++$count;
			}
		}
		return $count;
	}

	public static function rrmdir( $dir ) {
		$dir = rtrim( $dir, '/' );
		if ( ! is_dir( $dir ) || 0 !== strpos( wp_normalize_path( $dir ), wp_normalize_path( FULGUR_CACHE_DIR ) ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $dir );
	}

	/**
	 * Cache statistics (page count, size).
	 */
	public static function usage() {
		$dir   = FULGUR_CACHE_DIR . '/' . Installer::site_host();
		$pages = 0;
		$size  = 0;
		if ( is_dir( $dir ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$size += $file->getSize();
				if ( preg_match( '/^index(-mobile)?\.html$/', $file->getFilename() ) ) {
					++$pages;
				}
			}
		}
		return array(
			'pages' => $pages,
			'size'  => $size,
		);
	}
}
