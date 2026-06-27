<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Utilities;

use Novamira\AdrianV2\Helpers\V4_Props;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discover_Ability_Metadata — maschinenlesbare Ability-Katalog via MCP.
 *
 * Wraps wp_get_abilities() and returns all registered novamira-adrianv2/*
 * abilities with input/output schemas, annotations, and category.
 * Enables pipeline skills to auto-generate their novamira-skill/*.md without
 * manual synchronisation between plugin code and pipeline documentation.
 *
 * @since 1.9.0
 */
final class Discover_Ability_Metadata {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/discover-ability-metadata',
			[
				'label'       => 'Discover Ability Metadata',
				'description' => 'Returns all registered novamira-adrianv2/* abilities with their input/output schemas, annotations (readonly/destructive/idempotent), and category. Use to auto-generate pipeline skill docs or to verify which abilities are active in this environment.',
				'category'    => 'adrianv2-utilities',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'prefix'   => [
							'type'        => 'string',
							'default'     => 'novamira-adrianv2',
							'description' => 'Filter by ability name prefix. Default: "novamira-adrianv2" (all plugin abilities).',
						],
						'category' => [
							'type'        => 'string',
							'description' => 'Optional: filter by category slug (e.g. "adrianv2-elementor").',
						],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta' => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				],
			]
		);
	}

	public static function execute( $input = null ): array {
		$prefix          = $input['prefix'] ?? 'novamira-adrianv2';
		$category_filter = $input['category'] ?? '';

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [
				'success'   => false,
				'error'     => 'wp_get_abilities() not available — WP Abilities API not loaded.',
				'abilities' => [],
				'count'     => 0,
			];
		}

		$all      = wp_get_abilities();
		$result   = [];
		$by_cat   = [];

		foreach ( $all as $name => $def ) {
			if ( $prefix && ! str_starts_with( $name, $prefix ) ) {
				continue;
			}

			$cat = $def['category'] ?? 'uncategorized';

			if ( $category_filter && $cat !== $category_filter ) {
				continue;
			}

			$annotations = $def['meta']['annotations'] ?? $def['annotations'] ?? [];
			$entry = [
				'name'         => $name,
				'label'        => $def['label'] ?? $def['name'] ?? $name,
				'description'  => $def['description'] ?? '',
				'category'     => $cat,
				'readonly'     => (bool) ( $annotations['readonly'] ?? false ),
				'destructive'  => (bool) ( $annotations['destructive'] ?? false ),
				'idempotent'   => (bool) ( $annotations['idempotent'] ?? true ),
				'input_schema' => $def['input_schema'] ?? $def['schema'] ?? null,
				'output_schema'=> $def['output_schema'] ?? null,
			];

			$result[]         = $entry;
			$by_cat[ $cat ][] = $name;
		}

		// Sort by name for stable output.
		usort( $result, fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

		return [
			'success'     => true,
			'count'       => count( $result ),
			'by_category' => array_map( 'count', $by_cat ),
			'abilities'   => $result,
		];
	}
}


