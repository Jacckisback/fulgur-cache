<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Images et iframes dans le HTML : lazy-load, dimensions (CLS), LCP prioritaire, AVIF/WebP, façades YouTube.
 */
class Media {

	public static function process( $html, array $s, array &$head, array &$footer ) {
		$body = stripos( $html, '<body' );
		if ( false === $body ) {
			return $html;
		}
		$before = substr( $html, 0, $body );
		$after  = substr( $html, $body );

		$has_priority = (bool) preg_match( '/<img\b[^>]*fetchpriority=["\']high["\']/i', $after );
		$index        = 0;
		$lcp_done     = false;

		$after = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( $s, &$head, &$index, &$lcp_done, $has_priority ) {
				$tag = $m[0];
				$src = self::attr( $tag, 'src' );
				if ( ! $src || 0 === strpos( $src, 'data:' ) || preg_match( '/\b(data-fulgur-skip|data-no-lazy|data-lazy-src|data-src)\b/i', $tag ) ) {
					return $tag;
				}
				$eager = $index < (int) $s['eager_images'];
				++$index;

				if ( $s['image_dimensions'] ) {
					$tag = self::dimensions( $tag, $src );
				}

				$picture = null;
				if ( $s['next_gen_images'] ) {
					list( $tag, $picture ) = self::next_gen( $tag, $s['image_format'] );
				}

				// Image LCP : la première image "de contenu" en haut de page.
				$is_lcp = false;
				if ( $has_priority ) {
					$is_lcp = (bool) preg_match( '/fetchpriority=["\']high["\']/i', $tag );
				} elseif ( $eager && ! $lcp_done && ! self::is_decorative( $tag ) ) {
					$is_lcp = true;
					$tag    = self::set_attr( $tag, 'fetchpriority', 'high' );
				}

				if ( $is_lcp && ! $lcp_done ) {
					$lcp_done = true;
					$tag      = self::remove_attr( $tag, 'loading' );
					$tag      = self::remove_attr( $tag, 'decoding' );
					$href     = self::attr( $tag, 'src' );
					$srcset   = self::attr( $tag, 'srcset' );
					$sizes    = self::attr( $tag, 'sizes' );
					$link     = '<link rel="preload" as="image" href="' . esc_url( $href ) . '"'
						. ( $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '"' : '' )
						. ( $sizes ? ' imagesizes="' . esc_attr( $sizes ) . '"' : '' )
						. ' fetchpriority="high">';
					if ( ! $picture ) {
						$head[] = $link;
						if ( ! $srcset ) {
							Optimizer::$early_hints[] = '<' . esc_url_raw( $href ) . '>; rel=preload; as=image; fetchpriority=high';
						}
					}
				} elseif ( $eager ) {
					if ( preg_match( '/\bloading=["\']lazy["\']/i', $tag ) ) {
						$tag = self::remove_attr( $tag, 'loading' );
					}
				} elseif ( $s['lazy_images'] && ! preg_match( '/\bloading=/i', $tag ) && ! preg_match( '/\bclass=["\'][^"\']*\b(skip-lazy|no-lazy)\b/i', $tag ) ) {
					$tag = self::set_attr( $tag, 'loading', 'lazy' );
				}
				if ( ! $is_lcp && ! preg_match( '/\bdecoding=/i', $tag ) ) {
					$tag = self::set_attr( $tag, 'decoding', 'async' );
				}

				return $picture ? str_replace( '%IMG%', $tag, $picture ) : $tag;
			},
			$after
		);

		// Iframes.
		$facade = false;
		$after  = preg_replace_callback(
			'#<iframe\b[^>]*>\s*</iframe>#i',
			function ( $m ) use ( $s, &$facade ) {
				$tag = $m[0];
				$src = self::attr( $tag, 'src' );
				if ( ! $src || preg_match( '/\bdata-fulgur-skip\b/i', $tag ) ) {
					return $tag;
				}
				if ( $s['youtube_facade'] && preg_match( '#(?:youtube(?:-nocookie)?\.com/embed/|youtu\.be/)([A-Za-z0-9_-]{11})#', $src, $yt ) ) {
					$facade = true;
					return self::youtube_facade( $tag, $src, $yt[1] );
				}
				if ( $s['lazy_iframes'] && ! preg_match( '/\bloading=/i', $tag ) ) {
					$tag = str_replace( '<iframe', '<iframe loading="lazy"', $tag );
				}
				return $tag;
			},
			$after
		);

