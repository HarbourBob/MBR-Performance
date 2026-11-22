<?php
/**
 * Rendering optimisations (roadmap M3b): content-visibility: auto.
 *
 * On a long page the browser lays out and paints every section, including the
 * dozens nobody has scrolled to yet. content-visibility: auto lets it skip that
 * work for anything off-screen, and do it just in time as the visitor scrolls —
 * a real gain for rendering time and for INP on long posts and page-builder
 * stacks. contain-intrinsic-size gives each skipped section a placeholder
 * height, and its `auto` keyword makes the browser remember the real height
 * once a section has been rendered, so the scrollbar settles.
 *
 * Applied only to an allowlist of section selectors — guessing at section
 * boundaries in arbitrary markup would be fragile — and never to the first few
 * matches, so the region holding the LCP element is always rendered normally.
 * "First few" is counted per parent with :nth-child(n+N of S); a browser too
 * old for that syntax drops the rule rather than applying it wrongly.
 *
 * Off by default. Known side effects are documented on the CSS tab.
 *
 * @package MBRPE
 * @since   2.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MBRPE_Render_Optimizations {

	/**
	 * Matches left alone at the top of each container.
	 */
	const DEFAULT_SKIP = 2;

	/**
	 * Placeholder height for a section not yet rendered, in pixels.
	 */
	const DEFAULT_HEIGHT = 600;

	/**
	 * Most selectors accepted.
	 */
	const MAX_SELECTORS = 30;

	/**
	 * Handle the inline CSS is attached to.
	 */
	const HANDLE = 'mbr-performance-content-visibility';

	/**
	 * @var MBRPE_Render_Optimizations|null
	 */
	private static $instance = null;

	/**
	 * @var array
	 */
	private $options = array();

	/**
	 * @return MBRPE_Render_Optimizations
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->options = mbrpe()->get_options( 'css' );
		if ( ! is_array( $this->options ) || empty( $this->options['cv_enabled'] ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Top-level section classes of the common page builders, plus core's
	 * group block at the top of post content.
	 *
	 * @return string One selector per line.
	 */
	public static function default_selectors() {
		return implode(
			"\n",
			array(
				'.elementor-top-section',
				'.e-con.e-parent',
				'.et_pb_section',
				'.fl-row',
				'.brxe-section',
				'.ct-section',
				'.kb-row-layout-wrap',
				'.entry-content > .wp-block-group',
				'.wp-block-post-content > .wp-block-group',
			)
		);
	}

	/**
	 * Split a textarea into clean, safe selectors.
	 *
	 * The selectors are printed inside a <style> element, so anything able to
	 * end a rule, start an at-rule, open a comment or close the element is
	 * refused outright. Each selector gets its own rule, so one the browser
	 * does not understand cannot take the others with it.
	 *
	 * @param string $raw
	 * @return string[]
	 */
	public static function parse_selectors( $raw ) {
		$out = array();
		foreach ( preg_split( '/\R/', (string) $raw ) as $line ) {
			foreach ( self::split_top_level( $line ) as $selector ) {
				$selector = trim( preg_replace( '/\s+/', ' ', $selector ) );
				if ( '' === $selector || strlen( $selector ) > 200 ) {
					continue;
				}
				// No rule, at-rule or comment delimiters, no backslash escapes,
				// and no '<' — nothing that could end the <style> element. A
				// '>' is fine: it is a combinator and cannot close a tag.
				if ( preg_match( '#[{};<@\\\\]|/\*|\*/#', $selector ) ) {
					continue;
				}
				if ( ! preg_match( '/^[A-Za-z0-9_\-\.#:\[\]="\' >+~*^$|(),%]+$/', $selector ) ) {
					continue;
				}
				if ( false !== strpos( $selector, '::' ) ) {
					continue; // Pseudo-elements cannot be contained.
				}
				$out[] = $selector;
				if ( count( $out ) >= self::MAX_SELECTORS ) {
					break 2;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Split on commas that are not inside brackets or quotes.
	 *
	 * @param string $s
	 * @return string[]
	 */
	private static function split_top_level( $s ) {
		$parts = array();
		$buf   = '';
		$depth = 0;
		$quote = '';
		$len   = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( '' !== $quote ) {
				$buf .= $c;
				if ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '(' === $c || '[' === $c ) {
				$depth++;
			} elseif ( ')' === $c || ']' === $c ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ',' === $c && 0 === $depth ) {
				$parts[] = $buf;
				$buf     = '';
				continue;
			}
			$buf .= $c;
		}
		$parts[] = $buf;
		return $parts;
	}

	/**
	 * The last compound selector — what :nth-child(… of S) counts among
	 * siblings. ".entry-content > .wp-block-group" counts ".wp-block-group".
	 *
	 * @param string $selector
	 * @return string
	 */
	private static function last_compound( $selector ) {
		$depth = 0;
		$cut   = 0;
		$len   = strlen( $selector );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $selector[ $i ];
			if ( '(' === $c || '[' === $c ) {
				$depth++;
			} elseif ( ')' === $c || ']' === $c ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( 0 === $depth && ( ' ' === $c || '>' === $c || '+' === $c || '~' === $c ) ) {
				$cut = $i + 1;
			}
		}
		return trim( substr( $selector, $cut ) );
	}

	/**
	 * Build the stylesheet.
	 *
	 * @param string[] $selectors
	 * @param int      $skip   Matches to leave alone in each container.
	 * @param int      $height Placeholder height in px.
	 * @return string
	 */
	public static function build_css( $selectors, $skip, $height ) {
		$skip   = max( 0, min( 20, (int) $skip ) );
		$height = max( 50, min( 5000, (int) $height ) );
		$decl   = '{content-visibility:auto;contain-intrinsic-size:auto ' . $height . 'px}';

		$rules = array();
		foreach ( (array) $selectors as $selector ) {
			if ( $skip > 0 ) {
				$compound = self::last_compound( $selector );
				if ( '' === $compound ) {
					continue;
				}
				$rules[] = $selector . ':nth-child(n+' . ( $skip + 1 ) . ' of ' . $compound . ')' . $decl;
			} else {
				$rules[] = $selector . $decl;
			}
		}
		if ( empty( $rules ) ) {
			return '';
		}
		// Screen only: print layout must not depend on what was scrolled to.
		return "/* MBR Performance: content-visibility */\n@media screen{" . implode( '', $rules ) . '}';
	}

	/**
	 * Attach the CSS to a source-less handle so it prints inline in the head.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( is_admin() || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
			return;
		}

		$raw = isset( $this->options['cv_selectors'] ) ? $this->options['cv_selectors'] : self::default_selectors();
		$css = self::build_css(
			self::parse_selectors( $raw ),
			isset( $this->options['cv_skip'] ) ? (int) $this->options['cv_skip'] : self::DEFAULT_SKIP,
			isset( $this->options['cv_height'] ) ? (int) $this->options['cv_height'] : self::DEFAULT_HEIGHT
		);
		if ( '' === $css ) {
			return;
		}

		wp_register_style( self::HANDLE, false, array(), MBRPE_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, $css );
	}
}
