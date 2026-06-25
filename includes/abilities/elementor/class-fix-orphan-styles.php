<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fix_Orphan_Styles — batch-remove style definitions that are never referenced.
 *
 * The Conversion_Auditor flags orphan styles (type=class, severity=warning,
 * message contains "orphan style"). This ability walks the same posts and
 * removes the unused style entries from element.styles maps.
 *
 * Typical cause: V3→V4 conversion left style defs behind after element removal,
 * or duplicate conversions generated extra style blocks.
 *
 * @since 1.8.0
 */
class Fix_Orphan_Styles {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/fix-orphan-styles',
			[
				'label'       => 'Fix Orphan Styles',
				'description' => 'Removes style class definitions from _elementor_data that are defined in element.styles but never referenced in settings.classes. dry_run:true (default) shows what would be removed without writing.',
				'category'    => 'adrianv2-elementor',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'post_ids' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'integer' ],
							'description' => 'Specific post IDs to process. null = all Elementor posts.',
						],
						'dry_run'  => [ 'type' => 'boolean', 'default' => true ],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta' => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				],
			]
		);
	}

	public static function execute( $input = null ): array {
		$post_ids = $input['post_ids'] ?? null;
		$dry_run  = $input['dry_run'] ?? true;

		if ( is_array( $post_ids ) && ! empty( $post_ids ) ) {
			$ids = array_map( 'absint', $post_ids );
		} else {
			$ids = self::discover_posts();
		}

		$stats    = [ 'posts_scanned' => 0, 'posts_modified' => 0, 'styles_removed' => 0 ];
		$per_page = [];
		$errors   = [];

		foreach ( $ids as $post_id ) {
			$stats['posts_scanned']++;
			$result = self::fix_post( $post_id, $dry_run );
			if ( isset( $result['error'] ) ) {
				$errors[] = $result['error'];
				continue;
			}
			if ( $result['removed'] > 0 ) {
				$stats['posts_modified']++;
				$stats['styles_removed'] += $result['removed'];
				$per_page[] = $result;
			}
		}

		return [
			'success'  => true,
			'dry_run'  => $dry_run,
			'stats'    => $stats,
			'per_page' => $per_page,
			'errors'   => $errors,
		];
	}

	private static function fix_post( int $post_id, bool $dry_run ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_elementor_data' LIMIT 1",
			$post_id
		) );

		if ( ! $raw ) {
			return [ 'error' => "No _elementor_data for post {$post_id}." ];
		}

		$tree = json_decode( $raw, true );
		if ( ! is_array( $tree ) ) {
			return [ 'error' => "Invalid JSON in post {$post_id}." ];
		}

		$removed = 0;
		$updated = self::walk( $tree, $removed );

		if ( ! $dry_run && $removed > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_value' => wp_json_encode( $updated ) ],
				[ 'post_id' => $post_id, 'meta_key' => '_elementor_data' ],
				[ '%s' ],
				[ '%d', '%s' ]
			);
			delete_post_meta( $post_id, '_elementor_css' );
		}

		return [
			'post_id' => $post_id,
			'title'   => get_the_title( $post_id ),
			'removed' => $removed,
		];
	}

	private static function walk( array $tree, int &$removed ): array {
		foreach ( $tree as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			if ( ! empty( $el['styles'] ) && is_array( $el['styles'] ) ) {
				// Collect class IDs actually referenced in settings.classes.
				$referenced = self::collect_referenced( $el['settings'] ?? [] );

				foreach ( array_keys( $el['styles'] ) as $style_id ) {
					if ( ! isset( $referenced[ $style_id ] ) ) {
						unset( $el['styles'][ $style_id ] );
						$removed++;
					}
				}
			}

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::walk( $el['elements'], $removed );
			}
		}

		return $tree;
	}

	/** @return array<string,true> */
	private static function collect_referenced( array $settings ): array {
		$refs    = [];
		$classes = $settings['classes']['value'] ?? [];
		foreach ( (array) $classes as $cid ) {
			if ( is_string( $cid ) ) {
				$refs[ $cid ] = true;
			}
		}
		return $refs;
	}

	private static function discover_posts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col(
			"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
			 WHERE pm.meta_key='_elementor_data' AND pm.meta_value NOT IN ('','[]')
			 AND p.post_status IN ('publish','draft','private')
			 ORDER BY pm.post_id ASC"
		);
		return array_map( 'intval', $rows ?? [] );
	}
}
