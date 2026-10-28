<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * CDN rewriting of static files and Cloudflare purge.
 */
class Cdn {

	public static function rewrite( $html ) {
		$cdn = untrailingslashit( esc_url_raw( Settings::get( 'cdn_url' ) ) );
		if ( ! $cdn ) {
			return $html;
		}
		$host       = preg_quote( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '#' );
		$exclusions = Settings::lines( 'cdn_exclusions' );

		return preg_replace_callback(
			'#(?:https?:)?//' . $host . '(/(?:[^"\'\s)]*?/)?(?:wp-content|wp-includes)/[^"\'\s)?]+\.(?:css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|pdf))(\?[^"\'\s)]*)?#i',
			function ( $m ) use ( $cdn, $exclusions ) {
				foreach ( $exclusions as $ex ) {
					if ( '' !== $ex && false !== stripos( $m[0], $ex ) ) {
						return $m[0];
					}
				}
				return $cdn . $m[1] . ( isset( $m[2] ) ? $m[2] : '' );
			},
			$html
		);
	}

	/**
	 * Cloudflare purge (specific URLs or the whole cache). Non-blocking.
	 */
	public static function cloudflare_purge( array $urls = array() ) {
		$token = Settings::get( 'cloudflare_token' );
		$zone  = Settings::get( 'cloudflare_zone' );
		if ( ! $token || ! $zone ) {
			return;
		}
		$body = $urls ? array( 'files' => array_slice( array_values( $urls ), 0, 30 ) ) : array( 'purge_everything' => true );
		wp_remote_post(
			'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache',
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'     => wp_json_encode( $body ),
			)
		);
	}

	public static function cloudflare_test() {
		$token = Settings::get( 'cloudflare_token' );
		$zone  = Settings::get( 'cloudflare_zone' );
		if ( ! $token || ! $zone ) {
			return new \WP_Error( 'fulgur_cf', __( 'Enter your Cloudflare API token and zone ID.', 'fulgur-cache' ) );
		}
		$res = wp_remote_get(
			'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ),
			array(
				'timeout' => 8,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['success'] ) ) {
			return new \WP_Error( 'fulgur_cf', __( 'Cloudflare refused the connection: check the token (“Zone > Cache Purge” permission) and the zone ID.', 'fulgur-cache' ) );
		}
		return $data['result']['name'];
	}
}
