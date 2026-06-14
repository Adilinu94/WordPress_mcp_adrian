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
 *   1. Diagnostics         — error log, must exist before anything tries to record.
 *   2. Helpers             — merged utility surface (read/write, v4 vars, guards).
 *   3. V4_Props            — required by V4_Styles and atomic abilities.
 *   4. V4_Styles           — depends on V4_Props.
 *   5. V4_Content_Extractor — required by SEO and A11Y abilities.
 *   6. V4_Color_Contrast   — required by A11Y abilities.
 *   7. V4_Seo_Meta         — required by SEO abilities.
 *   8. PHP_Sandbox_Validator — required by PHP_Sandbox_Store.
 *   9. PHP_Sandbox_Store   — depends on Validator.
 *  10. Audit_Helpers       — required by SEO and A11Y abilities.
 *  11. Ability_Registry    — trait, not auto-loaded; only required when an
 *                             ability class `use`s it (PHP loads traits on
 *                             demand). Including the require_once here keeps
 *                             things explicit and matches the file layout.
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Diagnostics (error collection).
require_once __DIR__ . '/class-diagnostics.php';

// 2. Helpers (merged utility surface).
require_once __DIR__ . '/class-helpers.php';

// 3-4. V4 atomic props + styles.
require_once __DIR__ . '/class-v4-props.php';
require_once __DIR__ . '/class-v4-styles.php';

// 5-7. Content extraction + color contrast + SEO meta.
require_once __DIR__ . '/class-v4-content-extractor.php';
require_once __DIR__ . '/class-v4-color-contrast.php';
require_once __DIR__ . '/class-v4-seo-meta.php';

// 8-9. PHP sandbox (validator before store).
require_once __DIR__ . '/class-php-sandbox-validator.php';
require_once __DIR__ . '/class-php-sandbox-store.php';

// 10. Audit helpers.
require_once __DIR__ . '/class-audit-helpers.php';

// 11. Ability registry trait.
require_once __DIR__ . '/trait-ability-registry.php';

// 12. Elementor data helpers trait (used by Elementor/Media/A11y ability classes).
require_once __DIR__ . '/trait-elementor-data-helpers.php';

// ─── REST-API: V4 Prop-Schema Endpoint (ENH-16) ──────────────────────────
// Serves the canonical V4 property-type schema for sync-schema.js.
// GET /wp-json/novamira/v1/prop-schema
add_action('rest_api_init', function () {
    register_rest_route('novamira/v1', '/prop-schema', [
        'methods'             => 'GET',
        'callback'            => function () {
            return \Novamira\AdrianV2\Helpers\V4_Props::get_schema();
        },
        'permission_callback' => '__return_true',
    ]);
});
