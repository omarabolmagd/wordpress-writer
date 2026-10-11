<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Saves downloaded or generated images into the Media Library.
 */
final class Media {

	private const ALLOWED = array(
		IMAGETYPE_JPEG => array( 'jpg', 'image/jpeg' ),
		IMAGETYPE_PNG  => array( 'png', 'image/png' ),
		IMAGETYPE_WEBP => array( 'webp', 'image/webp' ),
	);

	/**
	 * Stores image bytes as an attachment and returns its ID.
	 */
	public static function save_bytes( string $bytes, string $basename, string $alt, string $caption = '', array $meta = array() ): int {
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || ! isset( self::ALLOWED[ $info[2] ] ) ) {
			throw new \RuntimeException( 'The downloaded file is not a JPEG, PNG or WebP image.' );
		}
		if ( $info[0] < 200 || $info[1] < 120 ) {
			throw new \RuntimeException( 'The image is too small to use.' );
		}
		list( $ext, $mime ) = self::ALLOWED[ $info[2] ];

		$basename = sanitize_file_name( $basename );
		$basename = $basename ? $basename : 'aaw-image';
		$upload   = wp_upload_bits( $basename . '.' . $ext, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			throw new \RuntimeException( 'Could not save the image: ' . $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => wp_strip_all_tags( $alt ? $alt : $basename ),
				'post_excerpt'   => wp_kses_post( $caption ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			throw new \RuntimeException( 'Could not add the image to the Media Library: ' . $attachment_id->get_error_message() );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $attachment_id, '_aaw_' . $key, $value );
		}
		return (int) $attachment_id;
	}

	/** Downloads an image URL and returns its bytes. */
	public static function download( string $url, array $headers = array(), int $timeout = 60 ): string {
		$response = Http::get(
			$url,
			array(
				'timeout'             => $timeout,
				'headers'             => $headers,
				'limit_response_size' => 15 * MB_IN_BYTES,
			)
		);
		if ( 200 !== $response['status'] ) {
			throw new ApiError( 'Download failed with HTTP ' . $response['status'] . ': ' . mb_substr( wp_strip_all_tags( $response['body'] ), 0, 200 ), $response['status'] );
		}
		return $response['body'];
	}

	public static function attach_to_post( array $attachment_ids, int $post_id ): void {
		foreach ( array_unique( array_filter( $attachment_ids ) ) as $id ) {
			wp_update_post( array( 'ID' => (int) $id, 'post_parent' => $post_id ) );
		}
	}
}
