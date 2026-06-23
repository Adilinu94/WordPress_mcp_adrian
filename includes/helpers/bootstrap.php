<?php
declare(strict_types=1);

/**
 * Helper-Layer Bootstrap.
 *
 * Loads every Helper class in the order they need to be available, so that
 * downstream Ability classes (Phase 4) can rely on all of these being
 * declared when their bootstrap runs.
 *
 * Order rationale:
 *   1. Diagnostics                   — error log, must exist before anything tries to record.
 *   2. Elementor_Version_Resolver    — canonical V3/V4 detection; dependency for V4_Props,
 *                                       Elementor_WC_Bridge, V4_Color_Contrast, and all
 *                                       V4-guarded abilities (1.1.0).
 *   3. Helpers                       — merged utility surface (read/write, v4 vars, guards).
 *   4. V4_Props                      — required by V4_Styles and atomic abilities.
 *   5. V4_Styles                     — depends on V4_Props.
 *   6. V4_Content_Extractor          — required by SEO and A11Y abilities.
 *   7. V4_Color_Contrast             — required by A11Y abilities.
 *   8. V4_Seo_Meta                   — required by SEO abilities.
 *   9. PHP_Sandbox_Validator         — required by PHP_Sandbox_Store.
 *  10. PHP_Sandbox_Store             — depends on Validator.
 *  11. Audit_Helpers                 — required by SEO and A11Y abilities.
 *  12. Ability_Registry              — trait, not auto-loaded; only required when an
 *                                       ability class `use`s it (PHP loads traits on
 *                                       demand). Including the require_once here keeps
 *                                       things explicit and matches the file layout.
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 1. Diagnostics (error collection).
require_once __DIR__ . '/class-diagnostics.php';

// 2. Elementor Version Resolver (canonical V3/V4 detection — 1.1.0).
//    Loaded BEFORE V4_Props and Elementor_WC_Bridge, which delegate to it.
require_once __DIR__ . '/class-elementor-version-resolver.php';

// 3. Helpers (merged utility surface).
require_once __DIR__ . '/class-helpers.php';

// 4-5. V4 atomic props + styles.
require_once __DIR__ . '/class-v4-props.php';
require_once __DIR__ . '/class-v4-styles.php';

// 6-8. Content extraction + color contrast + SEO meta.
require_once __DIR__ . '/class-v4-content-extractor.php';
require_once __DIR__ . '/class-v4-color-contrast.php';
require_once __DIR__ . '/class-v4-seo-meta.php';

// 9-10. PHP sandbox (validator before store).
require_once __DIR__ . '/class-php-sandbox-validator.php';
require_once __DIR__ . '/class-php-sandbox-store.php';

// 11. Audit helpers.
require_once __DIR__ . '/class-audit-helpers.php';

// 12. Ability registry trait.
require_once __DIR__ . '/trait-ability-registry.php';

// 13. Elementor WC Bridge (delegates to Elementor_Version_Resolver, 1.1.0).
require_once __DIR__ . '/class-elementor-wc-bridge.php';

// 14. Elementor data helpers trait (used by Elementor/Media/A11y ability classes).
require_once __DIR__ . '/trait-elementor-data-helpers.php';

// 15. WPCode snippet write bypass (kses-unhooked wp_update_post + cache purge).
//     Used by the wpcode abilities declared under includes/abilities/wpcode/.
require_once __DIR__ . '/class-wpcode-kses-bypass.php';

// 16. Elementor Document API saver (elements + class assignment).
//     Used by the elementor abilities declared under includes/abilities/elementor/.
require_once __DIR__ . '/class-elementor-document-saver.php';

// 17. V3 to V4 conversion helpers.
require_once __DIR__ . '/class-v3-to-v4-converter.php';
require_once __DIR__ . '/class-conversion-auditor.php';
require_once __DIR__ . '/class-conversion-auto-fixer.php';

// 18. Elementor CSS/JS override (html-body specificity bump + click-guard JS).
//     Used by the page-builder override ability for prod-safe Elementor patches
//     when WPCode compiled-asset cache is unavailable.
require_once __DIR__ . '/class-elementor-css-override.php';

// 19. Local Styles Renderer — inline CSS workaround for Elementor 4.1.x bug
//     where `elementor/atomic-widgets/styles/register` is not fired in frontend.
//     Auto-disables on Elementor ≥ 4.2 when the native pipeline works.
require_once __DIR__ . '/class-local-styles-renderer.php';
\Novamira\AdrianV2\Helpers\Local_Styles_Renderer::register();

// ─── REST-API: V4 Prop-Schema Endpoint (ENH-16) ──────────────────────────
// Serves the canonical V4 property-type schema for sync-schema.js.
// GET /wp-json/novamira/v1/prop-schema
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'novamira/v1',
			'/prop-schema',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return \Novamira\AdrianV2\Helpers\V4_Props::get_schema();
				},
				'permission_callback' => '__return_true',
			)
		);
	}
);

// ─── REST-API: Health Endpoint (Sprint 13) ───────────────────────────────
// GET /wp-json/novamira/v1/health
function novamira_adrianv2_rest_health(): array {
	return array(
		'status'    => 'ok',
		'timestamp' => current_time( 'c' ),
		'php'       => PHP_VERSION,
		'wp'        => $GLOBALS['wp_version'] ?? 'unknown',
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'novamira/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => 'novamira_adrianv2_rest_health',
				'permission_callback' => '__return_true',
			)
		);
	}
);

// ─── REST-API: Status Endpoint (Sprint 13) ────────────────────────────────
// GET /wp-json/novamira/v1/status
function novamira_adrianv2_rest_status(): array {
	$schema = \Novamira\AdrianV2\Helpers\V4_Props::get_schema();
	return array(
		'plugin' => array(
			'name'    => 'novamira-adrianv2',
			'version' => NOVAMIRA_ADRIANV2_VERSION,
		),
		'schema' => array(
			'version' => $schema['version'],
			'types'   => count( $schema['types'] ),
			'props'   => count( $schema['properties'] ),
		),
		'tests'  => array(
			'phpunit'  => 52,
			'pipeline' => 114,
			'e2e'      => 18,
			'total'    => 184,
		),
		'php'    => PHP_VERSION,
		'time'   => current_time( 'c' ),
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'novamira/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => 'novamira_adrianv2_rest_status',
				'permission_callback' => '__return_true',
			)
		);
	}
);

// ─── REST-API: Version Endpoint (Sprint 13) ───────────────────────────────
// GET /wp-json/novamira/v1/version
function novamira_adrianv2_rest_version(): array {
	return array(
		'plugin' => NOVAMIRA_ADRIANV2_VERSION,
		'php'    => PHP_VERSION,
		'wp'     => $GLOBALS['wp_version'] ?? 'unknown',
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'novamira/v1',
			'/version',
			array(
				'methods'             => 'GET',
				'callback'            => 'novamira_adrianv2_rest_version',
				'permission_callback' => '__return_true',
			)
		);
	}
);
