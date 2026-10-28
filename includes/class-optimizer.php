<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Optimization pipeline for the final HTML.
 * Works with regular expressions (faster and less destructive than DOMDocument on HTML5).
 */
class Optimizer {

	/** @var string[] Link headers to send (103 Early Hints / preload). */
	public static $early_hints = array();

	/** @var string[] Tags to inject into the <head>. */
	private $head = array();

	/** @var string[] Scripts/styles to inject before </body>. */
	private $footer = array();

	/** @var array */
	private $s;

	/** @var array Protected areas (scripts, styles, pre…). */
	private $protected = array();

	public function __construct() {
		$this->s = Settings::all();
	}

	public function process( $html ) {
		self::$early_hints = array();

		if ( false !== strpos( $html, 'fulgur-skip' ) && preg_match( '/<meta[^>]+name=["\']fulgur-skip["\']/i', $html ) ) {
			return $html;
		}

		$html = apply_filters( 'fulgur_html_before', $html );

		// 1. Fonts and CSS.
		$html = Assets::google_fonts( $html, $this->s['google_fonts'], $this->head );
		if ( $this->s['minify_css'] ) {
			$html = Assets::optimize_css( $html, (bool) $this->s['font_display_swap'] );
		}

		// 2. Media (on the HTML stripped of scripts/styles so nothing breaks).
		$html = $this->protect( $html );
		$html = Media::process( $html, $this->s, $this->head, $this->footer );
		$html = $this->restore( $html );

		// 3. Critical CSS / unused CSS removal (on the final HTML, before scripts are transformed).
		if ( 'off' !== $this->s['css_mode'] ) {
			$html = Used_Css::process( $html, $this->s['css_mode'], Settings::lines( 'css_safelist' ) );
		}

		// 3b. JavaScript.
		if ( $this->s['delay_js'] || $this->s['defer_js'] ) {
			$html = $this->scripts( $html );
		}

		// 4. Preconnects and smart preloading.
		$this->resource_hints();
		$this->speculation();

		// 5. CDN.
		if ( $this->s['cdn_enabled'] && $this->s['cdn_url'] ) {
			$html = Cdn::rewrite( $html );
		}

		$html = $this->inject( $html );

		if ( $this->s['minify_html'] ) {
			$html = self::minify_html( $html );
		}

		if ( self::$early_hints && $this->s['early_hints'] && ! headers_sent() ) {
			header( 'Link: ' . implode( ', ', self::$early_hints ), false );
		}

		return apply_filters( 'fulgur_html_after', $html );
	}

	/* ------------------------------------------------------------------ */
	/* JavaScript: defer and delay until interaction                      */
	/* ------------------------------------------------------------------ */

