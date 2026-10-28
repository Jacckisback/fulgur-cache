<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * CSS (minification, font-display) and Google Fonts (swap or GDPR-friendly local hosting).
 */
class Assets {

	const FONTS_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

	public static function min_dir() {
		return FULGUR_CACHE_DIR . '/min';
	}

	public static function min_url() {
		return content_url( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( self::min_dir() ) ) );
	}

	/**
	 * Converts a local URL into a disk path (or null if external/missing).
	 */
	public static function url_to_path( $url ) {
		$url = html_entity_decode( (string) $url );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		$url      = strtok( $url, '?#' );
		$home     = wp_parse_url( home_url() );
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $url_host && strtolower( $url_host ) !== strtolower( $home['host'] ) ) {
			return null;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return null;
		}
		$path = rawurldecode( $path );

		$content_path = (string) wp_parse_url( content_url(), PHP_URL_PATH );
		if ( $content_path && 0 === strpos( $path, $content_path . '/' ) ) {
			$file = WP_CONTENT_DIR . substr( $path, strlen( $content_path ) );
		} else {
			$site_path = rtrim( (string) wp_parse_url( site_url(), PHP_URL_PATH ), '/' );
			if ( $site_path && 0 === strpos( $path, $site_path . '/' ) ) {
				$path = substr( $path, strlen( $site_path ) );
			}
			$file = ABSPATH . ltrim( $path, '/' );
		}
		if ( false !== strpos( $file, '..' ) ) {
			return null;
		}
		return is_file( $file ) ? $file : null;
	}

	/* ------------------------------------------------------------------ */
	/* CSS                                                                */
	/* ------------------------------------------------------------------ */

	public static function optimize_css( $html, $swap ) {
		// Local external stylesheets.
		$html = preg_replace_callback(
			'#<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>#i',
			function ( $m ) use ( $swap ) {
				if ( ! preg_match( '/\bhref=["\']([^"\']+)["\']/i', $m[0], $href ) ) {
					return $m[0];
				}
				$file = self::url_to_path( $href[1] );
				if ( ! $file || '.css' !== substr( $file, -4 ) || self::is_excluded( $href[1] ) || preg_match( '/\bdata-fulgur-skip\b/i', $m[0] ) ) {
					return $m[0];
				}
				$url = self::minified_file( $file, $href[1], $swap );
				return $url ? str_replace( $href[0], 'href="' . esc_url( $url ) . '"', $m[0] ) : $m[0];
			},
			$html
		);

		// Inline styles.
		return preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style>)#is',
			function ( $m ) use ( $swap ) {
				$css = self::minify_css( $m[2] );
				if ( $swap ) {
					$css = self::font_display( $css );
				}
				return $m[1] . $css . $m[3];
			},
			$html
		);
	}

	private static function is_excluded( $url ) {
		foreach ( (array) apply_filters( 'fulgur_css_exclusions', array() ) as $pattern ) {
			if ( \Fulgur_Serve::match( $pattern, $url ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generates the minified version (once) and returns its URL.
	 */
	private static function minified_file( $file, $original_url, $swap ) {
		$hash = substr( md5( $file . filemtime( $file ) . filesize( $file ) . (int) $swap . FULGUR_VERSION ), 0, 12 );
		$name = sanitize_file_name( basename( $file, '.css' ) ) . '-' . $hash . '.css';
		$dest = self::min_dir() . '/' . $name;

		if ( ! is_file( $dest ) ) {
			wp_mkdir_p( self::min_dir() );
			$css = (string) file_get_contents( $file );
			$css = self::absolutize_urls( $css, $original_url );
			$css = self::minify_css( $css );
			if ( $swap ) {
				$css = self::font_display( $css );
			}
			if ( ! Installer::atomic_write( $dest, $css ) ) {
				return null;
			}
			if ( function_exists( 'gzencode' ) ) {
				Installer::atomic_write( $dest . '.gz', gzencode( $css, 9 ) );
			}
		}
		return self::min_url() . '/' . $name;
	}

	/**
	 * Relative url() must point to the original folder once the file is moved.
	 */
	public static function absolutize_urls( $css, $base_url ) {
		$base_url = strtok( html_entity_decode( $base_url ), '?#' );
		if ( 0 === strpos( $base_url, '//' ) ) {
			$base_url = ( is_ssl() ? 'https:' : 'http:' ) . $base_url;
		} elseif ( 0 === strpos( $base_url, '/' ) ) {
			$base_url = home_url( $base_url );
		}
		$base_dir = trailingslashit( dirname( $base_url ) );

		return preg_replace_callback(
			'#url\(\s*(["\']?)([^"\')]+)\1\s*\)#i',
			function ( $m ) use ( $base_dir ) {
				$u = trim( $m[2] );
				if ( preg_match( '#^(data:|https?:|//|/|\#|var\()#i', $u ) ) {
					return $m[0];
				}
				$abs = $base_dir . $u;
				// Resolve ../
				$parts = wp_parse_url( $abs );
				$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
				$segs  = array();
				foreach ( explode( '/', $path ) as $seg ) {
					if ( '..' === $seg ) {
						array_pop( $segs );
					} elseif ( '.' !== $seg ) {
						$segs[] = $seg;
					}
				}
				$abs = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . implode( '/', $segs )
					. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
				return 'url("' . $abs . '")';
			},
			$css
		);
	}

	/**
	 * Safe CSS minifier: strings protected, calc() preserved.
	 */
	public static function minify_css( $css ) {
		$strings = array();
		$css     = preg_replace_callback(
			'/("(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\')/s',
			function ( $m ) use ( &$strings ) {
				$key             = "\x1AS" . count( $strings ) . "\x1A";
				$strings[ $key ] = $m[0];
				return $key;
			},
			$css
		);
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = preg_replace( '/\s*([{};,])\s*/', '$1', $css );
		$css = preg_replace( '/:\s+/', ':', $css );
		$css = preg_replace( '/;}/', '}', $css );
		return trim( strtr( $css, $strings ) );
	}

	/**
	 * Adds font-display:swap to @font-face rules that lack it (text visible immediately).
	 */
	public static function font_display( $css ) {
		return preg_replace_callback(
			'/@font-face\s*{([^}]*)}/i',
			function ( $m ) {
				if ( false !== stripos( $m[1], 'font-display' ) ) {
					return $m[0];
				}
				return '@font-face{font-display:swap;' . ltrim( $m[1] ) . '}';
			},
			$css
		);
	}

	/* ------------------------------------------------------------------ */
	/* Google Fonts                                                       */
	/* ------------------------------------------------------------------ */

	public static function google_fonts( $html, $mode, array &$head ) {
		if ( 'off' === $mode || false === strpos( $html, 'fonts.googleapis.com' ) ) {
			return $html;
		}

		$local_ok = false;
		$html     = preg_replace_callback(
			'#<link\b[^>]*\bhref=["\']((?:https?:)?//fonts\.googleapis\.com/css2?\?[^"\']+)["\'][^>]*>#i',
			function ( $m ) use ( $mode, &$local_ok ) {
				$url = html_entity_decode( $m[1] );
				if ( 0 === strpos( $url, '//' ) ) {
					$url = 'https:' . $url;
				}
				if ( false === strpos( $url, 'display=' ) ) {
					$url .= '&display=swap';
				}
				if ( 'local' === $mode ) {
					$local = self::localize_google_font( $url );
					if ( $local ) {
						$local_ok = true;
						return '<link rel="stylesheet" id="fulgur-gfonts-' . substr( md5( $url ), 0, 6 ) . '" href="' . esc_url( $local ) . '" media="all">';
					}
				}
				return str_replace( $m[1], esc_url( $url ), $m[0] );
			},
			$html
		);

		if ( ! $local_ok || false !== strpos( $html, 'fonts.googleapis.com' ) ) {
			$head[]                    = '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
			Optimizer::$early_hints[]  = '<https://fonts.gstatic.com>; rel=preconnect; crossorigin';
		}
		return $html;
	}

	/**
	 * Downloads the Google Fonts CSS and woff2 files to serve them from the site (GDPR + one less connection).
	 */
	public static function localize_google_font( $url ) {
		$hash = substr( md5( $url ), 0, 12 );
		$dir  = FULGUR_CACHE_DIR . '/fonts';
		$file = $dir . '/gf-' . $hash . '.css';
		$base = content_url( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( $dir ) ) );

		if ( is_file( $file ) ) {
			return $base . '/gf-' . $hash . '.css';
		}
		// Avoid retrying a failing font in a loop.
		if ( get_transient( 'fulgur_gf_fail_' . $hash ) ) {
			return null;
		}

		$res = wp_remote_get( $url, array( 'timeout' => 6, 'user-agent' => self::FONTS_UA ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			set_transient( 'fulgur_gf_fail_' . $hash, 1, HOUR_IN_SECONDS );
			return null;
		}
		$css = wp_remote_retrieve_body( $res );
		wp_mkdir_p( $dir );

		$failed = false;
		$css    = preg_replace_callback(
			'#url\((https://fonts\.gstatic\.com/[^)]+)\)#i',
			function ( $m ) use ( $dir, $base, &$failed ) {
				$name = substr( md5( $m[1] ), 0, 16 ) . '.' . pathinfo( wp_parse_url( $m[1], PHP_URL_PATH ), PATHINFO_EXTENSION );
				if ( ! is_file( $dir . '/' . $name ) ) {
					$font = wp_remote_get( $m[1], array( 'timeout' => 6 ) );
					if ( is_wp_error( $font ) || 200 !== wp_remote_retrieve_response_code( $font ) ) {
						$failed = true;
						return $m[0];
					}
					Installer::atomic_write( $dir . '/' . $name, wp_remote_retrieve_body( $font ) );
				}
				return 'url(' . $base . '/' . $name . ')';
			},
			$css
		);

		if ( $failed ) {
			set_transient( 'fulgur_gf_fail_' . $hash, 1, HOUR_IN_SECONDS );
			return null;
		}

		Installer::atomic_write( $file, self::minify_css( $css ) );
		return $base . '/gf-' . $hash . '.css';
	}
}
