<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Plugin Name:       Novamira AdrianV2
 * Plugin URI:        https://www.novamira.ai
 * Description:       Consolidated AI abilities for Novamira. Combines the original Adrians toolkit and the Adrians Extra add-on into a single, well-organized plugin with per-group bootstrapping, proper error isolation, and clean ability-category registration. Requires the Novamira Base plugin and Elementor.
 * Version:           1.10.0
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Requires Plugins: novamira, elementor
 * Author:            Dynamic.ooo
 * Author URI:        https://www.novamira.ai
 * License:           AGPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:       novamira-adrianv2
 * Copyright:         Ovation S.r.l.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
    exit();
}

// -----------------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------------

define(constant_name: 'NOVAMIRA_ADRIANV2_VERSION', value: '1.10.0');
define(constant_name: 'NOVAMIRA_ADRIANV2_FILE', value: __FILE__);
define(constant_name: 'NOVAMIRA_ADRIANV2_DIR', value: plugin_dir_path(__FILE__));
define(constant_name: 'NOVAMIRA_ADRIANV2_MIN_PHP_VERSION', value: '8.0');
define(constant_name: 'NOVAMIRA_ADRIANV2_MIN_WP_VERSION', value: '6.9');
define(constant_name: 'NOVAMIRA_ADRIANV2_BUNDLE_NS', value: 'Novamira\AdrianV2');

// -----------------------------------------------------------------------------
// Dependency state (static, like the official Novamira plugin)
// -----------------------------------------------------------------------------

/**
 * Shared storage for the current runtime dependency error.
 *
 * @return \WP_Error|null
 */
function novamira_adrianv2_dependency_error(?\WP_Error $error = null)
{
    static $current = null;
    if ($error !== null) {
        $current = $error;
    }
    return $current;
}

function novamira_adrianv2_set_dependency_error(\WP_Error $error): void
{
    novamira_adrianv2_dependency_error($error);
}

/**
 * Return the current runtime dependency error, if any.
 *
 * @return \WP_Error|null
 */
function novamira_adrianv2_get_dependency_error()
{
    return novamira_adrianv2_dependency_error();
}

/**
 * Whether every runtime dependency is met right now.
 */
function novamira_adrianv2_dependencies_ok(): bool
{
    return novamira_adrianv2_check_dependencies() === null;
}

// -----------------------------------------------------------------------------
// Dependency check (PHP, WP, Elementor, Novamira Base, Abilities API)
// -----------------------------------------------------------------------------

/**
 * Verify every runtime dependency. Returns null on success, or a WP_Error
 * describing the first missing dependency on failure.
 *
 * @return \WP_Error|null
 */
function novamira_adrianv2_check_dependencies()
{
    if (version_compare(PHP_VERSION, NOVAMIRA_ADRIANV2_MIN_PHP_VERSION, '<')) {
        return new \WP_Error(
            'novamira_adrianv2_php_too_old',
            sprintf(
                /* translators: 1: required PHP version, 2: actual PHP version */
                __('Novamira AdrianV2 requires PHP %1$s or higher (you are running %2$s).', 'novamira-adrianv2'),
                NOVAMIRA_ADRIANV2_MIN_PHP_VERSION,
                PHP_VERSION
            )
        );
    }

    global $wp_version;
    if (version_compare((string) $wp_version, NOVAMIRA_ADRIANV2_MIN_WP_VERSION, '<')) {
        return new \WP_Error(
            'novamira_adrianv2_wp_too_old',
            sprintf(
                /* translators: 1: required WP version, 2: actual WP version */
                __('Novamira AdrianV2 requires WordPress %1$s or higher (you are running %2$s).', 'novamira-adrianv2'),
                NOVAMIRA_ADRIANV2_MIN_WP_VERSION,
                (string) $wp_version
            )
        );
    }

    if (!did_action('elementor/loaded')) {
        return new \WP_Error(
            'novamira_adrianv2_elementor_missing',
            __('Novamira AdrianV2 requires the Elementor plugin to be installed and active.', 'novamira-adrianv2')
        );
    }

    // Novamira Base provides the MCP adapter and the ability registry glue.
    if (!function_exists('novamira_load_bundled_dependencies') && !function_exists('wp_register_ability_category')) {
        return new \WP_Error(
            'novamira_adrianv2_novamira_base_missing',
            __('Novamira AdrianV2 requires the Novamira Base plugin to be installed and active.', 'novamira-adrianv2')
        );
    }

    // The Abilities API is core in WordPress 6.9+/7.0; only missing on older WP.
    if (!function_exists('wp_register_ability')) {
        return new \WP_Error(
            'novamira_adrianv2_abilities_api_missing',
            sprintf(
                /* translators: %s: required WordPress version */
                __('Novamira AdrianV2 requires the WordPress Abilities API (core in WordPress %s+).', 'novamira-adrianv2'),
                NOVAMIRA_ADRIANV2_MIN_WP_VERSION
            )
        );
    }

    return null;
}

