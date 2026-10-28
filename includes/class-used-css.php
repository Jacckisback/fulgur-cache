<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Unused CSS removal and critical CSS, 100% in PHP (no external service).
 *
 * For each page: inventory of the tags, classes, ids and attributes present in the HTML,
 * then only the CSS rules that can apply are inlined in the <head>.
 * Full stylesheets stay where they are in the document (cascade order is preserved):
 * - "critical" mode: loaded asynchronously right after rendering;
 * - "remove" mode:   loaded on first interaction (maximum gain).
 */
class Used_Css {

	const MAX_INLINE = 350000; // Above this, keep the original stylesheets (abnormally heavy theme).

	/** @var array Page inventory. */
	private $tags = array();
	private $classes = array();
	private $ids = array();
	private $attrs = array();
	private $safelist = array();

	public static function process( $html, $mode, array $safelist ) {
		if ( 'off' === $mode ) {
			return $html;
		}
		return ( new self( $safelist ) )->run( $html, $mode );
	}

	private function __construct( array $safelist ) {
		$this->safelist = $safelist;
	}

	private function run( $html, $mode ) {
		if ( ! preg_match_all( '#<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>#i', $html, $links, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$sheets = array();
		foreach ( $links[0] as $link ) {
			$tag   = $link[0];
			$href  = Media::attr( $tag, 'href' );
			$media = strtolower( trim( Media::attr( $tag, 'media' ) ) );
			if ( ! $href || 'print' === $media || preg_match( '/\bdata-fulgur-skip\b/i', $tag ) ) {
				continue;
			}
			$file = Assets::url_to_path( $href );
			if ( ! $file || '.css' !== substr( $file, -4 ) ) {
				continue;
			}
			$sheets[] = array(
				'tag'   => $tag,
				'file'  => $file,
				'url'   => $href,
				'media' => ( '' === $media || 'all' === $media || 'screen' === $media ) ? '' : $media,
			);
		}
		if ( ! $sheets ) {
			return $html;
		}

		$this->inventory( $html );

		$used = '';
		foreach ( $sheets as $sheet ) {
			$tree = self::parsed( $sheet['file'], $sheet['url'] );
			if ( null === $tree ) {
				return $html; // Unreadable stylesheet: take no risk.
			}
			$css = $this->filter( $tree );
			if ( '' !== $sheet['media'] && '' !== $css ) {
				$css = '@media ' . $sheet['media'] . '{' . $css . '}';
			}
			$used .= $css;
		}
		$used = self::prune_at_rules( $used );

		if ( strlen( $used ) > self::MAX_INLINE ) {
			return $html;
		}

		// Replace the <link> tags (at their original position).
		$first = true;
		foreach ( $sheets as $sheet ) {
			$original = $sheet['media'] ? $sheet['media'] : 'all';
			if ( 'remove' === $mode ) {
				$new = preg_replace( '/\brel=["\']stylesheet["\']/i', 'rel="fulgur-css"', $sheet['tag'], 1 );
				$new = preg_replace( '/\s+media=["\'][^"\']*["\']/i', '', $new );
				$new = str_replace( '<link', '<link data-media="' . esc_attr( $original ) . '"', $new );
			} else {
				$new = preg_replace( '/\s+media=["\'][^"\']*["\']/i', '', $sheet['tag'] );
				$new = str_replace( '<link', '<link media="print" data-media="' . esc_attr( $original ) . '" onload="this.media=this.dataset.media;this.onload=null"', $new );
				$new .= '<noscript>' . $sheet['tag'] . '</noscript>';
			}
			if ( $first ) {
				$new   = '<style id="fulgur-used-css">' . $used . '</style>' . $new;
				$first = false;
			}
			$html = self::replace_once( $html, $sheet['tag'], $new );
		}

		if ( 'remove' === $mode ) {
			$loader = '<script id="fulgur-css-loader">(function(w,d){var E=["keydown","mousedown","mousemove","touchstart","wheel","scroll","pointerdown"],done=0;'
				. 'function go(){if(done)return;done=1;E.forEach(function(e){w.removeEventListener(e,go,{passive:!0})});'
				. 'd.querySelectorAll(\'link[rel="fulgur-css"]\').forEach(function(l){l.media=l.getAttribute("data-media")||"all";l.rel="stylesheet"})}'
				. 'E.forEach(function(e){w.addEventListener(e,go,{passive:!0})});w.addEventListener("beforeprint",go)})(window,document);</script>';
			$pos    = strripos( $html, '</body>' );
			if ( false !== $pos ) {
				$html = substr( $html, 0, $pos ) . $loader . substr( $html, $pos );
			}
		}

		return $html;
	}

	private static function replace_once( $haystack, $needle, $replace ) {
		$pos = strpos( $haystack, $needle );
		return false === $pos ? $haystack : substr_replace( $haystack, $replace, $pos, strlen( $needle ) );
	}

	/* ------------------------------------------------------------------ */
	/* HTML inventory                                                      */
	/* ------------------------------------------------------------------ */

	private function inventory( $html ) {
		// Classes added dynamically by inline scripts (classList.add('x'), addClass("x")…).
		if ( preg_match_all( '#<script\b[^>]*>(.*?)</script>#is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $js ) {
				if ( preg_match_all( '/["\']([a-zA-Z_][\w-]{1,50}(?:\s+[a-zA-Z_][\w-]{1,50})*)["\']/', $js, $words ) ) {
					foreach ( $words[1] as $group ) {
						foreach ( preg_split( '/\s+/', $group ) as $w ) {
							$this->classes[ $w ] = true;
						}
					}
				}
			}
		}

		$body = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );

		if ( preg_match_all( '/<([a-zA-Z][a-zA-Z0-9-]*)\b([^>]*)>/', $body, $tags, PREG_SET_ORDER ) ) {
			foreach ( $tags as $t ) {
				$this->tags[ strtolower( $t[1] ) ] = true;
				$attrs = $t[2];
				if ( '' === trim( $attrs ) ) {
					continue;
				}
				if ( preg_match_all( '/([^\s"\'=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $attrs, $am, PREG_SET_ORDER ) ) {
					foreach ( $am as $a ) {
						$name                 = strtolower( $a[1] );
						$this->attrs[ $name ] = true;
						$value                = isset( $a[4] ) && '' !== $a[4] ? $a[4] : ( isset( $a[3] ) && '' !== $a[3] ? $a[3] : ( $a[2] ?? '' ) );
						if ( 'class' === $name ) {
							foreach ( preg_split( '/\s+/', html_entity_decode( $value ) ) as $c ) {
								if ( '' !== $c ) {
									$this->classes[ $c ] = true;
								}
							}
						} elseif ( 'id' === $name && '' !== $value ) {
							$this->ids[ html_entity_decode( $value ) ] = true;
						}
					}
				}
			}
		}
		foreach ( array( 'html', 'body', 'head' ) as $always ) {
			$this->tags[ $always ] = true;
		}
	}

	/* ------------------------------------------------------------------ */
	/* CSS parsing (cached per file)                                      */
	/* ------------------------------------------------------------------ */

	private static function parsed( $file, $url ) {
		$key   = md5( $file . filemtime( $file ) . filesize( $file ) . FULGUR_VERSION );
		$cache = FULGUR_CACHE_DIR . '/ucss/' . $key . '.dat';
		if ( is_file( $cache ) ) {
			$tree = @unserialize( (string) file_get_contents( $cache ), array( 'allowed_classes' => false ) );
			if ( is_array( $tree ) ) {
				return $tree;
			}
		}
		$css = @file_get_contents( $file );
		if ( false === $css ) {
			return null;
		}
		// Files already minified by Fulgur have absolute URLs; others are fixed here.
		if ( false === strpos( wp_normalize_path( $file ), wp_normalize_path( Assets::min_dir() ) ) ) {
			$css = Assets::absolutize_urls( $css, $url );
		}
		$tree = self::parse( Assets::minify_css( $css ) );
		wp_mkdir_p( dirname( $cache ) );
		Installer::atomic_write( $cache, serialize( $tree ) );
		return $tree;
	}

	/**
	 * CSS parser: builds a tree of rules, conditional at-rule blocks and raw blocks.
	 */
	public static function parse( $css ) {
		$i = 0;
		return self::parse_block( $css, $i, strlen( $css ) );
	}

	private static function parse_block( $css, &$i, $len ) {
		$nodes = array();
		$buf   = '';
		while ( $i < $len ) {
			$n = strcspn( $css, "{};\"'", $i );
			if ( $n ) {
				$buf .= substr( $css, $i, $n );
				$i   += $n;
				if ( $i >= $len ) {
					break;
				}
			}
			$c = $css[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$end  = self::skip_string( $css, $i, $len );
				$buf .= substr( $css, $i, $end - $i );
				$i    = $end;
				continue;
			}
			if ( '{' === $c ) {
				++$i;
				$prelude = trim( $buf );
				$buf     = '';
				if ( '' !== $prelude && '@' === $prelude[0] ) {
					$name = preg_match( '/^@([\w-]+)/', $prelude, $m ) ? strtolower( $m[1] ) : '';
					if ( in_array( $name, array( 'media', 'supports', 'layer', 'container', 'document', '-moz-document', 'scope', 'starting-style' ), true ) ) {
						$nodes[] = array( 'at', $prelude, self::parse_block( $css, $i, $len ) );
					} else {
						$nodes[] = array( 'raw', $prelude, self::read_body( $css, $i, $len ), $name );
					}
				} else {
					$nodes[] = array( 'rule', $prelude, self::read_body( $css, $i, $len ) );
				}
				continue;
			}
			if ( '}' === $c ) {
				++$i;
				return $nodes;
			}
			// ';': top-level statement (@import, @layer a, b;).
			$stmt = trim( $buf );
			if ( '' !== $stmt && 0 !== stripos( $stmt, '@charset' ) ) {
				$nodes[] = array( 'stmt', $stmt );
			}
			$buf = '';
			++$i;
		}
		return $nodes;
	}

	private static function skip_string( $css, $i, $len ) {
		$q = $css[ $i ];
		++$i;
		while ( $i < $len ) {
			if ( '\\' === $css[ $i ] ) {
				$i += 2;
				continue;
			}
			if ( $q === $css[ $i ] ) {
				return $i + 1;
			}
			++$i;
		}
		return $len;
	}

	private static function read_body( $css, &$i, $len ) {
		$start = $i;
		$depth = 1;
		while ( $i < $len ) {
			$n  = strcspn( $css, "{}\"'", $i );
			$i += $n;
			if ( $i >= $len ) {
				break;
			}
			$c = $css[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$i = self::skip_string( $css, $i, $len );
				continue;
			}
			$depth += ( '{' === $c ) ? 1 : -1;
			++$i;
			if ( 0 === $depth ) {
				return substr( $css, $start, $i - 1 - $start );
			}
		}
		return substr( $css, $start );
	}

	/* ------------------------------------------------------------------ */
	/* Filtering                                                           */
	/* ------------------------------------------------------------------ */

	private function filter( array $nodes ) {
		$out = '';
		foreach ( $nodes as $node ) {
			switch ( $node[0] ) {
				case 'rule':
					$kept = array();
					foreach ( self::split_selectors( $node[1] ) as $sel ) {
						if ( $this->selector_used( $sel ) ) {
							$kept[] = $sel;
						}
					}
					if ( $kept ) {
						$out .= implode( ',', $kept ) . '{' . $node[2] . '}';
					}
					break;
				case 'at':
					$inner = $this->filter( $node[2] );
					if ( '' !== $inner ) {
						$out .= $node[1] . '{' . $inner . '}';
					}
					break;
				case 'raw':
					$out .= $node[1] . '{' . $node[2] . '}'; // @font-face, @keyframes… sorted out afterwards.
					break;
				case 'stmt':
					$out .= $node[1] . ';';
					break;
			}
		}
		return $out;
	}

	/**
	 * Splits "a, b:is(c, d)" into selectors without breaking parentheses or brackets.
	 */
	public static function split_selectors( $prelude ) {
		$parts = array();
		$depth = 0;
		$cur   = '';
		$len   = strlen( $prelude );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $prelude[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$cur .= $c . $prelude[ ++$i ];
				continue;
			}
			if ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ')' === $c || ']' === $c ) {
				--$depth;
			} elseif ( ',' === $c && 0 === $depth ) {
				$parts[] = trim( $cur );
				$cur     = '';
				continue;
			}
			$cur .= $c;
		}
		$parts[] = trim( $cur );
		return array_filter( $parts, 'strlen' );
	}

