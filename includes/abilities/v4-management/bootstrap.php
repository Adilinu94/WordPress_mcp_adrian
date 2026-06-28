<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

/**
 * Bootstrap for V4 Management abilities.
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */

add_action('wp_abilities_api_init', static function () {
    require_once __DIR__ . '/class-sync-schema.php';
    \Novamira\AdrianV2\Abilities\V4Management\Sync_Schema::register();

    require_once __DIR__ . '/class-rollback-build.php';
    \Novamira\AdrianV2\Abilities\V4Management\Rollback_Build::register();

    require_once __DIR__ . '/class-v4-setup-atomic-editor.php';
    \Novamira\AdrianV2\Abilities\V4Management\V4_Setup_Atomic_Editor::register();

    require_once __DIR__ . '/class-v4-batch-build-page.php';
    \Novamira\AdrianV2\Abilities\V4Management\V4_Batch_Build_Page::register();

    require_once __DIR__ . '/class-v4-security.php';
    \Novamira\AdrianV2\Abilities\V4Management\V4_Security::register();
}, 20);
