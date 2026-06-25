<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Elementor_Version_Resolver;
use Novamira\AdrianV2\Helpers\V3_To_V4_Converter;
use Novamira\AdrianV2\Helpers\Conversion_Auditor;
use Novamira\AdrianV2\Helpers\Elementor_Document_Saver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert_Site_V3_To_V4 — Bulk V3→V4 conversion for all pages on a site.
 *
 * Discovers all Elementor V3 pages via list-elementor-pages logic, then
 * runs convert-page-v3-to-v4 logic on each. Uses the same variable_map and
 * semantic_classes for all pages to maintain design-system coherence.
 *
 * WARNING: Run kit-convert-v3-to-v4 ONCE before calling this and pass
 * the resulting variable_map here. Never call kit-convert twice.
 *
 * @package Novamira_AdrianV2
 * @since   1.5.1
 */
class Convert_Site_V3_To_V4 {

	/** Maximum pages to convert in a single call (prevent timeout). */
	const MAX_PAGES_PER_RUN = 50;

	/**
	 * Register the MCP ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/convert-site-v3-to-v4',
			array(
				'label'               => 'Convert Entire Site V3 to V4',
				'description'         => 'Discovers all V3 Elementor pages and converts them to V4 Atomic in one call. Pass variable_map from kit-convert-v3-to-v4 (call kit-convert only ONCE beforehand). dry_run:true by default. Use limit to process pages in batches.',
				'category'            => 'adrianv2-elementor',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'variable_map'            => array(
							'type'        => 'object',
							'description' => 'From kit-convert-v3-to-v4 (call kit-convert only ONCE). Used to resolve hex→GV references across all pages.',
							'default'     => array(),
						),
						'semantic_classes'         => array(
							'type'        => 'object',
							'description' => 'From kit-convert-v3-to-v4: {heading:[], body:[], button:[]}. Maps semantic roles to Global Class IDs.',
							'default'     => array(),
						),
						'dry_run'                 => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Preview all conversions without persisting. Default: true.',
						),
						'unknown_widget_strategy' => array(
							'type'        => 'string',
							'enum'        => array( 'keep_v3', 'skip', 'error' ),
							'default'     => 'keep_v3',
							'description' => 'How to handle widgets without a V4 equivalent.',
						),
						'auto_fix'                => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Auto-fix audit issues after each page conversion.',
						),
						'limit'                   => array(
							'type'        => 'integer',
							'default'     => 10,
							'description' => 'Max pages per call (1–50). Use offset for batch pagination.',
						),
						'offset'                  => array(
							'type'        => 'integer',
							'default'     => 0,
							'description' => 'Page offset for batch processing.',
						),
						'post_ids'                => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Optional: convert only these specific post IDs instead of auto-discovering V3 pages.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'dry_run'       => array( 'type' => 'boolean' ),
						'total_found'   => array( 'type' => 'integer', 'description' => 'Total V3 pages found.' ),
						'processed'     => array( 'type' => 'integer', 'description' => 'Pages processed in this call.' ),
						'succeeded'     => array( 'type' => 'integer' ),
						'failed'        => array( 'type' => 'integer' ),
						'pages'         => array( 'type' => 'array', 'description' => 'Per-page conversion results.' ),
						'summary_stats' => array( 'type' => 'object', 'description' => 'Aggregated conversion stats.' ),
					),
				),
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
	 * Execute the ability.
	 *
	 * @param array|null $input
	 * @return array|\WP_Error
	 */
	public static function execute( $input = null ) {
		if ( ! Elementor_Version_Resolver::site_is_v4() ) {
			return new \WP_Error(
				'v4_not_available',
				'Bulk site conversion requires Elementor 4.0+ to be installed.'
			);
		}

		$variable_map   = (array) ( $input['variable_map'] ?? array() );
		$semantic_cls   = (array) ( $input['semantic_classes'] ?? array() );
		$dry_run        = (bool) ( $input['dry_run'] ?? true );
		$strategy       = $input['unknown_widget_strategy'] ?? 'keep_v3';
		$auto_fix       = (bool) ( $input['auto_fix'] ?? false );
		$limit          = min( (int) ( $input['limit'] ?? 10 ), self::MAX_PAGES_PER_RUN );
		$offset         = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$explicit_ids   = $input['post_ids'] ?? array();

		$limit = max( 1, $limit );

		// Discover V3 pages or use explicit list.
		if ( ! empty( $explicit_ids ) && is_array( $explicit_ids ) ) {
			$all_v3_ids  = array_map( 'intval', $explicit_ids );
			$total_found = count( $all_v3_ids );
		} else {
			$all_v3_ids  = self::discover_v3_pages();
			$total_found = count( $all_v3_ids );
		}

		// Apply pagination.
		$page_ids  = array_slice( $all_v3_ids, $offset, $limit );
		$processed = count( $page_ids );

		// Build color index once for all pages.
		$color_index = V3_To_V4_Converter::build_color_index( $variable_map );

		// Process each page.
		$results       = array();
		$succeeded     = 0;
		$failed        = 0;
		$agg_converted = 0;
		$agg_kept_v3   = 0;
		$agg_skipped   = 0;

		foreach ( $page_ids as $post_id ) {
			$result = self::convert_single_page(
				$post_id,
				$variable_map,
				$semantic_cls,
				$color_index,
				$dry_run,
				$strategy,
				$auto_fix
			);

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'title'   => get_the_title( $post_id ),
					'success' => false,
					'error'   => $result->get_error_message(),
				);
				++$failed;
			} else {
				$results[] = array(
					'post_id'  => $post_id,
					'title'    => get_the_title( $post_id ),
					'success'  => true,
					'stats'    => $result['stats'] ?? array(),
					'warnings' => $result['warnings'] ?? array(),
					'audit'    => isset( $result['audit'] ) ? array(
						'total_issues' => $result['audit']['total_issues'] ?? 0,
						'by_severity'  => $result['audit']['by_severity'] ?? array(),
					) : null,
				);
				++$succeeded;
				$agg_converted += $result['stats']['converted'] ?? 0;
				$agg_kept_v3   += $result['stats']['kept_v3'] ?? 0;
				$agg_skipped   += $result['stats']['skipped'] ?? 0;
			}
		}

		return array(
			'success'       => true,
			'dry_run'       => $dry_run,
			'total_found'   => $total_found,
			'processed'     => $processed,
			'succeeded'     => $succeeded,
			'failed'        => $failed,
			'has_more'      => ( $offset + $processed ) < $total_found,
			'next_offset'   => $offset + $processed,
			'pages'         => $results,
			'summary_stats' => array(
				'total_converted'  => $agg_converted,
				'total_kept_v3'    => $agg_kept_v3,
				'total_skipped'    => $agg_skipped,
			),
		);
	}

	/**
	 * Discover all posts with V3 Elementor data (legacy widget count > 0).
	 *
	 * @return int[] Array of post IDs.
	 */
	private static function discover_v3_pages(): array {
		global $wpdb;

		// Find posts with _elementor_data that contain V3 elTypes (section/column).
		// We check for 'elType":"section' or 'elType":"column' as V3 indicators.
		$results = $wpdb->get_col(
			"SELECT DISTINCT pm.post_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_elementor_data'
			  AND pm.meta_value != '[]'
			  AND pm.meta_value != ''
			  AND (
			      pm.meta_value LIKE '%\"elType\":\"section\"%'
			   OR pm.meta_value LIKE '%\"elType\":\"column\"%'
			  )
			  AND p.post_status IN ('publish', 'draft', 'private')
			ORDER BY pm.post_id ASC"
		);

		return array_map( 'intval', $results ?? array() );
	}

	/**
	 * Convert a single page using the same logic as convert-page-v3-to-v4.
	 *
	 * @param int    $post_id
	 * @param array  $variable_map
	 * @param array  $semantic_classes
	 * @param array  $color_index        Pre-built color index.
	 * @param bool   $dry_run
	 * @param string $strategy
	 * @param bool   $auto_fix
	 * @return array|\WP_Error
	 */
	private static function convert_single_page(
		int $post_id,
		array $variable_map,
		array $semantic_classes,
		array $color_index,
		bool $dry_run,
		string $strategy,
		bool $auto_fix
	) {
		global $wpdb;

		// Read via raw SQL to avoid wp_unslash corruption.
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
				$post_id
			)
		);

		if ( null === $raw || '' === $raw ) {
			return new \WP_Error( 'no_data', "_elementor_data missing for post {$post_id}." );
		}

		$v3_tree = json_decode( $raw, true );
		if ( ! is_array( $v3_tree ) ) {
			return new \WP_Error( 'invalid_json', "_elementor_data is not valid JSON for post {$post_id}." );
		}

		// Run converter.
		$stats    = array( 'elements_read' => 0, 'converted' => 0, 'kept_v3' => 0, 'skipped' => 0, 'unsupported_widgets' => array() );
		$warnings = array();

		$v4_tree = V3_To_V4_Converter::convert(
			$v3_tree,
			$strategy,
			$stats,
			$warnings,
			$variable_map,
			$semantic_classes,
			$color_index
		);

		// Audit.
		$audit_result = Conversion_Auditor::audit( $v4_tree );

		// Auto-fix if requested.
		$fixes_applied = 0;
		if ( $auto_fix && class_exists( '\Novamira\AdrianV2\Helpers\Conversion_AutoFixer' ) ) {
			$v4_tree       = \Novamira\AdrianV2\Helpers\Conversion_AutoFixer::run( $v4_tree, $fixes_applied );
		}

		// Persist if not dry run.
		if ( ! $dry_run ) {
			// Backup V3 data.
			$wpdb->replace(
				$wpdb->postmeta,
				array(
					'post_id'    => $post_id,
					'meta_key'   => '_novamira_v3_backup',
					'meta_value' => $raw,
				)
			);

			// Write V4 data via safe SQL.
			$json = wp_json_encode( $v4_tree );
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $json ),
				array( 'post_id' => $post_id, 'meta_key' => '_elementor_data' ),
				array( '%s' ),
				array( '%d', '%s' )
			);

			// Update elementor version meta.
			update_post_meta( $post_id, '_elementor_version', '4.0.0' );

			// Clear CSS cache.
			delete_post_meta( $post_id, '_elementor_css' );
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
			}
		}

		return array(
			'success'        => true,
			'dry_run'        => $dry_run,
			'source_post_id' => $post_id,
			'stats'          => $stats,
			'warnings'       => $warnings,
			'audit'          => array(
				'total_issues' => count( $audit_result ),
				'by_severity'  => self::group_by( $audit_result, 'severity' ),
				'by_type'      => self::group_by( $audit_result, 'type' ),
				'issues'       => $audit_result,
			),
			'fixes_applied'  => $fixes_applied,
		);
	}

	/**
	 * Group audit issues by a key.
	 *
	 * @param array  $issues
	 * @param string $key
	 * @return array
	 */
	private static function group_by( array $issues, string $key ): array {
		$groups = array();
		foreach ( $issues as $issue ) {
			$k = $issue[ $key ] ?? 'unknown';
			if ( ! isset( $groups[ $k ] ) ) {
				$groups[ $k ] = 0;
			}
			++$groups[ $k ];
		}
		return $groups;
	}
}