		if ( $facade ) {
			$head[]   = '<style id="fulgur-yt-css">.fulgur-yt{position:relative;display:block;width:100%;max-width:100%;aspect-ratio:16/9;background:#000;cursor:pointer;overflow:hidden;border:0;padding:0}.fulgur-yt img{width:100%;height:100%;object-fit:cover;display:block;transition:opacity .2s}.fulgur-yt:hover img{opacity:.85}.fulgur-yt::after{content:"";position:absolute;left:50%;top:50%;width:68px;height:48px;margin:-24px 0 0 -34px;background:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 68 48\'%3E%3Cpath d=\'M66.5 7.7a8.5 8.5 0 0 0-6-6C55.3.3 34 .3 34 .3s-21.3 0-26.5 1.4a8.5 8.5 0 0 0-6 6C.1 13 .1 24 .1 24s0 11 1.4 16.3a8.5 8.5 0 0 0 6 6C12.7 47.7 34 47.7 34 47.7s21.3 0 26.5-1.4a8.5 8.5 0 0 0 6-6C67.9 35 67.9 24 67.9 24s0-11-1.4-16.3z\' fill=\'%23f00\'/%3E%3Cpath d=\'M45 24 27 14v20z\' fill=\'%23fff\'/%3E%3C/svg%3E") no-repeat center/contain}.fulgur-yt iframe{position:absolute;inset:0;width:100%;height:100%;border:0}</style>';
			$footer[] = '<script id="fulgur-yt">document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".fulgur-yt");if(!b||b.querySelector("iframe"))return;e.preventDefault();var f=document.createElement("iframe");f.src=b.getAttribute("data-src");f.allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture;web-share";f.allowFullscreen=!0;f.title=b.getAttribute("aria-label")||"YouTube";b.appendChild(f)});</script>';
		}

