<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Builds block-editor (Gutenberg) markup that matches what the editor itself saves,
 * so drafts open without "unexpected content" warnings.
 *
 * All $html arguments must already be safe HTML (see Links::render()).
 */
final class Blocks {

	private static function block( string $name, array $attrs, string $html, array $inner = array() ): string {
		$inner_content = array( "\n" . $html . "\n" );
		if ( $inner ) {
			// $html holds the wrapper with a "%s" where inner blocks go.
			list( $open, $close ) = explode( '%s', $html, 2 );
			$inner_content        = array( "\n" . $open );
			foreach ( $inner as $i => $unused ) {
				$inner_content[] = null;
			}
			$inner_content[] = $close . "\n";
		}
		return serialize_block(
			array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => $inner,
				'innerHTML'    => implode( '', array_filter( $inner_content, 'is_string' ) ),
				'innerContent' => $inner_content,
			)
		);
	}

	private static function parsed( string $name, array $attrs, string $html ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	public static function paragraph( string $html, string $class = '' ): string {
		if ( $class ) {
			return self::block( 'core/paragraph', array( 'className' => $class ), '<p class="' . esc_attr( $class ) . '">' . $html . '</p>' );
		}
		return self::block( 'core/paragraph', array(), '<p>' . $html . '</p>' );
	}

	public static function heading( string $html, int $level = 2 ): string {
		$level = max( 2, min( 6, $level ) );
		$attrs = 2 === $level ? array() : array( 'level' => $level );
		return self::block( 'core/heading', $attrs, '<h' . $level . ' class="wp-block-heading">' . $html . '</h' . $level . '>' );
	}

	/** @param string[] $items_html */
	public static function list( array $items_html, bool $ordered = false ): string {
		$tag   = $ordered ? 'ol' : 'ul';
		$items = array();
		foreach ( $items_html as $item ) {
			$items[] = self::parsed( 'core/list-item', array(), '<li>' . $item . '</li>' );
		}
		$attrs = $ordered ? array( 'ordered' => true ) : array();
		return self::block( 'core/list', $attrs, '<' . $tag . ' class="wp-block-list">%s</' . $tag . '>', $items );
	}

	/**
	 * @param string[]   $headers_html
	 * @param string[][] $rows_html
	 */
	public static function table( array $headers_html, array $rows_html ): string {
		$html = '<figure class="wp-block-table"><table class="has-fixed-layout">';
		if ( $headers_html ) {
			$html .= '<thead><tr>';
			foreach ( $headers_html as $h ) {
				$html .= '<th>' . $h . '</th>';
			}
			$html .= '</tr></thead>';
		}
		$html .= '<tbody>';
		foreach ( $rows_html as $row ) {
			$html .= '<tr>';
			foreach ( $row as $cell ) {
				$html .= '<td>' . $cell . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></figure>';
		return self::block( 'core/table', array(), $html );
	}

	public static function image( int $attachment_id, string $alt, string $caption_html = '' ): string {
		$src = wp_get_attachment_image_url( $attachment_id, 'large' );
		if ( ! $src ) {
			return '';
		}
		$html = '<figure class="wp-block-image size-large"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $attachment_id . '"/>';
		if ( '' !== $caption_html ) {
			$html .= '<figcaption class="wp-element-caption">' . $caption_html . '</figcaption>';
		}
		$html .= '</figure>';
		return self::block(
			'core/image',
			array(
				'id'              => $attachment_id,
				'sizeSlug'        => 'large',
				'linkDestination' => 'none',
			),
			$html
		);
	}

	/**
	 * Yoast SEO FAQ block. Yoast reads the block attributes to output FAQPage schema.
	 * Markup mirrors Yoast's save() (packages/js/src/structured-data-blocks/faq).
	 *
	 * @param array<int,array{0:string,1:string}> $pairs [question_html, answer_html]
	 */
	public static function faq( array $pairs ): string {
		$questions = array();
		$html      = '<div class="schema-faq wp-block-yoast-faq-block">';
		$base      = (int) floor( microtime( true ) * 1000 );
		foreach ( array_values( $pairs ) as $i => $pair ) {
			list( $q, $a ) = $pair;
			$id            = 'faq-question-' . ( $base + $i );
			$questions[]   = array(
				'id'           => $id,
				'question'     => $q,
				'answer'       => $a,
				'images'       => array(),
				'jsonQuestion' => $q,
				'jsonAnswer'   => $a,
			);
			$html .= '<div class="schema-faq-section" id="' . esc_attr( $id ) . '"><strong class="schema-faq-question">' . $q . '</strong> <p class="schema-faq-answer">' . $a . '</p> </div> ';
		}
		$html .= '</div>';
		return self::block( 'yoast/faq-block', array( 'questions' => $questions ), $html );
	}

	public static function join( array $blocks ): string {
		return implode( "\n\n", array_filter( $blocks, static fn( $b ) => '' !== trim( (string) $b ) ) );
	}
}
