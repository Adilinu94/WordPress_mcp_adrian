<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Elementor_Document_Saver;
use Novamira\AdrianV2\Helpers\Elementor_Version_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert_Page_V3_To_V4 — migrates a V3 Elementor page to V4 Atomic structure.
 *
 * Reads _elementor_data from post_id and converts:
 *   section  → e-flexbox
 *   column   → e-div-block
 *   container → e-flexbox
 *   known V3 widgets → their V4 atomic equivalents
 *   unknown widgets  → kept (keep_v3), dropped (skip), or abort (error)
 *
 * Defaults to dry_run=true so nothing is written unless explicitly requested.
 * Writes via Elementor_Document_Saver::save_data() so cache is invalidated correctly.
 *
 * @package Novamira_AdrianV2
 * @since   1.2.0
 */
class Convert_Page_V3_To_V4 {

	/**
	 * V3 widget types that have a direct V4 atomic replacement.
	 * Value null means a custom conversion is performed (see convert_widget).
	 *
	 * @var array<string, string|null>
	 */
	private const WIDGET_MAP = [
		'heading'     => 'e-heading',
		'text-editor' => 'e-paragraph',
		'button'      => 'e-button',
		'image'       => 'e-image',
		'divider'     => 'e-divider',
		'spacer'      => null, // converted to e-div-block with padding
	];

	/**
	 * Register the MCP ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/convert-page-v3-to-v4',
			array(
				'label'               => 'Convert Page V3 to V4 Atomic',
				'description'         => 'Converts an Elementor V3 page tree (section/column/widget) into V4 Atomic (e-flexbox/e-div-block/atomic widgets). Runs as dry_run=true by default — pass dry_run=false to write. Use target_post_id to write to a copy instead of overwriting the source.',
				'category'            => 'novamira-adrianv2',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'                 => array(
							'type'        => 'integer',
							'description' => 'Source page ID to read and convert.',
						),
						'dry_run'                 => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Preview only — returns converted_tree but does not persist. Default: true.',
						),
						'target_post_id'          => array(
							'type'        => 'integer',
							'default'     => null,
							'description' => 'Write the converted tree to this post instead of overwriting the source. Recommended for safe conversion.',
						),
						'unknown_widget_strategy' => array(
							'type'        => 'string',
							'enum'        => array( 'keep_v3', 'skip', 'error' ),
							'default'     => 'keep_v3',
							'description' => 'What to do with V3 widgets that have no V4 atomic equivalent. keep_v3=pass through unchanged, skip=omit from output, error=abort the whole conversion.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Execute the conversion.
	 *
	 * @param array|null $input
	 * @return array|\WP_Error
	 */
	public static function execute( $input = null ) {
		$post_id   = (int) ( $input['post_id'] ?? 0 );
		$dry_run   = (bool) ( $input['dry_run'] ?? true );
		$target_id = isset( $input['target_post_id'] ) ? (int) $input['target_post_id'] : null;
		$strategy  = $input['unknown_widget_strategy'] ?? 'keep_v3';

		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', 'post_id must be a positive integer.' );
		}
		if ( ! in_array( $strategy, array( 'keep_v3', 'skip', 'error' ), true ) ) {
			$strategy = 'keep_v3';
		}

		// V4 guard: conversion to V4 requires V4 to be available on this site.
		if ( ! Elementor_Version_Resolver::site_is_v4() ) {
			return new \WP_Error(
				'v4_not_available',
				'Page conversion to V4 requires Elementor 4.0+ (atomic runtime) to be installed on this site.'
			);
		}

