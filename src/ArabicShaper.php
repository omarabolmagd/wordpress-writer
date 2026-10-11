<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares Arabic text for image libraries (GD/FreeType) that draw glyphs left to right
 * and do not join letters: picks the right contextual form for each letter, forms
 * lam-alef ligatures, then reorders the line visually while keeping Latin words and
 * numbers left to right.
 */
final class ArabicShaper {

	/**
	 * Presentation forms: [isolated, final, initial, medial]. Two forms means the letter
	 * only joins to the letter before it.
	 */
	private const FORMS = array(
		0x0621 => array( 0xFE80 ),
		0x0622 => array( 0xFE81, 0xFE82 ),
		0x0623 => array( 0xFE83, 0xFE84 ),
		0x0624 => array( 0xFE85, 0xFE86 ),
		0x0625 => array( 0xFE87, 0xFE88 ),
		0x0626 => array( 0xFE89, 0xFE8A, 0xFE8B, 0xFE8C ),
		0x0627 => array( 0xFE8D, 0xFE8E ),
		0x0628 => array( 0xFE8F, 0xFE90, 0xFE91, 0xFE92 ),
		0x0629 => array( 0xFE93, 0xFE94 ),
		0x062A => array( 0xFE95, 0xFE96, 0xFE97, 0xFE98 ),
		0x062B => array( 0xFE99, 0xFE9A, 0xFE9B, 0xFE9C ),
		0x062C => array( 0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0 ),
		0x062D => array( 0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4 ),
		0x062E => array( 0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8 ),
		0x062F => array( 0xFEA9, 0xFEAA ),
		0x0630 => array( 0xFEAB, 0xFEAC ),
		0x0631 => array( 0xFEAD, 0xFEAE ),
		0x0632 => array( 0xFEAF, 0xFEB0 ),
		0x0633 => array( 0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4 ),
		0x0634 => array( 0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8 ),
		0x0635 => array( 0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC ),
		0x0636 => array( 0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0 ),
		0x0637 => array( 0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4 ),
		0x0638 => array( 0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8 ),
		0x0639 => array( 0xFEC9, 0xFECA, 0xFECB, 0xFECC ),
		0x063A => array( 0xFECD, 0xFECE, 0xFECF, 0xFED0 ),
		0x0641 => array( 0xFED1, 0xFED2, 0xFED3, 0xFED4 ),
		0x0642 => array( 0xFED5, 0xFED6, 0xFED7, 0xFED8 ),
		0x0643 => array( 0xFED9, 0xFEDA, 0xFEDB, 0xFEDC ),
		0x0644 => array( 0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0 ),
		0x0645 => array( 0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4 ),
		0x0646 => array( 0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8 ),
		0x0647 => array( 0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC ),
		0x0648 => array( 0xFEED, 0xFEEE ),
		0x0649 => array( 0xFEEF, 0xFEF0 ),
		0x064A => array( 0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4 ),
		0x067E => array( 0xFB56, 0xFB57, 0xFB58, 0xFB59 ),
		0x0686 => array( 0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D ),
		0x0698 => array( 0xFB8A, 0xFB8B ),
		0x06A4 => array( 0xFB6A, 0xFB6B, 0xFB6C, 0xFB6D ),
		0x06A9 => array( 0xFB8E, 0xFB8F, 0xFB90, 0xFB91 ),
		0x06AF => array( 0xFB92, 0xFB93, 0xFB94, 0xFB95 ),
		0x06CC => array( 0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF ),
	);

	/** Lam + alef variants: alef => [isolated ligature, final ligature]. */
	private const LAM_ALEF = array(
		0x0622 => array( 0xFEF5, 0xFEF6 ),
		0x0623 => array( 0xFEF7, 0xFEF8 ),
		0x0625 => array( 0xFEF9, 0xFEFA ),
		0x0627 => array( 0xFEFB, 0xFEFC ),
	);

	private const TATWEEL = 0x0640;

	private const MIRROR = array(
		'(' => ')',
		')' => '(',
		'[' => ']',
		']' => '[',
		'{' => '}',
		'}' => '{',
		'«' => '»',
		'»' => '«',
		'<' => '>',
		'>' => '<',
	);

	/** True for harakat and other marks that don't affect joining. They are dropped for display. */
	private static function is_mark( int $cp ): bool {
		return ( $cp >= 0x064B && $cp <= 0x065F ) || 0x0670 === $cp || ( $cp >= 0x06D6 && $cp <= 0x06ED );
	}

	private static function joins_next( int $cp ): bool {
		return self::TATWEEL === $cp || ( isset( self::FORMS[ $cp ] ) && 4 === count( self::FORMS[ $cp ] ) );
	}

