<?php
declare(strict_types=1);

/**
 * V4_Setup_Atomic_Editor — Ensure Elementor V4 Atomic Editor is ready.
 *
 * Registers two abilities:
 *   1. v4-setup-atomic-editor — Checks/activates Elementor experiments,
 *      verifies atomic widget availability, and returns a capability report.
 *   2. v4-setup-foundation    — Sets up a page for V4 atomic editing:
 *      creates default container, applies theme settings.
 *
 * @package Novamira_AdrianV2
 * @since   1.8.0
 */

namespace Novamira\AdrianV2\Abilities\V4Management;

use Novamira\AdrianV2\Helpers\Elementor_Version_Resolver;
use Novamira\AdrianV2\Helpers\V4_Props;
use Novamira\AdrianV2\Helpers\Elementor_Data_Helpers;
use Novamira\AdrianV2\Helpers\Ability_Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static ability registrar for V4 atomic editor setup.
 */
class V4_Setup_Atomic_Editor {
    use Elementor_Data_Helpers;
    use Ability_Registry;

    /** @var string[] */
    private static array $ability_names = [];

    /**
     * Register both abilities.
     */
    public static function register(): void {
        self::register_setup_atomic_editor();
        self::register_setup_foundation();
    }

    // =========================================================================
    // v4-setup-atomic-editor
    // =========================================================================