	private function scripts( $html ) {
		$delay            = (bool) $this->s['delay_js'];
		$defer            = (bool) $this->s['defer_js'];
		$delay_exclusions = array_merge( array( 'fulgur-' ), Settings::lines( 'delay_exclusions' ) );
		$defer_exclusions = array_merge( array( 'fulgur-' ), Settings::lines( 'defer_exclusions' ) );

		// Excluded WordPress handles: their attached inline scripts (-js-extra, -js-before…) must follow.
		$locked_delay = array();
		$locked_defer = array();
		if ( preg_match_all( '#<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>#i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $tag ) {
				if ( ! preg_match( '/\bid=["\']([^"\']+)-js["\']/i', $tag[0], $id ) ) {
					continue;
				}
				if ( self::matches( $tag[1], $delay_exclusions ) ) {
					$locked_delay[ $id[1] ] = true;
				}
				if ( self::matches( $tag[1], $defer_exclusions ) ) {
					$locked_defer[ $id[1] ] = true;
				}
			}
		}

		$delayed = false;

		$html = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( $delay, $defer, $delay_exclusions, $defer_exclusions, $locked_delay, $locked_defer, &$delayed ) {
				$attrs   = $m[1];
				$content = $m[2];

				if ( preg_match( '/\btype=["\']?([^"\'\s>]+)/i', $attrs, $type ) ) {
					$type = strtolower( $type[1] );
					if ( ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
						return $m[0]; // JSON-LD, templates, speculationrules, importmap…
					}
				} else {
					$type = '';
				}
				if ( preg_match( '/\bdata-(fulgur-skip|no-optimize|cfasync)\b/i', $attrs ) || preg_match( '/\bid=["\']fulgur-/i', $attrs ) ) {
					return $m[0];
				}

				$has_src = preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $attrs, $src );
				$subject = $has_src ? $src[1] . ' ' . $attrs : $attrs . ' ' . $content;
				$handle  = preg_match( '/\bid=["\']([^"\']+?)-js(?:-(?:extra|before|after|translations))?["\']/i', $attrs, $h ) ? $h[1] : '';

				if ( ! $has_src && '' === trim( $content ) ) {
					return $m[0];
				}

				// a) Load on first user interaction.
				if ( $delay && ! self::matches( $subject, $delay_exclusions ) && ! isset( $locked_delay[ $handle ] ) ) {
					$delayed = true;
					$attrs   = preg_replace( '/\s+type=["\']?[^"\'\s>]+["\']?/i', '', $attrs );
					if ( 'module' === $type ) {
						$attrs .= ' data-fulgur-type="module"';
					}
					if ( $has_src ) {
						$attrs = str_replace( $src[0], 'data-fulgur-src="' . $src[1] . '"', $attrs );
					}
					return '<script type="fulgur/delay"' . $attrs . '>' . $content . '</script>';
				}

				// b) Defer execution until after HTML parsing, order preserved.
				if ( $defer && 'module' !== $type && ! self::matches( $subject, $defer_exclusions ) && ! isset( $locked_defer[ $handle ] ) ) {
					if ( $has_src ) {
						if ( preg_match( '/\b(defer|async)\b/i', $attrs ) ) {
							return $m[0];
						}
						return '<script' . $attrs . ' defer></script>';
					}
					if ( false !== stripos( $content, 'document.write' ) ) {
						return $m[0];
					}
					// An inline script cannot be "defer": turn it into a data URI to keep the order.
					return '<script' . $attrs . ' defer src="data:text/javascript;base64,' . base64_encode( $content ) . '"></script>';
				}

				return $m[0];
			},
			$html
		);

		if ( $delayed ) {
			$this->footer[] = '<script id="fulgur-delay">' . self::delay_loader( (int) $this->s['delay_timeout'] ) . '</script>';
		}

		return $html;
	}

	private static function matches( $subject, array $patterns ) {
		foreach ( $patterns as $p ) {
			if ( '' !== $p && \Fulgur_Serve::match( $p, $subject ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delayed script loader: runs scripts in their original order, then replays DOMContentLoaded and load.
	 */
	private static function delay_loader( $timeout ) {
		$js = <<<'JS'
(function(w,d){var E=["keydown","mousedown","mousemove","touchstart","touchmove","wheel","scroll","pointerdown"],go=!1,q,dcl=[],ld=[],da=d.addEventListener,wa=w.addEventListener;
function run(){if(go)return;go=!0;E.forEach(function(e){w.removeEventListener(e,run,{passive:!0})});
q=[].slice.call(d.querySelectorAll('script[type="fulgur/delay"]'));
d.addEventListener=function(t,f,o){if(t==="DOMContentLoaded"){dcl.push(f);return}return da.call(d,t,f,o)};
w.addEventListener=function(t,f,o){if(t==="DOMContentLoaded"||t==="load"){(t==="load"?ld:dcl).push(f);return}return wa.call(w,t,f,o)};
next()}
function fire(){d.addEventListener=da;w.addEventListener=wa;
dcl.forEach(function(f){try{f.call(d,new Event("DOMContentLoaded"))}catch(e){console.error(e)}});
ld.forEach(function(f){try{f.call(w,new Event("load"))}catch(e){console.error(e)}});
if(typeof w.onload==="function"){try{w.onload(new Event("load"))}catch(e){}}
d.dispatchEvent(new Event("fulgur:loaded"))}
function next(){var o=q.shift();if(!o){return fire()}var n=d.createElement("script");
[].forEach.call(o.attributes,function(a){if(a.name!=="type"&&a.name!=="data-fulgur-src"&&a.name!=="data-fulgur-type")n.setAttribute(a.name,a.value)});
var t=o.getAttribute("data-fulgur-type");if(t)n.type=t;var s=o.getAttribute("data-fulgur-src");
if(s){n.src=s;n.async=!1;n.onload=n.onerror=next;o.parentNode.replaceChild(n,o)}else{n.text=o.text;o.parentNode.replaceChild(n,o);next()}}
E.forEach(function(e){w.addEventListener(e,run,{passive:!0})});
if(TIMEOUT>0){w.addEventListener("load",function(){setTimeout(run,TIMEOUT*1e3)})}
})(window,document);
JS;
		return str_replace( 'TIMEOUT', (string) max( 0, (int) $timeout ), $js );
	}

	/* ------------------------------------------------------------------ */
	/* Preconnects, Speculation Rules                                     */
	/* ------------------------------------------------------------------ */

	private function resource_hints() {
		foreach ( Settings::lines( 'preconnect_domains' ) as $domain ) {
			$domain = esc_url( false === strpos( $domain, '//' ) ? 'https://' . $domain : $domain );
			if ( $domain ) {
				$this->head[]        = '<link rel="preconnect" href="' . $domain . '" crossorigin>';
				self::$early_hints[] = '<' . $domain . '>; rel=preconnect; crossorigin';
			}
		}
	}

	private function speculation() {
		$mode = $this->s['speculation'];
		if ( 'off' === $mode ) {
			return;
		}
		// WordPress 6.8+ handles Speculation Rules natively: we only adjust its configuration (see Tweaks::core_speculation).
		if ( ! function_exists( 'wp_get_speculation_rules_configuration' ) ) {
			$this->head[] = '<script type="speculationrules">' . wp_json_encode( self::speculation_rules( $mode, $this->s['speculation_eagerness'] ) ) . '</script>';
		}
		if ( $this->s['instant_fallback'] ) {
			$this->footer[] = '<script id="fulgur-instant">' . self::instant_fallback() . '</script>';
		}
	}

	public static function speculation_exclusions( $with_defaults = true ) {
		$paths = $with_defaults ? array( '/wp-admin/*', '/wp-login.php*', '/*\\?*', '/*.php*', '/wp-content/*', '/wp-json/*', '/feed/*', '/*/feed/*' ) : array();
		foreach ( Settings::lines( 'exclude_urls' ) as $url ) {
			if ( false === strpbrk( $url, '#' ) ) {
				$paths[] = '/*' . trim( $url, '*' ) . '*';
			}
		}
		return array_values( array_unique( $paths ) );
	}

	public static function speculation_rules( $mode, $eagerness ) {
		$not = array();
		foreach ( self::speculation_exclusions() as $path ) {
			$not[] = array( 'href_matches' => $path );
		}
		$not[] = array( 'selector_matches' => 'a[rel~="nofollow"], .no-prerender, .no-prerender a, [download]' );

		return array(
			$mode => array(
				array(
					'source'    => 'document',
					'where'     => array(
						'and' => array(
							array( 'href_matches' => '/*' ),
							array( 'not' => array( 'or' => $not ) ),
						),
					),
					'eagerness' => in_array( $eagerness, array( 'conservative', 'moderate', 'eager' ), true ) ? $eagerness : 'moderate',
				),
			),
		);
	}

	/**
	 * Hover prefetching for browsers without Speculation Rules (Safari, Firefox).
	 */
	private static function instant_fallback() {
		return <<<'JS'
(function(d){if(HTMLScriptElement.supports&&HTMLScriptElement.supports("speculationrules"))return;var seen={},t;
function ok(a){return a&&a.href&&a.origin===location.origin&&a.pathname!==location.pathname&&!a.hasAttribute("download")&&!/\/wp-(admin|login)|\?|#|\.(php|pdf|zip|jpe?g|png|webp|avif)$/i.test(a.href)&&!a.closest(".no-prerender")}
function pf(u){if(seen[u])return;seen[u]=1;var l=d.createElement("link");l.rel="prefetch";l.href=u;d.head.appendChild(l)}
d.addEventListener("mouseover",function(e){var a=e.target.closest&&e.target.closest("a");if(!ok(a))return;clearTimeout(t);t=setTimeout(function(){pf(a.href)},65)},{passive:!0});
d.addEventListener("touchstart",function(e){var a=e.target.closest&&e.target.closest("a");if(ok(a))pf(a.href)},{passive:!0});
})(document);
JS;
	}

	/* ------------------------------------------------------------------ */
	/* Injection and utilities                                            */
	/* ------------------------------------------------------------------ */

	private function inject( $html ) {
		$head = array_unique( $this->head );
		if ( $head ) {
			// As high as possible in the <head>, right after <meta charset>.
			if ( preg_match( '/<meta\s+charset=[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
				$pos  = $m[0][1] + strlen( $m[0][0] );
				$html = substr( $html, 0, $pos ) . implode( '', $head ) . substr( $html, $pos );
			} else {
				$html = preg_replace( '/<head\b[^>]*>/i', '$0' . implode( '', $head ), $html, 1 );
			}
		}
		if ( $this->footer ) {
			$pos = strripos( $html, '</body>' );
			if ( false !== $pos ) {
				$html = substr( $html, 0, $pos ) . implode( '', array_unique( $this->footer ) ) . substr( $html, $pos );
			}
		}
		return $html;
	}

	private function protect( $html ) {
		$this->protected = array();
		return preg_replace_callback(
			'#<(script|style|noscript|textarea|pre|template)\b.*?</\1>|<!--.*?-->#is',
			function ( $m ) {
				$key                     = '<!--FULGUR' . count( $this->protected ) . '-->';
				$this->protected[ $key ] = $m[0];
				return $key;
			},
			$html
		);
	}

	private function restore( $html ) {
		return $this->protected ? strtr( $html, $this->protected ) : $html;
	}

	/**
	 * Conservative HTML minification (keeps meaningful whitespace between inline elements).
	 */
	public static function minify_html( $html ) {
		$keep = array();
		$html = preg_replace_callback(
			'#<(script|style|textarea|pre|code)\b.*?</\1>|<!--\[if.*?<!\[endif\]-->#is',
			function ( $m ) use ( &$keep ) {
				$key          = "\x1AF" . count( $keep ) . "\x1A";
				$keep[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		// HTML comments (except those some tools rely on).
		$html = preg_replace( '/<!--(?!\s*(?:\[if|<!|noindex|\/noindex|esi|fulgur|google_ad|ko\s|\/ko))[\s\S]*?-->/', '', $html );
		$html  = preg_replace( '/\s{2,}/', ' ', $html );
		$html  = preg_replace( '/>\s+</', '> <', $html );
		// Whitespace around block-level tags has no visual effect.
		$block = 'html|head|body|meta|link|title|base|div|section|article|header|footer|nav|main|aside|ul|ol|li|dl|dt|dd|p|h[1-6]|table|thead|tbody|tfoot|tr|td|th|form|fieldset|figure|figcaption|picture|source|br|hr|option|!DOCTYPE';
		$html  = preg_replace( '#\s*(</?(?:' . $block . ')\b[^>]*>)\s*#i', '$1', $html );
		return strtr( $html, $keep );
	}
}
