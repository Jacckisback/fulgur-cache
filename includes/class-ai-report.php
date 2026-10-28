<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Rapport technique envoyé à l'IA : serveur, charge, WordPress, réglages, HTML et PageSpeed.
 * Aucune donnée personnelle ni contenu éditorial n'est transmis.
 */
class Ai_Report {

	public static function build( $with_psi = true ) {
		@set_time_limit( 300 );
		$report = array(
			'generated_at' => gmdate( 'c' ),
			'server'       => self::server(),
			'load'         => Metrics::summary(),
			'wordpress'    => self::wordpress(),
			'settings'     => self::settings(),
			'ttfb_ms'      => Diagnostics::speed_test(),
			'html'         => self::html_audit(),
			'diagnostics'  => array_map(
				static function ( $c ) {
					return $c['status'] . ' : ' . $c['title'];
				},
				Diagnostics::run()
			),
			'history'      => Ai::history(),
		);
		if ( $with_psi ) {
			$report['pagespeed'] = array(
				'mobile'  => self::pagespeed( home_url( '/' ), 'mobile' ),
				'desktop' => self::pagespeed( home_url( '/' ), 'desktop' ),
			);
		}
		return $report;
	}

	private static function server() {
		$cpu = null;
		if ( @is_readable( '/proc/cpuinfo' ) ) {
			$cpu = substr_count( (string) @file_get_contents( '/proc/cpuinfo' ), "\nprocessor" ) + 1;
		}
		$mem = array();
		if ( @is_readable( '/proc/meminfo' ) && preg_match_all( '/^(MemTotal|MemAvailable):\s+(\d+)/m', (string) @file_get_contents( '/proc/meminfo' ), $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$mem[ $row[1] . '_mb' ] = (int) round( $row[2] / 1024 );
			}
		}
		$opcache = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false;
		$load    = function_exists( 'sys_getloadavg' ) ? @sys_getloadavg() : false;
		$free    = @disk_free_space( WP_CONTENT_DIR );

		return array(
			'software'           => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'apache_or_litespeed'=> Installer::is_apache(),
			'php_version'        => PHP_VERSION,
			'php_sapi'           => PHP_SAPI,
			'memory_limit'       => ini_get( 'memory_limit' ),
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'opcache_enabled'    => is_array( $opcache ) && ! empty( $opcache['opcache_enabled'] ),
			'opcache_memory_used_percent' => is_array( $opcache ) && ! empty( $opcache['memory_usage'] )
				? round( $opcache['memory_usage']['used_memory'] * 100 / max( 1, $opcache['memory_usage']['used_memory'] + $opcache['memory_usage']['free_memory'] ), 1 ) : null,
			'cpu_cores'          => $cpu,
			'load_average'       => is_array( $load ) ? array_map( 'floatval', $load ) : null,
			'memory'             => $mem,
			'disk_free_gb'       => $free ? round( $free / 1073741824, 1 ) : null,
			'object_cache'       => wp_using_ext_object_cache(),
			'brotli'             => function_exists( 'brotli_compress' ),
			'webp_encoder'       => Images::supports( 'webp' ),
			'avif_encoder'       => Images::supports( 'avif' ),
			'wp_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'behind_cloudflare'  => isset( $_SERVER['HTTP_CF_RAY'] ),
			'cache_usage'        => Page_Cache::usage(),
		);
	}