    private static function register_setup_atomic_editor(): void {
        $name = 'novamira-adrianv2/v4-setup-atomic-editor';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('V4 Setup Atomic Editor', 'novamira-adrianv2'),
            'description'         => __(
                'Checks and configures the Elementor V4 Atomic Editor environment. '
                . 'Reports Elementor version, atomic widget availability, active experiments, '
                . 'and global classes status. Can optionally activate required experiments. '
                . 'Use this before starting a V4 build session to ensure the environment is ready.',
                'novamira-adrianv2'
            ),
            'category'            => 'adrianv2-v4-management',
            'execute_callback'    => [self::class, 'execute_setup_atomic_editor'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'activate_experiments' => [
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => __('If true, attempts to activate required Elementor experiments.', 'novamira-adrianv2'),
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'elementor_version'          => ['type' => 'string'],
                    'is_v4'                      => ['type' => 'boolean'],
                    'atomic_supported'           => ['type' => 'boolean'],
                    'global_classes_available'   => ['type' => 'boolean'],
                    'experiments'                => ['type' => 'object'],
                    'experiments_activated'      => ['type' => 'array'],
                    'recommendations'            => ['type' => 'array'],
                    'ready'                      => ['type' => 'boolean'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * Execute v4-setup-atomic-editor.
     *
     * @param array|null $input
     * @return array
     */
    public static function execute_setup_atomic_editor($input = null): array {
        $input    = $input ?? [];
        $activate = (bool) ($input['activate_experiments'] ?? false);

        $elementor_active  = class_exists('\\Elementor\\Plugin');
        $version_string    = Elementor_Version_Resolver::site_version_string();
        $is_v4             = Elementor_Version_Resolver::site_is_v4();
        $atomic_supported  = V4_Props::is_atomic_supported();
        $global_classes    = class_exists(
            '\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository'
        );

        // Check experiment status.
        $experiments = self::get_experiment_status();
        $recommendations = [];
        $activated = [];

        // Required experiments for V4 atomic editing.
        $required_experiments = [
            'container'           => 'Flexbox Container',
            'e_atomic_elements'   => 'Atomic Elements',
            'atomic_widgets'      => 'Atomic Widgets',
            'editor_v2'           => 'Editor V2',
            'e_optimized_markup'  => 'Optimized Markup',
        ];

        foreach ($required_experiments as $feature => $label) {
            $status = $experiments[$feature] ?? 'unknown';
            if ($status !== 'active') {
                $recommendations[] = sprintf(
                    'Experiment "%s" (%s) is %s. %s',
                    $label,
                    $feature,
                    $status,
                    $activate ? 'Attempting activation...' : 'Use activate_experiments:true to auto-activate.'
                );

                if ($activate) {
                    $result = self::activate_experiment($feature);
                    $activated[] = [
                        'feature' => $feature,
                        'label'   => $label,
                        'success' => $result,
                    ];
                }
            }
        }

        // Check if Elementor kit is configured.
        $kit_id = (int) get_option('elementor_active_kit', 0);
        if (!$kit_id) {
            $recommendations[] = 'No active Elementor kit found. Create a kit in Elementor → Settings.';
        }

        $ready = $is_v4 && $atomic_supported && empty($recommendations);

        return [
            'elementor_version'        => $version_string,
            'is_v4'                    => $is_v4,
            'atomic_supported'         => $atomic_supported,
            'global_classes_available' => $global_classes,
            'active_kit_id'            => $kit_id ?: null,
            'experiments'              => $experiments,
            'experiments_activated'    => $activated,
            'recommendations'          => $recommendations,
            'ready'                    => $ready,
            'summary'                  => $ready
                ? 'V4 Atomic Editor is ready.'
                : sprintf(
                    'V4 Atomic Editor needs attention: %d recommendation(s).',
                    count($recommendations)
                ),
        ];
    }

    /**
     * Get status of relevant Elementor experiments.
     *
     * @return array<string, string>
     */
    private static function get_experiment_status(): array {
        $status = [];

        if (!class_exists('\\Elementor\\Plugin')) {
            return $status;
        }

        $elementor = \Elementor\Plugin::instance();
        if (
            !isset($elementor->experiments)
            || !is_object($elementor->experiments)
            || !method_exists($elementor->experiments, 'is_feature_active')
        ) {
            return $status;
        }

        $features = [
            'container', 'e_atomic_elements', 'atomic_widgets',
            'editor_v2', 'e_optimized_markup', 'e_font_icon_svg',
            'nested-elements', 'container_grid',
        ];

        foreach ($features as $feature) {
            try {
                $status[$feature] = $elementor->experiments->is_feature_active($feature)
                    ? 'active' : 'inactive';
            } catch (\Throwable $e) {
                $status[$feature] = 'error';
            }
        }

        return $status;
    }

    /**
     * Attempt to activate a single Elementor experiment.
     *
     * @param string $feature
     * @return bool
     */
    private static function activate_experiment(string $feature): bool {
        if (!class_exists('\\Elementor\\Plugin')) {
            return false;
        }

        try {
            $elementor = \Elementor\Plugin::instance();
            if (
                isset($elementor->experiments)
                && is_object($elementor->experiments)
                && method_exists($elementor->experiments, 'set_feature_default_state')
            ) {
                $elementor->experiments->set_feature_default_state($feature, 'active');
                return true;
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Novamira] Cannot activate experiment ' . $feature . ': experiments API not available.');
            }
            return false;
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[Novamira] Failed to activate experiment ' . $feature . ': ' . $e->getMessage());
            }
            return false;
        }
    }

    // =========================================================================
    // v4-setup-foundation
    // =========================================================================

    private static function register_setup_foundation(): void {
        $name = 'novamira-adrianv2/v4-setup-foundation';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('V4 Setup Foundation', 'novamira-adrianv2'),
            'description'         => __(
                'Sets up a WordPress page with a V4 atomic foundation container. '
                . 'Creates the page if needed, applies the Elementor template, and '
                . 'inserts a root e-flexbox container ready for atomic widget building. '
                . 'Returns the page ID and root container element ID.',
                'novamira-adrianv2'
            ),
            'category'            => 'adrianv2-v4-management',
            'execute_callback'    => [self::class, 'execute_setup_foundation'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [
                        'type'        => 'integer',
                        'description' => __('Existing post ID to set up. Omit to create a new page.', 'novamira-adrianv2'),
                    ],
                    'title'      => [
                        'type'        => 'string',
                        'default'     => 'V4 Atomic Page',
                        'description' => __('Page title (only used when creating a new page).', 'novamira-adrianv2'),
                    ],
                    'post_type'  => [
                        'type'        => 'string',
                        'default'     => 'page',
                        'description' => __('Post type for new page.', 'novamira-adrianv2'),
                    ],
                    'status'     => [
                        'type'        => 'string',
                        'enum'        => ['draft', 'publish'],
                        'default'     => 'draft',
                        'description' => __('Post status.', 'novamira-adrianv2'),
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'post_id'             => ['type' => 'integer'],
                    'root_element_id'     => ['type' => 'string'],
                    'title'               => ['type' => 'string'],
                    'permalink'           => ['type' => 'string'],
                    'elementor_edit_url'  => ['type' => 'string'],
                    'created'             => ['type' => 'boolean'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * Execute v4-setup-foundation.
     *
     * @param array|null $input
     * @return array|\WP_Error
     */
    public static function execute_setup_foundation($input = null) {
        $input = $input ?? [];

        // V4 guard.
        if (!Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error(
                'v4_required',
                sprintf(
                    __('V4 foundation requires Elementor 4.0+. Detected: %s.', 'novamira-adrianv2'),
                    Elementor_Version_Resolver::site_version_string()
                )
            );
        }

        $post_id   = (int) ($input['post_id'] ?? 0);
        $title     = sanitize_text_field((string) ($input['title'] ?? 'V4 Atomic Page'));
        $post_type = sanitize_text_field((string) ($input['post_type'] ?? 'page'));
        $status    = sanitize_text_field((string) ($input['status'] ?? 'draft'));

        $created = false;

        // Create or verify the page.
        if ($post_id > 0) {
            $post = get_post($post_id);
            if (!$post) {
                return new \WP_Error('post_not_found', "Post $post_id not found.");
            }
        } else {
            $post_id = wp_insert_post([
                'post_title'   => $title,
                'post_type'    => $post_type,
                'post_status'  => $status,
                'post_content' => '',
            ], true);

            if (is_wp_error($post_id)) {
                return $post_id;
            }
            $created = true;
        }

        // Set Elementor edit mode.
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);

        // Build root flexbox container.
        $root_id      = self::generate_id();
        $style_id     = 'e-' . $root_id . '-root';

        $root_element = [
            'id'              => $root_id,
            'elType'          => 'e-flexbox',
            'widgetType'      => 'e-flexbox',
            'isInner'         => false,
            'settings'        => [
                'classes' => V4_Props::classes([$style_id]),
                'tag'     => V4_Props::string('div'),
            ],
            'elements'        => [],
            'styles'          => [
                $style_id => [
                    'id'       => $style_id,
                    'label'    => 'Root Container',
                    'type'     => 'class',
                    'variants' => [[
                        'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                        'props'      => [
                            'display'        => V4_Props::string('flex'),
                            'flex-direction' => V4_Props::string('column'),
                            'width'          => V4_Props::size(100, '%'),
                            'min-height'     => V4_Props::size(100, 'vh'),
                            'padding-block-start'  => V4_Props::size(40, 'px'),
                            'padding-block-end'    => V4_Props::size(40, 'px'),
                            'padding-inline-start' => V4_Props::size(20, 'px'),
                            'padding-inline-end'   => V4_Props::size(20, 'px'),
                        ],
                        'custom_css' => null,
                    ]],
                ],
            ],
            'interactions'    => [],
            'editor_settings' => [],
            'version'         => ELEMENTOR_VERSION,
        ];

        // Save.
        $elements = [$root_element];
        $save = self::write_page($post_id, $elements);
        if (is_wp_error($save)) {
            return $save;
        }

        $permalink         = get_permalink($post_id) ?: '';
        $elementor_edit_url = admin_url('post.php?post=' . $post_id . '&action=elementor');

        return [
            'post_id'            => $post_id,
            'root_element_id'    => $root_id,
            'title'              => get_the_title($post_id),
            'permalink'          => $permalink,
            'elementor_edit_url' => $elementor_edit_url,
            'created'            => $created,
            'summary'            => sprintf(
                '%s page %d with V4 root container %s.',
                $created ? 'Created' : 'Setup',
                $post_id,
                $root_id
            ),
        ];
    }
}
