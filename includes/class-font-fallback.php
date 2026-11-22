<?php
/**
 * Font fallback metrics (roadmap M3a).
 *
 * For every self-hosted Google Font family this module:
 *
 *   1. Measures the font once — vertical metrics and average glyph width —
 *      and stores the result. The self-hosted copy is WOFF2, which PHP cannot
 *      decompress without Brotli, so a TrueType copy of the same font is
 *      fetched from Google for measuring and then discarded.
 *   2. Declares a "<Family> Fallback" @font-face that points at a local system
 *      font (Arial, Times New Roman or Courier New, plus their metric-twins on
 *      Linux) with size-adjust and ascent/descent/line-gap overrides, so it
 *      takes up the same room as the web font. Printed with the self-hosted
 *      @font-face rules, inline, so it costs no request.
 *   3. Puts that fallback into every font-family stack that names the family,
 *      so the browser actually uses it while the web font loads:
 *      `font-family: "Inter", sans-serif` becomes
 *      `font-family: "Inter", "Inter Fallback", sans-serif`. Inline <style>
 *      blocks and style attributes are rewritten in the page; local
 *      stylesheets are served from rewritten copies under
 *      /uploads/mbr-performance-fontfallback/, built once per file version.
 *
 * Step 3 can be switched off for anyone who would rather add the fallback
 * names to their own CSS.
 *
 * @package MBRPE
 * @since   2.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MBRPE_Font_Fallback {

	/**
	 * Option holding measurements, keyed by family.
	 */
	const OPTION = 'mbrpe_font_metrics';

	/**
	 * Uploads sub-directory for rewritten stylesheets.
	 */
	const DIRNAME = 'mbr-performance-fontfallback';

	/**
	 * Bump when the rewriting output changes, so every copy is rebuilt.
	 */
	const REWRITE_ALGO = '1';

	/**
	 * Largest stylesheet rewritten at request time, in bytes.
	 */
	const MAX_BYTES = 1572864;

	/**
	 * @var MBRPE_Font_Fallback|null
	 */
	private static $instance = null;

	/**
	 * Per-request memo: family => overrides.
	 *
	 * @var array|null
	 */
	private static $families = null;

	/**
	 * Per-request memo: stylesheet URL => URL to serve.
	 *
	 * @var array
	 */
	private static $url_memo = array();

	/**
	 * @return MBRPE_Font_Fallback
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Front-end hooks, only when the feature is on. The @font-face rules
	 * themselves are printed by MBRPE_Font_Optimizations alongside the
	 * self-hosted faces; this class adds the font-family rewriting.
	 */
	private function __construct() {
		add_action( 'wp_ajax_mbrpe_measure_fonts', array( $this, 'ajax_measure' ) );

		if ( ! self::is_enabled() || ! self::rewrite_enabled() ) {
			return;
		}

		// Between the page cache, which is outermost, and every other buffer.
		// Used CSS Mode B inlines its CSS from a buffer at priority 1, and the
		// rewrite has to see that CSS, so it must run after it — which for
		// nested output buffers means starting before it.
		add_action( 'template_redirect', array( $this, 'start_buffer' ), -1 );
	}

	// =====================================================================
	// Settings
	// =====================================================================

	/**
	 * @return array
	 */
	private static function options() {
		$opts = mbrpe()->get_options( 'fonts' );
		return is_array( $opts ) ? $opts : array();
	}

	/**
	 * Fallback metrics need self-hosted fonts: nothing else is measured.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$opts = self::options();
		return ! empty( $opts['self_host_google_fonts'] ) && ! empty( $opts['fallback_metrics'] );
	}

	/**
	 * @return bool
	 */
	public static function rewrite_enabled() {
		$opts = self::options();
		return ! isset( $opts['fallback_rewrite'] ) || ! empty( $opts['fallback_rewrite'] );
	}

	/**
	 * Category chosen on the Fonts tab for a family, or 'auto'.
	 *
	 * @param string $family
	 * @return string auto|sans|serif|mono
	 */
	public static function chosen_category( $family ) {
		$opts = self::options();
		$map  = isset( $opts['fallback_overrides'] ) && is_array( $opts['fallback_overrides'] ) ? $opts['fallback_overrides'] : array();
		$key  = self::family_key( $family );
		return ( isset( $map[ $key ] ) && in_array( $map[ $key ], array( 'sans', 'serif', 'mono' ), true ) ) ? $map[ $key ] : 'auto';
	}

	/**
	 * Option-array key for a family.
	 *
	 * @param string $family
	 * @return string
	 */
	public static function family_key( $family ) {
		return strtolower( MBRPE_Font_Metrics::clean_family( $family ) );
	}

	/**
	 * Self-hosted families, as stored by the Fonts tab.
	 *
	 * @return array family => variants
	 */
	public static function local_families() {
		$fonts = get_option( 'mbrpe_local_fonts', array() );
		return is_array( $fonts ) ? $fonts : array();
	}

	/**
	 * Stored measurements.
	 *
	 * @return array
	 */
	public static function stored() {
		$m = get_option( self::OPTION, array() );
		return is_array( $m ) ? $m : array();
	}

	/**
	 * Families with usable measurements, and their overrides.
	 *
	 * @return array family => overrides (see MBRPE_Font_Metrics::overrides())
	 */
	public static function families() {
		if ( null !== self::$families ) {
			return self::$families;
		}
		self::$families = array();

		$stored = self::stored();
		foreach ( array_keys( self::local_families() ) as $family ) {
			$family = (string) $family;
			$key    = self::family_key( $family );
			if ( '' === $key || empty( $stored[ $key ]['metrics'] ) ) {
				continue;
			}
			$metrics  = $stored[ $key ]['metrics'];
			$category = self::chosen_category( $family );
			if ( 'auto' === $category ) {
				$category = isset( $stored[ $key ]['category'] ) ? $stored[ $key ]['category'] : 'sans';
			}
			self::$families[ MBRPE_Font_Metrics::clean_family( $family ) ] = MBRPE_Font_Metrics::overrides( $metrics, $category );
		}

		return self::$families;
	}

	/**
	 * Forget the per-request memo, after measuring or saving.
	 *
	 * @return void
	 */
	public static function reset_memo() {
		self::$families = null;
		self::$url_memo = array();
	}

	// =====================================================================
	// @font-face output
	// =====================================================================

	/**
	 * Fallback @font-face rules for every measured family.
	 *
	 * @return string
	 */
	public static function font_face_css() {
		if ( ! self::is_enabled() ) {
			return '';
		}
		$css = '';
		foreach ( self::families() as $family => $o ) {
			$css .= MBRPE_Font_Metrics::font_face_css( $family, $o );
		}
		return '' === $css ? '' : "/* MBR Performance: metric-matched fallbacks */\n" . $css;
	}

	// =====================================================================
	// Measuring
	// =====================================================================

	/**
	 * Hosts a measurement copy may come from — the same list, and the same
	 * filter, as the self-hosting downloader.
	 *
	 * @return string[]
	 */
	private static function font_hosts() {
		/** This filter is documented in includes/class-admin.php */
		return array_map( 'strtolower', (array) apply_filters( 'mbrpe_font_download_hosts', array( 'fonts.gstatic.com' ) ) );
	}

	/**
	 * Which variant of a family to measure: regular if it was downloaded,
	 * otherwise the first upright weight, otherwise whatever there is.
	 *
	 * @param array $variants
	 * @return string
	 */
	private static function pick_variant( $variants ) {
		$variants = array_values( array_map( 'strval', (array) $variants ) );
		if ( in_array( '400', $variants, true ) || in_array( 'regular', $variants, true ) ) {
			return '400';
		}
		foreach ( $variants as $v ) {
			if ( false === strpos( $v, 'italic' ) ) {
				return preg_replace( '/[^0-9]/', '', $v ) ? preg_replace( '/[^0-9]/', '', $v ) : '400';
			}
		}
		return isset( $variants[0] ) ? $variants[0] : '400';
	}

	/**
	 * Fetch a measurable (non-WOFF2) copy of one variant from Google and
	 * measure it.
	 *
	 * Google decides the format from the User-Agent: browsers it knows get
	 * WOFF2, anything else gets TrueType. Two agents are tried in case one
	 * stops working; a network failure stops at once.
	 *
	 * @param string $family  Exact, case-sensitive name as self-hosted.
	 * @param string $variant Weight, optionally suffixed "italic".
	 * @return array|WP_Error Metrics from MBRPE_Font_Metrics::measure().
	 */
	public static function fetch_and_measure( $family, $variant ) {
		$encoded = str_replace( '%20', '+', rawurlencode( $family ) );
		$variant = preg_replace( '/[^0-9a-z]/i', '', (string) $variant );

		if ( false !== strpos( $variant, 'italic' ) ) {
			$weight = str_replace( 'italic', '', $variant );
			$api    = 'https://fonts.googleapis.com/css2?family=' . $encoded . ':ital,wght@1,' . ( $weight ? $weight : '400' );
		} else {
			$api = 'https://fonts.googleapis.com/css2?family=' . $encoded . ':wght@' . ( $variant ? $variant : '400' );
		}

		$agents = array(
			'MBR-Performance/' . MBRPE_VERSION . ' (font metrics; +https://littlewebshack.com/mbr-performance/)',
			// Safari 5.1, which Google serves TrueType.
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_6_8) AppleWebKit/534.59.10 (KHTML, like Gecko) Version/5.1.9 Safari/534.59.10',
		);

		$last = new WP_Error( 'mbrpe_font_measure', __( 'Google did not supply a font file that could be measured.', 'mbr-performance' ) );

		foreach ( $agents as $agent ) {
			$response = wp_remote_get(
				$api,
				array(
					'user-agent' => $agent,
					'timeout'    => 10,
				)
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'mbrpe_font_network', __( 'Could not reach Google Fonts to measure this font.', 'mbr-performance' ) . ' ' . $response->get_error_message() );
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error( 'mbrpe_font_api', __( 'Google Fonts did not recognise this family and weight.', 'mbr-performance' ) );
			}

			$css = (string) wp_remote_retrieve_body( $response );
			if ( ! preg_match_all( '/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)\s*format\(\s*[\'"]?(truetype|opentype|woff)[\'"]?\s*\)/i', $css, $m, PREG_SET_ORDER ) ) {
				continue; // WOFF2 only for this agent; try the next.
			}

			// The last block is the basic-Latin one when Google splits by range.
			$url  = end( $m )[1];
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || ! in_array( $host, self::font_hosts(), true ) ) {
				$last = new WP_Error( 'mbrpe_font_host', __( 'Google pointed at a font file on an unexpected host, so it was not fetched.', 'mbr-performance' ) );
				continue;
			}

			$file = wp_remote_get(
				$url,
				array(
					'timeout'             => 15,
					'limit_response_size' => 8 * MB_IN_BYTES,
				)
			);
			if ( is_wp_error( $file ) ) {
				return new WP_Error( 'mbrpe_font_network', __( 'Could not download the font file to measure it.', 'mbr-performance' ) . ' ' . $file->get_error_message() );
			}

			$metrics = MBRPE_Font_Metrics::measure( (string) wp_remote_retrieve_body( $file ) );
			if ( ! is_wp_error( $metrics ) ) {
				return $metrics;
			}
			$last = $metrics;
		}

		return $last;
	}

	/**
	 * Measure self-hosted families that have no current measurement, and
	 * forget measurements for families no longer self-hosted.
	 *
	 * Bounded by a time budget so a slow or unreachable Google cannot hold a
	 * settings save hostage; anything left over is measured next time.
	 *
	 * @param bool $force  Re-measure everything.
	 * @param int  $budget Seconds.
	 * @return array{measured:int,failed:int,pending:int,errors:array}
	 */
	public static function measure_missing( $force = false, $budget = 20 ) {
		$start  = microtime( true );
		$stored = self::stored();
		$local  = self::local_families();
		$out    = array(
			'measured' => 0,
			'failed'   => 0,
			'pending'  => 0,
			'errors'   => array(),
		);

		$keep = array();
		foreach ( $local as $family => $variants ) {
			$keep[ self::family_key( $family ) ] = true;
		}
		foreach ( array_keys( $stored ) as $key ) {
			if ( ! isset( $keep[ $key ] ) ) {
				unset( $stored[ $key ] );
			}
		}

		$offline = false;
		foreach ( $local as $family => $variants ) {
			$family = (string) $family;
			$key    = self::family_key( $family );
			$entry  = isset( $stored[ $key ] ) ? $stored[ $key ] : array();
			$fresh  = ! empty( $entry['metrics'] ) && isset( $entry['algo'] ) && MBRPE_Font_Metrics::ALGO === $entry['algo'];
			if ( $fresh && ! $force ) {
				continue;
			}
			if ( $offline || ( microtime( true ) - $start ) > $budget ) {
				$out['pending']++;
				continue;
			}

			$variant = self::pick_variant( $variants );
			$metrics = self::fetch_and_measure( $family, $variant );

			if ( is_wp_error( $metrics ) ) {
				$out['failed']++;
				$out['errors'][ $family ] = $metrics->get_error_message();
				// Keep a previous good measurement rather than lose it to a
				// temporary failure.
				if ( empty( $entry['metrics'] ) ) {
					$stored[ $key ] = array(
						'family'   => $family,
						'error'    => $metrics->get_error_message(),
						'measured' => time(),
					);
				}
				if ( 'mbrpe_font_network' === $metrics->get_error_code() ) {
					$offline = true;
				}
				continue;
			}

			unset( $metrics['panose_raw'] );
			$stored[ $key ] = array(
				'family'   => $family,
				'variant'  => $variant,
				'metrics'  => $metrics,
				'category' => MBRPE_Font_Metrics::guess_category( $metrics, $family ),
				'algo'     => MBRPE_Font_Metrics::ALGO,
				'measured' => time(),
			);
			$out['measured']++;
		}

		update_option( self::OPTION, $stored, true );
		self::reset_memo();

		return $out;
	}

	/**
	 * After fonts were downloaded or cleared from the Fonts tab.
	 *
	 * @return void
	 */
	public static function after_fonts_changed() {
		if ( self::is_enabled() ) {
			self::measure_missing( false, 15 );
		} elseif ( empty( self::local_families() ) ) {
			delete_option( self::OPTION );
		}
		self::reset_memo();
		self::purge_rewritten();
		if ( class_exists( 'MBRPE_Page_Cache_Purge' ) ) {
			MBRPE_Page_Cache_Purge::purge_all( 'fonts-changed' );
		}
	}

	/**
	 * Settings saved. Measure anything new once the feature is on, and drop
	 * rewritten stylesheets — the family set or the categories may differ.
	 * The page cache is purged by its own handler on every save.
	 *
	 * @return void
	 */
	public static function on_options_updated() {
		self::reset_memo();
		if ( self::is_enabled() ) {
			self::measure_missing( false, 20 );
		}
		self::purge_rewritten();
	}

	/**
	 * Fonts tab: measure every family now.
	 *
	 * @return void
	 */
	public function ajax_measure() {
		check_ajax_referer( 'mbrpe_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mbr-performance' ) ) );
		}
		if ( empty( self::local_families() ) ) {
			wp_send_json_error( array( 'message' => __( 'There are no self-hosted fonts to measure yet. Download them above first.', 'mbr-performance' ) ) );
		}

		$result = self::measure_missing( true, 45 );
		self::purge_rewritten();
		if ( class_exists( 'MBRPE_Page_Cache_Purge' ) ) {
			MBRPE_Page_Cache_Purge::purge_all( 'fonts-measured' );
		}

		$message = sprintf(
			/* translators: 1: number measured, 2: number failed */
			__( 'Measured %1$d font families. Failed: %2$d.', 'mbr-performance' ),
			$result['measured'],
			$result['failed']
		);
		if ( $result['pending'] > 0 ) {
			/* translators: %d: number of families not yet measured */
			$message .= ' ' . sprintf( __( '%d were left for next time because Google was slow to answer.', 'mbr-performance' ), $result['pending'] );
		}
		foreach ( $result['errors'] as $family => $error ) {
			$message .= "\n" . $family . ': ' . $error;
		}

		wp_send_json_success( array( 'message' => $message ) );
	}

	// =====================================================================
	// font-family rewriting
	// =====================================================================

	/**
	 * Requests where the page must be left exactly as rendered.
	 *
	 * @return bool
	 */
	private static function should_skip() {
		if ( is_admin() || is_feed() || is_embed() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return true;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return true;
		}
		return false;
	}

	/**
	 * @return void
	 */
	public function start_buffer() {
		if ( self::should_skip() || empty( self::families() ) ) {
			return;
		}
		ob_start( array( $this, 'buffer' ) );
	}

	/**
	 * Rewrite a finished HTML document.
	 *
	 * @param string $html
	 * @return string
	 */
	public function buffer( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '</head>' ) ) {
			return $html;
		}
		try {
			return self::rewrite_html( $html, self::families() );
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	/**
	 * Rewrite <style> blocks, style attributes and local stylesheet links.
	 *
	 * @param string $html
	 * @param array  $families family => overrides
	 * @return string
	 */
	public static function rewrite_html( $html, $families ) {
		if ( empty( $families ) ) {
			return $html;
		}

		// Shelve scripts and textareas, whose contents are not CSS even when
		// they contain something that looks like a <style> tag.
		$shelf = array();
		$out   = preg_replace_callback(
			'#<(script|textarea)\b[^>]*>.*?</\1\s*>#is',
			static function ( $m ) use ( &$shelf ) {
				$key           = '<!--mbrpe-ff-' . count( $shelf ) . '-->';
				$shelf[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		if ( null === $out ) {
			return $html;
		}
		$html = $out;

		// <style> blocks.
		$out = preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style\s*>)#is',
			static function ( $m ) use ( $families ) {
				return $m[1] . self::rewrite_css( $m[2], $families ) . $m[3];
			},
			$html
		);
		if ( null !== $out ) {
			$html = $out;
		}

		// style="" and style='' attributes.
		$out = preg_replace_callback(
			'#(\sstyle\s*=\s*)(["\'])(.*?)\2#is',
			static function ( $m ) use ( $families ) {
				if ( false === stripos( $m[3], 'font' ) ) {
					return $m[0];
				}
				$decoded   = html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$rewritten = self::rewrite_css( $decoded, $families, true );
				if ( $rewritten === $decoded ) {
					return $m[0];
				}
				return $m[1] . '"' . htmlspecialchars( $rewritten, ENT_QUOTES, 'UTF-8' ) . '"';
			},
			$html
		);
		if ( null !== $out ) {
			$html = $out;
		}

		// Local stylesheets, whether linked directly or preloaded by an async
		// pattern (Async CSS, Used CSS Mode A).
		$out = preg_replace_callback(
			'#<link\b[^>]*>#i',
			static function ( $m ) use ( $families ) {
				$tag = $m[0];
				if ( ! preg_match( '/\brel\s*=\s*["\']?([^"\'>]+)/i', $tag, $rel ) ) {
					return $tag;
				}
				$rel   = strtolower( $rel[1] );
				$sheet = false !== strpos( $rel, 'stylesheet' )
					|| ( false !== strpos( $rel, 'preload' ) && preg_match( '/\bas\s*=\s*["\']?style\b/i', $tag ) );
				if ( ! $sheet || ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $h ) ) {
					return $tag;
				}
				$href = html_entity_decode( $h[2], ENT_QUOTES, 'UTF-8' );
				$new  = self::rewritten_url( $href, $families );
				if ( $new === $href ) {
					return $tag;
				}
				return str_replace( $h[0], 'href=' . $h[1] . esc_url( $new ) . $h[1], $tag );
			},
			$html
		);
		if ( null !== $out ) {
			$html = $out;
		}

		return empty( $shelf ) ? $html : strtr( $html, $shelf );
	}

	/**
	 * Insert fallback families into every font-family stack in a stylesheet.
	 *
	 * Handles font-family, the font shorthand, and custom properties with
	 * "font" in their name (--wp--preset--font-family--inter, Elementor's
	 * --e-global-typography-*-font-family). @font-face rules are left alone:
	 * their font-family names the face being declared, not a stack.
	 *
	 * @param string $css
	 * @param array  $families   family => overrides (only the keys are used).
	 * @param bool   $is_inline  A style attribute: declarations with no rule.
	 * @return string
	 */
	public static function rewrite_css( $css, $families, $is_inline = false ) {
		if ( ! is_string( $css ) || '' === $css || empty( $families ) ) {
			return $css;
		}

		// Cheap test first: is any family named at all?
		$lookup  = array();
		$present = false;
		foreach ( array_keys( $families ) as $family ) {
			$lookup[ strtolower( $family ) ] = $family;
			if ( false !== stripos( $css, $family ) ) {
				$present = true;
			}
		}
		if ( ! $present ) {
			return $css;
		}

		// Shelve comments and the at-rules whose font-family is a name, not a
		// stack. @font-feature-values nests one level of blocks.
		$shelf = array();
		$shelve = static function ( $m ) use ( &$shelf ) {
			$key           = "\x01" . count( $shelf ) . "\x02";
			$shelf[ $key ] = $m[0];
			return $key;
		};
		$work = preg_replace_callback( '#/\*.*?\*/#s', $shelve, $css );
		if ( null === $work ) {
			return $css;
		}
		$work = preg_replace_callback( '#@font-(?:face|palette-values|feature-values)\b[^{]*\{(?:[^{}]|\{[^{}]*\})*\}#i', $shelve, $work );
		if ( null === $work ) {
			return $css;
		}

		$lead = $is_inline ? '(^|[;\s])' : '(^|[{;\s])';
		$out  = preg_replace_callback(
			'#' . $lead . '(font-family|font|--[A-Za-z0-9_\-]*font[A-Za-z0-9_\-]*)(\s*:\s*)([^;{}]*)#i',
			static function ( $m ) use ( $lookup ) {
				$prop  = strtolower( $m[2] );
				$value = $m[4];
				if ( 'font' === $prop ) {
					$value = self::rewrite_shorthand( $value, $lookup );
				} else {
					$value = self::rewrite_stack( $value, $lookup );
				}
				return $m[1] . $m[2] . $m[3] . $value;
			},
			$work
		);
		if ( null === $out ) {
			return $css;
		}

		return empty( $shelf ) ? $out : strtr( $out, $shelf );
	}

	/**
	 * The font shorthand: family list comes after the size (and optional
	 * line height). System keywords and var() values are left alone.
	 *
	 * @param string $value
	 * @param array  $lookup lowercase name => family
	 * @return string
	 */
	private static function rewrite_shorthand( $value, $lookup ) {
		$size = '(?:\d*\.?\d+(?:px|pt|pc|em|rem|ex|ch|%|vw|vh|vmin|vmax|in|cm|mm|q|lh|rlh|cap|ic|cqw|cqh|cqi|cqb)|xx-small|x-small|small|medium|large|x-large|xx-large|xxx-large|smaller|larger|(?:calc|clamp|min|max|var)\([^()]*(?:\([^()]*\)[^()]*)*\))';
		if ( ! preg_match_all( '#(?:^|\s)' . $size . '(?:\s*/\s*[^\s,]+)?\s+#i', $value, $all, PREG_OFFSET_CAPTURE ) ) {
			return $value;
		}
		$last  = end( $all[0] );
		$split = $last[1] + strlen( $last[0] );
		return substr( $value, 0, $split ) . self::rewrite_stack( substr( $value, $split ), $lookup );
	}

	/**
	 * Insert "<Family> Fallback" after each self-hosted family in a list.
	 *
	 * Splits on commas outside quotes and brackets, compares names
	 * case-insensitively with quotes removed, and leaves the original text
	 * — spacing, quoting, !important — exactly as it was.
	 *
	 * @param string $value
	 * @param array  $lookup lowercase name => family
	 * @return string
	 */
	private static function rewrite_stack( $value, $lookup ) {
		// !important belongs to the whole declaration; set it aside so a
		// fallback inserted after the last family lands before it.
		$important = '';
		if ( preg_match( '/\s*!\s*important\s*$/i', $value, $im ) ) {
			$important = $im[0];
			$value     = substr( $value, 0, -strlen( $im[0] ) );
		}

		$items = array();
		$buf   = '';
		$quote = '';
		$depth = 0;
		$len   = strlen( $value );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $value[ $i ];
			if ( '' !== $quote ) {
				$buf .= $c;
				if ( '\\' === $c && $i + 1 < $len ) {
					$buf .= $value[ ++$i ];
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '(' === $c ) {
				$depth++;
			} elseif ( ')' === $c ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ',' === $c && 0 === $depth ) {
				$items[] = $buf;
				$buf     = '';
				continue;
			}
			$buf .= $c;
		}
		$items[] = $buf;

		$names = array_map( array( __CLASS__, 'normalise_name' ), $items );
		$count = count( $items );
		$done  = array();

		$changed = false;
		foreach ( $items as $i => $item ) {
			$name = $names[ $i ];
			if ( '' === $name || ! isset( $lookup[ $name ] ) || isset( $done[ $name ] ) ) {
				continue;
			}
			$done[ $name ] = true;
			$fallback      = MBRPE_Font_Metrics::fallback_name( $lookup[ $name ] );
			if ( $i + 1 < $count && strtolower( $fallback ) === $names[ $i + 1 ] ) {
				continue; // Already there.
			}
			// Straight after the name, before any whitespace that followed it.
			$core        = rtrim( $item );
			$items[ $i ] = $core . ',"' . $fallback . '"' . substr( $item, strlen( $core ) );
			$changed     = true;
		}

		if ( ! $changed ) {
			return $value . $important;
		}
		return implode( ',', $items ) . $important;
	}

	/**
	 * A list item reduced to a comparable family name: quotes and !important
	 * removed, whitespace collapsed, lower case. Empty for var(), keywords
	 * with arguments and anything else that is not a plain name.
	 *
	 * @param string $item
	 * @return string
	 */
	public static function normalise_name( $item ) {
		$item = trim( $item );
		if ( '' === $item || false !== strpos( $item, '(' ) ) {
			return '';
		}
		if ( ( '"' === $item[0] || "'" === $item[0] ) && substr( $item, -1 ) === $item[0] ) {
			$item = substr( $item, 1, -1 );
		}
		return strtolower( trim( preg_replace( '/\s+/', ' ', stripslashes( $item ) ) ) );
	}

	// =====================================================================
	// Rewritten stylesheet copies
	// =====================================================================

	/**
	 * URL of a rewritten copy of a local stylesheet, or the original URL
	 * when it names none of the families (or is not local).
	 *
	 * @param string $href
	 * @param array  $families
	 * @return string
	 */
	public static function rewritten_url( $href, $families ) {
		if ( ! is_string( $href ) || '' === $href ) {
			return $href;
		}
		if ( isset( self::$url_memo[ $href ] ) ) {
			return self::$url_memo[ $href ];
		}
		self::$url_memo[ $href ] = $href;

		if ( ! class_exists( 'MBRPE_CSS_Optimizations' ) ) {
			return $href;
		}
		$path = MBRPE_CSS_Optimizations::url_to_path( $href, array( 'css' ) );
		if ( '' === $path ) {
			return $href;
		}
		$norm = wp_normalize_path( $path );

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return $href;
		}
		$base = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		if ( 0 === strpos( $norm, $base . self::DIRNAME . '/' ) ) {
			return $href; // Already one of ours.
		}

		$size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $size || $size > self::MAX_BYTES || ! $mtime ) {
			return $href;
		}

		$target = self::target_for( $norm, $size, $mtime, $families, $uploads );
		if ( null === $target || is_file( $target['skip'] ) ) {
			return $href;
		}
		if ( ! is_file( $target['path'] ) && ! self::build( $path, $href, $families, $target ) ) {
			return $href;
		}

		self::$url_memo[ $href ] = $target['url'];
		return $target['url'];
	}

	/**
	 * Where the rewritten copy of a file lives. Mirrors the source path, like
	 * the minifier, so URL-fragment exclusions written against the original
	 * still match.
	 *
	 * @param string $norm     Normalised source path.
	 * @param int    $size
	 * @param int    $mtime
	 * @param array  $families
	 * @param array  $uploads  wp_upload_dir().
	 * @return array|null {dir, path, url, skip}
	 */
	private static function target_for( $norm, $size, $mtime, $families, $uploads ) {
		$roots = array(
			trailingslashit( wp_normalize_path( ABSPATH ) ),
			trailingslashit( wp_normalize_path( dirname( WP_CONTENT_DIR ) ) ),
		);
		$rel = '';
		foreach ( $roots as $root ) {
			if ( 0 === strpos( $norm, $root ) ) {
				$rel = substr( $norm, strlen( $root ) );
				break;
			}
		}
		if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
			return null;
		}

		$rel_dir = dirname( $rel );
		$rel_dir = ( '.' === $rel_dir ) ? '' : trim( $rel_dir, '/' ) . '/';
		$stem    = pathinfo( $rel, PATHINFO_FILENAME );
		$hash    = substr( md5( $norm . '|' . $size . '|' . $mtime . '|' . self::REWRITE_ALGO . '|' . implode( ',', array_keys( $families ) ) ), 0, 10 );

		$dir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . self::DIRNAME . '/' . $rel_dir;
		$url = trailingslashit( $uploads['baseurl'] ) . self::DIRNAME . '/' . $rel_dir;

		return array(
			'dir'  => $dir,
			'path' => $dir . $stem . '.' . $hash . '.css',
			'url'  => $url . $stem . '.' . $hash . '.css',
			'skip' => $dir . $stem . '.' . $hash . '.skip',
		);
	}

	/**
	 * Write the rewritten copy, or a marker saying the file needs none.
	 *
	 * @param string $path     Source path.
	 * @param string $href     Source URL, for making relative URLs absolute.
	 * @param array  $families
	 * @param array  $target
	 * @return bool True if a copy now exists.
	 */
	private static function build( $path, $href, $families, $target ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a validated local stylesheet; see url_to_path().
		$original = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $original || '' === $original ) {
			return false;
		}
		if ( ! wp_mkdir_p( $target['dir'] ) ) {
			return false;
		}
		self::ensure_root_index();

		$rewritten = self::rewrite_css( $original, $families );
		if ( $rewritten === $original ) {
			MBRPE_CSS_Optimizations::write_file( $target['skip'], '' );
			return false;
		}

		// The copy is served from another directory: relative url() and
		// @import targets must point back at the original location.
		$rewritten = MBRPE_CSS_Optimizations::rewrite_css_urls( $rewritten, $href );
		// A source map would point at the wrong place from the copy.
		$rewritten = preg_replace( '#/\*\#\s*sourceMappingURL=[^*]*\*/#', '', $rewritten );

		$tmp = $target['path'] . '.' . substr( md5( uniqid( '', true ) ), 0, 6 ) . '.tmp';
		if ( ! MBRPE_CSS_Optimizations::write_file( $tmp, $rewritten ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace inside the plugin's own cache directory.
		if ( ! @rename( $tmp, $target['path'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $tmp );
			return is_file( $target['path'] );
		}
		return true;
	}

	/**
	 * Blank index so the directory cannot be browsed.
	 *
	 * @return void
	 */
	private static function ensure_root_index() {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return;
		}
		$index = trailingslashit( $uploads['basedir'] ) . self::DIRNAME . '/index.html';
		if ( ! is_file( $index ) ) {
			MBRPE_CSS_Optimizations::write_file( $index, '' );
		}
	}

	/**
	 * Delete every rewritten copy and marker.
	 *
	 * @return int Files removed.
	 */
	public static function purge_rewritten() {
		self::$url_memo = array();
		$uploads        = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return 0;
		}
		$root = trailingslashit( $uploads['basedir'] ) . self::DIRNAME;
		if ( ! is_dir( $root ) || is_link( $root ) ) {
			return 0;
		}

		$deleted = 0;
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $item ) {
				if ( $item->isLink() ) {
					continue;
				}
				if ( $item->isDir() ) {
					@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
					continue;
				}
				if ( 'index.html' === $item->getFilename() && dirname( $item->getPathname() ) === $root ) {
					continue;
				}
				wp_delete_file( $item->getPathname() );
				$deleted++;
			}
		} catch ( \Exception $e ) {
			return $deleted;
		}
		return $deleted;
	}
}