		// Read source data.
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return new \WP_Error( 'no_elementor_data', "No _elementor_data found for post $post_id." );
		}

		$source_tree = is_array( $raw ) ? $raw : json_decode( $raw, true );
		if ( ! is_array( $source_tree ) ) {
			return new \WP_Error( 'invalid_data', 'Could not decode _elementor_data as a JSON array.' );
		}

		// Convert.
		$stats = array(
			'elements_read'       => 0,
			'converted'           => 0,
			'kept_v3'             => 0,
			'skipped'             => 0,
			'unsupported_widgets' => array(),
		);
		$warnings       = array();
		$converted_tree = self::convert_elements( $source_tree, $strategy, $stats, $warnings );

		// Abort if error strategy triggered.
		if ( 'error' === $strategy && ! empty( $stats['unsupported_widgets'] ) ) {
			return new \WP_Error(
				'unsupported_widgets',
				'Conversion aborted: unsupported widget types found (unknown_widget_strategy=error).',
				array( 'widgets' => array_unique( $stats['unsupported_widgets'] ) )
			);
		}

		$result = array(
			'success'        => true,
			'dry_run'        => $dry_run,
			'source_post_id' => $post_id,
			'target_post_id' => null,
			'stats'          => $stats,
			'warnings'       => $warnings,
		);

		if ( $dry_run ) {
			$result['converted_tree'] = $converted_tree;
			return $result;
		}

		// Determine write target.
		$write_id              = $target_id ?? $post_id;
		$result['target_post_id'] = $write_id;

		// Backup the original V3 tree on the source post before any write.
		update_post_meta( $post_id, '_novamira_v3_backup', wp_slash( wp_json_encode( $source_tree ) ) );

		// Ensure the target is registered as an Elementor builder page.
		update_post_meta( $write_id, '_elementor_edit_mode', 'builder' );

		$save = Elementor_Document_Saver::save_data( $write_id, $converted_tree );
		if ( ! $save['success'] ) {
			return new \WP_Error( 'save_failed', 'Elementor_Document_Saver::save_data() failed.', $save );
		}

		if ( ! empty( $save['warnings'] ) ) {
			$result['warnings'] = array_merge( $result['warnings'], $save['warnings'] );
		}

		return $result;
	}

	/**
	 * Recursively convert a V3 element list to V4 atomic elements.
	 *
	 * @param array  $elements
	 * @param string $strategy
	 * @param array  $stats    Passed by reference.
	 * @param array  $warnings Passed by reference.
	 * @return array
	 */
	private static function convert_elements(
		array $elements,
		string $strategy,
		array &$stats,
		array &$warnings
	): array {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$stats['elements_read']++;
			$type = $el['elType'] ?? '';

			if ( 'section' === $type ) {
				$out[] = self::convert_section( $el, $strategy, $stats, $warnings );
			} elseif ( 'column' === $type ) {
				$out[] = self::convert_column( $el, $strategy, $stats, $warnings );
			} elseif ( 'container' === $type ) {
				// Transitional V3 flex-container → e-flexbox.
				$out[] = self::convert_container( $el, $strategy, $stats, $warnings );
			} elseif ( 'widget' === $type ) {
				$converted = self::convert_widget( $el, $strategy, $stats, $warnings );
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

	/** V3 section → e-flexbox */
	private static function convert_section(
		array $el,
		string $strategy,
		array &$stats,
		array &$warnings
	): array {
		$stats['converted']++;
		return array(
			'id'       => self::gen_id(),
			'elType'   => 'e-flexbox',
			'settings' => self::make_container_settings( $el['settings'] ?? array() ),
			'elements' => self::convert_elements( $el['elements'] ?? array(), $strategy, $stats, $warnings ),
		);
	}

	/** V3 column → e-div-block */
	private static function convert_column(
		array $el,
		string $strategy,
		array &$stats,
		array &$warnings
	): array {
		$stats['converted']++;
		return array(
			'id'       => self::gen_id(),
			'elType'   => 'e-div-block',
			'settings' => self::make_container_settings( $el['settings'] ?? array() ),
			'elements' => self::convert_elements( $el['elements'] ?? array(), $strategy, $stats, $warnings ),
		);
	}

	/** V3 container (transitional) → e-flexbox */
	private static function convert_container(
		array $el,
		string $strategy,
		array &$stats,
		array &$warnings
	): array {
		$stats['converted']++;
		return array(
			'id'       => self::gen_id(),
			'elType'   => 'e-flexbox',
			'settings' => self::make_container_settings( $el['settings'] ?? array() ),
			'elements' => self::convert_elements( $el['elements'] ?? array(), $strategy, $stats, $warnings ),
		);
	}

	/**
	 * Convert a V3 widget to its V4 atomic equivalent.
	 * Returns null when the element should be omitted (skip strategy).
	 */
	private static function convert_widget(
		array $el,
		string $strategy,
		array &$stats,
		array &$warnings
	): ?array {
		$wt = $el['widgetType'] ?? '';
		$s  = $el['settings'] ?? array();

		if ( ! array_key_exists( $wt, self::WIDGET_MAP ) ) {
			// Unsupported widget — apply strategy.
			$stats['unsupported_widgets'][] = $wt;
			if ( 'skip' === $strategy ) {
				$stats['skipped']++;
				return null;
			}
			// keep_v3 and error both pass the original element through;
			// error mode is checked by the caller after the full pass.
			$stats['kept_v3']++;
			return $el;
		}

		$atomic = self::WIDGET_MAP[ $wt ];
		$stats['converted']++;

		// spacer → e-div-block with vertical padding.
		if ( 'spacer' === $wt ) {
			$height = (float) ( $s['space']['size'] ?? 50 );
			$unit   = $s['space']['unit'] ?? 'px';
			return array(
				'id'       => self::gen_id(),
				'elType'   => 'e-div-block',
				'settings' => array(
					'classes' => array( '$$type' => 'classes', 'value' => array() ),
					'padding' => array(
						'block-start'  => $height,
						'block-end'    => $height,
						'inline-start' => 0,
						'inline-end'   => 0,
						'unit'         => $unit,
					),
				),
				'elements' => array(),
			);
		}

		$new_settings = array( 'classes' => array( '$$type' => 'classes', 'value' => array() ) );

		if ( 'heading' === $wt ) {
			$new_settings['title'] = $s['title'] ?? '';
			if ( ! empty( $s['header_size'] ) ) {
				$new_settings['tag'] = $s['header_size'];
			}
		} elseif ( 'text-editor' === $wt ) {
			// Strip the outer <p>…</p> wrapper Elementor adds around editor content.
			$text                = trim( $s['editor'] ?? '' );
			$text                = preg_replace( '/^<p>(.*)<\/p>$/s', '$1', $text ) ?? $text;
			$new_settings['text'] = $text;
		} elseif ( 'button' === $wt ) {
			$new_settings['text'] = $s['text'] ?? '';
			if ( ! empty( $s['link'] ) ) {
				$new_settings['link'] = $s['link'];
			}
		} elseif ( 'image' === $wt ) {
			$img = $s['image'] ?? array();
			// GOTCHA (docs/GOTCHAS.md): if attachment id is set, do NOT include url.
			if ( ! empty( $img['id'] ) ) {
				$new_settings['image'] = array( 'id' => (int) $img['id'] );
			} elseif ( ! empty( $img['url'] ) ) {
				$new_settings['image'] = array( 'url' => $img['url'] );
			}
			if ( ! empty( $s['image_size'] ) ) {
				$new_settings['image_size'] = $s['image_size'];
			}
		} elseif ( 'divider' === $wt ) {
			if ( ! empty( $s['style'] ) ) {
				$new_settings['style'] = $s['style'];
			}
			if ( ! empty( $s['weight'] ) ) {
				$new_settings['weight'] = $s['weight'];
			}
			if ( ! empty( $s['color'] ) ) {
				$new_settings['color'] = $s['color'];
			}
		}

		return array(
			'id'         => self::gen_id(),
			'elType'     => 'widget',
			'widgetType' => $atomic,
			'settings'   => $new_settings,
			'elements'   => array(),
		);
	}

	/**
	 * Build a minimal V4 container settings array.
	 * Initializes the required `classes` wrapper and carries over basic layout styles.
	 */
	private static function make_container_settings( array $v3 ): array {
		$settings = array( 'classes' => array( '$$type' => 'classes', 'value' => array() ) );

		// Padding (V3 uses top/right/bottom/left; V4 uses block/inline logical props).
		if ( ! empty( $v3['padding'] ) && is_array( $v3['padding'] ) && isset( $v3['padding']['unit'] ) ) {
			$p                    = $v3['padding'];
			$settings['padding'] = array(
				'block-start'  => (float) ( $p['top'] ?? 0 ),
				'block-end'    => (float) ( $p['bottom'] ?? 0 ),
				'inline-start' => (float) ( $p['left'] ?? 0 ),
				'inline-end'   => (float) ( $p['right'] ?? 0 ),
				'unit'         => $p['unit'],
			);
		}

		// Plain background color (hex string only — no gradient conversion here).
		if ( ! empty( $v3['background_color'] ) && is_string( $v3['background_color'] ) ) {
			$settings['background-color'] = $v3['background_color'];
		}

		return $settings;
	}

	/**
	 * Generate a unique 7-character Elementor element ID (matches editor format).
	 */
	private static function gen_id(): string {
		return substr( md5( uniqid( '', true ) ), 0, 7 );
	}
}

add_action( 'wp_abilities_api_init', array( Convert_Page_V3_To_V4::class, 'register' ) );
