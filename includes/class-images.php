<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * AVIF/WebP conversion: on upload, in bulk from the admin, and on the fly for theme images.
 * Variants are created next to the original: photo.jpg → photo.jpg.webp / photo.jpg.avif
 */
class Images {

	const QUEUE = 'fulgur_image_queue';
	const MAX_PIXELS = 25000000;

	/** @var array */
	private static $exists = array();

	public function __construct() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_upload' ), 20, 2 );
		add_action( 'delete_attachment', array( $this, 'on_delete' ) );
		add_action( 'fulgur_process_images', array( __CLASS__, 'process_queue' ) );
		add_action( 'shutdown', array( __CLASS__, 'save_queue' ) );
	}

	public static function supports( $format ) {
		static $cache = array();
		if ( isset( $cache[ $format ] ) ) {
			return $cache[ $format ];
		}
		$ok = false;
		if ( class_exists( '\Imagick' ) ) {
			try {
				$ok = (bool) \Imagick::queryFormats( strtoupper( $format ) );
			} catch ( \Exception $e ) {
				$ok = false;
			}
		}
		if ( ! $ok && function_exists( 'gd_info' ) ) {
			$info = gd_info();
			if ( 'webp' === $format ) {
				$ok = function_exists( 'imagewebp' ) && ! empty( $info['WebP Support'] );
			} elseif ( 'avif' === $format ) {
				$ok = function_exists( 'imageavif' ) && ! empty( $info['AVIF Support'] );
			}
		}
		return $cache[ $format ] = $ok;
	}

	public static function formats() {
		$formats = array();
		if ( self::supports( 'webp' ) ) {
			$formats[] = 'webp';
		}
		if ( 'avif' === Settings::get( 'image_format' ) && self::supports( 'avif' ) ) {
			$formats[] = 'avif';
		}
		return $formats;
	}

	/**
	 * Variant URL if it exists; otherwise queues the image and returns ''.
	 */
	public static function variant_url( $url, $format ) {
		$clean = strtok( html_entity_decode( (string) $url ), '?#' );
		if ( ! preg_match( '/\.(jpe?g|png)$/i', $clean ) ) {
			return '';
		}
		$file = Assets::url_to_path( $clean );
		if ( ! $file || 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( WP_CONTENT_DIR ) ) ) {
			return '';
		}
		$variant = $file . '.' . $format;
		if ( ! isset( self::$exists[ $variant ] ) ) {
			self::$exists[ $variant ] = is_file( $variant ) && filesize( $variant ) > 0;
		}
		if ( self::$exists[ $variant ] ) {
			return $clean . '.' . $format;
		}
		if ( ! is_file( $variant ) && self::supports( $format ) ) {
			self::enqueue( $file );
		}
		return '';
	}

	/* Queue --------------------------------------------------------------- */

	/** @var array|null */
	private static $queue = null;
	private static $queue_dirty = false;

	private static function enqueue( $file ) {
		if ( null === self::$queue ) {
			self::$queue = (array) get_option( self::QUEUE, array() );
		}
		if ( count( self::$queue ) < 2000 && ! in_array( $file, self::$queue, true ) ) {
			self::$queue[]      = $file;
			self::$queue_dirty  = true;
		}
	}

	public static function save_queue() {
		if ( self::$queue_dirty ) {
			update_option( self::QUEUE, array_values( array_unique( self::$queue ) ), false );
			self::$queue_dirty = false;
		}
	}

	public static function process_queue( $limit = 25 ) {
		$queue = (array) get_option( self::QUEUE, array() );
		if ( ! $queue ) {
			return 0;
		}
		$batch = array_splice( $queue, 0, $limit );
		update_option( self::QUEUE, $queue, false );
		$done  = 0;
		$start = microtime( true );
		foreach ( $batch as $file ) {
			$done += self::convert_all( $file );
			if ( microtime( true ) - $start > 20 ) {
				break;
			}
		}
		if ( $done && ! get_option( self::QUEUE ) ) {
			// Queue finished: cached pages are rebuilt with the new variants.
			do_action( 'fulgur_images_converted', $done );
		}
		return $done;
	}

	/* Conversion ---------------------------------------------------------- */

	public static function convert_all( $file ) {
		$count = 0;
		foreach ( self::formats() as $format ) {
			if ( self::convert( $file, $format ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Creates the variant. If it is heavier than the original, an empty file is left as a marker.
	 */
	public static function convert( $file, $format ) {
		$dest = $file . '.' . $format;
		if ( is_file( $dest ) || ! is_file( $file ) || ! preg_match( '/\.(jpe?g|png)$/i', $file ) ) {
			return false;
		}
		$info = @getimagesize( $file );
		if ( ! $info || $info[0] * $info[1] > self::MAX_PIXELS ) {
			return false;
		}
		$quality = max( 30, min( 95, (int) Settings::get( 'image_quality' ) ) );
		if ( 'avif' === $format ) {
			$quality = max( 30, $quality - 15 ); // AVIF reaches the same visual quality with a lower setting.
		}

		$data = self::encode_imagick( $file, $format, $quality );
		if ( null === $data ) {
			$data = self::encode_gd( $file, $format, $quality, $info );
		}
		if ( ! $data ) {
			return false;
		}
		if ( strlen( $data ) >= filesize( $file ) ) {
			@file_put_contents( $dest, '' ); // Marker: the original is already optimal.
			return false;
		}
		return Installer::atomic_write( $dest, $data );
	}

	private static function encode_imagick( $file, $format, $quality ) {
		if ( ! class_exists( '\Imagick' ) || ! self::imagick_supports( $format ) ) {
			return null;
		}
		try {
			$im = new \Imagick( $file );
			$im->stripImage();
			$im->setImageFormat( $format );
			$im->setImageCompressionQuality( $quality );
			if ( 'webp' === $format ) {
				$im->setOption( 'webp:method', '6' );
			}
			$blob = $im->getImageBlob();
			$im->clear();
			return $blob;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	private static function imagick_supports( $format ) {
		try {
			return (bool) \Imagick::queryFormats( strtoupper( $format ) );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	private static function encode_gd( $file, $format, $quality, $info ) {
		$fn = 'webp' === $format ? 'imagewebp' : 'imageavif';
		if ( ! function_exists( $fn ) ) {
			return null;
		}
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				$img = @imagecreatefromjpeg( $file );
				break;
			case IMAGETYPE_PNG:
				$img = @imagecreatefrompng( $file );
				if ( $img ) {
					imagepalettetotruecolor( $img );
					imagealphablending( $img, false );
					imagesavealpha( $img, true );
				}
				break;
			default:
				return null;
		}
		if ( ! $img ) {
			return null;
		}
		ob_start();
		$ok   = 'webp' === $format ? imagewebp( $img, null, $quality ) : imageavif( $img, null, $quality, 6 );
		$data = ob_get_clean();
		imagedestroy( $img );
		return $ok ? $data : null;
	}

	/* Media library hooks ------------------------------------------------- */

	public function on_upload( $metadata, $attachment_id ) {
		if ( ! Settings::get( 'next_gen_images' ) ) {
			return $metadata;
		}
		foreach ( self::attachment_files( $attachment_id, $metadata ) as $file ) {
			self::convert_all( $file );
		}
		return $metadata;
	}

	public function on_delete( $attachment_id ) {
		foreach ( self::attachment_files( $attachment_id ) as $file ) {
			foreach ( array( 'webp', 'avif' ) as $format ) {
				if ( is_file( $file . '.' . $format ) ) {
					wp_delete_file( $file . '.' . $format );
				}
			}
		}
	}

	public static function attachment_files( $attachment_id, $metadata = null ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! preg_match( '/\.(jpe?g|png)$/i', $file ) ) {
			return array();
		}
		$metadata = $metadata ? $metadata : wp_get_attachment_metadata( $attachment_id );
		$files    = array( $file );
		$dir      = dirname( $file );
		if ( ! empty( $metadata['original_image'] ) ) {
			$files[] = $dir . '/' . $metadata['original_image'];
		}
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
		}
		return array_unique( $files );
	}

	/**
	 * Bulk conversion (called by the admin, in batches, with a progress bar).
	 */
	public static function bulk( $offset, $limit = 5 ) {
		$ids   = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$converted = 0;
		foreach ( $ids as $id ) {
			foreach ( self::attachment_files( $id ) as $file ) {
				$converted += self::convert_all( $file );
			}
		}
		$total = self::total_images();
		$next  = $offset + count( $ids );
		return array(
			'done'      => $next >= $total || ! $ids,
			'offset'    => $next,
			'total'     => $total,
			'converted' => $converted,
		);
	}

	public static function total_images() {
		$counts = wp_count_attachments( array( 'image/jpeg', 'image/png' ) );
		$total  = 0;
		foreach ( array( 'image/jpeg', 'image/png' ) as $mime ) {
			$total += isset( $counts->$mime ) ? (int) $counts->$mime : 0;
		}
		return $total;
	}
}
