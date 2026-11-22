<?php
/**
 * Font metrics: read the vertical metrics and average character width from a
 * font file, and turn them into the @font-face overrides that make a system
 * font occupy the same space as a web font.
 *
 * The technique is the one Next.js and Capsize use. While a web font loads
 * with font-display: swap, text is drawn in a fallback such as Arial; when the
 * web font arrives the text re-flows, because the two fonts have different
 * widths and line heights, and that re-flow is layout shift. Declaring the
 * fallback as its own @font-face with size-adjust (to match the average glyph
 * width) and ascent-, descent- and line-gap-override (to match the line box)
 * makes the swap close to invisible.
 *
 * Reads TrueType and OpenType files and WOFF 1.0 (zlib); WOFF2 needs Brotli,
 * which PHP rarely has, so the measuring code asks Google for a TrueType copy
 * of the same font instead of parsing the self-hosted WOFF2.
 *
 * Pure PHP, no dependencies, read-only: nothing here writes anywhere.
 *
 * @package MBRPE
 * @since   2.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MBRPE_Font_Metrics {

	/**
	 * Bump when measurement or the override formula changes, so stored
	 * measurements are taken again.
	 */
	const ALGO = '1';

	/**
	 * Characters averaged for size-adjust, weighted roughly by how often each
	 * letter occurs in English text, spaces included. The same string as
	 * Next.js, so the fallback widths below — measured over it — line up.
	 */
	const AVG_STRING = 'aaabcdeeeefghiijklmnnoopqrrssttuvwxyz      ';

	/**
	 * Fallback faces, one per category.
	 *
	 * `avg` is the advance width summed over AVG_STRING and divided by its
	 * length, in font units. Arimo / Liberation Sans, Tinos / Liberation
	 * Serif and Cousine / Liberation Mono are metric-compatible with Arial,
	 * Times New Roman and Courier New — identical advance widths and vertical
	 * metrics by design — so they are listed as alternatives for Linux and
	 * ChromeOS, where the Microsoft originals are usually absent.
	 *
	 * @return array
	 */
	public static function fallbacks() {
		return array(
			'sans'  => array(
				'label' => 'Arial',
				'upm'   => 2048,
				'avg'   => 934.5116279069767,
				'local' => array( 'Arial', 'ArialMT', 'Arimo', 'Liberation Sans' ),
				'bold'  => array( 'Arial Bold', 'Arial-BoldMT', 'Arimo Bold', 'Liberation Sans Bold' ),
			),
			'serif' => array(
				'label' => 'Times New Roman',
				'upm'   => 2048,
				'avg'   => 854.3953488372093,
				'local' => array( 'Times New Roman', 'TimesNewRomanPSMT', 'Tinos', 'Liberation Serif' ),
				'bold'  => array( 'Times New Roman Bold', 'TimesNewRomanPS-BoldMT', 'Tinos Bold', 'Liberation Serif Bold' ),
			),
			'mono'  => array(
				'label' => 'Courier New',
				'upm'   => 2048,
				'avg'   => 1229.0,
				'local' => array( 'Courier New', 'CourierNewPSMT', 'Cousine', 'Liberation Mono' ),
				'bold'  => array( 'Courier New Bold', 'CourierNewPS-BoldMT', 'Cousine Bold', 'Liberation Mono Bold' ),
			),
		);
	}

	// =====================================================================
	// Parsing
	// =====================================================================

	/**
	 * Measure a font file.
	 *
	 * @param string $data Raw bytes of a TTF, OTF or WOFF file.
	 * @return array|WP_Error {
	 *     @type int        $upm       Units per em.
	 *     @type int        $ascent    hhea ascender (or typo, see below).
	 *     @type int        $descent   hhea descender, negative.
	 *     @type int        $line_gap
	 *     @type float|null $avg       Mean advance over AVG_STRING, or null.
	 *     @type bool       $fixed     Monospaced.
	 *     @type int[]      $panose    The ten PANOSE bytes.
	 *     @type int        $x_avg     OS/2 xAvgCharWidth.
	 * }
	 */
	public static function measure( $data ) {
		if ( ! is_string( $data ) || strlen( $data ) < 12 ) {
			return new WP_Error( 'mbrpe_font_short', __( 'The font file is empty or truncated.', 'mbr-performance' ) );
		}

		try {
			$tables = self::tables( $data );

			foreach ( array( 'head', 'hhea', 'maxp', 'hmtx', 'cmap' ) as $required ) {
				if ( ! isset( $tables[ $required ] ) ) {
					/* translators: %s: OpenType table tag */
					return new WP_Error( 'mbrpe_font_table', sprintf( __( 'The font has no %s table.', 'mbr-performance' ), $required ) );
				}
			}

			$head = $tables['head'];
			$hhea = $tables['hhea'];

			$upm = self::u16( $head, 18 );
			if ( $upm < 16 || $upm > 16384 ) {
				return new WP_Error( 'mbrpe_font_upm', __( 'The font reports an impossible units-per-em value.', 'mbr-performance' ) );
			}

			$ascent   = self::s16( $hhea, 4 );
			$descent  = self::s16( $hhea, 6 );
			$line_gap = self::s16( $hhea, 8 );
			$n_hmetrics = self::u16( $hhea, 34 );
			$n_glyphs   = self::u16( $tables['maxp'], 4 );

			$panose = array_fill( 0, 10, 0 );
			$x_avg  = 0;
			if ( isset( $tables['OS/2'] ) && strlen( $tables['OS/2'] ) >= 78 ) {
				$os2   = $tables['OS/2'];
				$x_avg = self::s16( $os2, 2 );
				for ( $i = 0; $i < 10; $i++ ) {
					$panose[ $i ] = ord( $os2[ 32 + $i ] );
				}
				// A font with no usable hhea values relies on its typo metrics.
				if ( 0 === $ascent && 0 === $descent ) {
					$ascent   = self::s16( $os2, 68 );
					$descent  = self::s16( $os2, 70 );
					$line_gap = self::s16( $os2, 72 );
				}
			}

			$fixed = false;
			if ( isset( $tables['post'] ) && strlen( $tables['post'] ) >= 16 ) {
				$fixed = 0 !== self::u32( $tables['post'], 12 );
			}

			if ( $ascent <= 0 || $descent > 0 ) {
				return new WP_Error( 'mbrpe_font_vmetrics', __( 'The font\'s vertical metrics could not be read.', 'mbr-performance' ) );
			}

			$widths = self::advance_widths( $tables['hmtx'], $n_hmetrics, $n_glyphs );
			$cmap   = self::cmap_lookup( $tables['cmap'] );
			$avg    = self::average_width( $cmap, $widths );

			return array(
				'upm'      => $upm,
				'ascent'   => $ascent,
				'descent'  => $descent,
				'line_gap' => max( 0, $line_gap ),
				'avg'      => $avg,
				'fixed'    => $fixed,
				'panose'   => $panose,
				'x_avg'    => $x_avg,
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'mbrpe_font_parse', __( 'The font file could not be read.', 'mbr-performance' ) . ' ' . $e->getMessage() );
		}
	}

	/**
	 * Extract the raw tables from an sfnt or WOFF 1.0 file.
	 *
	 * @param string $data
	 * @return array tag => bytes
	 * @throws \Exception On a malformed or unsupported file.
	 */
	private static function tables( $data ) {
		$sig = substr( $data, 0, 4 );

		if ( 'wOF2' === $sig ) {
			throw new \Exception( 'WOFF2 is not supported here.' );
		}
		if ( 'ttcf' === $sig ) {
			throw new \Exception( 'Font collections are not supported.' );
		}

		$wanted = array( 'head', 'hhea', 'maxp', 'hmtx', 'cmap', 'OS/2', 'post' );
		$tables = array();

		if ( 'wOFF' === $sig ) {
			$num = self::u16( $data, 12 );
			for ( $i = 0; $i < $num; $i++ ) {
				$rec  = 44 + 20 * $i;
				$tag  = self::bytes( $data, $rec, 4 );
				if ( ! in_array( $tag, $wanted, true ) ) {
					continue;
				}
				$off  = self::u32( $data, $rec + 4 );
				$comp = self::u32( $data, $rec + 8 );
				$orig = self::u32( $data, $rec + 12 );
				$raw  = self::bytes( $data, $off, $comp );
				if ( $comp < $orig ) {
					if ( ! function_exists( 'gzuncompress' ) ) {
						throw new \Exception( 'zlib is not available.' );
					}
					$raw = @gzuncompress( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( false === $raw || strlen( $raw ) !== $orig ) {
						throw new \Exception( 'A WOFF table did not decompress.' );
					}
				}
				$tables[ $tag ] = $raw;
			}
			return $tables;
		}

		if ( ! in_array( $sig, array( "\x00\x01\x00\x00", 'OTTO', 'true' ), true ) ) {
			throw new \Exception( 'Not a TrueType, OpenType or WOFF file.' );
		}

		$num = self::u16( $data, 4 );
		for ( $i = 0; $i < $num; $i++ ) {
			$rec = 12 + 16 * $i;
			$tag = self::bytes( $data, $rec, 4 );
			if ( ! in_array( $tag, $wanted, true ) ) {
				continue;
			}
			$tables[ $tag ] = self::bytes( $data, self::u32( $data, $rec + 8 ), self::u32( $data, $rec + 12 ) );
		}
		return $tables;
	}

	/**
	 * Advance width of every glyph.
	 *
	 * @param string $hmtx
	 * @param int    $n_hmetrics
	 * @param int    $n_glyphs
	 * @return int[] Indexed by glyph ID; glyphs past the last long metric
	 *               share its advance, as the format specifies.
	 * @throws \Exception
	 */
	private static function advance_widths( $hmtx, $n_hmetrics, $n_glyphs ) {
		if ( $n_hmetrics < 1 ) {
			throw new \Exception( 'No horizontal metrics.' );
		}
		$n_hmetrics = min( $n_hmetrics, intdiv( strlen( $hmtx ), 4 ) );
		$widths     = array();
		for ( $g = 0; $g < $n_hmetrics; $g++ ) {
			$widths[ $g ] = self::u16( $hmtx, 4 * $g );
		}
		$last = $widths[ $n_hmetrics - 1 ];
		for ( $g = $n_hmetrics; $g < $n_glyphs; $g++ ) {
			$widths[ $g ] = $last;
		}
		return $widths;
	}

	/**
	 * Build a code point → glyph ID lookup from the best Unicode subtable.
	 *
	 * Formats 12 (full Unicode) and 4 (BMP) cover essentially every web font;
	 * the lookup is only ever asked about ASCII letters and the space.
	 *
	 * @param string $cmap
	 * @return callable int code point → int glyph ID (0 when missing).
	 * @throws \Exception
	 */
	private static function cmap_lookup( $cmap ) {
		$num        = self::u16( $cmap, 2 );
		$candidates = array();

		for ( $i = 0; $i < $num; $i++ ) {
			$rec      = 4 + 8 * $i;
			$platform = self::u16( $cmap, $rec );
			$encoding = self::u16( $cmap, $rec + 2 );
			$offset   = self::u32( $cmap, $rec + 4 );
			$format   = self::u16( $cmap, $offset );

			// Windows Unicode, then Unicode platform, preferring full-repertoire
			// subtables.
			$rank = null;
			if ( 3 === $platform && 10 === $encoding && 12 === $format ) {
				$rank = 0;
			} elseif ( 0 === $platform && 12 === $format ) {
				$rank = 1;
			} elseif ( 3 === $platform && 1 === $encoding && 4 === $format ) {
				$rank = 2;
			} elseif ( 0 === $platform && 4 === $format ) {
				$rank = 3;
			}
			if ( null !== $rank ) {
				$candidates[ $rank ] = array( $format, $offset );
			}
		}

		if ( empty( $candidates ) ) {
			throw new \Exception( 'No Unicode character map.' );
		}
		ksort( $candidates );
		list( $format, $offset ) = reset( $candidates );

		if ( 12 === $format ) {
			$groups = self::u32( $cmap, $offset + 12 );
			$list   = array();
			for ( $i = 0; $i < $groups && $i < 100000; $i++ ) {
				$g      = $offset + 16 + 12 * $i;
				$list[] = array( self::u32( $cmap, $g ), self::u32( $cmap, $g + 4 ), self::u32( $cmap, $g + 8 ) );
			}
			return static function ( $cp ) use ( $list ) {
				foreach ( $list as $group ) {
					if ( $cp >= $group[0] && $cp <= $group[1] ) {
						return $group[2] + ( $cp - $group[0] );
					}
				}
				return 0;
			};
		}

		// Format 4.
		$seg_count = intdiv( self::u16( $cmap, $offset + 6 ), 2 );
		$ends      = $offset + 14;
		$starts    = $ends + 2 * $seg_count + 2;
		$deltas    = $starts + 2 * $seg_count;
		$ranges    = $deltas + 2 * $seg_count;

		return static function ( $cp ) use ( $cmap, $seg_count, $ends, $starts, $deltas, $ranges ) {
			for ( $i = 0; $i < $seg_count; $i++ ) {
				$end = self::u16( $cmap, $ends + 2 * $i );
				if ( $cp > $end ) {
					continue;
				}
				$start = self::u16( $cmap, $starts + 2 * $i );
				if ( $cp < $start ) {
					return 0;
				}
				$delta = self::s16( $cmap, $deltas + 2 * $i );
				$range = self::u16( $cmap, $ranges + 2 * $i );
				if ( 0 === $range ) {
					return ( $cp + $delta ) & 0xFFFF;
				}
				$addr  = $ranges + 2 * $i + $range + 2 * ( $cp - $start );
				$glyph = self::u16( $cmap, $addr );
				return 0 === $glyph ? 0 : ( ( $glyph + $delta ) & 0xFFFF );
			}
			return 0;
		};
	}

	/**
	 * Mean advance width over AVG_STRING, or null if any character is
	 * missing (a subset without basic Latin, an icon font).
	 *
	 * @param callable $cmap
	 * @param int[]    $widths
	 * @return float|null
	 */
	private static function average_width( $cmap, $widths ) {
		$total = 0;
		$len   = strlen( self::AVG_STRING );
		for ( $i = 0; $i < $len; $i++ ) {
			$glyph = call_user_func( $cmap, ord( self::AVG_STRING[ $i ] ) );
			if ( $glyph <= 0 || ! isset( $widths[ $glyph ] ) ) {
				return null;
			}
			$total += $widths[ $glyph ];
		}
		return $total / $len;
	}

	// =====================================================================
	// Classification and overrides
	// =====================================================================

	/**
	 * Decide which system font a family should fall back to.
	 *
	 * Monospace from the post table; serif or sans from PANOSE when the font
	 * fills it in (many do not); then the family name; sans otherwise.
	 *
	 * @param array  $metrics From measure().
	 * @param string $family
	 * @return string sans|serif|mono
	 */
	public static function guess_category( $metrics, $family = '' ) {
		if ( ! empty( $metrics['fixed'] ) ) {
			return 'mono';
		}

		$panose = isset( $metrics['panose'] ) && is_array( $metrics['panose'] ) ? $metrics['panose'] : array();
		if ( isset( $panose[0], $panose[1] ) && 2 === (int) $panose[0] ) {
			if ( 9 === (int) $panose[3] ) {
				return 'mono'; // bProportion: monospaced.
			}
			$serif_style = (int) $panose[1];
			if ( $serif_style >= 2 && $serif_style <= 10 ) {
				return 'serif';
			}
			if ( $serif_style >= 11 && $serif_style <= 15 ) {
				return 'sans';
			}
		}

		$name = strtolower( (string) $family );
		if ( preg_match( '/\b(mono|code)\b/', $name ) ) {
			return 'mono';
		}
		if ( false !== strpos( $name, 'sans' ) ) {
			return 'sans';
		}
		if ( preg_match( '/serif|slab|garamond|baskerville|caslon|bodoni|didot|goudy|times|georgia/', $name ) ) {
			return 'serif';
		}

		// Popular Google serif families whose names give nothing away.
		$serifs = array(
			'playfair display', 'playfair', 'merriweather', 'lora', 'libre baskerville', 'cormorant', 'crimson text',
			'crimson pro', 'bitter', 'arvo', 'spectral', 'cardo', 'old standard tt', 'frank ruhl libre', 'domine',
			'prata', 'abril fatface', 'alegreya', 'vollkorn', 'cinzel', 'rokkitt', 'noticia text', 'gelasio',
			'literata', 'newsreader', 'fraunces', 'aleo', 'martel', 'tinos', 'marcellus', 'yeseva one', 'lusitana',
			'neuton', 'unna', 'rufina', 'amiri', 'brawler', 'young serif', 'instrument serif', 'gloock', 'eczar',
			'besley', 'petrona', 'source serif 4', 'pt serif', 'noto serif', 'ibm plex serif', 'roboto slab',
		);
		if ( in_array( $name, $serifs, true ) ) {
			return 'serif';
		}

		return 'sans';
	}

	/**
	 * Compute the overrides for a measured font against a fallback.
	 *
	 * size-adjust scales the fallback so its average glyph is as wide as the
	 * web font's. The vertical overrides are then divided by size-adjust,
	 * because the browser applies size-adjust to them as well.
	 *
	 * @param array  $metrics  From measure().
	 * @param string $category sans|serif|mono
	 * @return array{size_adjust:float,ascent:float,descent:float,line_gap:float,category:string,width_matched:bool}
	 */
	public static function overrides( $metrics, $category ) {
		$fallbacks = self::fallbacks();
		$category  = isset( $fallbacks[ $category ] ) ? $category : 'sans';
		$fb        = $fallbacks[ $category ];

		$upm  = max( 1, (int) $metrics['upm'] );
		$size = 1.0;
		$matched = false;
		if ( ! empty( $metrics['avg'] ) ) {
			$candidate = ( (float) $metrics['avg'] / $upm ) / ( $fb['avg'] / $fb['upm'] );
			// Anything outside this range means the measurement is wrong (an
			// icon font, a broken cmap), not that the font is that unusual.
			if ( $candidate >= 0.5 && $candidate <= 2.0 ) {
				$size    = $candidate;
				$matched = true;
			}
		}

		$scale = $upm * $size;

		return array(
			'size_adjust'   => round( $size * 100, 2 ),
			'ascent'        => round( abs( (int) $metrics['ascent'] ) / $scale * 100, 2 ),
			'descent'       => round( abs( (int) $metrics['descent'] ) / $scale * 100, 2 ),
			'line_gap'      => round( max( 0, (int) $metrics['line_gap'] ) / $scale * 100, 2 ),
			'category'      => $category,
			'width_matched' => $matched,
		);
	}

	/**
	 * The fallback family name used in CSS for a web font family.
	 *
	 * @param string $family
	 * @return string
	 */
	public static function fallback_name( $family ) {
		return self::clean_family( $family ) . ' Fallback';
	}

	/**
	 * A family name safe to put between double quotes in CSS.
	 *
	 * @param string $family
	 * @return string
	 */
	public static function clean_family( $family ) {
		return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^A-Za-z0-9 _\-]/', '', (string) $family ) ) );
	}

	/**
	 * @font-face rules for one family: a regular face and a bold one, so bold
	 * text falls back to the real bold system font rather than a smeared
	 * synthetic bold of the regular.
	 *
	 * @param string $family
	 * @param array  $o From overrides().
	 * @return string
	 */
	public static function font_face_css( $family, $o ) {
		$fallbacks = self::fallbacks();
		$fb        = isset( $fallbacks[ $o['category'] ] ) ? $fallbacks[ $o['category'] ] : $fallbacks['sans'];
		$name      = self::fallback_name( $family );

		$descriptors = sprintf(
			'size-adjust:%s%%;ascent-override:%s%%;descent-override:%s%%;line-gap-override:%s%%',
			self::num( $o['size_adjust'] ),
			self::num( $o['ascent'] ),
			self::num( $o['descent'] ),
			self::num( $o['line_gap'] )
		);

		$src = static function ( $names ) {
			return implode(
				',',
				array_map(
					static function ( $n ) {
						return 'local("' . $n . '")';
					},
					$names
				)
			);
		};

		return '@font-face{font-family:"' . $name . '";font-style:normal;font-weight:400;src:' . $src( $fb['local'] ) . ';' . $descriptors . "}\n"
			. '@font-face{font-family:"' . $name . '";font-style:normal;font-weight:700;src:' . $src( $fb['bold'] ) . ';' . $descriptors . "}\n";
	}

	/**
	 * Format a percentage without locale surprises.
	 *
	 * @param float $n
	 * @return string
	 */
	private static function num( $n ) {
		return number_format( (float) $n, 2, '.', '' );
	}

	// =====================================================================
	// Binary helpers
	// =====================================================================

	/**
	 * @param string $s
	 * @param int    $off
	 * @param int    $len
	 * @return string
	 * @throws \Exception When the range runs past the end of the data.
	 */
	private static function bytes( $s, $off, $len ) {
		if ( $off < 0 || $len < 0 || $off + $len > strlen( $s ) ) {
			throw new \Exception( 'Offset out of range.' );
		}
		return substr( $s, $off, $len );
	}

	/**
	 * @param string $s
	 * @param int    $off
	 * @return int
	 * @throws \Exception
	 */
	private static function u16( $s, $off ) {
		$v = unpack( 'n', self::bytes( $s, $off, 2 ) );
		return (int) $v[1];
	}

	/**
	 * @param string $s
	 * @param int    $off
	 * @return int
	 * @throws \Exception
	 */
	private static function s16( $s, $off ) {
		$v = self::u16( $s, $off );
		return $v >= 0x8000 ? $v - 0x10000 : $v;
	}

	/**
	 * @param string $s
	 * @param int    $off
	 * @return int
	 * @throws \Exception
	 */
	private static function u32( $s, $off ) {
		$v = unpack( 'N', self::bytes( $s, $off, 4 ) );
		return (int) $v[1];
	}
}
