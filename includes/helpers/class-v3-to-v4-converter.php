<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V3_To_V4_Converter — stateless helper that recursively converts
 * Elementor V3 page trees into V4 Atomic structures.
 *
 * Supports optional variable_map and semantic_classes (from kit-convert-v3-to-v4
 * via the ability class) for design-system-aware conversion: color matching,
 * semantic global class assignment, and local-style-to-class extraction.
 *
 * @package Novamira_AdrianV2
 * @since   1.2.0
 */
final class V3_To_V4_Converter {

	public const WIDGET_MAP = [
		'heading'     => 'e-heading',
		'text-editor' => 'e-paragraph',
		'button'      => 'e-button',
		'image'       => 'e-image',
		'icon'        => null,
		'video'       => null,
		'youtube'     => null,        // standalone legacy widget; handled specially below
		'divider'     => 'e-divider',
		'spacer'      => null,
		'icon-box'    => null,        // kept_v3 with descriptive warning
		'image-box'   => null,        // kept_v3 with descriptive warning
	];

	/**
	 * Recursively convert a V3 element list to V4 atomic elements.
	 *
	 * The color index is built once from $variable_map on the top-level
	 * call. Recursive calls pass the pre-built index via $color_index so
	 * build_color_index() only runs once per page conversion.
	 *
	 * @param array      $elements         V3 element tree.
	 * @param string     $strategy         Unknown widget strategy.
	 * @param array      $stats            Stats reference.
	 * @param array      $warnings         Warnings reference.
	 * @param array      $variable_map     V3-ID → {id, label, type, value} (top-level only).
	 * @param array      $semantic_classes {heading, body, button}.
	 * @param array|null $color_index      Pre-built [normalized_hex => 'var(--id)'] (recursive calls).
	 * @return array
	 */
	public static function convert_elements(
		array $elements,
		string $strategy,
		array &$stats,
		array &$warnings,
		array $variable_map = [],
		array $semantic_classes = [],
		?array $color_index = null
	): array {
		// Build index once on top-level call; recursive calls pass it pre-built.
		if ( $color_index === null ) {
			$color_index = self::build_color_index( $variable_map );
		}

		// Ensure all stat keys exist — callers that pass $stats = [] won't trigger PHP undefined-key warnings.
		$stats['elements_read']       ??= 0;
		$stats['converted']           ??= 0;
		$stats['kept_v3']             ??= 0;
		$stats['skipped']             ??= 0;
		$stats['unsupported_widgets'] ??= [];

		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$stats['elements_read']++;
			$type = $el['elType'] ?? '';

			if ( 'section' === $type ) {
				$out[] = self::convert_section( $el, $strategy, $stats, $warnings, $color_index, $semantic_classes );
			} elseif ( 'column' === $type ) {
				$out[] = self::convert_column( $el, $strategy, $stats, $warnings, $color_index, $semantic_classes );
			} elseif ( 'container' === $type ) {
				$out[] = self::convert_container( $el, $strategy, $stats, $warnings, $color_index, $semantic_classes );
			} elseif ( 'widget' === $type ) {
				$converted = self::convert_widget( $el, $strategy, $stats, $warnings, $color_index, $semantic_classes );
				if ( null !== $converted ) {
					$out[] = $converted;
				}
			} else {
				$warnings[] = "Unknown elType '$type' kept unchanged.";
				$out[]      = $el;
			}
		}
		return $out;
	}

	public static function convert_section(
		array $el, string $strategy, array &$stats, array &$warnings,
		array $ci = [], array $sc = []
	): array {
		$stats['converted']++;
		$v3_settings = $el['settings'] ?? array();
		self::resolve_globals( $v3_settings );
		$element_id = self::gen_id();
		$settings   = self::make_container_settings( $v3_settings, $ci, $element_id );

		// Extract container-level styles into a local style class.
		$style_result = self::extract_and_apply_container_styles(
			$v3_settings,
			$element_id,
			$ci,
			$settings
		);

		return array(
			'id'       => $element_id,
			'elType'   => 'e-flexbox',
			'settings' => $style_result['settings'],
			'elements' => self::wrap_direct_widget_children(
				self::convert_elements( $el['elements'] ?? array(), $strategy, $stats, $warnings, [], $sc, $ci )
			),
			'styles'   => $style_result['styles'],
		);
	}

	public static function convert_column(
		array $el, string $strategy, array &$stats, array &$warnings,
		array $ci = [], array $sc = []
	): array {
		$stats['converted']++;
		$v3_settings = $el['settings'] ?? array();
		self::resolve_globals( $v3_settings );
		$element_id = self::gen_id();
		$settings   = self::make_container_settings( $v3_settings, $ci, $element_id );

		// Extract container-level styles into a local style class.
		$style_result = self::extract_and_apply_container_styles(
			$v3_settings,
			$element_id,
			$ci,
			$settings
		);

		return array(
			'id'       => $element_id,
			'elType'   => 'e-div-block',
			'settings' => $style_result['settings'],
			'elements' => self::convert_elements( $el['elements'] ?? array(), $strategy, $stats, $warnings, [], $sc, $ci ),
			'styles'   => $style_result['styles'],
		);
	}

	public static function convert_container(
		array $el, string $strategy, array &$stats, array &$warnings,
		array $ci = [], array $sc = []
	): array {
		$stats['converted']++;
		$v3_settings = $el['settings'] ?? array();
		self::resolve_globals( $v3_settings );
		$element_id = self::gen_id();
		$settings   = self::make_container_settings( $v3_settings, $ci, $element_id );

		// Extract container-level styles into a local style class.
		$style_result = self::extract_and_apply_container_styles(
			$v3_settings,
			$element_id,
			$ci,
			$settings
		);

		return array(
			'id'       => $element_id,
			'elType'   => 'e-flexbox',
			'settings' => $style_result['settings'],
			'elements' => self::wrap_direct_widget_children(
				self::convert_elements( $el['elements'] ?? array(), $strategy, $stats, $warnings, [], $sc, $ci )
			),
			'styles'   => $style_result['styles'],
		);
	}

	/**
	 * Convert a V3 widget to its V4 atomic equivalent.
	 *
	 * @param array  $el               V3 widget element.
	 * @param string $strategy         Unknown widget strategy.
	 * @param array  $stats            Stats reference.
	 * @param array  $warnings         Warnings reference.
	 * @param array  $color_index      [normalized_hex => 'var(--id)'] lookup.
	 * @param array  $semantic_classes {heading, body, button} → class_id[].
	 * @return array|null
	 */
	public static function convert_widget(
		array $el,
		string $strategy,
		array &$stats,
		array &$warnings,
		array $color_index = [],
		array $semantic_classes = []
	): ?array {
		$wt = $el['widgetType'] ?? '';
		$s  = $el['settings'] ?? array();

		// ── Resolve __globals__ → inline values (global colors ID → hex) ──
		self::resolve_globals( $s );

		if ( ! array_key_exists( $wt, self::WIDGET_MAP ) ) {
			$stats['unsupported_widgets'][] = $wt;
			if ( 'skip' === $strategy ) {
				$stats['skipped']++;
				return null;
			}
			$stats['kept_v3']++;
			return $el;
		}

		$atomic = self::WIDGET_MAP[ $wt ];
		$stats['converted']++;

		// spacer → e-div-block with vertical padding.
		if ( 'spacer' === $wt ) {
			$height = (float) ( $s['space']['size'] ?? 50 );
			$unit   = $s['space']['unit'] ?? 'px';
			$spacer_id = self::gen_id();

			// Spacer can have responsive overrides.
			$responsive    = self::extract_responsive_overrides( $s, $color_index );
			$spacer_props  = self::extract_style_props_for_widget( $s, 'spacer', $color_index );
			$style_result  = self::build_and_apply_styles( $spacer_id, $spacer_props, $responsive, array() );

			return array(
				'id'       => $spacer_id,
				'elType'   => 'e-div-block',
				'settings' => array_merge(
					array(
						'padding' => array(
							'block-start'  => $height,
							'block-end'    => $height,
							'inline-start' => 0,
							'inline-end'   => 0,
							'unit'         => $unit,
						),
					),
					$style_result['settings']
				),
				'elements' => array(),
				'styles'   => $style_result['styles'],
			);
		}

		// Initialize settings with required classes wrapper.
		$class_values = array();

		// ── Design-system: assign semantic global classes per widget type ──
		if ( ! empty( $semantic_classes ) ) {
			if ( 'heading' === $wt && ! empty( $semantic_classes['heading'] ) ) {
				$class_values = $semantic_classes['heading'];
			} elseif ( ( 'text-editor' === $wt || 'button' === $wt ) && ! empty( $semantic_classes['body'] ) ) {
				$class_values = $semantic_classes['body'];
			}
			// Buttons get BOTH body AND button classes.
			if ( 'button' === $wt && ! empty( $semantic_classes['button'] ) ) {
				$class_values = array_merge( $class_values, $semantic_classes['button'] );
			}
		}

		$new_settings = array( 'classes' => array( '$$type' => 'classes', 'value' => array_values( array_unique( $class_values ) ) ) );

		// ── icon → e-svg ──
		if ( 'icon' === $wt ) {
			$svg = array();
			$lib = $s['selected_icon_library'] ?? ( $s['__fa4_migration_border_button_icon_library'] ?? '' );

			if ( 'svg' === $lib && ! empty( $s['svg']['url'] ) ) {
				$svg['url'] = $s['svg']['url'];
				if ( ! empty( $s['svg']['id'] ) ) {
					$svg['id'] = (int) $s['svg']['id'];
				}
			} elseif ( ! empty( $s['icon'] ) && is_string( $s['icon'] ) ) {
				$svg['icon_class'] = $s['icon'];
			} elseif ( ! empty( $s['selected_icon']['value'] ) && is_string( $s['selected_icon']['value'] ) ) {
				$svg['icon_class'] = $s['selected_icon']['value'];
			}

			if ( empty( $svg ) ) {
				$warnings[] = "Widget 'icon' could not resolve to SVG — keeping as V3.";
				$stats['kept_v3']++;
				$stats['converted']--;
				return $el;
			}

			$new_settings['svg'] = $svg;
			$atomic = 'e-svg';
		}

		// ── icon-box → kept_v3 or skip (no V4 Atomic equivalent yet) ──
		if ( 'icon-box' === $wt ) {
			if ( 'skip' === $strategy ) {
				$stats['skipped']++;
				$stats['converted']--;
				return null;
			}
			$warnings[] = "Widget 'icon-box' has no V4 Atomic equivalent — keeping as V3. Consider splitting into an e-svg + e-heading + e-paragraph container.";
			$stats['kept_v3']++;
			$stats['converted']--;
			return $el;
		}

		// ── image-box → kept_v3 or skip (no V4 Atomic equivalent yet) ──
		if ( 'image-box' === $wt ) {
			if ( 'skip' === $strategy ) {
				$stats['skipped']++;
				$stats['converted']--;
				return null;
			}
			$warnings[] = "Widget 'image-box' has no V4 Atomic equivalent — keeping as V3. Consider splitting into an e-image + e-heading + e-paragraph container.";
			$stats['kept_v3']++;
			$stats['converted']--;
			return $el;
		}

		// ── youtube (standalone legacy widget) → e-youtube ──
		if ( 'youtube' === $wt ) {
			$atomic = 'e-youtube';
			if ( ! empty( $s['url'] ) )        { $new_settings['url']      = $s['url']; }
			if ( ! empty( $s['autoplay'] ) )   { $new_settings['autoplay'] = (bool) $s['autoplay']; }
			if ( ! empty( $s['controls'] ) )   { $new_settings['controls'] = (bool) $s['controls']; }
			if ( ! empty( $s['mute'] ) )       { $new_settings['mute']     = (bool) $s['mute']; }
			if ( ! empty( $s['loop'] ) )       { $new_settings['loop']     = (bool) $s['loop']; }
		}

		// ── video → e-youtube / e-self-hosted-video ──
		if ( 'video' === $wt ) {
			$video_type = $s['video_type'] ?? 'youtube';

			if ( 'youtube' === $video_type ) {
				$atomic = 'e-youtube';
				if ( ! empty( $s['youtube_url'] ) ) { $new_settings['url'] = $s['youtube_url']; }
				if ( ! empty( $s['youtube_autoplay'] ) ) { $new_settings['autoplay'] = (bool) $s['youtube_autoplay']; }
				if ( ! empty( $s['youtube_controls'] ) ) { $new_settings['controls'] = (bool) $s['youtube_controls']; }
			} elseif ( 'vimeo' === $video_type ) {
				if ( ! empty( $s['vimeo_url'] ) ) { $new_settings['url'] = $s['vimeo_url']; }
				$atomic = 'e-self-hosted-video';
			} elseif ( 'hosted' === $video_type || 'self_hosted' === $video_type ) {
				$atomic = 'e-self-hosted-video';
				if ( ! empty( $s['hosted_url'] ) ) { $new_settings['url'] = $s['hosted_url']; }
				elseif ( ! empty( $s['url'] ) ) { $new_settings['url'] = $s['url']; }
			} else {
				$warnings[] = "Widget 'video' with unknown video_type '$video_type' — keeping as V3.";
				$stats['kept_v3']++;
				$stats['converted']--;
				return $el;
			}

			if ( ! empty( $s['autoplay'] ) && ! isset( $new_settings['autoplay'] ) ) { $new_settings['autoplay'] = (bool) $s['autoplay']; }
			if ( ! empty( $s['mute'] ) ) { $new_settings['mute'] = (bool) $s['mute']; }
			if ( ! empty( $s['loop'] ) ) { $new_settings['loop'] = (bool) $s['loop']; }
			if ( ! empty( $s['modestbranding'] ) ) { $new_settings['modestbranding'] = (bool) $s['modestbranding']; }
		}

		// ── Widget-specific property mapping ──
		if ( 'heading' === $wt ) {
			$new_settings['title'] = V4_Props::html( (string) ( $s['title'] ?? '' ) );
			$new_settings['tag']   = $s['header_size'] ?? 'h2';
		} elseif ( 'text-editor' === $wt ) {
			$text = self::normalize_paragraph_content( (string) ( $s['editor'] ?? '' ) );
			$new_settings['paragraph'] = V4_Props::html( $text );
		} elseif ( 'button' === $wt ) {
			$new_settings['text'] = V4_Props::html( (string) ( $s['text'] ?? '' ) );
			if ( ! empty( $s['link'] ) ) { $new_settings['link'] = $s['link']; }
			// Button icon (selected_icon).
			if ( ! empty( $s['selected_icon']['value'] ) && is_string( $s['selected_icon']['value'] ) ) {
				$new_settings['icon'] = array( 'value' => $s['selected_icon']['value'] );
				if ( ! empty( $s['selected_icon']['library'] ) ) {
					$new_settings['icon']['library'] = $s['selected_icon']['library'];
				}
			}
			if ( ! empty( $s['icon_align'] ) ) {
				$new_settings['icon_align'] = $s['icon_align'];
			}
		} elseif ( 'image' === $wt ) {
			$img = $s['image'] ?? array();
			if ( ! empty( $img['id'] ) ) {
				$new_settings['image'] = V4_Props::image( (int) $img['id'] );
			} elseif ( ! empty( $img['url'] ) ) {
				$new_settings['image'] = V4_Props::image( 0, (string) $img['url'] );
			}
			if ( ! empty( $s['image_size'] ) ) { $new_settings['size'] = (string) $s['image_size']; }
		} elseif ( 'divider' === $wt ) {
			if ( ! empty( $s['style'] ) ) { $new_settings['style'] = $s['style']; }
			if ( ! empty( $s['weight'] ) ) { $new_settings['weight'] = $s['weight']; }
			if ( ! empty( $s['color'] ) ) { $new_settings['color'] = self::resolve_color_var( $s['color'], $color_index ); }
		}

		// ── Extract local V3 styles into a style class with responsive variants ──
		$element_id       = self::gen_id();
		$desktop_props    = self::extract_style_props_for_widget( $s, $wt, $color_index );
		$responsive       = self::extract_responsive_overrides( $s, $color_index );
		$style_result     = self::build_and_apply_styles( $element_id, $desktop_props, $responsive, $new_settings['classes']['value'] );

		return array(
			'id'         => $element_id,
			'elType'     => 'widget',
			'widgetType' => $atomic,
			'settings'   => array_merge( $new_settings, $style_result['settings'] ),
			'elements'   => array(),
			'styles'     => $style_result['styles'],
		);
	}

	/**
	 * Build a minimal V4 container settings array with optional
	 * variable-mapped background-color.
	 *
	 * @param array  $v3         V3 element settings.
	 * @param array  $ci         Color index for fast lookup.
	 * @param string $element_id Element ID for style class generation.
	 * @return array
	 */
	public static function make_container_settings( array $v3, array $ci = [], string $element_id = '' ): array {
		$settings = array( 'classes' => array( '$$type' => 'classes', 'value' => array() ) );

		if ( ! empty( $v3['padding'] ) && is_array( $v3['padding'] ) && isset( $v3['padding']['unit'] ) ) {
			$p = $v3['padding'];
			$settings['padding'] = array(
				'block-start'  => (float) ( $p['top'] ?? 0 ),
				'block-end'    => (float) ( $p['bottom'] ?? 0 ),
				'inline-start' => (float) ( $p['left'] ?? 0 ),
				'inline-end'   => (float) ( $p['right'] ?? 0 ),
				'unit'         => $p['unit'],
			);
		}

		// Min height (sections, columns, containers).
		if ( ! empty( $v3['min_height'] ) && is_array( $v3['min_height'] ) && isset( $v3['min_height']['size'] ) ) {
			$settings['min_height'] = array(
				'size' => (float) $v3['min_height']['size'],
				'unit' => $v3['min_height']['unit'] ?? 'px',
			);
		}

		// CSS classes (V3 stores as _css_classes or css_classes).
		if ( ! empty( $v3['_css_classes'] ) && is_string( $v3['_css_classes'] ) ) {
			$settings['css_classes'] = $v3['_css_classes'];
		} elseif ( ! empty( $v3['css_classes'] ) && is_string( $v3['css_classes'] ) ) {
			$settings['css_classes'] = $v3['css_classes'];
		}

		// HTML element ID / anchor.
		if ( ! empty( $v3['_element_id'] ) && is_string( $v3['_element_id'] ) ) {
			$settings['_element_id'] = $v3['_element_id'];
		}

		// Content width (boxed/full) — sections.
		if ( ! empty( $v3['content_width'] ) && is_string( $v3['content_width'] ) ) {
			$settings['v3_content_width'] = $v3['content_width'];
		}

		// Element width type (FULL / INNER / CUSTOM_WIDTH).
		if ( ! empty( $v3['_element_width'] ) && is_string( $v3['_element_width'] ) ) {
			$settings['_element_width'] = $v3['_element_width'];
		}

		// Custom width value (when _element_width is CUSTOM_WIDTH).
		if ( ! empty( $v3['_element_custom_width'] ) && is_array( $v3['_element_custom_width'] ) && isset( $v3['_element_custom_width']['size'] ) ) {
			$settings['_element_custom_width'] = $v3['_element_custom_width'];
		}

		return $settings;
	}

	// =====================================================================
	// Local Style → Class Extraction
	// =====================================================================

	/**
	 * Extract V3 style properties into V4 $$type-wrapped props for a given widget type.
	 *
	 * Maps V3 widget-specific style settings (colors, typography, spacing, border)
	 * to V4 CSS property names. Color values are resolved through the color index.
	 *
	 * @param array  $v3_settings V3 element settings.
	 * @param string $widget_type Widget type (heading, text-editor, button, etc.).
	 * @param array  $ci          Color index [normalized_hex => 'var(--id)'].
	 * @return array V4 $$type-wrapped props (CSS property → {$$type, value}).
	 */
	public static function extract_style_props_for_widget( array $v3_settings, string $widget_type, array $ci ): array {
		$props = array();

		// ── Common typography extraction (nested format: typography_typography array) ──
		$typo = $v3_settings['typography_typography'] ?? null;
		$had_nested_typo = false;
		if ( is_string( $typo ) ) {
			$typo = json_decode( $typo, true );
		}
		if ( is_array( $typo ) ) {
			$had_nested_typo = true;
			if ( ! empty( $typo['typography_font_family'] ) ) {
				$props['font-family'] = self::v4_string( $typo['typography_font_family'] );
			}
			if ( isset( $typo['typography_font_weight'] ) && '' !== $typo['typography_font_weight'] && null !== $typo['typography_font_weight'] ) {
				$props['font-weight'] = self::v4_string( (string) $typo['typography_font_weight'] );
			}
			$fs = $typo['typography_font_size'] ?? null;
			if ( is_array( $fs ) && isset( $fs['size'] ) && ! empty( $fs['size'] ) ) {
				$props['font-size'] = self::v4_size( (float) $fs['size'], $fs['unit'] ?? 'px' );
			}
			$lh = $typo['typography_line_height'] ?? null;
			if ( is_array( $lh ) && isset( $lh['size'] ) && ! empty( $lh['size'] ) ) {
				$props['line-height'] = self::v4_size( (float) $lh['size'], $lh['unit'] ?? 'em' );
			}
			if ( ! empty( $typo['typography_letter_spacing'] ) ) {
				$ls = $typo['typography_letter_spacing'];
				if ( is_array( $ls ) && isset( $ls['size'] ) ) {
					$props['letter-spacing'] = self::v4_size( (float) $ls['size'], $ls['unit'] ?? 'px' );
				}
			}
			if ( ! empty( $typo['typography_text_transform'] ) ) {
				$props['text-transform'] = self::v4_string( $typo['typography_text_transform'] );
			}
			if ( ! empty( $typo['typography_font_style'] ) ) {
				$props['font-style'] = self::v4_string( $typo['typography_font_style'] );
			}
			if ( ! empty( $typo['typography_text_decoration'] ) ) {
				$props['text-decoration'] = self::v4_string( $typo['typography_text_decoration'] );
			}
		}

		// ── Flat typography fallback (when typography_typography is "custom" or missing) ──
		if ( ! $had_nested_typo ) {
			if ( ! empty( $v3_settings['typography_font_family'] ) && is_string( $v3_settings['typography_font_family'] ) ) {
				$props['font-family'] = self::v4_string( $v3_settings['typography_font_family'] );
			}
			$flat_fs = $v3_settings['typography_font_size'] ?? null;
			if ( is_array( $flat_fs ) && isset( $flat_fs['size'] ) && ! empty( $flat_fs['size'] ) ) {
				$props['font-size'] = self::v4_size( (float) $flat_fs['size'], $flat_fs['unit'] ?? 'px' );
			}
			$flat_fw = $v3_settings['typography_font_weight'] ?? null;
			if ( isset( $flat_fw ) && '' !== $flat_fw && null !== $flat_fw ) {
				$props['font-weight'] = self::v4_string( (string) $flat_fw );
			}
			$flat_lh = $v3_settings['typography_line_height'] ?? null;
			if ( is_array( $flat_lh ) && isset( $flat_lh['size'] ) && ! empty( $flat_lh['size'] ) ) {
				$props['line-height'] = self::v4_size( (float) $flat_lh['size'], $flat_lh['unit'] ?? 'em' );
			}
			if ( ! empty( $v3_settings['typography_text_transform'] ) && is_string( $v3_settings['typography_text_transform'] ) ) {
				$props['text-transform'] = self::v4_string( $v3_settings['typography_text_transform'] );
			}
			if ( ! empty( $v3_settings['typography_letter_spacing'] ) ) {
				$flat_ls = $v3_settings['typography_letter_spacing'];
				if ( is_array( $flat_ls ) && isset( $flat_ls['size'] ) ) {
					$props['letter-spacing'] = self::v4_size( (float) $flat_ls['size'], $flat_ls['unit'] ?? 'px' );
				}
			}
		}

		// ── Color extraction per widget type ──
		switch ( $widget_type ) {
			case 'heading':
				if ( ! empty( $v3_settings['title_color'] ) ) {
					$props['color'] = self::v4_color( self::resolve_color_var( $v3_settings['title_color'], $ci ) );
				}
				// heading uses 'align' || 'text_align' for alignment.
				$align = $v3_settings['align'] ?? ( $v3_settings['text_align'] ?? '' );
				if ( ! empty( $align ) ) {
					$props['text-align'] = self::v4_string( $align );
				}
				break;

			case 'text-editor':
				if ( ! empty( $v3_settings['text_color'] ) ) {
					$props['color'] = self::v4_color( self::resolve_color_var( $v3_settings['text_color'], $ci ) );
				}
				if ( ! empty( $v3_settings['text_align'] ) ) {
					$props['text-align'] = self::v4_string( $v3_settings['text_align'] );
				}
				if ( ! empty( $v3_settings['drop_cap'] ) && 'yes' === $v3_settings['drop_cap'] ) {
					// Drop cap is a content feature, skip in styles.
				}
				break;

			case 'button':
				if ( ! empty( $v3_settings['button_background_color'] ) ) {
					$props['background-color'] = self::v4_color( self::resolve_color_var( $v3_settings['button_background_color'], $ci ) );
				}
				if ( ! empty( $v3_settings['button_text_color'] ) ) {
					$props['color'] = self::v4_color( self::resolve_color_var( $v3_settings['button_text_color'], $ci ) );
				}
				if ( ! empty( $v3_settings['button_border_border'] ) ) {
					$props['border-style'] = self::v4_string( $v3_settings['button_border_border'] );
				}
				if ( ! empty( $v3_settings['button_border_width'] ) && is_array( $v3_settings['button_border_width'] ) ) {
					$bw = $v3_settings['button_border_width'];
					$props['border-width'] = self::v4_string(
						( $bw['top'] ?? '0' ) . ( $bw['unit'] ?? 'px' ) . ' ' .
						( $bw['right'] ?? '0' ) . ( $bw['unit'] ?? 'px' ) . ' ' .
						( $bw['bottom'] ?? '0' ) . ( $bw['unit'] ?? 'px' ) . ' ' .
						( $bw['left'] ?? '0' ) . ( $bw['unit'] ?? 'px' )
					);
				}
				if ( ! empty( $v3_settings['button_border_color'] ) ) {
					$props['border-color'] = self::v4_color( self::resolve_color_var( $v3_settings['button_border_color'], $ci ) );
				}
				if ( ! empty( $v3_settings['button_border_radius'] ) && is_array( $v3_settings['button_border_radius'] ) ) {
					$br = $v3_settings['button_border_radius'];
					$props['border-radius'] = self::v4_size( (float) ( $br['top'] ?? 0 ), $br['unit'] ?? 'px' );
				}
				// Button alignment: support both button_text_align and generic align key.
				$btn_align = $v3_settings['button_text_align'] ?? ( $v3_settings['align'] ?? '' );
				if ( ! empty( $btn_align ) && is_string( $btn_align ) ) {
					$props['text-align'] = self::v4_string( $btn_align );
				}
				// Button padding: support both text_padding and button_padding keys.
				$bp = $v3_settings['text_padding'] ?? $v3_settings['button_padding'] ?? null;
				if ( is_array( $bp ) ) {
					$unit = $bp['unit'] ?? 'px';
					if ( isset( $bp['top'] ) ) { $props['padding-block-start'] = self::v4_size( (float) $bp['top'], $unit ); }
					if ( isset( $bp['bottom'] ) ) { $props['padding-block-end'] = self::v4_size( (float) $bp['bottom'], $unit ); }
					if ( isset( $bp['left'] ) ) { $props['padding-inline-start'] = self::v4_size( (float) $bp['left'], $unit ); }
					if ( isset( $bp['right'] ) ) { $props['padding-inline-end'] = self::v4_size( (float) $bp['right'], $unit ); }
				}
				break;

			case 'image':
				if ( ! empty( $v3_settings['image_align'] ) ) {
					$props['text-align'] = self::v4_string( $v3_settings['image_align'] );
				}
				if ( ! empty( $v3_settings['width'] ) && is_array( $v3_settings['width'] ) && isset( $v3_settings['width']['size'] ) ) {
					$props['width'] = self::v4_size( (float) $v3_settings['width']['size'], $v3_settings['width']['unit'] ?? 'px' );
				}
				if ( ! empty( $v3_settings['max_width'] ) && is_array( $v3_settings['max_width'] ) && isset( $v3_settings['max_width']['size'] ) ) {
					$props['max-width'] = self::v4_size( (float) $v3_settings['max_width']['size'], $v3_settings['max_width']['unit'] ?? 'px' );
				}
				if ( ! empty( $v3_settings['image_border_border'] ) ) {
					$props['border-style'] = self::v4_string( $v3_settings['image_border_border'] );
				}
				if ( ! empty( $v3_settings['image_border_radius'] ) && is_array( $v3_settings['image_border_radius'] ) ) {
					$ibr = $v3_settings['image_border_radius'];
					$props['border-radius'] = self::v4_size( (float) ( $ibr['top'] ?? 0 ), $ibr['unit'] ?? 'px' );
				}
				break;

			case 'icon':
				if ( ! empty( $v3_settings['primary_color'] ) ) {
					$props['color'] = self::v4_color( self::resolve_color_var( $v3_settings['primary_color'], $ci ) );
				}
				if ( ! empty( $v3_settings['size'] ) && is_array( $v3_settings['size'] ) && isset( $v3_settings['size']['size'] ) ) {
					$props['font-size'] = self::v4_size( (float) $v3_settings['size']['size'], $v3_settings['size']['unit'] ?? 'px' );
				}
				if ( ! empty( $v3_settings['align'] ) ) {
					$props['text-align'] = self::v4_string( $v3_settings['align'] );
				}
				break;

			case 'divider':
				if ( ! empty( $v3_settings['align'] ) ) {
					$props['text-align'] = self::v4_string( $v3_settings['align'] );
				}
				if ( ! empty( $v3_settings['gap'] ) && is_array( $v3_settings['gap'] ) && isset( $v3_settings['gap']['size'] ) ) {
					$props['padding-block-start'] = self::v4_size( (float) $v3_settings['gap']['size'], $v3_settings['gap']['unit'] ?? 'px' );
				}
				break;

			case 'container':
				$layout_strings = array(
					'flex_direction'  => 'flex-direction',
					'justify_content' => 'justify-content',
					'align_items'     => 'align-items',
					'align_content'   => 'align-content',
					'flex_wrap'       => 'flex-wrap',
					'overflow'        => 'overflow',
				);
				foreach ( $layout_strings as $v3_key => $css_prop ) {
					if ( ! empty( $v3_settings[ $v3_key ] ) && is_string( $v3_settings[ $v3_key ] ) ) {
						$props[ $css_prop ] = self::v4_string( $v3_settings[ $v3_key ] );
					}
				}
				if ( ! empty( $v3_settings['gap'] ) && is_array( $v3_settings['gap'] ) && isset( $v3_settings['gap']['size'] ) ) {
					$props['gap'] = self::v4_size( (float) $v3_settings['gap']['size'], $v3_settings['gap']['unit'] ?? 'px' );
				}
				if ( ! empty( $v3_settings['row_gap'] ) && is_array( $v3_settings['row_gap'] ) && isset( $v3_settings['row_gap']['size'] ) ) {
					$props['row-gap'] = self::v4_size( (float) $v3_settings['row_gap']['size'], $v3_settings['row_gap']['unit'] ?? 'px' );
				}
				if ( ! empty( $v3_settings['column_gap'] ) && is_array( $v3_settings['column_gap'] ) && isset( $v3_settings['column_gap']['size'] ) ) {
					$props['column-gap'] = self::v4_size( (float) $v3_settings['column_gap']['size'], $v3_settings['column_gap']['unit'] ?? 'px' );
				}
				// Min height (container-level).
				if ( ! empty( $v3_settings['min_height'] ) && is_array( $v3_settings['min_height'] ) && isset( $v3_settings['min_height']['size'] ) ) {
					$props['min-height'] = self::v4_size( (float) $v3_settings['min_height']['size'], $v3_settings['min_height']['unit'] ?? 'px' );
				}
				break;
		}

		// ── Common spacing extraction (padding/margin, with _prefix support) ──
		// Elementor V3 stores widget padding/margin as _padding/_margin or padding/margin.
		$p = $v3_settings['_padding'] ?? $v3_settings['padding'] ?? null;
		if ( is_array( $p ) && isset( $p['unit'] ) ) {
			$unit = $p['unit'];
			if ( isset( $p['top'] ) ) { $props['padding-block-start'] = self::v4_size( (float) $p['top'], $unit ); }
			if ( isset( $p['bottom'] ) ) { $props['padding-block-end'] = self::v4_size( (float) $p['bottom'], $unit ); }
			if ( isset( $p['left'] ) ) { $props['padding-inline-start'] = self::v4_size( (float) $p['left'], $unit ); }
			if ( isset( $p['right'] ) ) { $props['padding-inline-end'] = self::v4_size( (float) $p['right'], $unit ); }
		}

		$m = $v3_settings['_margin'] ?? $v3_settings['margin'] ?? null;
		if ( is_array( $m ) && isset( $m['unit'] ) ) {
			$unit = $m['unit'];
			if ( isset( $m['top'] ) ) { $props['margin-block-start'] = self::v4_size( (float) $m['top'], $unit ); }
			if ( isset( $m['bottom'] ) ) { $props['margin-block-end'] = self::v4_size( (float) $m['bottom'], $unit ); }
			if ( isset( $m['left'] ) ) { $props['margin-inline-start'] = self::v4_size( (float) $m['left'], $unit ); }
			if ( isset( $m['right'] ) ) { $props['margin-inline-end'] = self::v4_size( (float) $m['right'], $unit ); }
		}

		// ── Background (applies to containers and some widgets) ──
		if ( ! empty( $v3_settings['background_color'] ) && is_string( $v3_settings['background_color'] ) ) {
			$props['background-color'] = self::v4_color( self::resolve_color_var( $v3_settings['background_color'], $ci ) );
		}
		if ( ! empty( $v3_settings['background_image'] ) && is_array( $v3_settings['background_image'] ) && ! empty( $v3_settings['background_image']['url'] ) ) {
			$props['background-image'] = self::v4_string( 'url(' . esc_url( $v3_settings['background_image']['url'] ) . ')' );
		}
		if ( ! empty( $v3_settings['background_position'] ) && is_string( $v3_settings['background_position'] ) ) {
			$props['background-position'] = self::v4_string( $v3_settings['background_position'] );
		}
		if ( ! empty( $v3_settings['background_repeat'] ) && is_string( $v3_settings['background_repeat'] ) ) {
			$props['background-repeat'] = self::v4_string( $v3_settings['background_repeat'] );
		}
		if ( ! empty( $v3_settings['background_size'] ) && is_string( $v3_settings['background_size'] ) ) {
			$props['background-size'] = self::v4_string( $v3_settings['background_size'] );
		}

		// ── Border (generic, for containers) ──
		if ( ! empty( $v3_settings['border_border'] ) ) {
			$props['border-style'] = self::v4_string( $v3_settings['border_border'] );
		}
		if ( ! empty( $v3_settings['border_width'] ) && is_array( $v3_settings['border_width'] ) ) {
			$bw = $v3_settings['border_width'];
			$unit = $bw['unit'] ?? 'px';
			$props['border-width'] = self::v4_string(
				( $bw['top'] ?? '0' ) . $unit . ' ' .
				( $bw['right'] ?? '0' ) . $unit . ' ' .
				( $bw['bottom'] ?? '0' ) . $unit . ' ' .
				( $bw['left'] ?? '0' ) . $unit
			);
		}
		if ( ! empty( $v3_settings['border_color'] ) ) {
			$props['border-color'] = self::v4_color( self::resolve_color_var( $v3_settings['border_color'], $ci ) );
		}
		if ( ! empty( $v3_settings['border_radius'] ) && is_array( $v3_settings['border_radius'] ) ) {
			$br = $v3_settings['border_radius'];
			$unit = $br['unit'] ?? 'px';
			$top = (float) ( $br['top'] ?? 0 );
			$right = (float) ( $br['right'] ?? 0 );
			$bottom = (float) ( $br['bottom'] ?? 0 );
			$left = (float) ( $br['left'] ?? 0 );
			if ( $top === $right && $right === $bottom && $bottom === $left ) {
				$props['border-radius'] = self::v4_size( $top, $unit );
			} else {
				$props['border-radius'] = self::v4_string( "{$top}{$unit} {$right}{$unit} {$bottom}{$unit} {$left}{$unit}" );
			}
		}

		// ── Box shadow ──
		if ( ! empty( $v3_settings['box_shadow_box_shadow_type'] ) && 'yes' === $v3_settings['box_shadow_box_shadow_type'] ) {
			$bs = $v3_settings['box_shadow_box_shadow'] ?? array();
			if ( ! empty( $bs ) ) {
				$h  = $bs['horizontal'] ?? 0;
				$v  = $bs['vertical'] ?? 0;
				$bl = $bs['blur'] ?? 0;
				$sp = $bs['spread'] ?? 0;
				$cl = $bs['color'] ?? 'rgba(0,0,0,0.5)';
				$inset = ! empty( $bs['position'] ) && 'inset' === $bs['position'] ? 'inset ' : '';
				$props['box-shadow'] = self::v4_string(
					$inset . "{$h}px {$v}px {$bl}px {$sp}px " . $cl
				);
			}
		}

		return $props;
	}

	/**
	 * Extract responsive (tablet/mobile) overrides from V3 settings.
	 *
	 * V3 stores breakpoint overrides as separate settings keys with
	 * _tablet and _mobile suffixes (e.g., typography_font_size_tablet).
	 *
	 * @param array $v3_settings V3 element settings.
	 * @param array $ci          Color index [normalized_hex => 'var(--id)'].
	 * @return array {tablet: [css_prop => {$$type, value}, ...], mobile: [...]}
	 */
	public static function extract_responsive_overrides( array $v3_settings, array $ci ): array {
		$overrides = array( 'tablet' => array(), 'mobile' => array() );

		// ── Typography size overrides ──
		$typography_keys = array(
			'typography_font_size'        => 'font-size',
			'typography_line_height'      => 'line-height',
			'typography_letter_spacing'   => 'letter-spacing',
		);

		foreach ( $typography_keys as $v3_key => $css_prop ) {
			foreach ( array( 'tablet', 'mobile' ) as $bp ) {
				$override_key = $v3_key . '_' . $bp;
				$value = $v3_settings[ $override_key ] ?? null;
				if ( is_array( $value ) && isset( $value['size'] ) && ! empty( $value['size'] ) ) {
					$overrides[ $bp ][ $css_prop ] = self::v4_size( (float) $value['size'], $value['unit'] ?? 'px' );
				}
			}
		}

		// ── Spacing overrides (padding/margin) ──
		$spacing_keys = array( 'padding', 'margin' );
		foreach ( $spacing_keys as $spacing_key ) {
			foreach ( array( 'tablet', 'mobile' ) as $bp ) {
				$override_key = $spacing_key . '_' . $bp;
				$value = $v3_settings[ $override_key ] ?? null;
				if ( is_array( $value ) && isset( $value['unit'] ) ) {
					$unit = $value['unit'];
					$prefix = ( 'padding' === $spacing_key ) ? 'padding' : 'margin';
					if ( isset( $value['top'] ) ) {
						$overrides[ $bp ][ "{$prefix}-block-start" ] = self::v4_size( (float) $value['top'], $unit );
					}
					if ( isset( $value['bottom'] ) ) {
						$overrides[ $bp ][ "{$prefix}-block-end" ] = self::v4_size( (float) $value['bottom'], $unit );
					}
					if ( isset( $value['left'] ) ) {
						$overrides[ $bp ][ "{$prefix}-inline-start" ] = self::v4_size( (float) $value['left'], $unit );
					}
					if ( isset( $value['right'] ) ) {
						$overrides[ $bp ][ "{$prefix}-inline-end" ] = self::v4_size( (float) $value['right'], $unit );
					}
				}
			}
		}

		// ── Background color overrides ──
		foreach ( array( 'tablet', 'mobile' ) as $bp ) {
			$bg_key = 'background_color_' . $bp;
			if ( ! empty( $v3_settings[ $bg_key ] ) && is_string( $v3_settings[ $bg_key ] ) ) {
				$overrides[ $bp ]['background-color'] = self::v4_color(
					self::resolve_color_var( $v3_settings[ $bg_key ], $ci )
				);
			}
		}

		// ── Alignment overrides ──
		foreach ( array( 'tablet', 'mobile' ) as $bp ) {
			foreach ( array( 'text_align', 'align' ) as $align_key ) {
				$override_key = $align_key . '_' . $bp;
				if ( ! empty( $v3_settings[ $override_key ] ) ) {
					$css_prop = ( 'text_align' === $align_key ) ? 'text-align' : 'text-align';
					$overrides[ $bp ][ $css_prop ] = self::v4_string( $v3_settings[ $override_key ] );
				}
			}
		}

		// ── Width overrides (image width_tablet/width_mobile) ──
		foreach ( array( 'tablet', 'mobile' ) as $bp ) {
			$width_key = 'width_' . $bp;
			$value = $v3_settings[ $width_key ] ?? null;
			if ( is_array( $value ) && isset( $value['size'] ) && ! empty( $value['size'] ) ) {
				$overrides[ $bp ]['width'] = self::v4_size( (float) $value['size'], $value['unit'] ?? 'px' );
			}
		}

		// ── Container layout overrides (flex-direction, justify, align) ──
		$container_layout_keys = array(
			'flex_direction'  => 'flex-direction',
			'justify_content' => 'justify-content',
			'align_items'     => 'align-items',
			'align_content'   => 'align-content',
			'flex_wrap'       => 'flex-wrap',
		);
		foreach ( $container_layout_keys as $v3_key => $css_prop ) {
			foreach ( array( 'tablet', 'mobile' ) as $bp ) {
				$override_key = $v3_key . '_' . $bp;
				if ( ! empty( $v3_settings[ $override_key ] ) && is_string( $v3_settings[ $override_key ] ) ) {
					$overrides[ $bp ][ $css_prop ] = self::v4_string( $v3_settings[ $override_key ] );
				}
			}
		}

		// ── Container min_height overrides ──
		foreach ( array( 'tablet', 'mobile' ) as $bp ) {
			$mh_key = 'min_height_' . $bp;
			$value = $v3_settings[ $mh_key ] ?? null;
			if ( is_array( $value ) && isset( $value['size'] ) && ! empty( $value['size'] ) ) {
				$overrides[ $bp ]['min-height'] = self::v4_size( (float) $value['size'], $value['unit'] ?? 'px' );
			}
		}

		// ── Container gap overrides ──
		foreach ( array( 'tablet', 'mobile' ) as $bp ) {
			$gap_key = 'gap_' . $bp;
			$value = $v3_settings[ $gap_key ] ?? null;
			if ( is_array( $value ) && isset( $value['size'] ) && ! empty( $value['size'] ) ) {
				$overrides[ $bp ]['gap'] = self::v4_size( (float) $value['size'], $value['unit'] ?? 'px' );
			}
		}

		// ── Border radius overrides (image, button) ──
		foreach ( array( 'tablet', 'mobile' ) as $bp ) {
			foreach ( array( 'image_border_radius', 'button_border_radius', 'border_radius' ) as $radius_key ) {
				$override_key = $radius_key . '_' . $bp;
				$value = $v3_settings[ $override_key ] ?? null;
				if ( is_array( $value ) && isset( $value['top'] ) ) {
					$overrides[ $bp ]['border-radius'] = self::v4_size( (float) $value['top'], $value['unit'] ?? 'px' );
					break; // One border-radius override per breakpoint.
				}
			}
		}

		// ── Spacer height overrides ──
		foreach ( array( 'tablet', 'mobile' ) as $bp ) {
			$space_key = 'space_' . $bp;
			$value = $v3_settings[ $space_key ] ?? null;
			if ( is_array( $value ) && isset( $value['size'] ) && ! empty( $value['size'] ) ) {
				$overrides[ $bp ]['padding-block-start'] = self::v4_size( (float) $value['size'], $value['unit'] ?? 'px' );
				$overrides[ $bp ]['padding-block-end']   = self::v4_size( (float) $value['size'], $value['unit'] ?? 'px' );
			}
		}

		// Remove empty breakpoint arrays.
		if ( empty( $overrides['tablet'] ) ) {
			unset( $overrides['tablet'] );
		}
		if ( empty( $overrides['mobile'] ) ) {
			unset( $overrides['mobile'] );
		}

		return $overrides;
	}

	/**
	 * Build V4 style variants from desktop props and responsive overrides,
	 * then create a local style class and apply it to the element.
	 *
	 * Returns merged settings (with updated classes) and styles map.
	 *
	 * @param string   $element_id           Generated element ID.
	 * @param array    $desktop_props        V4 $$type-wrapped desktop props.
	 * @param array    $responsive_overrides {tablet: [...], mobile: [...]}.
	 * @param string[] $existing_class_ids   Already-assigned global class IDs.
	 * @return array {settings: [...], styles: [...], class_ids: [...]}
	 */
	public static function build_and_apply_styles(
		string $element_id,
		array $desktop_props,
		array $responsive_overrides,
		array $existing_class_ids = []
	): array {
		// If no style props at all, return empty.
		if ( empty( $desktop_props ) && empty( $responsive_overrides ) ) {
			return array(
				'settings'  => array(),
				'styles'    => array(),
				'class_ids' => $existing_class_ids,
			);
		}

		// Generate a local style class ID.
		$class_id = 'e-' . $element_id . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 7 );

		// Build variants array: desktop first, then tablet, then mobile.
		$variants = array();

		if ( ! empty( $desktop_props ) ) {
			$variants[] = array(
				'meta'       => array( 'breakpoint' => 'desktop', 'state' => null ),
				'props'      => $desktop_props,
				'custom_css' => null,
			);
		}

		if ( ! empty( $responsive_overrides['tablet'] ) ) {
			$variants[] = array(
				'meta'       => array( 'breakpoint' => 'tablet', 'state' => null ),
				'props'      => $responsive_overrides['tablet'],
				'custom_css' => null,
			);
		}

		if ( ! empty( $responsive_overrides['mobile'] ) ) {
			$variants[] = array(
				'meta'       => array( 'breakpoint' => 'mobile', 'state' => null ),
				'props'      => $responsive_overrides['mobile'],
				'custom_css' => null,
			);
		}

		$style_def = array(
			'id'       => $class_id,
			'label'    => 'local',
			'type'     => 'class',
			'variants' => $variants,
		);

		// Merge class IDs.
		$all_class_ids = array_merge( $existing_class_ids, array( $class_id ) );

		return array(
			'settings'  => array(
				'classes' => array( '$$type' => 'classes', 'value' => array_values( array_unique( $all_class_ids ) ) ),
			),
			'styles'    => array( $class_id => $style_def ),
			'class_ids' => $all_class_ids,
		);
	}

	/**
	 * Extract container-level styles (background, padding, margin, border)
	 * and build a local style class with responsive variants.
	 *
	 * Called from convert_section/column/container to handle per-container
	 * styles that should live in a styles map rather than inline settings.
	 *
	 * @param array  $v3_settings V3 element settings.
	 * @param string $element_id  Generated element ID.
	 * @param array  $ci          Color index.
	 * @param array  $existing    Already-built settings (from make_container_settings).
	 * @return array {settings: [...], styles: [...]}
	 */
	private static function extract_and_apply_container_styles(
		array $v3_settings,
		string $element_id,
		array $ci,
		array $existing
	): array {
		// Extract desktop-level container style props.
		$desktop_props = self::extract_style_props_for_widget( $v3_settings, 'container', $ci );

		// Extract responsive overrides.
		$responsive = self::extract_responsive_overrides( $v3_settings, $ci );

		// Also add container-specific layout props not covered by make_container_settings.
		if ( ! empty( $v3_settings['gap'] ) && is_array( $v3_settings['gap'] ) && isset( $v3_settings['gap']['size'] ) ) {
			$desktop_props['gap'] = self::v4_size( (float) $v3_settings['gap']['size'], $v3_settings['gap']['unit'] ?? 'px' );
		}
		if ( ! empty( $v3_settings['content_width'] ) && is_array( $v3_settings['content_width'] ) && isset( $v3_settings['content_width']['size'] ) ) {
			$desktop_props['width'] = self::v4_size( (float) $v3_settings['content_width']['size'], $v3_settings['content_width']['unit'] ?? 'px' );
		}

		// Build and apply styles.
		$existing_class_ids = $existing['classes']['value'] ?? array();
		$style_result = self::build_and_apply_styles( $element_id, $desktop_props, $responsive, $existing_class_ids );

		// Merge the updated classes into existing settings.
		$settings = $existing;
		if ( isset( $style_result['settings']['classes'] ) ) {
			$settings['classes'] = $style_result['settings']['classes'];
		}

		return array(
			'settings' => $settings,
			'styles'   => $style_result['styles'],
		);
	}

	// =====================================================================
	// Color Index & Resolution
	// =====================================================================

	/**
	 * Build an O(1) lookup index from the variable_map.
	 *
	 * Pre-computes normalized color → CSS var() reference so
	 * resolve_color_var() is a simple array key lookup.
	 * Only indexes global-color-variable type entries.
	 *
	 * @param array $variable_map V3-ID → {id, label, type, value}.
	 * @return array{string, string} [normalized_hex => 'e-gv-...'].
	 */
	public static function build_color_index( array $variable_map ): array {
		if ( empty( $variable_map ) ) {
			return array();
		}
		$index = array();
		foreach ( $variable_map as $entry ) {
			$value = $entry['value'] ?? '';
			$id    = $entry['id'] ?? '';
			if ( $value === '' || $id === '' ) {
				continue;
			}
			$entry_type = $entry['type'] ?? '';
			if ( 'global-color-variable' !== $entry_type ) {
				continue;
			}
			$normalized           = self::normalize_color( $value );
			$index[ $normalized ] = $id;
		}
		return $index;
	}

	/**
	 * Resolve a raw hex/rgba color to a V4 variable reference using the
	 * pre-built color index. Falls back to the raw color if no match.
	 *
	 * @param string $color Raw color string.
	 * @param array  $ci    Color index from build_color_index().
	 * @return string CSS var() reference or original color.
	 */
	public static function resolve_color_var( string $color, array $ci ): string {
		if ( empty( $ci ) ) {
			return $color;
		}
		$normalized = self::normalize_color( $color );
		return $ci[ $normalized ] ?? $color;
	}

	/**
	 * Normalize a color for comparison: trim, lowercase, compact whitespace.
	 */
	private static function normalize_color( string $color ): string {
		$color = self::normalize_color_value( trim( $color ) );
		if ( str_starts_with( $color, '#' ) ) {
			return strtolower( $color );
		}
		if ( str_starts_with( $color, 'rgba' ) || str_starts_with( $color, 'rgb' ) ) {
			return strtolower( preg_replace( '/\s+/', '', $color ) );
		}
		return $color;
	}

	// =====================================================================
	// V4 $$type Property Helpers
	// =====================================================================

	/**
	 * Wrap a value as a V4 $$type: string prop.
	 */
	private static function v4_string( string $value ): array {
		return array( '$$type' => 'string', 'value' => $value );
	}

	/**
	 * Wrap a value as a V4 $$type: size prop.
	 */
	private static function v4_size( float $size, string $unit = 'px' ): array {
		return array( '$$type' => 'size', 'value' => array( 'size' => $size, 'unit' => $unit ) );
	}

	/**
	 * Wrap a color value as V4 $$type prop.
	 *
	 * Detects Global Variable references and wraps as global-color-variable.
	 * Plain hex/rgba values are wrapped as string.
	 */
	private static function v4_color( string $value ): array {
		$value = self::normalize_color_value( $value );
		if ( preg_match( '/^var\(--(e-gv-[^)]+)\)$/', $value, $match ) ) {
			$value = $match[1];
		}
		if ( str_starts_with( $value, 'e-gv-' ) ) {
			return array( '$$type' => 'global-color-variable', 'value' => $value );
		}
		return array( '$$type' => 'string', 'value' => $value );
	}

	/**
	 * Convert unsupported 8-digit hex colors to rgba() for V4 style props.
	 */
	private static function normalize_color_value( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^#([0-9a-fA-F]{8})$/', $value, $match ) ) {
			$hex   = $match[1];
			$red   = hexdec( substr( $hex, 0, 2 ) );
			$green = hexdec( substr( $hex, 2, 2 ) );
			$blue  = hexdec( substr( $hex, 4, 2 ) );
			$alpha = round( hexdec( substr( $hex, 6, 2 ) ) / 255, 3 );
			return "rgba($red,$green,$blue,$alpha)";
		}
		return $value;
	}

	/**
	 * Convert V3 paragraph HTML to the e-paragraph html-v3 content string.
	 */
	private static function normalize_paragraph_content( string $html ): string {
		$html = trim( $html );
		$html = preg_replace( '/<\/p>\s*<p[^>]*>/i', '<br><br>', $html ) ?? $html;
		$html = preg_replace( '/^\s*<p[^>]*>/i', '', $html ) ?? $html;
		$html = preg_replace( '/<\/p>\s*$/i', '', $html ) ?? $html;
		return trim( $html );
	}

	/**
	 * Wrap direct widgets so e-flexbox children remain V4-layout-safe.
	 */
	private static function wrap_direct_widget_children( array $children ): array {
		$wrapped = array();
		foreach ( $children as $child ) {
			if ( is_array( $child ) && 'widget' === ( $child['elType'] ?? '' ) ) {
				$wrapped[] = array(
					'id'       => self::gen_id(),
					'elType'   => 'e-div-block',
					'settings' => array(
						'classes' => array( '$$type' => 'classes', 'value' => array() ),
					),
					'elements' => array( $child ),
					'styles'   => array(),
				);
				continue;
			}
			$wrapped[] = $child;
		}
		return $wrapped;
	}

	// =====================================================================
	// Utilities
	// =====================================================================

	public static function gen_id(): string {
		return substr( md5( uniqid( '', true ) ), 0, 7 );
	}

	// =====================================================================
	// Globals Resolution (__globals__ → inline values)
	// =====================================================================

	/**
	 * Static cache of kit global colors: [_id => hex_color].
	 *
	 * Loaded once from the active Elementor kit's _elementor_page_settings.
	 * Combines system_colors and custom_colors.
	 *
	 * @return array{string, string}
	 */
	private static function get_kit_global_colors(): array {
		static $colors = null;

		if ( null !== $colors ) {
			return $colors;
		}

		$colors = array();

		try {
			$kit_id = get_option( 'elementor_active_kit' );
			if ( empty( $kit_id ) ) {
				return $colors;
			}

			$kit_settings = get_post_meta( (int) $kit_id, '_elementor_page_settings', true );
			if ( ! is_array( $kit_settings ) ) {
				return $colors;
			}

			// Merge system_colors + custom_colors into a single [_id => color] map.
			foreach ( array( 'system_colors', 'custom_colors' ) as $key ) {
				$entries = $kit_settings[ $key ] ?? array();
				if ( ! is_array( $entries ) ) {
					continue;
				}
				foreach ( $entries as $entry ) {
					$id    = $entry['_id'] ?? '';
					$color = $entry['color'] ?? '';
					if ( '' !== $id && '' !== $color ) {
						$colors[ $id ] = $color;
					}
				}
			}
		} catch ( \Exception $e ) {
			// Silently fail — global color resolution is best-effort.
		}

		return $colors;
	}

	/**
	 * Return a map of typography preset _id → array of typography properties.
	 *
	 * @return array<string, array>  e.g. { "primary" => {"typography_font_family":"Roboto",...} }
	 */
	private static function get_kit_global_typography(): array {
		static $typo = null;

		if ( null !== $typo ) {
			return $typo;
		}

		$typo = array();

		try {
			$kit_id = get_option( 'elementor_active_kit' );
			if ( empty( $kit_id ) ) {
				return $typo;
			}

			$kit_settings = get_post_meta( (int) $kit_id, '_elementor_page_settings', true );
			if ( ! is_array( $kit_settings ) ) {
				return $typo;
			}

			// Merge system_typography + custom_typography.
			foreach ( array( 'system_typography', 'custom_typography' ) as $key ) {
				$entries = $kit_settings[ $key ] ?? array();
				if ( ! is_array( $entries ) ) {
					continue;
				}
				foreach ( $entries as $entry ) {
					$id = $entry['_id'] ?? '';
					if ( '' === $id ) {
						continue;
					}
					// Collect all typography_* properties from this preset entry.
					$props = array();
					foreach ( $entry as $prop_key => $prop_val ) {
						if ( str_starts_with( $prop_key, 'typography_' ) ) {
							$props[ $prop_key ] = $prop_val;
						}
					}
					if ( ! empty( $props ) ) {
						$typo[ $id ] = $props;
					}
				}
			}
		} catch ( \Exception $e ) {
			// Best-effort — silently ignore.
		}

		return $typo;
	}

	/**
	 * Resolve V3 __globals__ references in widget/container settings.
	 *
	 * For each key in __globals__ that points to a global color ID
	 * (globals/colors?id=XXXXX), looks up the hex value in the kit's
	 * global colors map and overwrites the corresponding inline setting.
	 *
	 * This allows the normal style extraction pipeline to pick up
	 * global colors as if they were inline values, which then flow
	 * through resolve_color_var() for V4 variable mapping.
	 *
	 * Modifies $settings in-place.
	 *
	 * @param array &$settings V3 element settings (mutated).
	 */
	private static function resolve_globals( array &$settings ): void {
		$globals = $settings['__globals__'] ?? array();
		if ( ! is_array( $globals ) || empty( $globals ) ) {
			return;
		}

		$kit_colors = self::get_kit_global_colors();

		foreach ( $globals as $setting_key => $global_ref ) {
			if ( ! is_string( $global_ref ) ) {
				continue;
			}

			// ── Color globals ────────────────────────────────────────────────
			if ( str_starts_with( $global_ref, 'globals/colors?id=' ) ) {
				$color_id = substr( $global_ref, strlen( 'globals/colors?id=' ) );
				if ( '' !== $color_id && isset( $kit_colors[ $color_id ] ) ) {
					// Overwrite inline setting with resolved hex value so it flows
					// through resolve_color_var() into a V4 global-color-variable.
					$settings[ $setting_key ] = $kit_colors[ $color_id ];
				}
				continue;
			}

			// ── Typography globals ────────────────────────────────────────────
			if ( str_starts_with( $global_ref, 'globals/typography?id=' ) ) {
				$typo_id = substr( $global_ref, strlen( 'globals/typography?id=' ) );
				$kit_typo = self::get_kit_global_typography();
				if ( '' !== $typo_id && isset( $kit_typo[ $typo_id ] ) ) {
					// Expand all typography_* properties into widget settings.
					// The $setting_key is the base (e.g. 'typography' or 'title_typography').
					// Prefixed sub-keys: $setting_key_font_family, $setting_key_font_size, etc.
					$prefix = ( 'typography' === $setting_key ) ? '' : $setting_key . '_';
					foreach ( $kit_typo[ $typo_id ] as $prop => $val ) {
						// prop is e.g. 'typography_font_family'; strip leading 'typography_'.
						$sub = substr( $prop, strlen( 'typography_' ) );
						$settings[ $prefix . 'typography_' . $sub ] = $val;
					}
				}
				continue;
			}
		}
	}
}
