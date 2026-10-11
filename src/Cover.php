<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the branded featured image: the Arabic title on the Ana Afdal colour.
 */
final class Cover {

	public const WIDTH  = 1200;
	public const HEIGHT = 675;

	private static function font(): string {
		return AAW_DIR . 'assets/fonts/Tajawal-Bold.ttf';
	}

	public static function available(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagettftext' ) && is_readable( self::font() );
	}

	/** Renders the cover and returns JPEG bytes. */
	public static function render( string $title, string $label = '' ): string {
		if ( ! self::available() ) {
			throw new \RuntimeException( 'The server\'s PHP GD library with FreeType is needed to draw covers.' );
		}
		$w   = self::WIDTH;
		$h   = self::HEIGHT;
		$img = imagecreatetruecolor( $w, $h );
		imagealphablending( $img, true );
		imagesavealpha( $img, false );

		list( $r, $g, $b ) = self::rgb( (string) Settings::get( 'brand_color' ) );

		// Diagonal gradient from the brand colour to a deeper shade.
		for ( $y = 0; $y < $h; $y++ ) {
			$t   = $y / $h;
			$mix = 1 - 0.38 * $t;
			$col = imagecolorallocate( $img, (int) ( $r * $mix ), (int) ( $g * $mix ), (int) ( $b * $mix ) );
			imageline( $img, 0, $y, $w, $y, $col );
		}

		// Soft decorative circles.
		$white_faint = imagecolorallocatealpha( $img, 255, 255, 255, 116 );
		imagefilledellipse( $img, 90, 600, 520, 520, $white_faint );
		imagefilledellipse( $img, 1130, 40, 300, 300, $white_faint );
		$white_fainter = imagecolorallocatealpha( $img, 255, 255, 255, 122 );
		imagefilledellipse( $img, 260, 80, 160, 160, $white_fainter );

		$white  = imagecolorallocate( $img, 255, 255, 255 );
		$font   = self::font();
		$margin = 80;
		$right  = $w - $margin;

		// Category label pill, top right.
		if ( '' !== trim( $label ) ) {
			$label_vis = ArabicShaper::visual( $label );
			$size      = 24;
			$box       = imagettfbbox( $size, 0, $font, $label_vis );
			$tw        = abs( $box[2] - $box[0] );
			// A solid tint (not alpha) so the overlapping shapes of the rounded pill don't leave seams.
			$mix     = 0.95;
			$pill_bg = imagecolorallocate( $img, (int) ( $r * $mix * 0.75 + 255 * 0.25 ), (int) ( $g * $mix * 0.75 + 255 * 0.25 ), (int) ( $b * $mix * 0.75 + 255 * 0.25 ) );
			self::rounded_rect( $img, $right - $tw - 44, 58, $right, 112, 27, $pill_bg );
			imagettftext( $img, $size, 0, $right - 22 - $tw, 95, $white, $font, $label_vis );
		}

		// Title: largest size that fits in at most three lines.
		$max_width = $w - 2 * $margin;
		$lines     = array();
		$size      = 66;
		for ( ; $size >= 38; $size -= 4 ) {
			$lines = self::wrap( $title, $font, $size, $max_width );
			if ( count( $lines ) <= 3 ) {
				break;
			}
		}
		$lines       = array_slice( $lines, 0, 4 );
		$line_height = (int) round( $size * 1.55 );
		$block_h     = $line_height * count( $lines );
		$y           = (int) ( ( $h - $block_h ) / 2 + $size * 1.1 ) + 10;
		foreach ( $lines as $line ) {
			$vis = ArabicShaper::visual( $line );
			$box = imagettfbbox( $size, 0, $font, $vis );
			$tw  = abs( $box[2] - $box[0] );
			imagettftext( $img, $size, 0, $right - $tw, $y, $white, $font, $vis );
			$y += $line_height;
		}

		// Accent bar and site name, bottom.
		imagefilledrectangle( $img, $right - 90, $h - 92, $right, $h - 86, $white );
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
		$site_vis  = ArabicShaper::visual( $site_name );
		$box       = imagettfbbox( 26, 0, $font, $site_vis );
		imagettftext( $img, 26, 0, $right - abs( $box[2] - $box[0] ), $h - 44, $white, $font, $site_vis );

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		imagettftext( $img, 20, 0, $margin, $h - 46, $white, $font, preg_replace( '/^www\./', '', $host ) );

		self::place_logo( $img, $margin, $h - 150 );

		ob_start();
		imagejpeg( $img, null, 88 );
		$bytes = (string) ob_get_clean();
		imagedestroy( $img );
		return $bytes;
	}

	/** Creates the cover and saves it to the Media Library. */
	public static function create( string $title, string $label, string $slug, string $alt ): int {
		return Media::save_bytes( self::render( $title, $label ), $slug . '-cover', $alt, '', array( 'image_source' => 'cover' ) );
	}

	/** Splits logical text into lines that fit the width once shaped. */
	public static function wrap( string $text, string $font, int $size, int $max_width ): array {
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$lines = array();
		$line  = '';
		foreach ( $words as $word ) {
			$try = '' === $line ? $word : $line . ' ' . $word;
			$box = imagettfbbox( $size, 0, $font, ArabicShaper::visual( $try ) );
			if ( abs( $box[2] - $box[0] ) <= $max_width || '' === $line ) {
				$line = $try;
			} else {
				$lines[] = $line;
				$line    = $word;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines;
	}

	private static function place_logo( $img, int $x, int $y ): void {
		$logo_id = (int) Settings::get( 'cover_logo_id' );
		if ( ! $logo_id ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
		}
		if ( ! $logo_id ) {
			return;
		}
		$path = get_attached_file( $logo_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return;
		}
		$logo = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore
		if ( ! $logo ) {
			return;
		}
		$lw     = imagesx( $logo );
		$lh     = imagesy( $logo );
		$target = 64;
		$nw     = (int) round( $lw * $target / max( 1, $lh ) );
		if ( $nw > 260 ) {
			$nw     = 260;
			$target = (int) round( $lh * 260 / max( 1, $lw ) );
		}
		imagecopyresampled( $img, $logo, $x, $y, 0, 0, $nw, $target, $lw, $lh );
		imagedestroy( $logo );
	}

	private static function rounded_rect( $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color ): void {
		imagefilledrectangle( $img, $x1 + $r, $y1, $x2 - $r, $y2, $color );
		imagefilledrectangle( $img, $x1, $y1 + $r, $x1 + $r - 1, $y2 - $r, $color );
		imagefilledrectangle( $img, $x2 - $r + 1, $y1 + $r, $x2, $y2 - $r, $color );
		imagefilledarc( $img, $x1 + $r, $y1 + $r, 2 * $r, 2 * $r, 180, 270, $color, IMG_ARC_PIE );
		imagefilledarc( $img, $x2 - $r, $y1 + $r, 2 * $r, 2 * $r, 270, 360, $color, IMG_ARC_PIE );
		imagefilledarc( $img, $x1 + $r, $y2 - $r, 2 * $r, 2 * $r, 90, 180, $color, IMG_ARC_PIE );
		imagefilledarc( $img, $x2 - $r, $y2 - $r, 2 * $r, 2 * $r, 0, 90, $color, IMG_ARC_PIE );
	}

	private static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			$hex = '00AEAD';
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}
}
