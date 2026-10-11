<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Gets in-article images from Pexels, Gemini or a screenshot service and saves them to the Media Library.
 */
final class Images {

	private const USED_PEXELS = 'aaw_pexels_used';

	/** Image sources usable right now, given which keys are set. */
	public static function available_sources(): array {
		$ready   = Settings::readiness();
		$sources = array();
		if ( $ready['pexels'] ) {
			$sources[] = 'pexels';
		}
		if ( $ready['gemini'] ) {
			$sources[] = 'ai';
		}
		if ( $ready['screenshot'] ) {
			$sources[] = 'screenshot';
		}
		$sources[] = 'none';
		return $sources;
	}

	/**
	 * Fetches one planned image. Returns the attachment ID.
	 *
	 * @param array{source:string,query:string,prompt:string,url:string,alt:string} $plan
	 */
	public static function fetch( array $plan, int $job_id, string $slug ): int {
		$alt = trim( (string) $plan['alt'] );
		switch ( $plan['source'] ) {
			case 'pexels':
				return self::pexels( (string) $plan['query'], $alt, $slug );
			case 'ai':
				return self::ai( (string) $plan['prompt'], $alt, $job_id, $slug );
			case 'screenshot':
				return self::screenshot( (string) $plan['url'], $alt, $job_id, $slug );
		}
		throw new \InvalidArgumentException( 'Unknown image source: ' . $plan['source'] );
	}

	public static function pexels( string $query, string $alt, string $slug ): int {
		$key = Settings::key( 'pexels_key' );
		if ( '' === $key || '' === trim( $query ) ) {
			throw new \RuntimeException( 'Pexels needs an API key and a search query.' );
		}
		$url      = add_query_arg(
			array(
				'query'       => rawurlencode( $query ),
				'orientation' => 'landscape',
				'size'        => 'large',
				'per_page'    => 15,
			),
			'https://api.pexels.com/v1/search'
		);
		$response = Http::get( $url, array( 'timeout' => 30, 'headers' => array( 'Authorization' => $key ) ) );
		if ( 200 !== $response['status'] ) {
			throw new ApiError( 'Pexels error ' . $response['status'], $response['status'] );
		}
		$data   = json_decode( $response['body'], true );
		$photos = is_array( $data['photos'] ?? null ) ? $data['photos'] : array();
		if ( ! $photos ) {
			throw new \RuntimeException( 'Pexels found no photos for "' . $query . '".' );
		}

		$used   = (array) get_option( self::USED_PEXELS, array() );
		$chosen = null;
		foreach ( $photos as $photo ) {
			if ( ! in_array( (int) $photo['id'], $used, true ) ) {
				$chosen = $photo;
				break;
			}
		}
		$chosen = $chosen ? $chosen : $photos[0];

		$src   = $chosen['src']['large2x'] ?? ( $chosen['src']['large'] ?? ( $chosen['src']['original'] ?? '' ) );
		$bytes = Media::download( $src );

		$used[] = (int) $chosen['id'];
		update_option( self::USED_PEXELS, array_slice( array_values( array_unique( $used ) ), -500 ), false );

		$caption = 'صورة: ' . esc_html( (string) ( $chosen['photographer'] ?? 'Pexels' ) ) . ' عبر Pexels';
		return Media::save_bytes(
			$bytes,
			$slug . '-pexels-' . (int) $chosen['id'],
			$alt,
			$caption,
			array(
				'image_source' => 'pexels',
				'pexels_id'    => (int) $chosen['id'],
				'pexels_url'   => esc_url_raw( (string) ( $chosen['url'] ?? '' ) ),
			)
		);
	}