	private static function wordpress() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all     = get_plugins();
		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $all[ $file ] ) && 'fulgur-cache/fulgur-cache.php' !== $file ) {
				$plugins[] = $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'];
			}
		}
		$theme  = wp_get_theme();
		$counts = array();
		foreach ( array( 'post', 'page', 'product' ) as $type ) {
			if ( post_type_exists( $type ) ) {
				$counts[ $type ] = (int) wp_count_posts( $type )->publish;
			}
		}
		return array(
			'version'        => get_bloginfo( 'version' ),
			'multisite'      => is_multisite(),
			'locale'         => get_locale(),
			'theme'          => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (enfant de ' . $theme->parent()->get( 'Name' ) . ')' : '' ),
			'block_theme'    => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'active_plugins' => $plugins,
			'published'      => $counts,
			'images'         => Images::total_images(),
			'woocommerce'    => class_exists( 'WooCommerce' ),
		);
	}

	private static function settings() {
		$s = Settings::all();
		unset( $s['cloudflare_token'], $s['cloudflare_zone'], $s['cdn_url'], $s['onboarding_done'] );
		return $s;
	}

	/**
	 * Analyse du HTML d'origine (sans optimisation) de la page d'accueil.
	 */
	private static function html_audit() {
		$res = wp_remote_get(
			add_query_arg( 'fulgur_off', '1', home_url( '/' ) ),
			array(
				'timeout'    => 20,
				'sslverify'  => false,
				'user-agent' => 'Mozilla/5.0 (compatible; FulgurAudit/1.0)',
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'error' => $res->get_error_message() );
		}
		$html = wp_remote_retrieve_body( $res );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		preg_match_all( '#<script\b[^>]*\bsrc=["\']([^"\']+)#i', $html, $ext_js );
		preg_match_all( '#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $inline_js );
		preg_match_all( '#<link\b[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)#i', $html, $css );
		preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $inline_css );

		$third = array();
		foreach ( array_merge( $ext_js[1], $css[1] ) as $url ) {
			$h = wp_parse_url( $url, PHP_URL_HOST );
			if ( $h && strtolower( $h ) !== strtolower( $host ) ) {
				$third[ $h ] = ( $third[ $h ] ?? 0 ) + 1;
			}
		}
		arsort( $third );

		$css_bytes = 0;
		foreach ( $css[1] as $url ) {
			$file = Assets::url_to_path( $url );
			if ( $file ) {
				$css_bytes += filesize( $file );
			}
		}

		$builders = array();
		foreach ( array( 'elementor' => 'Elementor', 'et_pb_' => 'Divi', 'fl-builder' => 'Beaver Builder', 'vc_row' => 'WPBakery', 'brxe-' => 'Bricks', 'oxy-' => 'Oxygen', 'wp-block-' => 'Gutenberg', 'kadence' => 'Kadence', 'generateblocks' => 'GenerateBlocks', 'spectra' => 'Spectra' ) as $marker => $name ) {
			if ( false !== stripos( $html, $marker ) ) {
				$builders[] = $name;
			}
		}

		return array(
			'html_kb'              => round( strlen( $html ) / 1024, 1 ),
			'dom_elements_approx'  => substr_count( $html, '<' ) - substr_count( $html, '</' ),
			'external_scripts'     => count( $ext_js[1] ),
			'inline_scripts'       => count( $inline_js[1] ),
			'inline_scripts_kb'    => round( strlen( implode( '', $inline_js[1] ) ) / 1024, 1 ),
			'stylesheets'          => count( $css[1] ),
			'local_css_kb'         => round( $css_bytes / 1024, 1 ),
			'inline_css_kb'        => round( strlen( implode( '', $inline_css[1] ) ) / 1024, 1 ),
			'images'               => substr_count( strtolower( $html ), '<img' ),
			'iframes'              => substr_count( strtolower( $html ), '<iframe' ),
			'youtube_embeds'       => preg_match_all( '#youtube(?:-nocookie)?\.com/embed#i', $html ),
			'uses_jquery'          => false !== stripos( $html, 'jquery' ),
			'google_fonts'         => false !== stripos( $html, 'fonts.googleapis.com' ),
			'page_builders'        => $builders,
			'third_party_domains'  => array_slice( $third, 0, 15, true ),
			'script_sources'       => array_slice( array_map( array( __CLASS__, 'short_url' ), $ext_js[1] ), 0, 40 ),
		);
	}

	public static function short_url( $url ) {
		$url = strtok( (string) $url, '?' );
		return preg_replace( '#^https?://' . preg_quote( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '#' ) . '#i', '', $url );
	}

	/**
	 * Google PageSpeed Insights (Lighthouse) — résumé compact des métriques et opportunités.
	 */
	public static function pagespeed( $url, $strategy ) {
		$key  = Ai::secret( 'psi' );
		$api  = add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => $strategy,
				'category' => 'performance',
				'key'      => $key ? $key : null,
			),
			'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
		);
		$res = wp_remote_get( $api . '&category=best-practices', array( 'timeout' => 90 ) );
		if ( is_wp_error( $res ) ) {
			return array( 'error' => $res->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['lighthouseResult'] ) ) {
			return array( 'error' => $data['error']['message'] ?? 'PageSpeed indisponible (quota ?)' );
		}
		$lr     = $data['lighthouseResult'];
		$audits = $lr['audits'];
		$out    = array(
			'score'   => isset( $lr['categories']['performance']['score'] ) ? (int) round( $lr['categories']['performance']['score'] * 100 ) : null,
			'metrics' => array(),
		);
		foreach ( array( 'first-contentful-paint', 'largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index', 'server-response-time' ) as $id ) {
			if ( isset( $audits[ $id ] ) ) {
				$out['metrics'][ $id ] = $audits[ $id ]['displayValue'] ?? null;
			}
		}
		if ( isset( $audits['largest-contentful-paint-element']['details']['items'][0] ) ) {
			$item = $audits['largest-contentful-paint-element']['details']['items'][0];
			$node = $item['items'][0]['node'] ?? ( $item['node'] ?? array() );
			$out['lcp_element'] = isset( $node['snippet'] ) ? substr( $node['snippet'], 0, 300 ) : null;
		}
		$out['console_errors'] = isset( $audits['errors-in-console']['details']['items'] ) ? count( $audits['errors-in-console']['details']['items'] ) : 0;

		$perf_ids = array();
		foreach ( (array) ( $lr['categories']['performance']['auditRefs'] ?? array() ) as $ref ) {
			$perf_ids[ $ref['id'] ] = true;
		}
		$issues = array();
		foreach ( $audits as $id => $a ) {
			if ( $perf_ids && ! isset( $perf_ids[ $id ] ) ) {
				continue;
			}
			if ( ! isset( $a['score'] ) || $a['score'] >= 0.9 || ( $a['scoreDisplayMode'] ?? '' ) === 'informative' || ( $a['scoreDisplayMode'] ?? '' ) === 'notApplicable' ) {
				continue;
			}
			$entry = array(
				'id'    => $id,
				'title' => $a['title'],
				'value' => $a['displayValue'] ?? '',
			);
			if ( ! empty( $a['details']['overallSavingsMs'] ) ) {
				$entry['savings_ms'] = (int) $a['details']['overallSavingsMs'];
			}
			if ( ! empty( $a['details']['items'] ) && is_array( $a['details']['items'] ) ) {
				$urls = array();
				foreach ( array_slice( $a['details']['items'], 0, 5 ) as $it ) {
					if ( ! empty( $it['url'] ) ) {
						$urls[] = self::short_url( $it['url'] );
					} elseif ( ! empty( $it['entity'] ) ) {
						$urls[] = is_array( $it['entity'] ) ? ( $it['entity']['text'] ?? '' ) : $it['entity'];
					}
				}
				if ( $urls ) {
					$entry['items'] = $urls;
				}
			}
			$issues[] = $entry;
		}
		usort(
			$issues,
			static function ( $a, $b ) {
				return ( $b['savings_ms'] ?? 0 ) <=> ( $a['savings_ms'] ?? 0 );
			}
		);
		$out['issues'] = array_slice( $issues, 0, 15 );
		return $out;
	}
}
