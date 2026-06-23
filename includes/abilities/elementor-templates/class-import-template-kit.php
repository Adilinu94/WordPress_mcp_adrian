<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import_Template_Kit — MCP ability: import a complete Template Kit from manifest JSON.
 *
 * Phases:
 *   0 PRE-FLIGHT  — manifest validation + environment checks
 *   1 DESIGN      — apply Elementor globals (colors, typography) from global-styles template
 *   2 PAGES       — create posts for all templates (Kit_Page_Creator)
 *   3 SITE CONFIG — permalink, front page, theme, cleanup (Kit_Site_Configurator)
 *   4 MENUS       — nav menus from manifest (Kit_Menu_Builder)
 *   5 CACHE       — invalidate Elementor CSS cache
 *
 * Phases that require active plugins / external connections (media download,
 * font localizer, plugin installer) are signalled in the response but not
 * auto-executed. The caller can run follow-up abilities for those.
 *
 * @since 1.7.0
 */
class Import_Template_Kit {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/import-template-kit',
			[
				'label'       => 'Import Template Kit',
				'description' => 'Import a complete Template Kit from a manifest JSON. Creates pages, sets site configuration, builds menus, applies design system. Pass the enhanced manifest format (Novamira) for full support, or the Elementor kit manifest together with pre-loaded template_contents.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'manifest' ],
					'properties' => [
						'manifest'          => [
							'type'        => 'string',
							'description' => 'Kit manifest JSON (enhanced Novamira format or Elementor kit format).',
						],
						'template_contents' => [
							'type'        => 'object',
							'description' => 'For Elementor kit format only: map of { source_path => content_array }. E.g. {"templates/home.json": [...elementor elements...]}.',
						],
						'dry_run'           => [
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Validate and plan without writing anything. Default: true.',
						],
						'phases'            => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Limit to specific phases: "design", "pages", "site_config", "menus". Default: all.',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success'            => [ 'type' => 'boolean' ],
						'dry_run'            => [ 'type' => 'boolean' ],
						'kit_name'           => [ 'type' => 'string' ],
						'format'             => [ 'type' => 'string' ],
						'rollback_snapshot_id' => [ 'type' => 'string' ],
						'phases'             => [ 'type' => 'object' ],
						'warnings'           => [ 'type' => 'array' ],
						'errors'             => [ 'type' => 'array' ],
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
						'idempotent'  => false,
					],
				],
			]
		);
	}

	/**
	 * Execute the kit import.
	 *
	 * @param array|null $input
	 * @return array|\WP_Error
	 */
	public static function execute( $input = null ) {
		$manifest_json      = $input['manifest'] ?? '';
		$template_contents  = $input['template_contents'] ?? [];
		$dry_run            = $input['dry_run'] ?? true;
		$requested_phases   = $input['phases'] ?? [];

		// ── PHASE 0: PRE-FLIGHT ──────────────────────────────────────────────

		if ( ! is_string( $manifest_json ) || '' === $manifest_json ) {
			return new \WP_Error( 'missing_manifest', 'manifest parameter is required.' );
		}

		try {
			$manifest = new Kit_Manifest( $manifest_json, (array) $template_contents );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'invalid_manifest', $e->getMessage() );
		}

		$validation_errors = $manifest->validate();
		if ( ! empty( $validation_errors ) ) {
			return new \WP_Error( 'invalid_manifest', implode( ' | ', $validation_errors ) );
		}

		$kit_name    = $manifest->get_kit_name();
		$format      = $manifest->get_format();
		$phases_out  = [];
		$warnings    = [];
		$errors      = [];
		$snapshot_id = '';

		// Phase filter helper.
		$run = static function ( string $phase ) use ( $requested_phases ): bool {
			return empty( $requested_phases ) || in_array( $phase, $requested_phases, true );
		};

		// Report required plugins that are missing.
		$required = $manifest->get_required_plugins();
		if ( ! empty( $required ) ) {
			$missing = self::check_missing_plugins( $required );
			if ( ! empty( $missing ) ) {
				$warnings[] = 'Missing required plugins (use import-kit-plugins ability to install): ' . implode( ', ', $missing );
			}
		}
		foreach ( $manifest->get_premium_plugins() as $p ) {
			$warnings[] = "Premium plugin '{$p['name']}' must be installed manually: {$p['url']}";
		}

		// Create rollback snapshot before writing anything.
		if ( ! $dry_run ) {
			$snapshot_id = Kit_Rollback::create_snapshot( $kit_name, $manifest );
		}

		$import_session = $snapshot_id ?: ( 'dry_' . substr( uniqid(), -6 ) );

		// ── PHASE 1: DESIGN SYSTEM ───────────────────────────────────────────

		if ( $run( 'design' ) ) {
			$design = $manifest->get_design_system();
			if ( $design ) {
				$result = self::apply_design_system( $design, $dry_run );
				$phases_out['design'] = $result;
			} else {
				$phases_out['design'] = [ 'status' => 'skipped', 'reason' => 'No design system in manifest.' ];
			}
		}

		// ── PHASE 2: PAGES ───────────────────────────────────────────────────

		$id_map = [];

		if ( $run( 'pages' ) ) {
			$page_result = Kit_Page_Creator::create_all( $manifest, $import_session, $dry_run );
			$id_map      = $page_result['created'];

			if ( ! $dry_run && ! empty( $id_map ) ) {
				Kit_Rollback::record_posts( $snapshot_id, $id_map );
			}

			if ( ! empty( $page_result['errors'] ) ) {
				$errors = array_merge( $errors, $page_result['errors'] );
			}

			$phases_out['pages'] = [
				'status'  => 'completed',
				'created' => $page_result['results'],
				'errors'  => $page_result['errors'],
			];
		}

		// ── PHASE 3: SITE CONFIGURATION ──────────────────────────────────────

		if ( $run( 'site_config' ) ) {
			$config_result       = Kit_Site_Configurator::configure( $manifest, $id_map, $dry_run );
			$phases_out['site_config'] = array_merge( [ 'status' => 'completed' ], $config_result );
		}

		// ── PHASE 4: MENUS ───────────────────────────────────────────────────

		if ( $run( 'menus' ) ) {
			$menus_result = Kit_Menu_Builder::create_all( $manifest, $id_map, $dry_run );

			if ( ! $dry_run ) {
				$menu_ids = array_column( $menus_result['created'], 'menu_id' );
				if ( ! empty( $menu_ids ) ) {
					Kit_Rollback::record_menus( $snapshot_id, $menu_ids );
				}
			}

			if ( ! empty( $menus_result['errors'] ) ) {
				$errors = array_merge( $errors, $menus_result['errors'] );
			}

			$phases_out['menus'] = array_merge( [ 'status' => 'completed' ], $menus_result );
		}

		// ── PHASE 5: CACHE ───────────────────────────────────────────────────

		if ( ! $dry_run && ! empty( $id_map ) ) {
			foreach ( $id_map as $post_id ) {
				delete_post_meta( (int) $post_id, '_elementor_css' );
			}
			$phases_out['cache'] = [ 'status' => 'invalidated', 'posts' => count( $id_map ) ];
		}

		// ── MEDIA / FONTS / PLUGIN INSTALL — deferred ────────────────────────

		if ( ! empty( $manifest->get_media() ) ) {
			$warnings[] = count( $manifest->get_media() ) . ' media files need to be downloaded. Use novamira-adrianv2/import-kit-media ability.';
		}

		if ( ! empty( $manifest->get_fonts() ) ) {
			$warnings[] = 'Font localizer: use novamira-adrianv2/import-kit-fonts ability.';
		}

		// ── SUMMARY ──────────────────────────────────────────────────────────

		$page_count = count( $id_map );
		$menu_count = count( $phases_out['menus']['created'] ?? [] );
		$summary    = $dry_run
			? "Dry run: {$page_count} pages would be created for '{$kit_name}'."
			: "{$page_count} pages, {$menu_count} menus created for '{$kit_name}'.";

		return [
			'success'              => empty( $errors ),
			'dry_run'              => $dry_run,
			'kit_name'             => $kit_name,
			'format'               => $format,
			'rollback_snapshot_id' => $snapshot_id,
			'phases'               => $phases_out,
			'warnings'             => $warnings,
			'errors'               => $errors,
			'summary'              => $summary,
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Apply design system (globals) to the active Elementor kit.
	 *
	 * @param array $globals  page_settings structure.
	 * @param bool  $dry_run
	 * @return array
	 */
	private static function apply_design_system( array $globals, bool $dry_run ): array {
		$kit_id = (int) get_option( 'elementor_active_kit' );
		if ( ! $kit_id ) {
			return [ 'status' => 'skipped', 'reason' => 'No active Elementor kit (elementor_active_kit option not set).' ];
		}

		if ( ! $dry_run ) {
			$existing = get_post_meta( $kit_id, '_elementor_page_settings', true );
			$merged   = is_array( $existing ) ? array_merge( $existing, $globals ) : $globals;
			update_post_meta( $kit_id, '_elementor_page_settings', $merged );
			delete_post_meta( $kit_id, '_elementor_css' );
		}

		return [
			'status'      => 'completed',
			'kit_id'      => $kit_id,
			'keys_merged' => array_keys( $globals ),
		];
	}

	/**
	 * Return slugs of required plugins that are not currently active.
	 *
	 * @param array[] $required  From Kit_Manifest::get_required_plugins().
	 * @return string[]
	 */
	private static function check_missing_plugins( array $required ): array {
		$missing = [];
		foreach ( $required as $plugin ) {
			$slug = $plugin['slug'] ?? '';
			if ( ! $slug ) {
				continue;
			}
			// WP convention: main file is usually slug/slug.php.
			// is_plugin_active requires the plugin file relative to plugins dir.
			// We only do a best-effort check here; Kit_Plugin_Installer is authoritative.
			$guessed_file = "{$slug}/{$slug}.php";
			if ( ! function_exists( 'is_plugin_active' ) || ! is_plugin_active( $guessed_file ) ) {
				$missing[] = $slug;
			}
		}
		return $missing;
	}
}
