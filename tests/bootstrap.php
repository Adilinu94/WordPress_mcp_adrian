<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for Novamira AdrianV2 tests.
 *
 * Loads Composer autoloader (for PHPUnit + PSR-4 test namespace)
 * and the V3_To_V4_Converter class.
 */

// Define ABSPATH to prevent the converter's exit guard from killing PHPUnit.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', true );
}

// 1. Composer autoloader — required for PHPUnit itself and PSR-4 test discovery.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// 2. Converter class (already in classmap, but explicit load avoids edge cases).
require_once __DIR__ . '/../includes/helpers/class-v3-to-v4-converter.php';

// 3. Local_Styles_Renderer — needs WP stubs (add_action, apply_filters) before load.
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, mixed $cb, int $prio = 10, int $n = 1 ): bool { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { // phpcs:ignore
		return $value;
	}
}
if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
	define( 'ELEMENTOR_VERSION', '4.1.3' ); // Simulate 4.1.x — workaround must activate.
}
require_once __DIR__ . '/../includes/helpers/class-local-styles-renderer.php';

// 3. AutoFixer and Auditor classes.
require_once __DIR__ . '/../includes/helpers/class-conversion-auto-fixer.php';
require_once __DIR__ . '/../includes/helpers/class-conversion-auditor.php';

// 4. Abilities under test.
require_once __DIR__ . '/../includes/abilities/elementor/class-design-token-remap.php';
require_once __DIR__ . '/../includes/abilities/elementor/class-elementor-edit-element.php';
require_once __DIR__ . '/../includes/abilities/elementor-templates/class-kit-manifest.php';

// 5. Kit helper classes needed for unit tests.
require_once __DIR__ . '/../includes/abilities/elementor-templates/class-kit-page-creator.php';
require_once __DIR__ . '/../includes/abilities/elementor-templates/class-kit-menu-builder.php';
require_once __DIR__ . '/../includes/abilities/elementor-templates/class-kit-rollback.php';
require_once __DIR__ . '/../includes/abilities/elementor-templates/class-kit-plugin-installer.php';

// 6. WP stubs required by kit helper tests.
// In-memory option store for get_option / update_option.
$GLOBALS['_novamira_test_options'] = [];

// Minimal $wpdb stub (used by Kit_Page_Creator::resolve_template_ref).
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class {
		public string $postmeta = 'wp_postmeta';

		public function get_var( ?string $sql ): ?string {
			return null; // No DB in unit test environment.
		}

		public function prepare( string $sql, mixed ...$args ): string {
			return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
		}
	};
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed { // phpcs:ignore
		return $GLOBALS['_novamira_test_options'][ $option ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool { // phpcs:ignore
		$GLOBALS['_novamira_test_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string { // phpcs:ignore
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $post_id ): string|false { // phpcs:ignore
		return $post_id > 0 ? "https://example.com/?p={$post_id}" : false;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( string $field, mixed $value, string $taxonomy = '' ): object|false { // phpcs:ignore
		return false; // No terms in test environment.
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( mixed $term, string $taxonomy = '' ): string|false { // phpcs:ignore
		return false;
	}
}
if ( ! function_exists( 'get_theme_mod' ) ) {
	function get_theme_mod( string $name, mixed $default = false ): mixed { // phpcs:ignore
		return $default;
	}
}
if ( ! function_exists( 'set_theme_mod' ) ) {
	function set_theme_mod( string $name, mixed $value ): void {} // phpcs:ignore
}
if ( ! function_exists( 'switch_theme' ) ) {
	function switch_theme( string $stylesheet ): void {} // phpcs:ignore
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( bool $hard = true ): void {} // phpcs:ignore
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $post_id, bool $force_delete = false ): mixed { // phpcs:ignore
		return $post_id;
	}
}
if ( ! function_exists( 'wp_delete_nav_menu' ) ) {
	function wp_delete_nav_menu( mixed $menu ): bool|\WP_Error { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'deactivate_plugins' ) ) {
	function deactivate_plugins( mixed $plugins, bool $silent = false, mixed $network_wide = null ): void {} // phpcs:ignore
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
require_once __DIR__ . '/../includes/abilities/elementor/class-list-v3-pages.php';
require_once __DIR__ . '/../includes/abilities/elementor/class-fix-orphan-styles.php';
require_once __DIR__ . '/../includes/abilities/clonerlabs/class-clonerlabs-style-minifier.php';