		return $before . $after;
	}

	private static function youtube_facade( $tag, $src, $id ) {
		$src   = html_entity_decode( $src );
		$src  .= ( false === strpos( $src, '?' ) ? '?' : '&' ) . 'autoplay=1';
		$title = self::attr( $tag, 'title' );
		$w     = (int) self::attr( $tag, 'width' );
		$h     = (int) self::attr( $tag, 'height' );
		$ratio = ( $w && $h ) ? 'aspect-ratio:' . $w . '/' . $h . ';' : '';
		$label = $title ? $title : __( 'Play video', 'fulgur-cache' );
		return '<button type="button" class="fulgur-yt" style="' . $ratio . '" data-src="' . esc_url( $src ) . '" aria-label="' . esc_attr( $label ) . '">'
			. '<img src="https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg" alt="' . esc_attr( $label ) . '" loading="lazy" decoding="async" width="480" height="360">'
			. '</button>';
	}

	/**
	 * Logos, icônes, avatars et petites images ne sont jamais l'élément LCP.
	 */
	private static function is_decorative( $tag ) {
		if ( preg_match( '/(logo|icon|avatar|emoji|badge|flag|spinner|loader)/i', self::attr( $tag, 'class' ) . ' ' . self::attr( $tag, 'src' ) . ' ' . self::attr( $tag, 'alt' ) ) ) {
			return true;
		}
		$w = (int) self::attr( $tag, 'width' );
		return $w > 0 && $w < 300;
	}

	private static function dimensions( $tag, $src ) {
		$w = self::attr( $tag, 'width' );
		$h = self::attr( $tag, 'height' );
		if ( $w && $h ) {
			return $tag;
		}
		$file = Assets::url_to_path( $src );
		if ( ! $file || preg_match( '/\.svg$/i', $file ) ) {
			return $tag;
		}
		$size = @getimagesize( $file );
		if ( ! $size || ! $size[0] || ! $size[1] ) {
			return $tag;
		}
		if ( ! $w && ! $h ) {
			$w = $size[0];
			$h = $size[1];
		} elseif ( $w && is_numeric( $w ) ) {
			$h = (int) round( $w * $size[1] / $size[0] );
		} elseif ( $h && is_numeric( $h ) ) {
			$w = (int) round( $h * $size[0] / $size[1] );
		} else {
			return $tag;
		}
		$tag = self::set_attr( $tag, 'width', (string) $w );
		return self::set_attr( $tag, 'height', (string) $h );
	}

	/**
	 * Mode "webp" : remplacement direct des URL (97 % des navigateurs, aucun impact CSS).
	 * Mode "avif" : <picture> AVIF → WebP → original.
	 *
	 * @return array [ balise img, gabarit picture|null ]
	 */
	private static function next_gen( $tag, $format ) {
		$src    = self::attr( $tag, 'src' );
		$srcset = self::attr( $tag, 'srcset' );

		$webp_src    = Images::variant_url( $src, 'webp' );
		$webp_srcset = $srcset ? self::srcset_variant( $srcset, 'webp' ) : '';

		if ( 'avif' === $format && Images::supports( 'avif' ) ) {
			$avif_src    = Images::variant_url( $src, 'avif' );
			$avif_srcset = $srcset ? self::srcset_variant( $srcset, 'avif' ) : '';
			if ( $avif_src || $avif_srcset ) {
				$sizes   = self::attr( $tag, 'sizes' );
				$sources = '<source type="image/avif" srcset="' . esc_attr( $avif_srcset ? $avif_srcset : $avif_src ) . '"' . ( $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
				if ( $webp_src || $webp_srcset ) {
					$sources .= '<source type="image/webp" srcset="' . esc_attr( $webp_srcset ? $webp_srcset : $webp_src ) . '"' . ( $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
				}
				return array( $tag, '<picture class="fulgur-picture" style="display:contents">' . $sources . '%IMG%</picture>' );
			}
		}

		if ( $webp_src ) {
			$tag = self::set_attr( $tag, 'src', $webp_src );
		}
		if ( $webp_srcset ) {
			$tag = self::set_attr( $tag, 'srcset', $webp_srcset );
		}
		return array( $tag, null );
	}

	/**
	 * Convertit un srcset complet ; retourne '' si une seule taille manque (évite les mélanges).
	 */
	private static function srcset_variant( $srcset, $format ) {
		$out = array();
		foreach ( explode( ',', html_entity_decode( $srcset ) ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			$parts   = preg_split( '/\s+/', $candidate, 2 );
			$variant = Images::variant_url( $parts[0], $format );
			if ( ! $variant ) {
				return '';
			}
			$out[] = $variant . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
		}
		return implode( ', ', $out );
	}

	/* Utilitaires d'attributs ------------------------------------------- */

	public static function attr( $tag, $name ) {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) {
			return $m[2];
		}
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*([^\s"\'>]+)/i', $tag, $m ) ) {
			return $m[1];
		}
		return '';
	}

	public static function set_attr( $tag, $name, $value ) {
		$value = esc_attr( html_entity_decode( $value ) );
		$re    = '/(\s' . preg_quote( $name, '/' ) . '\s*=\s*)(["\']).*?\2/is';
		if ( preg_match( $re, $tag ) ) {
			return preg_replace_callback(
				$re,
				function ( $m ) use ( $value ) {
					return $m[1] . '"' . $value . '"';
				},
				$tag,
				1
			);
		}
		return preg_replace( '/^<(\w+)/', '<$1 ' . $name . '="' . str_replace( array( '\\', '$' ), array( '\\\\', '\$' ), $value ) . '"', $tag, 1 );
	}

	public static function remove_attr( $tag, $name ) {
		return preg_replace( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\']).*?\1/is', '', $tag );
	}
}