// -----------------------------------------------------------------------------
// Activation gate: block activation when a dependency is missing.
// -----------------------------------------------------------------------------

/**
 * Block plugin activation when a dependency is missing, and persist the
 * error so admin notices can show the same message until it is fixed.
 */
function novamira_adrianv2_activation_check(): void
{
    $error = novamira_adrianv2_check_dependencies();
    if ($error === null) {
        // Phase 1 (1.1.0): Install 8 adrianv2-* skills on activation.
        novamira_adrianv2_install_skills();
        return;
    }
    novamira_adrianv2_set_dependency_error($error);

    if (function_exists('deactivate_plugins')) {
        deactivate_plugins(plugin_basename(__FILE__));
    }

    wp_die(
        '<p>' . esc_html($error->get_error_message()) . '</p>',
        esc_html__('Novamira AdrianV2 installation is incomplete', 'novamira-adrianv2'),
        ['back_link' => true]
    );
}

register_activation_hook(__FILE__, 'novamira_adrianv2_activation_check');

/**
 * Install 8 adrianv2-* skills on plugin activation (idempotent).
 *
 * Called from the activation hook when all dependencies pass.
 * Also called on 'init' (priority 20) if the stored version is outdated
 * (handles plugin-update scenarios where activation hook doesn't fire).
 *
 * @since 1.1.0
 */
function novamira_adrianv2_install_skills(): void
{
    $installer_path = __DIR__ . '/includes/skills/installer.php';
    if (!file_exists($installer_path)) {
        return;
    }
    require_once $installer_path;
    if (!class_exists('\\Novamira\\AdrianV2\\Skills\\Installer')) {
        return;
    }

    $result = \Novamira\AdrianV2\Skills\Installer::install();

    // Log errors so admins can diagnose silent installation failures.
    if (!empty($result['errors'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Novamira AdrianV2] Skill installation errors: ' . implode('; ', $result['errors']));
        }
        // Don't set version — installer will retry on next page load.
        return;
    }

    // Record installed version so we don't re-run on every page load.
    update_option('novamira_adrianv2_skills_installed_version', NOVAMIRA_ADRIANV2_VERSION);
}

/**
 * 'init'-hook wrapper for skill installation on plugin update.
 *
 * Plugins_loaded is too early — wp_insert_post calls is_user_logged_in()
 * via _count_posts_cache_key, and pluggable functions aren't loaded yet.
 * 'init' (priority 20) runs after pluggable.php is loaded.
 *
 * Only runs once per version via novamira_adrianv2_skills_installed_version.
 *
 * @since 1.1.0
 */
function novamira_adrianv2_install_skills_on_init(): void
{
    $installed_ver = get_option('novamira_adrianv2_skills_installed_version', '');
    if ($installed_ver === NOVAMIRA_ADRIANV2_VERSION) {
        return;
    }
    novamira_adrianv2_install_skills();
}

// -----------------------------------------------------------------------------
// Runtime admin notices (PHP/WP/Elementor/Novamira Base missing).
// -----------------------------------------------------------------------------

/**
 * Render a persistent admin error when a runtime dependency is missing.
 */
function novamira_adrianv2_render_dependency_notice(): void
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    $error = novamira_adrianv2_get_dependency_error();
    if ($error === null) {
        $error = novamira_adrianv2_check_dependencies();
        if ($error !== null) {
            novamira_adrianv2_set_dependency_error($error);
        }
    }
    if ($error === null) {
        return;
    }

    wp_admin_notice(esc_html($error->get_error_message()), [
        'type'        => 'error',
        'dismissible' => false,
    ]);
}

add_action('admin_notices', 'novamira_adrianv2_render_dependency_notice');
add_action('network_admin_notices', 'novamira_adrianv2_render_dependency_notice');

// -----------------------------------------------------------------------------
// Runtime bootstrap (plugins_loaded): load helpers, categories, abilities
// -----------------------------------------------------------------------------

require_once __DIR__ . '/includes/helpers/bootstrap.php';
require_once __DIR__ . '/includes/categories.php';

// Phase 1 (1.1.0): Register V2 server-instructions filter for discover-abilities.
require_once __DIR__ . '/includes/integrations/server-instructions.php';
\Novamira\AdrianV2\Integrations\Server_Instructions::register();

// Phase 1 (1.1.0): Schedule skill installation on 'init' (NOT here —
// wp_insert_post calls is_user_logged_in() via _count_posts_cache_key,
// which isn't available during plugins_loaded). Activation hook path
// is unaffected because it runs in wp-admin context where pluggable
// functions are already loaded.
add_action('init', 'novamira_adrianv2_install_skills_on_init', 20);

require_once __DIR__ . '/includes/bootstrap.php';