	/**
	 * A selector is kept if each of its parts (tag, class, id, attribute) exists in the page.
	 * Pseudo-classes (:hover, :not(), :is()…) are ignored: a deliberately cautious approach.
	 */
	public function selector_used( $sel ) {
		// Attributes: the name must exist.
		if ( preg_match_all( '/\[\s*([\w:-]+)/', $sel, $am ) ) {
			foreach ( $am[1] as $attr ) {
				if ( ! isset( $this->attrs[ strtolower( $attr ) ] ) ) {
					return false;
				}
			}
		}
		$sel = preg_replace( '/\[(?:[^\[\]"\']|"[^"]*"|\'[^\']*\')*\]/', '', $sel );
		// Pseudo-classes / elements, with their arguments if any.
		$sel = preg_replace( '/(?<!\\\\)::?[a-zA-Z-]+(\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?/', '', $sel );

		if ( preg_match_all( '/([.#])((?:[\w-]|\\\\[0-9a-fA-F]{1,6}\s?|\\\\.)+)/', $sel, $tokens, PREG_SET_ORDER ) ) {
			foreach ( $tokens as $t ) {
				$name = self::unescape( $t[2] );
				if ( '.' === $t[1] ? isset( $this->classes[ $name ] ) : isset( $this->ids[ $name ] ) ) {
					continue;
				}
				if ( ! $this->safelisted( $name ) ) {
					return false;
				}
			}
		}
		// Tags (start of each compound selector).
		if ( preg_match_all( '/(?:^|[\s>+~])([a-zA-Z][a-zA-Z0-9-]*)/', $sel, $tm ) ) {
			foreach ( $tm[1] as $tag ) {
				$tag = strtolower( $tag );
				if ( ! isset( $this->tags[ $tag ] ) && false === strpos( $tag, '-' ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private function safelisted( $name ) {
		foreach ( $this->safelist as $pattern ) {
			if ( '' !== $pattern && false !== stripos( $name, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	private static function unescape( $s ) {
		$s = preg_replace_callback(
			'/\\\\([0-9a-fA-F]{1,6})\s?/',
			static function ( $m ) {
				return html_entity_decode( '&#' . hexdec( $m[1] ) . ';', ENT_QUOTES, 'UTF-8' );
			},
			$s
		);
		return preg_replace( '/\\\\(.)/s', '$1', $s );
	}

	/**
	 * Keeps only the @font-face and @keyframes actually used by the kept CSS.
	 */
	private static function prune_at_rules( $css ) {
		$blocks = array();
		$rest   = preg_replace_callback(
			'/@(?:-webkit-)?(?:keyframes|font-face)\b[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/i',
			function ( $m ) use ( &$blocks ) {
				$key            = "\x1AK" . count( $blocks ) . "\x1A";
				$blocks[ $key ] = $m[0];
				return $key;
			},
			$css
		);
		$seen = array();
		foreach ( $blocks as $key => $block ) {
			if ( preg_match( '/^@(?:-webkit-)?keyframes\s+([^\s{]+)/i', $block, $m ) ) {
				$name = trim( $m[1], '"\'' );
				$keep = (bool) preg_match( '/[:\s,]' . preg_quote( $name, '/' ) . '(?![\w-])/', $rest );
			} elseif ( preg_match( '/font-family:\s*([^;}]+)/i', $block, $m ) ) {
				$name = trim( $m[1], " \"'" );
				$keep = false !== stripos( $rest, $name );
			} else {
				$keep = true;
			}
			// Deduplication (same @font-face present in several stylesheets).
			if ( $keep && isset( $seen[ md5( $block ) ] ) ) {
				$keep = false;
			}
			$seen[ md5( $block ) ] = true;
			$blocks[ $key ]        = $keep ? $block : '';
		}
		return strtr( $rest, $blocks );
	}
}
