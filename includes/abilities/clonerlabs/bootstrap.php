<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ClonerLabs ability group — loads all classes and registers abilities.
 *
 * Load order matters: helpers first, then abilities that use them.
 *
 * @package Novamira_AdrianV2
 * @since   1.3.0
 */
add_action( 'wp_abilities_api_init', static function (): void {
	require_once __DIR__ . '/class-clonerlabs-style-minifier.php';
	require_once __DIR__ . '/class-clonerlabs-media-handler.php';
	require_once __DIR__ . '/class-clonerlabs-global-styles.php';
	require_once __DIR__ . '/class-import-clonerlabs-page.php';
	require_once __DIR__ . '/class-repair-clonerlabs-page.php';
	require_once __DIR__ . '/class-import-clonerlabs-batch.php';
	require_once __DIR__ . '/class-import-clonerlabs-library.php';
	require_once __DIR__ . '/class-convert-html-to-elementor.php';

	\Novamira\AdrianV2\Abilities\ClonerLabs\Import_ClonerLabs_Page::register();
	\Novamira\AdrianV2\Abilities\ClonerLabs\Repair_ClonerLabs_Page::register();
	\Novamira\AdrianV2\Abilities\ClonerLabs\Import_ClonerLabs_Batch::register();
	\Novamira\AdrianV2\Abilities\ClonerLabs\Import_ClonerLabs_Library::register();
	\Novamira\AdrianV2\Abilities\ClonerLabs\Convert_HTML_To_Elementor::register();
}, 20 );
