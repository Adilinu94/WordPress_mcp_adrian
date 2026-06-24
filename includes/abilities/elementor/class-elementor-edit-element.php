<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Guards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/elementor-edit-element
 *
 * Surgically updates the settings of a single Elementor element (widget,
 * section, column, or container) identified by its element ID, on any
 * V3 or V4 page. Accepts an arbitrary settings map and deep-merges it into
 * the element's existing settings.
 *
 * Designed to match the interface expected by site-clone-to-v3's real-fixers:
 *   { post_id, element_id, settings: { key: value, ... } }
 *
 * Compared to patch-element-styles this ability:
 * - Works on V3 AND V4 pages (no version guard)
 * - Takes a single element per call (simpler interface for QA auto-fix loops)
 * - Accepts any settings key, not only style props / class ops
 *
 * @since 1.7.1
 */
class Elementor_Edit_Element {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/elementor-edit-element',
			[
				'label'       => 'Edit Element Settings',
				'description' => 'Deep-merge an arbitrary settings object into a single Elementor element (widget, section, column, or container) identified by element ID, on any V3 or V4 page. Clears Elementor CSS cache after writing. Use this for targeted QA fixes: update _background_color, padding, margin, image, width, height, custom_css, or any other Elementor setting key without rebuilding the page. Accepts both V3 and V4 pages.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'post_id', 'element_id', 'settings' ],
					'properties' => [
						'post_id'    => [
							'type'        => 'integer',
							'description' => 'Page/post ID containing the element.',
						],
						'element_id' => [
							'type'        => 'string',
							'description' => 'Elementor element ID (the "id" field in _elementor_data, e.g. "a1b2c3d4").',
						],
						'settings'   => [
							'type'                 => 'object',
							'description'          => 'Settings to deep-merge into the element. Supports any Elementor setting key: _background_color, padding, margin, image { id, url }, width, height, _inline_size, custom_css, _css_classes, and so on.',
							'additionalProperties' => true,
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success'          => [ 'type' => 'boolean' ],
						'element_id'       => [ 'type' => 'string' ],
						'settings_updated' => [ 'type' => 'array', 'description' => 'Keys that were written.' ],
						'permalink'        => [ 'type' => 'string' ],
						'error'            => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					],
				],
			]
		);
	}

	public static function execute( ?array $input ): array {
		$post_id    = (int) ( $input['post_id']    ?? 0 );
		$element_id = trim( (string) ( $input['element_id'] ?? '' ) );
		$settings   = $input['settings'] ?? [];

		if ( $post_id <= 0 ) {
			return [ 'success' => false, 'error' => 'post_id is required.' ];
		}
		if ( $element_id === '' ) {
			return [ 'success' => false, 'error' => 'element_id is required.' ];
		}
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return [ 'success' => false, 'error' => 'settings must be a non-empty object.' ];
		}
		if ( ! get_post( $post_id ) ) {
			return [ 'success' => false, 'error' => "Post {$post_id} not found." ];
		}

		// Read _elementor_data safely (no wp_unslash corruption).
		global $wpdb;
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta}
				  WHERE post_id = %d AND meta_key = '_elementor_data'
				  LIMIT 1",
				$post_id
			)
		);

		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return [ 'success' => false, 'error' => 'Could not decode _elementor_data.' ];
		}

		$found = false;
		self::walk_and_update( $data, $element_id, $settings, $found );

		if ( ! $found ) {
			return [
				'success'    => false,
				'error'      => "Element '{$element_id}' not found in post {$post_id}.",
				'element_id' => $element_id,
			];
		}

		Guards::save_elementor_data( $post_id, $data );
		Guards::invalidate_elementor_cache( $post_id );

		return [
			'success'          => true,
			'element_id'       => $element_id,
			'settings_updated' => array_keys( $settings ),
			'permalink'        => (string) get_permalink( $post_id ),
		];
	}

	/**
	 * Recursively walk the element tree and deep-merge $settings into the
	 * first element whose 'id' matches $target_id.
	 *
	 * @param array  $elements   Reference to current level of the element tree.
	 * @param string $target_id  Element ID to find.
	 * @param array  $settings   Settings to merge.
	 * @param bool   $found      Set to true when the element is located.
	 */
	private static function walk_and_update(
		array &$elements,
		string $target_id,
		array $settings,
		bool &$found
	): void {
		foreach ( $elements as &$el ) {
			if ( $found ) {
				break;
			}

			if ( ( $el['id'] ?? '' ) === $target_id ) {
				$el['settings'] = array_replace_recursive(
					$el['settings'] ?? [],
					$settings
				);
				$found = true;
				return;
			}

			// Recurse into children (V3: elements[], V4: elements[]).
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk_and_update( $el['elements'], $target_id, $settings, $found );
			}
		}
	}
}
