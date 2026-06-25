<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Guards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/get-page-elements
 *
 * Reads the _elementor_data of a post/page and returns a flat list of all
 * elements with their IDs, types, parent IDs, and a lightweight settings
 * summary. Also returns a depth-first tree snapshot for structural
 * inspection without exposing the full raw JSON blob.
 *
 * Use cases:
 *   - QA auto-fixer in site-clone-to-v3: confirm element IDs after push
 *   - Diff/debug: inspect what's actually stored in DB vs what was built
 *   - PixelElementResolver: ground-truth ID lookup after elementor-inject-calibrated-page
 *
 * @since 1.7.1
 */
class Get_Page_Elements {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/get-page-elements',
			[
				'label'       => 'Get Page Elements',
				'description' => 'Read _elementor_data of a post/page and return a flat list of all elements with their IDs, types (elType + widgetType), parent IDs, nesting depth, and a lightweight settings summary (title, text, image URL, classes, custom_css presence). Useful for QA, debugging, and PixelElementResolver ground-truth ID lookup after elementor-inject-calibrated-page. Non-destructive read-only ability.',
				'category'    => 'adrianv2-elementor',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'post_id' ],
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => 'ID of the post/page to inspect.',
						],
						'include_settings' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'When true, include a full settings dump for each element (can be large). Default: false — only key settings are summarised.',
						],
						'filter_type' => [
							'type'        => 'string',
							'default'     => '',
							'description' => 'Filter to specific elType (section, column, container, widget) or widgetType (heading, button, image …). Empty = return all.',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success'       => [ 'type' => 'boolean' ],
						'post_id'       => [ 'type' => 'integer' ],
						'total'         => [ 'type' => 'integer', 'description' => 'Total elements (after filter).' ],
						'elements'      => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'          => [ 'type' => 'string' ],
									'el_type'     => [ 'type' => 'string' ],
									'widget_type' => [ 'type' => 'string' ],
									'parent_id'   => [ 'type' => 'string' ],
									'depth'       => [ 'type' => 'integer' ],
									'children'    => [ 'type' => 'integer', 'description' => 'Direct child count.' ],
									'summary'     => [ 'type' => 'object', 'description' => 'Key settings: title, text, image_url, classes, has_custom_css.' ],
									'settings'    => [ 'type' => 'object', 'description' => 'Full settings (only when include_settings=true).' ],
								],
							],
						],
						'error' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
					],
				],
			]
		);
	}

	public static function execute( ?array $input ): array {
		$post_id          = (int) ( $input['post_id'] ?? 0 );
		$include_settings = (bool) ( $input['include_settings'] ?? false );
		$filter_type      = trim( (string) ( $input['filter_type'] ?? '' ) );

		if ( $post_id <= 0 ) {
			return [ 'success' => false, 'error' => 'post_id is required.' ];
		}
		if ( ! get_post( $post_id ) ) {
			return [ 'success' => false, 'error' => "Post {$post_id} not found." ];
		}

		$data = Guards::get_elementor_data( $post_id );
		if ( $data === false ) {
			return [ 'success' => false, 'error' => "No Elementor data found for post {$post_id}." ];
		}

		$flat = [];
		self::flatten( $data, '', 0, $flat );

		// Apply type filter.
		if ( $filter_type !== '' ) {
			$flat = array_values( array_filter( $flat, function ( array $el ) use ( $filter_type ): bool {
				return $el['el_type'] === $filter_type
					|| $el['widget_type'] === $filter_type;
			} ) );
		}

		// Strip full settings unless requested.
		if ( ! $include_settings ) {
			foreach ( $flat as &$el ) {
				unset( $el['settings'] );
			}
		}

		return [
			'success'  => true,
			'post_id'  => $post_id,
			'total'    => count( $flat ),
			'elements' => $flat,
		];
	}

	/**
	 * Recursively flatten the Elementor element tree into a list.
	 *
	 * @param array  $elements
	 * @param string $parent_id
	 * @param int    $depth
	 * @param array  $out       Mutable result list.
	 */
	public static function flatten(
		array $elements,
		string $parent_id,
		int $depth,
		array &$out
	): void {
		foreach ( $elements as $el ) {
			$id          = (string) ( $el['id'] ?? '' );
			$el_type     = (string) ( $el['elType'] ?? '' );
			$widget_type = (string) ( $el['widgetType'] ?? '' );
			$settings    = $el['settings'] ?? [];
			$children    = $el['elements'] ?? [];

			$entry = [
				'id'          => $id,
				'el_type'     => $el_type,
				'widget_type' => $widget_type,
				'parent_id'   => $parent_id,
				'depth'       => $depth,
				'children'    => count( $children ),
				'summary'     => self::summarise( $settings, $el_type, $widget_type ),
				'settings'    => $settings, // stripped later unless include_settings=true
			];

			$out[] = $entry;

			if ( ! empty( $children ) ) {
				self::flatten( $children, $id, $depth + 1, $out );
			}
		}
	}

	/**
	 * Build a compact settings summary (no large blobs).
	 */
	private static function summarise( array $s, string $el_type, string $widget_type ): array {
		$summary = [];

		// Title / text fields.
		foreach ( [ 'title', 'editor', 'text', 'button_text', 'prefix_label', 'suffix_label' ] as $key ) {
			if ( ! empty( $s[ $key ] ) && is_string( $s[ $key ] ) ) {
				$summary[ $key ] = mb_substr( wp_strip_all_tags( $s[ $key ] ), 0, 80 );
				break;
			}
		}

		// Image URL.
		if ( ! empty( $s['image']['url'] ) ) {
			$summary['image_url'] = $s['image']['url'];
		} elseif ( ! empty( $s['background_image']['url'] ) ) {
			$summary['image_url'] = $s['background_image']['url'];
		}

		// Global classes (Atomic V4).
		if ( ! empty( $s['classes']['value'] ) && is_array( $s['classes']['value'] ) ) {
			$summary['classes'] = $s['classes']['value'];
		} elseif ( ! empty( $s['_css_classes'] ) ) {
			$summary['classes'] = explode( ' ', $s['_css_classes'] );
		}

		// Custom CSS flag.
		if ( ! empty( $s['custom_css'] ) || ! empty( $s['_element_custom_css'] ) ) {
			$summary['has_custom_css'] = true;
		}

		// Background colour.
		if ( ! empty( $s['background_color'] ) ) {
			$summary['background_color'] = $s['background_color'];
		} elseif ( ! empty( $s['_background_color'] ) ) {
			$summary['background_color'] = $s['_background_color'];
		}

		return $summary;
	}
}