	public static function ai( string $prompt, string $alt, int $job_id, string $slug ): int {
		$key = Settings::key( 'gemini_key' );
		if ( '' === $key || '' === trim( $prompt ) ) {
			throw new \RuntimeException( 'AI images need a Gemini API key and a prompt.' );
		}
		$model   = (string) Settings::get( 'gemini_model' );
		$full    = Prompts::ai_image( $prompt );
		$headers = array( 'x-goog-api-key' => $key );

		$response = Http::post_json(
			'https://generativelanguage.googleapis.com/v1beta/interactions',
			array(
				'model'           => $model,
				'input'           => $full,
				'response_format' => array(
					'type'         => 'image',
					'mime_type'    => 'image/jpeg',
					'aspect_ratio' => '16:9',
					'image_size'   => '1K',
				),
			),
			$headers,
			180
		);
		$image = 200 === $response['status'] ? self::find_image( json_decode( $response['body'], true ) ) : null;

		if ( ! $image ) {
			// Fall back to the older generateContent endpoint, which Google still supports.
			$response = Http::post_json(
				'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
				array(
					'contents'         => array( array( 'parts' => array( array( 'text' => $full ) ) ) ),
					'generationConfig' => array(
						'responseModalities' => array( 'IMAGE' ),
						'imageConfig'        => array(
							'aspectRatio' => '16:9',
							'imageSize'   => '1K',
						),
					),
				),
				$headers,
				180
			);
			if ( 200 !== $response['status'] ) {
				$err = json_decode( $response['body'], true );
				throw new ApiError( 'Gemini error ' . $response['status'] . ': ' . (string) ( $err['error']['message'] ?? '' ), $response['status'] );
			}
			$image = self::find_image( json_decode( $response['body'], true ) );
		}
		if ( ! $image ) {
			throw new \RuntimeException( 'Gemini did not return an image. The prompt may have been blocked; try a different description.' );
		}

		Costs::log( $job_id, 'gemini', 'ai_image', (float) Settings::get( 'price_gemini_image' ), array( 'model' => $model ) );

		$bytes = base64_decode( $image, true );
		if ( false === $bytes ) {
			throw new \RuntimeException( 'Gemini returned image data that could not be decoded.' );
		}
		return Media::save_bytes( $bytes, $slug . '-ai-' . wp_generate_password( 4, false ), $alt, '', array( 'image_source' => 'ai', 'ai_prompt' => $prompt ) );
	}

	/** A syntactically valid public http(s) URL. The screenshot service fetches it, not this server. */
	public static function is_public_url( string $url ): bool {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || ! str_contains( $host, '.' ) || 'localhost' === $host ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return true;
	}

	/** Finds base64 image data anywhere in a Gemini response (Interactions or generateContent shape). */
	public static function find_image( $node ): ?string {
		if ( ! is_array( $node ) ) {
			return null;
		}
		if ( ( $node['type'] ?? '' ) === 'image' && ! empty( $node['data'] ) && is_string( $node['data'] ) ) {
			return $node['data'];
		}
		foreach ( array( 'inlineData', 'inline_data' ) as $k ) {
			if ( isset( $node[ $k ]['data'] ) && is_string( $node[ $k ]['data'] ) && str_starts_with( (string) ( $node[ $k ]['mimeType'] ?? $node[ $k ]['mime_type'] ?? 'image/' ), 'image/' ) ) {
				return $node[ $k ]['data'];
			}
		}
		$found = null;
		foreach ( $node as $child ) {
			$data = self::find_image( $child );
			if ( $data ) {
				$found = $data; // Keep the last image, which is the final one in multi-step output.
			}
		}
		return $found;
	}

	public static function screenshot( string $page_url, string $alt, int $job_id, string $slug ): int {
		$key = Settings::key( 'screenshot_key' );
		if ( '' === $key ) {
			throw new \RuntimeException( 'Screenshots need a screenshot service key.' );
		}
		if ( ! self::is_public_url( $page_url ) ) {
			throw new \RuntimeException( 'The screenshot needs a valid public URL.' );
		}
		$request_url = strtr(
			(string) Settings::get( 'screenshot_url_template' ),
			array(
				'{key}' => rawurlencode( $key ),
				'{url}' => rawurlencode( $page_url ),
			)
		);
		$bytes = Media::download( $request_url, array(), 120 );
		Costs::log( $job_id, 'screenshot', 'screenshot', (float) Settings::get( 'price_screenshot' ), array( 'url' => $page_url ) );

		$host = (string) wp_parse_url( $page_url, PHP_URL_HOST );
		return Media::save_bytes( $bytes, $slug . '-screenshot-' . sanitize_title( $host ), $alt, '', array( 'image_source' => 'screenshot', 'screenshot_url' => esc_url_raw( $page_url ) ) );
	}
}
