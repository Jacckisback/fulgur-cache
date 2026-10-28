<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Installe/retire le drop-in advanced-cache.php, la constante WP_CACHE,
 * le fichier de configuration lu avant WordPress et les règles serveur.
 */
class Installer {

	const HTACCESS_MARKER = 'Fulgur Cache';

	public static function install() {
		wp_mkdir_p( FULGUR_CACHE_DIR );
		wp_mkdir_p( FULGUR_CONFIG_DIR );
		self::protect_dir( FULGUR_CONFIG_DIR );
		self::write_config();
		self::write_dropin();
		self::set_wp_cache( true );
		self::write_htaccess();
	}

	public static function uninstall() {
		self::remove_dropin();
		self::set_wp_cache( false );
		self::remove_htaccess();
		$file = \Fulgur_Serve::config_file( FULGUR_CONFIG_DIR );
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Configuration exportée en PHP pur pour le drop-in (aucune requête SQL à la lecture).
	 */
	public static function write_config() {
		$s      = Settings::all();
		$config = array(
			'page_cache'           => (bool) $s['page_cache'],
			'cache_mobile'         => (bool) $s['cache_mobile'],
			'lifespan'             => (int) $s['cache_lifespan'] * HOUR_IN_SECONDS,
			'ignored_query_params' => Settings::lines( 'ignored_query_params' ),
			'exclude_urls'         => Settings::lines( 'exclude_urls' ),
			'exclude_cookies'      => Settings::lines( 'exclude_cookies' ),
			'exclude_user_agents'  => Settings::lines( 'exclude_user_agents' ),
			'cache_dir'            => FULGUR_CACHE_DIR,
			'charset'              => get_option( 'blog_charset', 'UTF-8' ),
			'generated'            => time(),
		);

		wp_mkdir_p( FULGUR_CONFIG_DIR );
		$php  = "<?php\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $config, true ) . ";\n";
		$file = \Fulgur_Serve::config_file( FULGUR_CONFIG_DIR, self::site_host() );
		self::atomic_write( $file, $php );
		\Fulgur_Serve::set_config( $config );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true );
		}
	}

	public static function site_host() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		$host = strtolower( $host . ( $port ? ':' . $port : '' ) );
		return preg_replace( '/[^a-z0-9.\-]/', '_', $host );
	}

	public static function dropin_path() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	public static function dropin_is_ours() {
		$file = self::dropin_path();
		return is_file( $file ) && false !== strpos( (string) file_get_contents( $file ), 'FULGUR_DROPIN' );
	}

	public static function write_dropin() {
		$file = self::dropin_path();
		if ( is_file( $file ) && ! self::dropin_is_ours() ) {
			return new \WP_Error( 'fulgur_dropin_conflict', __( 'Another caching plugin is already using advanced-cache.php.', 'fulgur-cache' ) );
		}
		$tpl = (string) file_get_contents( FULGUR_DIR . 'templates/advanced-cache.php' );
		$tpl = str_replace(
			array( '{{SERVE_FILE}}', '{{CONFIG_DIR}}' ),
			array( addslashes( FULGUR_DIR . 'includes/serve.php' ), addslashes( FULGUR_CONFIG_DIR ) ),
			$tpl
		);
		return self::atomic_write( $file, $tpl ) ? true : new \WP_Error( 'fulgur_dropin_write', __( 'Cannot write wp-content/advanced-cache.php.', 'fulgur-cache' ) );
	}

	public static function remove_dropin() {
		if ( self::dropin_is_ours() ) {
			wp_delete_file( self::dropin_path() );
		}
	}

	public static function wp_config_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		if ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return dirname( ABSPATH ) . '/wp-config.php';
		}
		return '';
	}

	/**
	 * Ajoute ou retire define( 'WP_CACHE', true ) dans wp-config.php.
	 */
	public static function set_wp_cache( $enable ) {
		if ( $enable && defined( 'WP_CACHE' ) && WP_CACHE ) {
			return true;
		}
		$file = self::wp_config_path();
		if ( ! $file || ! is_writable( $file ) ) {
			return false;
		}
		$config = (string) file_get_contents( $file );
		$line   = "define( 'WP_CACHE', true ); // Fulgur Cache";

		// Retire toute définition existante de WP_CACHE.
		$clean = preg_replace( '/^\s*define\(\s*[\'"]WP_CACHE[\'"]\s*,[^)]*\)\s*;.*$\R?/mi', '', $config );

		if ( $enable ) {
			$clean = preg_replace( '/^<\?php\s*/', "<?php\n" . $line . "\n", $clean, 1 );
		}
		if ( $clean === $config ) {
			return true;
		}
		return self::atomic_write( $file, $clean );
	}

	public static function is_apache() {
		global $is_apache, $is_litespeed;
		return ! empty( $is_apache ) || ! empty( $is_litespeed );
	}

	/**
	 * Règles Apache/LiteSpeed : service statique sans PHP + compression + cache navigateur.
	 */
	public static function htaccess_rules() {
		$s         = Settings::all();
		$cache_rel = self::relative_to_root( FULGUR_CACHE_DIR );
		$rules     = array();

		if ( $s['browser_cache'] ) {
			$rules[] = '<IfModule mod_mime.c>';
			$rules[] = '  AddType image/avif .avif';
			$rules[] = '  AddType image/webp .webp';
			$rules[] = '  AddType font/woff2 .woff2';
			$rules[] = '</IfModule>';
			$rules[] = '<IfModule mod_deflate.c>';
			$rules[] = '  AddOutputFilterByType DEFLATE text/html text/css text/plain text/xml application/javascript application/json application/xml image/svg+xml font/ttf font/otf';
			$rules[] = '</IfModule>';
			$rules[] = '<IfModule mod_brotli.c>';
			$rules[] = '  AddOutputFilterByType BROTLI_COMPRESS text/html text/css text/plain text/xml application/javascript application/json application/xml image/svg+xml';
			$rules[] = '</IfModule>';
			$rules[] = '<IfModule mod_expires.c>';
			$rules[] = '  ExpiresActive On';
			$rules[] = '  ExpiresByType text/html "access plus 0 seconds"';
			$rules[] = '  ExpiresByType text/css "access plus 1 year"';
			$rules[] = '  ExpiresByType application/javascript "access plus 1 year"';
			$rules[] = '  ExpiresByType image/avif "access plus 1 year"';
			$rules[] = '  ExpiresByType image/webp "access plus 1 year"';
			$rules[] = '  ExpiresByType image/jpeg "access plus 1 year"';
			$rules[] = '  ExpiresByType image/png "access plus 1 year"';
			$rules[] = '  ExpiresByType image/gif "access plus 1 year"';
			$rules[] = '  ExpiresByType image/svg+xml "access plus 1 year"';
			$rules[] = '  ExpiresByType image/x-icon "access plus 1 year"';
			$rules[] = '  ExpiresByType font/woff2 "access plus 1 year"';
			$rules[] = '  ExpiresByType video/mp4 "access plus 1 year"';
			$rules[] = '</IfModule>';
			$rules[] = '<IfModule mod_headers.c>';
			$rules[] = '  <FilesMatch "\.(css|js|avif|webp|jpe?g|png|gif|svg|ico|woff2?|ttf|otf|mp4|webm)$">';
			$rules[] = '    Header set Cache-Control "public, max-age=31536000, immutable"';
			$rules[] = '  </FilesMatch>';
			$rules[] = '</IfModule>';
		}

		if ( $s['page_cache'] ) {
			$cookies   = implode( '|', array_map( 'preg_quote', array_merge( \Fulgur_Serve::BYPASS_COOKIES, Settings::lines( 'exclude_cookies' ) ) ) );
			$file_base = '%{DOCUMENT_ROOT}/' . $cache_rel . '/%{HTTP_HOST}%{REQUEST_URI}';

			$rules[] = '<IfModule mod_mime.c>';
			$rules[] = '  AddEncoding br .br';
			$rules[] = '  AddEncoding gzip .gz';
			$rules[] = '</IfModule>';
			$rules[] = '<FilesMatch "\.html\.(br|gz)$">';
			$rules[] = '  <IfModule mod_headers.c>';
			$rules[] = '    Header set Content-Type "text/html; charset=UTF-8"';
			$rules[] = '    Header append Vary Accept-Encoding';
			$rules[] = '  </IfModule>';
			$rules[] = '  <IfModule mod_deflate.c>';
			$rules[] = '    SetEnv no-gzip 1';
			$rules[] = '  </IfModule>';
			$rules[] = '  <IfModule mod_brotli.c>';
			$rules[] = '    SetEnv no-brotli 1';
			$rules[] = '  </IfModule>';
			$rules[] = '</FilesMatch>';
			$rules[] = '<IfModule mod_rewrite.c>';
			$rules[] = '  RewriteEngine On';
			$rules[] = '  RewriteBase /';

			foreach ( array( 'br' => 'br', 'gzip' => 'gz', '' => '' ) as $accept => $ext ) {
				$suffix  = $ext ? '.' . $ext : '';
				$rules[] = '  RewriteCond %{REQUEST_METHOD} GET';
				$rules[] = '  RewriteCond %{QUERY_STRING} =""';
				$rules[] = '  RewriteCond %{REQUEST_URI} /$';
				$rules[] = '  RewriteCond %{HTTP:Cookie} !(' . $cookies . ') [NC]';
				if ( $s['cache_mobile'] ) {
					$rules[] = '  RewriteCond %{HTTP_USER_AGENT} !(Mobile|Android|Silk/|Kindle|BlackBerry|Opera\ Mini|Opera\ Mobi|iPhone|iPod) [NC]';
				}
				foreach ( Settings::lines( 'exclude_urls' ) as $url ) {
					if ( false === strpbrk( $url, '#*' ) ) {
						$rules[] = '  RewriteCond %{REQUEST_URI} !' . preg_quote( $url, '' ) . ' [NC]';
					}
				}
				if ( $accept ) {
					$rules[] = '  RewriteCond %{HTTP:Accept-Encoding} ' . $accept;
				}
				$rules[] = '  RewriteCond "' . $file_base . 'index.html' . $suffix . '" -f';
				$rules[] = '  RewriteRule .* "/' . $cache_rel . '/%{HTTP_HOST}%{REQUEST_URI}index.html' . $suffix . '" [L]';
			}
			$rules[] = '</IfModule>';
		}

		return $rules;
	}

	public static function write_htaccess() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! self::is_apache() ) {
			return false;
		}
		$file = get_home_path() . '.htaccess';
		if ( ( file_exists( $file ) && ! is_writable( $file ) ) || ( ! file_exists( $file ) && ! is_writable( dirname( $file ) ) ) ) {
			return false;
		}
		// Nos règles doivent passer AVANT celles de WordPress.
		$content = file_exists( $file ) ? (string) file_get_contents( $file ) : '';
		$content = preg_replace( '/# BEGIN ' . self::HTACCESS_MARKER . '.*?# END ' . self::HTACCESS_MARKER . '\R*/s', '', $content );
		$block   = '# BEGIN ' . self::HTACCESS_MARKER . "\n" . implode( "\n", self::htaccess_rules() ) . "\n# END " . self::HTACCESS_MARKER . "\n\n";
		return self::atomic_write( $file, $block . ltrim( $content ) );
	}

	public static function remove_htaccess() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = get_home_path() . '.htaccess';
		if ( ! is_file( $file ) || ! is_writable( $file ) ) {
			return;
		}
		$content = (string) file_get_contents( $file );
		$clean   = preg_replace( '/# BEGIN ' . self::HTACCESS_MARKER . '.*?# END ' . self::HTACCESS_MARKER . '\R*/s', '', $content );
		if ( $clean !== $content ) {
			self::atomic_write( $file, $clean );
		}
	}

	/**
	 * Configuration Nginx à copier dans le bloc server (affichée dans l'admin).
	 */
	public static function nginx_rules() {
		$cache_rel = self::relative_to_root( FULGUR_CACHE_DIR );
		$cookies   = implode( '|', \Fulgur_Serve::BYPASS_COOKIES );
		return <<<NGINX
# --- Fulgur Cache: static serving without PHP ---
set \$fulgur_file "/{$cache_rel}/\$host\${uri}index.html";
set \$fulgur_ok 1;
if (\$request_method != GET) { set \$fulgur_ok 0; }
if (\$args != "") { set \$fulgur_ok 0; }
if (\$http_cookie ~* "({$cookies})") { set \$fulgur_ok 0; }
if (\$uri !~ /\$) { set \$fulgur_ok 0; }
if (\$fulgur_ok = 0) { set \$fulgur_file "/nonexistent"; }

gzip_static on;        # serves index.html.gz when present
# brotli_static on;    # uncomment if the ngx_brotli module is installed

location / {
    try_files \$fulgur_file \$uri \$uri/ /index.php?\$args;
}

location ~* \.(css|js|avif|webp|jpe?g|png|gif|svg|ico|woff2?|ttf|mp4)$ {
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable";
    access_log off;
}
NGINX;
	}

	private static function relative_to_root( $path ) {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$root = wp_normalize_path( untrailingslashit( get_home_path() ) );
		$path = wp_normalize_path( $path );
		if ( 0 === strpos( $path, $root ) ) {
			return ltrim( substr( $path, strlen( $root ) ), '/' );
		}
		return ltrim( str_replace( wp_normalize_path( ABSPATH ), '', $path ), '/' );
	}

	private static function protect_dir( $dir ) {
		if ( ! is_file( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" );
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
		}
	}

	/**
	 * Écriture atomique : jamais de fichier à moitié écrit servi à un visiteur.
	 */
	public static function atomic_write( $file, $content ) {
		$tmp = $file . '.' . uniqid( 'tmp', true );
		if ( false === @file_put_contents( $tmp, $content, LOCK_EX ) ) {
			return false;
		}
		$perms = is_file( $file ) ? fileperms( $file ) & 0777 : 0644;
		@chmod( $tmp, $perms );
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}
}
