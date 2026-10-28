<?php
/**
 * Moteur de service du cache, chargé AVANT WordPress par le drop-in advanced-cache.php.
 * Ce fichier ne doit dépendre d'aucune fonction WordPress.
 */

if ( class_exists( 'Fulgur_Serve', false ) ) {
	return;
}

class Fulgur_Serve {

	/** @var array|null */
	private static $config = null;

	/** Raison du contournement du cache (pour l'en-tête de debug). */
	public static $bypass = '';

	const BYPASS_COOKIES = array(
		'wordpress_logged_in_',
		'wp-postpass_',
		'comment_author_',
		'woocommerce_items_in_cart',
		'wp_woocommerce_session_',
		'edd_items_in_cart',
		'fulgur_nocache',
	);

	public static function host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : 'default';
		$host = preg_replace( '/[^a-z0-9.\-]/', '_', $host );
		return '' === $host ? 'default' : $host;
	}

	public static function config_file( $config_dir, $host = null ) {
		return rtrim( $config_dir, '/' ) . '/config-' . ( null === $host ? self::host() : $host ) . '.php';
	}

	public static function config( $config_dir = null ) {
		if ( null === self::$config ) {
			self::$config = array();
			if ( $config_dir ) {
				$file = self::config_file( $config_dir );
				if ( is_readable( $file ) ) {
					$data = include $file;
					if ( is_array( $data ) ) {
						self::$config = $data;
					}
				}
			}
		}
		return self::$config;
	}

	public static function set_config( array $config ) {
		self::$config = $config;
	}

	public static function is_mobile() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		return (bool) preg_match( '/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi|iPhone|iPod/i', $ua )
			&& ! preg_match( '/iPad|Tablet/i', $ua );
	}

	/**
	 * Supprime les paramètres de tracking. Retourne null si d'autres paramètres subsistent (non cachable).
	 */
	public static function clean_query( array $cfg ) {
		if ( empty( $_GET ) ) {
			return '';
		}
		$ignored = isset( $cfg['ignored_query_params'] ) ? (array) $cfg['ignored_query_params'] : array();
		$rest    = array_diff_key( $_GET, array_flip( $ignored ) );
		return empty( $rest ) ? '' : null;
	}

	/**
	 * Retourne la raison de ne pas utiliser le cache, ou '' si la requête est cachable.
	 */
	public static function bypass_reason( array $cfg ) {
		if ( empty( $cfg['page_cache'] ) ) {
			return 'disabled';
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return 'method';
		}
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || PHP_SAPI === 'cli' ) {
			return 'cli';
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return 'donotcachepage';
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = (string) parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/(wp-admin|wp-json|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-content|wp-includes)|\.(php|xml|txt|json|xsl)$|/feed/?$|/robots\.txt$#i', $path ) ) {
			return 'path';
		}

		if ( null === self::clean_query( $cfg ) ) {
			return 'query';
		}

		$cookie_rules = array_merge( self::BYPASS_COOKIES, isset( $cfg['exclude_cookies'] ) ? (array) $cfg['exclude_cookies'] : array() );
		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( $cookie_rules as $rule ) {
				if ( '' !== $rule && 0 === strpos( (string) $name, $rule ) ) {
					return 'cookie';
				}
			}
		}

		if ( ! empty( $cfg['exclude_urls'] ) ) {
			foreach ( (array) $cfg['exclude_urls'] as $pattern ) {
				if ( '' !== $pattern && self::match( $pattern, $path ) ) {
					return 'excluded_url';
				}
			}
		}

		if ( ! empty( $cfg['exclude_user_agents'] ) && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			foreach ( (array) $cfg['exclude_user_agents'] as $pattern ) {
				if ( '' !== $pattern && false !== stripos( (string) $_SERVER['HTTP_USER_AGENT'], $pattern ) ) {
					return 'user_agent';
				}
			}
		}

		return '';
	}

	/**
	 * Correspondance d'un motif d'exclusion : sous-chaîne, joker (*) ou regex (#...#).
	 */
	public static function match( $pattern, $subject ) {
		if ( '#' === $pattern[0] && strlen( $pattern ) > 2 && '#' === substr( $pattern, -1 ) ) {
			return (bool) @preg_match( $pattern . 'i', $subject );
		}
		if ( false !== strpos( $pattern, '*' ) ) {
			$regex = '#' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#i';
			return (bool) preg_match( $regex, $subject );
		}
		return false !== stripos( $subject, $pattern );
	}

	/**
	 * Dossier de cache correspondant à une URL (ou à la requête courante).
	 */
	public static function dir( $cache_dir, $host = null, $path = null ) {
		if ( null === $path ) {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
			$path = (string) parse_url( $uri, PHP_URL_PATH );
		}
		$path  = rawurldecode( $path );
		$parts = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				continue;
			}
			$parts[] = preg_replace( '/[^\p{L}\p{N}._\-~@+]/u', '_', $segment );
		}
		$dir = rtrim( $cache_dir, '/' ) . '/' . ( null === $host ? self::host() : $host );
		if ( $parts ) {
			$dir .= '/' . implode( '/', $parts );
		}
		return $dir;
	}

	public static function filename( array $cfg ) {
		return ( ! empty( $cfg['cache_mobile'] ) && self::is_mobile() ) ? 'index-mobile.html' : 'index.html';
	}

	/**
	 * Point d'entrée du drop-in : sert la page depuis le disque si possible.
	 */
	public static function serve( $config_dir ) {
		$cfg = self::config( $config_dir );
		if ( empty( $cfg ) ) {
			return;
		}

		self::$bypass = self::bypass_reason( $cfg );
		if ( '' !== self::$bypass ) {
			return;
		}

		$file = self::dir( $cfg['cache_dir'] ) . '/' . self::filename( $cfg );
		if ( ! is_file( $file ) ) {
			return;
		}

		$mtime    = (int) filemtime( $file );
		$lifespan = isset( $cfg['lifespan'] ) ? (int) $cfg['lifespan'] : 0;
		if ( $lifespan > 0 && ( time() - $mtime ) > $lifespan ) {
			return; // Expiré : WordPress régénère la page.
		}

		$encoding = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
		$serve    = $file;
		$content_encoding = '';
		if ( false !== strpos( $encoding, 'br' ) && is_file( $file . '.br' ) ) {
			$serve            = $file . '.br';
			$content_encoding = 'br';
		} elseif ( false !== strpos( $encoding, 'gzip' ) && is_file( $file . '.gz' ) && ! ini_get( 'zlib.output_compression' ) ) {
			$serve            = $file . '.gz';
			$content_encoding = 'gzip';
		}

		$last_modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';
		$etag          = '"' . dechex( $mtime ) . '-' . dechex( (int) filesize( $file ) ) . '"';

		header( 'Content-Type: text/html; charset=' . ( isset( $cfg['charset'] ) ? $cfg['charset'] : 'UTF-8' ) );
		header( 'X-Fulgur-Cache: HIT' );
		header( 'Last-Modified: ' . $last_modified );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: public, max-age=0, must-revalidate' );
		header( 'Vary: Accept-Encoding' . ( ! empty( $cfg['cache_mobile'] ) ? ', User-Agent' : '' ) );

		$headers_file = $file . '.headers';
		if ( is_file( $headers_file ) ) {
			$extra = json_decode( (string) file_get_contents( $headers_file ), true );
			if ( is_array( $extra ) ) {
				foreach ( $extra as $line ) {
					header( (string) $line, false );
				}
			}
		}

		$if_none_match     = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';
		$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : 0;
		if ( ( '' !== $if_none_match && $if_none_match === $etag ) || ( '' === $if_none_match && $if_modified_since && $if_modified_since >= $mtime ) ) {
			http_response_code( 304 );
			exit;
		}

		if ( '' !== $content_encoding ) {
			header( 'Content-Encoding: ' . $content_encoding );
		}
		header( 'Content-Length: ' . filesize( $serve ) );

		if ( 'HEAD' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			readfile( $serve );
		}
		exit;
	}
}
