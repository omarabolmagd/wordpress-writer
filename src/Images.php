<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Gets the featured image and in-article images from Gemini, Pexels or a screenshot service
 * and saves them to the Media Library.
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

	/** Featured-image styles, in the order each one tries its sources. */
	public const COVER_ORDER = array(
		'ai'      => array( 'ai', 'pexels', 'branded' ),
		'pexels'  => array( 'pexels', 'ai', 'branded' ),
		'branded' => array( 'branded' ),
	);

	/**
	 * Makes the featured image: by default a Gemini photo of a scene about the topic, with no
	 * text. Sources without a key are skipped; if every image service fails, the branded title
	 * cover is drawn instead, so a post always gets a featured image.
	 *
	 * @param array{prompt?:string,query?:string,alt?:string} $plan
	 * @param string[]|null $order Sources to try, in order; defaults to the "Featured image" setting.
	 * @return array{id:int, source:string, errors:array<string,string>}
	 */
	public static function cover( array $plan, string $title, string $label, int $job_id, string $slug, ?array $order = null ): array {
		$ready  = Settings::readiness();
		$order  = $order ?? ( self::COVER_ORDER[ (string) Settings::get( 'cover_style' ) ] ?? self::COVER_ORDER['ai'] );
		$alt    = '' !== trim( (string) ( $plan['alt'] ?? '' ) ) ? (string) $plan['alt'] : $title;
		$scene  = trim( (string) ( $plan['prompt'] ?? '' ) );
		$query  = trim( (string) ( $plan['query'] ?? '' ) );
		$errors = array();
		foreach ( $order as $source ) {
			try {
				if ( 'ai' === $source && $ready['gemini'] ) {
					if ( '' === $scene ) {
						$scene = 'A realistic everyday scene that shows the subject of an article titled "' . $title . '"';
					}
					return array( 'id' => self::ai( $scene, $alt, $job_id, $slug . '-cover', 'cover' ), 'source' => 'ai', 'errors' => $errors );
				}
				if ( 'pexels' === $source && $ready['pexels'] && '' !== $query ) {
					return array( 'id' => self::pexels( $query, $alt, $slug . '-cover' ), 'source' => 'pexels', 'errors' => $errors );
				}
				if ( 'branded' === $source ) {
					return array( 'id' => Cover::create( $title, $label, $slug, $alt ), 'source' => 'branded', 'errors' => $errors );
				}
			} catch ( Cancelled $e ) {
				throw $e;
			} catch ( \Throwable $e ) {
				$errors[ $source ] = $e->getMessage();
			}
		}
		throw new \RuntimeException( $errors ? implode( ' ', $errors ) : 'No image service is set up for the featured image.' );
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

	/**
	 * @param string $kind "image" for an in-article illustration, "cover" for the featured photo.
	 */
	public static function ai( string $prompt, string $alt, int $job_id, string $slug, string $kind = 'image' ): int {
		if ( '' === trim( $prompt ) ) {
			throw new \RuntimeException( 'AI images need a prompt.' );
		}
		$cover = 'cover' === $kind;
		$bytes = self::gemini_bytes( $cover ? Prompts::ai_cover( $prompt ) : Prompts::ai_image( $prompt ), $job_id, $cover ? 'ai_cover' : 'ai_image' );
		return Media::save_bytes( $bytes, $slug . '-ai-' . strtolower( wp_generate_password( 4, false ) ), $alt, '', array( 'image_source' => 'ai', 'image_role' => $cover ? 'cover' : 'section', 'ai_prompt' => $prompt ) );
	}

	/**
	 * Asks Gemini for one image and returns its bytes. Uses the Interactions API, waits for it if
	 * Google runs it in the background, and falls back to generateContent. Errors keep Google's own
	 * message, with plain advice for the common ones (no billing on the key's project, for example).
	 */
	public static function gemini_bytes( string $full_prompt, int $job_id, string $cost_item ): string {
		$key = Settings::key( 'gemini_key' );
		if ( '' === $key ) {
			throw new \RuntimeException( 'AI images need a Gemini API key.' );
		}
		$model   = (string) Settings::get( 'gemini_model' );
		$headers = array( 'x-goog-api-key' => $key );
		$base    = 'https://generativelanguage.googleapis.com/v1beta/';
		$errors  = array();
		$image   = null;

		try {
			$response = Http::post_json(
				$base . 'interactions',
				array(
					'model'           => $model,
					'input'           => $full_prompt,
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
			$data = json_decode( $response['body'], true );
			if ( 200 !== $response['status'] ) {
				$errors[] = self::gemini_error( $response['status'], $data );
			} else {
				// Long requests can come back while still running: wait for the result.
				$deadline = time() + 150;
				while ( is_array( $data ) && in_array( $data['status'] ?? '', array( 'in_progress', 'queued' ), true ) && ! empty( $data['id'] ) && time() < $deadline ) {
					Progress::tick();
					sleep( (int) apply_filters( 'aaw_gemini_poll_wait', 4 ) );
					$poll = Http::get( $base . 'interactions/' . rawurlencode( (string) $data['id'] ), array( 'timeout' => 60, 'headers' => $headers ) );
					$data = json_decode( $poll['body'], true );
					if ( 200 !== $poll['status'] ) {
						$errors[] = self::gemini_error( $poll['status'], $data );
						$data     = null;
					}
				}
				if ( is_array( $data ) ) {
					if ( in_array( $data['status'] ?? '', array( 'failed', 'cancelled' ), true ) ) {
						$errors[] = 'Gemini could not make the image: ' . self::gemini_message( $data );
					} else {
						$image = self::find_image_ref( $data );
						if ( ! $image ) {
							$errors[] = 'Gemini answered without an image' . ( in_array( $data['status'] ?? '', array( 'in_progress', 'queued' ), true ) ? ' in time.' : '. The prompt may have been blocked; try a different description.' );
						}
					}
				}
			}
		} catch ( Cancelled $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			$errors[] = $e->getMessage();
		}

		if ( ! $image ) {
			// The older generateContent endpoint, which Google still supports for image models.
			try {
				$response = Http::post_json(
					$base . 'models/' . rawurlencode( $model ) . ':generateContent',
					array(
						'contents'         => array( array( 'parts' => array( array( 'text' => $full_prompt ) ) ) ),
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
				$data = json_decode( $response['body'], true );
				if ( 200 === $response['status'] ) {
					$image = self::find_image_ref( $data );
				} elseif ( ! $errors ) {
					$errors[] = self::gemini_error( $response['status'], $data );
				}
			} catch ( Cancelled $e ) {
				throw $e;
			} catch ( \RuntimeException $e ) {
				if ( ! $errors ) {
					$errors[] = $e->getMessage();
				}
			}
		}

		$bytes = false;
		if ( $image && ! empty( $image['data'] ) ) {
			$bytes = base64_decode( (string) $image['data'], true );
		} elseif ( $image && ! empty( $image['uri'] ) ) {
			$file  = Http::get( (string) $image['uri'], array( 'timeout' => 60, 'headers' => str_starts_with( (string) $image['uri'], 'https://generativelanguage.googleapis.com/' ) ? $headers : array() ) );
			$bytes = 200 === $file['status'] ? $file['body'] : false;
			if ( false === $bytes ) {
				$errors[] = 'Gemini made the image but it could not be downloaded (HTTP ' . $file['status'] . ').';
			}
		}
		if ( ! $bytes ) {
			throw new ApiError( self::explain_gemini( $errors ? $errors : array( 'Gemini did not return an image.' ) ), 0 );
		}

		Costs::log( $job_id, 'gemini', $cost_item, (float) Settings::get( 'price_gemini_image' ), array( 'model' => $model ) );
		return $bytes;
	}

	/** "Gemini error 429: <Google's message>" from an error response. */
	private static function gemini_error( int $status, $data ): string {
		return 'Gemini error ' . $status . ': ' . self::gemini_message( $data );
	}

	private static function gemini_message( $data ): string {
		if ( ! is_array( $data ) ) {
			return 'no details';
		}
		$parts = array();
		foreach ( array( $data['error'] ?? null, ...( (array) ( $data['errors'] ?? array() ) ) ) as $err ) {
			if ( is_array( $err ) ) {
				$parts[] = trim( ( ! empty( $err['status'] ) ? $err['status'] . ' ' : '' ) . (string) ( $err['message'] ?? '' ) );
			} elseif ( is_string( $err ) && '' !== $err ) {
				$parts[] = $err;
			}
		}
		$parts = array_filter( $parts );
		return $parts ? implode( ' ', $parts ) : 'no details';
	}

	/** Adds plain advice to the errors people actually run into. */
	public static function explain_gemini( array $errors ): string {
		$text = implode( ' ', array_unique( $errors ) );
		if ( preg_match( '/RESOURCE_EXHAUSTED|quota|free[_ ]tier|limit: 0|billing|FAILED_PRECONDITION/i', $text ) ) {
			return __( 'Gemini image generation is not available on this API key. Google offers its image models only on the paid tier: in Google AI Studio, set up billing for the project this key belongs to, then try again.', 'ana-afdal-writer' ) . ' (' . $text . ')';
		}
		if ( preg_match( '/Gemini error 40[34]|PERMISSION_DENIED|NOT_FOUND|API key not valid/i', $text ) ) {
			return __( 'Gemini refused the request. Check the Gemini API key and the image model name in Settings.', 'ana-afdal-writer' ) . ' (' . $text . ')';
		}
		return $text;
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
		$ref = self::find_image_ref( $node );
		return $ref['data'] ?? null;
	}

	/**
	 * The last image in a Gemini response, as inline data or a link to download it.
	 *
	 * @return array{data?:string, uri?:string}|null
	 */
	public static function find_image_ref( $node ): ?array {
		if ( ! is_array( $node ) ) {
			return null;
		}
		if ( ( $node['type'] ?? '' ) === 'image' ) {
			if ( ! empty( $node['data'] ) && is_string( $node['data'] ) ) {
				return array( 'data' => $node['data'] );
			}
			if ( ! empty( $node['uri'] ) && is_string( $node['uri'] ) ) {
				return array( 'uri' => $node['uri'] );
			}
		}
		foreach ( array( 'inlineData', 'inline_data' ) as $k ) {
			if ( isset( $node[ $k ]['data'] ) && is_string( $node[ $k ]['data'] ) && str_starts_with( (string) ( $node[ $k ]['mimeType'] ?? $node[ $k ]['mime_type'] ?? 'image/' ), 'image/' ) ) {
				return array( 'data' => $node[ $k ]['data'] );
			}
		}
		$found = null;
		foreach ( $node as $child ) {
			$ref = self::find_image_ref( $child );
			if ( $ref ) {
				$found = $ref; // Keep the last image, which is the final one in multi-step output.
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
