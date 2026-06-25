<?php
declare(strict_types=1);

/**
 * WPCode abilities bootstrap — mirrors includes/abilities/php-sandbox/bootstrap.php.
 *
 * Loads the WPCode_Snippets ability class and registers its 7 abilities
 * (list, get, create, update, set-status, duplicate, delete) plus the
 * WpCode_Check_Setup readonly probe (novamira-adrianv2/wpcode-check-setup)
 * against the WordPress Abilities API. Silently no-ops if WPCode is not
 * active so the rest of the plugin remains unaffected.
 *
 * @package novamira-adrianv2
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$novamira_adrianv2_wpcode_files = [
	__DIR__ . '/class-wpcode-snippets.php',
	__DIR__ . '/class-wpcode-check-setup.php',
	__DIR__ . '/class-inject-wpcode-snippet.php',
];

foreach ( $novamira_adrianv2_wpcode_files as $novamira_adrianv2_wpcode_file ) {
	if ( file_exists( $novamira_adrianv2_wpcode_file ) ) {
		require_once $novamira_adrianv2_wpcode_file;
	}
}

// Only attempt registration if WPCode_Snippets is loaded and exposes register().
if ( class_exists( 'Novamira\\AdrianV2\\Abilities\\WpCode\\WpCode_Snippets' ) && method_exists( 'Novamira\\AdrianV2\\Abilities\\WpCode\\WpCode_Snippets', 'register' ) ) {
	Novamira\AdrianV2\Abilities\WpCode\WpCode_Snippets::register();
}

// Also register the readonly WPCode setup probe — it ships without a class
// dependency on WPCode itself (the probe gracefully reports the plugin as
// inactive if the WPCode class / constant is not present).
if ( class_exists( 'Novamira\\AdrianV2\\Abilities\\WpCode\\WpCode_Check_Setup' ) && method_exists( 'Novamira\\AdrianV2\\Abilities\\WpCode\\WpCode_Check_Setup', 'register' ) ) {
	Novamira\AdrianV2\Abilities\WpCode\WpCode_Check_Setup::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\WpCode\Inject_Wpcode_Snippet' ) && method_exists( 'Novamira\AdrianV2\Abilities\WpCode\Inject_Wpcode_Snippet', 'register' ) ) {
	Novamira\AdrianV2\Abilities\WpCode\Inject_Wpcode_Snippet::register();
}