	private static function joins_prev( int $cp ): bool {
		return self::TATWEEL === $cp || ( isset( self::FORMS[ $cp ] ) && count( self::FORMS[ $cp ] ) >= 2 );
	}

	private static function is_arabic_letter( int $cp ): bool {
		return isset( self::FORMS[ $cp ] ) || self::TATWEEL === $cp;
	}

	/** Converts logical Arabic text into contextual glyph forms (still in logical order). */
	public static function shape( string $text ): string {
		$chars = array();
		foreach ( mb_str_split( $text ) as $ch ) {
			$cp = mb_ord( $ch );
			if ( self::is_mark( $cp ) ) {
				continue;
			}
			$chars[] = $cp;
		}

		$out = array();
		$n   = count( $chars );
		for ( $i = 0; $i < $n; $i++ ) {
			$cp = $chars[ $i ];
			if ( ! isset( self::FORMS[ $cp ] ) ) {
				$out[] = $cp;
				continue;
			}
			$prev      = $i > 0 ? $chars[ $i - 1 ] : 0;
			$next      = $i + 1 < $n ? $chars[ $i + 1 ] : 0;
			$prev_join = $prev && self::joins_next( $prev );

			// Lam followed by an alef becomes one ligature glyph.
			if ( 0x0644 === $cp && $next && isset( self::LAM_ALEF[ $next ] ) ) {
				$out[] = self::LAM_ALEF[ $next ][ $prev_join ? 1 : 0 ];
				++$i;
				continue;
			}

			$forms     = self::FORMS[ $cp ];
			$next_join = 4 === count( $forms ) && $next && self::joins_prev( $next );

			if ( $prev_join && $next_join ) {
				$out[] = $forms[3];
			} elseif ( $prev_join && count( $forms ) >= 2 ) {
				$out[] = $forms[1];
			} elseif ( $next_join ) {
				$out[] = $forms[2];
			} else {
				// Isolated forms look like the base letter, and many fonts omit them, so use the base letter.
				$out[] = $cp;
			}
		}
		return implode( '', array_map( 'mb_chr', $out ) );
	}

	/**
	 * Shapes one line and returns it in visual order (left to right), for a right-to-left paragraph.
	 */
	public static function visual( string $line ): string {
		$shaped = self::shape( $line );
		$chars  = mb_str_split( $shaped );

		// Split into runs: strong LTR (Latin letters/digits and what sits between them) vs everything else.
		$runs    = array();
		$current = '';
		$is_ltr  = null;
		$count   = count( $chars );
		for ( $i = 0; $i < $count; $i++ ) {
			$ch  = $chars[ $i ];
			$ltr = self::is_ltr_char( $ch );
			if ( ! $ltr && null !== $is_ltr && $is_ltr && self::is_neutral( $ch ) ) {
				// Keep spaces/punctuation inside an LTR run when more LTR text follows before any Arabic.
				$ahead = self::next_strong( $chars, $i + 1 );
				if ( 'ltr' === $ahead ) {
					$current .= $ch;
					continue;
				}
			}
			if ( null === $is_ltr || $ltr === $is_ltr ) {
				$current .= $ch;
				$is_ltr   = $ltr;
				continue;
			}
			$runs[]  = array( $is_ltr, $current );
			$current = $ch;
			$is_ltr  = $ltr;
		}
		if ( '' !== $current ) {
			$runs[] = array( (bool) $is_ltr, $current );
		}

		$visual = '';
		foreach ( array_reverse( $runs ) as $run ) {
			list( $ltr, $text ) = $run;
			if ( $ltr ) {
				$visual .= $text;
			} else {
				$rev = array_reverse( mb_str_split( $text ) );
				foreach ( $rev as $c ) {
					$visual .= self::MIRROR[ $c ] ?? $c;
				}
			}
		}
		return $visual;
	}

	private static function is_ltr_char( string $ch ): bool {
		return (bool) preg_match( '/[A-Za-z0-9\x{00C0}-\x{024F}\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $ch );
	}

	private static function is_neutral( string $ch ): bool {
		return (bool) preg_match( '/[\s\.\,\-\+\:\/\&\'_#%]/u', $ch );
	}

	private static function next_strong( array $chars, int $from ): string {
		$count = count( $chars );
		for ( $i = $from; $i < $count; $i++ ) {
			if ( self::is_ltr_char( $chars[ $i ] ) ) {
				return 'ltr';
			}
			$cp = mb_ord( $chars[ $i ] );
			if ( $cp >= 0x0600 && $cp <= 0x06FF || $cp >= 0xFB50 && $cp <= 0xFEFF ) {
				return 'rtl';
			}
		}
		return 'none';
	}
}
