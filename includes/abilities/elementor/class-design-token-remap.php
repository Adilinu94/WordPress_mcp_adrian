<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Design_Token_Remap — site-wide remap of Global Variable IDs after a kit rebuild or brand update.
 *
 * After kit-convert-v3-to-v4 is re-run (e.g. for a brand refresh), existing GV IDs
 * (e-gv-bebd7fa) in page styles and Global Class props become stale. This ability
 * walks every post's _elementor_data AND the e_global_class CPT posts and replaces
 * each old ID with the corresponding new ID from remap_map.
 *
 * Only {$$type:"global-*-variable"} prop values are touched. Inline colors, sizes,
 * and strings are left untouched. Global Class IDs (gc-*) are never in scope.
 *
 * Scope "pages"         — only _elementor_data per post.
 * Scope "kit"           — only e_global_class CPT posts.
 * Scope "both" (default)— pages + kit.
 *
 * dry_run:true (default) counts replacements without writing anything.
 *
 * @package Novamira_AdrianV2
 * @since   1.6.0
 */
class Design_Token_Remap {

	/**
	 * Register the MCP ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/design-token-remap',
			array(
				'label'               => 'Design Token Remap',
				'description'         => 'Site-wide replacement of stale Global Variable IDs (e-gv-*) after a kit rebuild or brand update. Walks page _elementor_data and e_global_class CPT posts. dry_run:true by default.',
				'category'            => 'novamira-adrianv2',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'remap_map' ),
					'properties' => array(
						'remap_map' => array(
							'type'        => 'object',
							'description' => 'Map of { old_gv_id: new_gv_id } — e.g. { "e-gv-bebd7fa": "e-gv-f43276f" }. Keys and values must be bare IDs without var(--...) wrappers.',
						),
						'dry_run'   => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Count replacements without writing. Default: true.',
						),
						'scope'     => array(
							'type'        => 'string',
							'enum'        => array( 'pages', 'kit', 'both' ),
							'default'     => 'both',
							'description' => 'What to remap: pages (_elementor_data), kit (e_global_class CPTs), or both.',
						),
						'post_ids'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'default'     => null,
							'description' => 'Explicit list of post IDs to process (pages scope). null = auto-discover all Elementor posts.',
						),
						'limit'     => array(
							'type'        => 'integer',
							'default'     => 50,
							'description' => 'Max posts to process per call (pages scope). For pagination.',
						),
						'offset'    => array(
							'type'        => 'integer',
							'default'     => 0,
							'description' => 'Offset for pagination (pages scope).',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'          => array( 'type' => 'boolean' ),
						'dry_run'          => array( 'type' => 'boolean' ),
						'scope'            => array( 'type' => 'string' ),
						'stats'            => array(
							'type'       => 'object',
							'properties' => array(
								'posts_scanned'   => array( 'type' => 'integer' ),
								'posts_modified'  => array( 'type' => 'integer' ),
								'refs_replaced'   => array( 'type' => 'integer' ),
								'kit_refs_replaced' => array( 'type' => 'integer' ),
								'kit_classes_modified' => array( 'type' => 'integer' ),
							),
						),
						'per_page'         => array( 'type' => 'array' ),
						'kit'              => array( 'type' => 'object' ),
						'errors'           => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
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
		$remap_map = $input['remap_map'] ?? array();
		$dry_run   = $input['dry_run'] ?? true;
		$scope     = $input['scope'] ?? 'both';
		$post_ids  = $input['post_ids'] ?? null;
		$limit     = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		$offset    = isset( $input['offset'] ) ? (int) $input['offset'] : 0;

		// --- Validate remap_map ---
		if ( empty( $remap_map ) || ! is_array( $remap_map ) ) {
			return new \WP_Error( 'invalid_remap_map', 'remap_map must be a non-empty object mapping old GV IDs to new GV IDs.' );
		}
		foreach ( $remap_map as $old => $new ) {
			if ( ! is_string( $old ) || ! is_string( $new ) || '' === $old || '' === $new ) {
				return new \WP_Error( 'invalid_remap_map', 'All remap_map keys and values must be non-empty strings.' );
			}
			if ( str_starts_with( $old, 'var(--' ) || str_starts_with( $new, 'var(--' ) ) {
				return new \WP_Error( 'invalid_remap_map', 'remap_map keys/values must be bare IDs (e.g. "e-gv-abc"), not var(--...) wrappers.' );
			}
		}

		if ( ! in_array( $scope, array( 'pages', 'kit', 'both' ), true ) ) {
			$scope = 'both';
		}

		$stats = array(
			'posts_scanned'        => 0,
			'posts_modified'       => 0,
			'refs_replaced'        => 0,
			'kit_refs_replaced'    => 0,
			'kit_classes_modified' => 0,
		);
		$per_page_results = array();
		$errors           = array();
		$kit_result       = array( 'refs_replaced' => 0, 'classes_modified' => 0, 'classes_touched' => array() );

		// --- Pages scope ---
		if ( in_array( $scope, array( 'pages', 'both' ), true ) ) {
			if ( is_array( $post_ids ) && ! empty( $post_ids ) ) {
				$ids_to_process = array_map( 'intval', $post_ids );
				// Apply limit/offset to explicit list too.
				$ids_to_process = array_slice( $ids_to_process, $offset, $limit );
			} else {
				$all_ids        = self::discover_elementor_posts();
				$ids_to_process = array_slice( $all_ids, $offset, $limit );
			}

			foreach ( $ids_to_process as $post_id ) {
				$stats['posts_scanned']++;
				$result = self::remap_post( $post_id, $remap_map, $dry_run );

				if ( is_wp_error( $result ) ) {
					$errors[] = "post {$post_id}: " . $result->get_error_message();
					continue;
				}

				$per_page_results[] = $result;

				if ( $result['refs_replaced'] > 0 ) {
					$stats['posts_modified']++;
					$stats['refs_replaced'] += $result['refs_replaced'];
				}
			}
		}

		// --- Kit scope (e_global_class CPT posts) ---
		if ( in_array( $scope, array( 'kit', 'both' ), true ) ) {
			$kit_result = self::remap_global_classes( $remap_map, $dry_run );
			$stats['kit_refs_replaced']    = $kit_result['refs_replaced'];
			$stats['kit_classes_modified'] = $kit_result['classes_modified'];
		}

		return array(
			'success'  => true,
			'dry_run'  => $dry_run,
			'scope'    => $scope,
			'stats'    => $stats,
			'per_page' => $per_page_results,
			'kit'      => $kit_result,
			'errors'   => $errors,
		);
	}

	// =========================================================================
	// Pages
	// =========================================================================

	/**
	 * Discover all posts that have Elementor data.
	 *
	 * No V3/V4 filter — we remap references in any Elementor post.
	 *
	 * @return int[]
	 */
	private static function discover_elementor_posts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			"SELECT DISTINCT pm.post_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_elementor_data'
			  AND pm.meta_value != '[]'
			  AND pm.meta_value != ''
			  AND p.post_status IN ('publish', 'draft', 'private')
			ORDER BY pm.post_id ASC"
		);

		return array_map( 'intval', $results ?? array() );
	}

	/**
	 * Remap GV IDs inside a single post's _elementor_data.
	 *
	 * @param int   $post_id
	 * @param array $remap_map  { old_id => new_id }
	 * @param bool  $dry_run
	 * @return array|\WP_Error
	 */
	private static function remap_post( int $post_id, array $remap_map, bool $dry_run ) {
		global $wpdb;

		// Read raw to avoid wp_unslash corruption.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
				$post_id
			)
		);

		if ( null === $raw || '' === $raw ) {
			return new \WP_Error( 'no_data', "_elementor_data missing for post {$post_id}." );
		}

		$tree = json_decode( $raw, true );
		if ( ! is_array( $tree ) ) {
			return new \WP_Error( 'invalid_json', "_elementor_data is not valid JSON for post {$post_id}." );
		}

		$count        = 0;
		$updated_tree = self::walk_tree( $tree, $remap_map, $count );

		if ( ! $dry_run && $count > 0 ) {
			$json = wp_json_encode( $updated_tree );
			// Direct SQL to avoid wp_slash/wp_unslash mangling the JSON.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $json ),
				array( 'post_id' => $post_id, 'meta_key' => '_elementor_data' ),
				array( '%s' ),
				array( '%d', '%s' )
			);
			// Bust Elementor CSS cache so the page is re-rendered.
			delete_post_meta( $post_id, '_elementor_css' );
		}

		$post  = get_post( $post_id );
		$title = $post ? $post->post_title : "(post {$post_id})";

		return array(
			'post_id'       => $post_id,
			'title'         => $title,
			'refs_replaced' => $count,
		);
	}

	// =========================================================================
	// Kit — e_global_class CPTs
	// =========================================================================

	/**
	 * Remap GV IDs inside all e_global_class CPT posts.
	 *
	 * Each CPT post stores a PHP array in _elementor_global_class_data:
	 * { type, variants: [ { meta, props: { prop_name: { $$type, value } } } ] }
	 *
	 * @param array $remap_map
	 * @param bool  $dry_run
	 * @return array { refs_replaced, classes_modified, classes_touched }
	 */
	private static function remap_global_classes( array $remap_map, bool $dry_run ): array {
		$refs_replaced    = 0;
		$classes_modified = 0;
		$classes_touched  = array();

		$cpt_posts = get_posts(
			array(
				'post_type'      => 'e_global_class',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $cpt_posts as $cpt_id ) {
			$data = get_post_meta( (int) $cpt_id, '_elementor_global_class_data', true );

			if ( ! is_array( $data ) || empty( $data['variants'] ) ) {
				continue;
			}

			$count = 0;
			self::walk_variants( $data['variants'], $remap_map, $count );

			if ( $count > 0 ) {
				if ( ! $dry_run ) {
					update_post_meta( (int) $cpt_id, '_elementor_global_class_data', $data );
				}
				$class_id = get_post_meta( (int) $cpt_id, '_elementor_global_class_id', true );
				$refs_replaced    += $count;
				$classes_modified++;
				$classes_touched[] = is_string( $class_id ) ? $class_id : "cpt-{$cpt_id}";
			}
		}

		return array(
			'refs_replaced'    => $refs_replaced,
			'classes_modified' => $classes_modified,
			'classes_touched'  => $classes_touched,
		);
	}

	// =========================================================================
	// Tree walkers
	// =========================================================================

	/**
	 * Recursively walk the Elementor element tree and remap GV IDs in styles.
	 *
	 * V4 element structure:
	 * element.styles = { style_id: { id, type, variants: [ { meta, props, custom_css } ] } }
	 *
	 * @param array $tree
	 * @param array $remap_map
	 * @param int   &$count
	 * @return array Updated tree.
	 */
	public static function walk_tree( array $tree, array $remap_map, int &$count ): array {
		foreach ( $tree as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['styles'] ) && is_array( $element['styles'] ) ) {
				self::walk_element_styles( $element['styles'], $remap_map, $count );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::walk_tree( $element['elements'], $remap_map, $count );
			}
		}

		return $tree;
	}

	/**
	 * Walk the styles map of a single element.
	 *
	 * styles = { style_id: { id, type, variants: [...] } }
	 *
	 * @param array $styles    Passed by reference.
	 * @param array $remap_map
	 * @param int   &$count
	 */
	public static function walk_element_styles( array &$styles, array $remap_map, int &$count ): void {
		foreach ( $styles as &$style_def ) {
			if ( ! is_array( $style_def ) || empty( $style_def['variants'] ) ) {
				continue;
			}
			self::walk_variants( $style_def['variants'], $remap_map, $count );
		}
	}

	/**
	 * Walk a variants array and remap props.
	 *
	 * variants = [ { meta, props: { prop_name: { $$type, value } }, custom_css } ]
	 *
	 * @param array $variants  Passed by reference.
	 * @param array $remap_map
	 * @param int   &$count
	 */
	public static function walk_variants( array &$variants, array $remap_map, int &$count ): void {
		foreach ( $variants as &$variant ) {
			if ( ! is_array( $variant ) || empty( $variant['props'] ) ) {
				continue;
			}
			foreach ( $variant['props'] as &$prop ) {
				if ( ! is_array( $prop ) ) {
					continue;
				}
				self::remap_prop( $prop, $remap_map, $count );
			}
		}
	}

	/**
	 * Replace the GV ID inside a single prop if it matches remap_map.
	 *
	 * Handles both bare IDs ("e-gv-abc") and CSS var() wrappers ("var(--e-gv-abc)").
	 * The wrapper format is preserved in the output.
	 *
	 * @param array $prop       Passed by reference.
	 * @param array $remap_map  { old_id => new_id }
	 * @param int   &$count
	 */
	public static function remap_prop( array &$prop, array $remap_map, int &$count ): void {
		$type  = $prop['$$type'] ?? '';
		$value = $prop['value'] ?? null;

		// Only act on global variable references.
		if ( ! str_contains( $type, 'variable' ) || ! is_string( $value ) || '' === $value ) {
			return;
		}

		// Detect and strip var(--...) wrapper.
		$has_var_wrapper = false;
		$bare_id         = $value;

		if ( str_starts_with( $value, 'var(--' ) && str_ends_with( $value, ')' ) ) {
			$has_var_wrapper = true;
			$bare_id         = substr( $value, 6, -1 ); // strip 'var(--' and ')'
		}

		if ( ! isset( $remap_map[ $bare_id ] ) ) {
			return;
		}

		$new_id      = $remap_map[ $bare_id ];
		$prop['value'] = $has_var_wrapper ? "var(--{$new_id})" : $new_id;
		++$count;
	}
}
