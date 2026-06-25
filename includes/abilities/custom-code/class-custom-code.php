<?php
declare(strict_types=1);

/**
 * V4 Custom Code Abilities — Port from EMCP class-custom-code-abilities.php
 *
 * Registers tools for injecting custom CSS, JavaScript, and site-wide
 * code snippets. CSS/JS injection works at element or page level.
 * Site-wide snippets use Elementor Pro's elementor_snippet CPT.
 *
 * Architecture: Fully static (no constructor dependencies). Uses the
 * shared Elementor_Data_Helpers trait for page read/write/find/update.
 * Zero hard dependency on Novamira Pro — gates on ELEMENTOR_PRO_VERSION
 * for Pro-only tools.
 *
 * Key differences from EMCP source:
 * - add-custom-js creates the HTML widget element inline (no factory dependency).
 * - Page-level CSS uses get_page_settings / save_page_settings from the trait.
 * - CSS sanitization is identical to EMCP (strip PHP/script tags, neutralize </style>).
 * - Permission callbacks are static methods referenced via [__CLASS__, ...].
 *
 * @package Extra
 * @since   1.6.0
 */

namespace Novamira\AdrianV2\Abilities\CustomCode;

use Novamira\AdrianV2\Helpers\V4_Props;
use Novamira\AdrianV2\Helpers\V4_Styles;
use Novamira\AdrianV2\Helpers\V4_Color_Contrast;
use Novamira\AdrianV2\Helpers\V4_Content_Extractor;
use Novamira\AdrianV2\Helpers\V4_Seo_Meta;
use Novamira\AdrianV2\Helpers\PHP_Sandbox_Store;
use Novamira\AdrianV2\Helpers\PHP_Sandbox_Validator;
use Novamira\AdrianV2\Helpers\Ability_Registry;
use Novamira\AdrianV2\Helpers\Elementor_Data_Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static ability registrar for custom code injection.
 *
 * @since 1.6.0
 */
class Custom_Code {
    use Elementor_Data_Helpers;
    use Ability_Registry;

    /** @var string[] */
    private static array $ability_names = [];

    /**
     * Register all custom code abilities.
     *
     * Call once from wp_abilities_api_init. add-custom-js registers
     * unconditionally (uses free HTML widget). The remaining tools
     * require Elementor Pro.
     */
    public static function register(): void {
        // Custom JS works with free Elementor (uses HTML widget).
        self::register_add_custom_js();

        // Pro-only tools require Elementor Pro.
        if (defined('ELEMENTOR_PRO_VERSION')) {
            self::register_add_custom_css();
            self::register_add_code_snippet();
            self::register_list_code_snippets();
        }
    }

    // =========================================================================
    // Permission callbacks
    // =========================================================================

    /**
     * Permission check for page/element editing.
     *
     * @param array|null $input The input data.
     * @return bool
     */
    public static function check_edit_permission($input = null): bool {
        if (!current_user_can('edit_posts')) {
            return false;
        }

        $post_id = absint($input['post_id'] ?? 0);
        if ($post_id && !current_user_can('edit_post', $post_id)) {
            return false;
        }

        return true;
    }

    /**
     * Permission check for injecting raw JavaScript into a page.
     *
     * Requires per-post edit rights PLUS unfiltered_html, since this tool
     * injects a <script> tag that WordPress would otherwise strip.
     *
     * @param array|null $input The input data.
     * @return bool
     */
    public static function check_js_permission($input = null): bool {
        return self::check_edit_permission($input) && current_user_can('unfiltered_html');
    }

    /**
     * Permission check for creating site-wide code snippets.
     *
     * @return bool
     */
    public static function check_snippet_permission(): bool {
        return current_user_can('manage_options') && current_user_can('unfiltered_html');
    }

    /**
     * Permission check for listing snippets (read-only).
     *
     * @return bool
     */
    public static function check_manage_permission(): bool {
        return current_user_can('manage_options');
    }

    // =========================================================================
    // CSS sanitization helper
    // =========================================================================

    /**
     * Sanitize CSS: strip PHP tags, script tags, and neutralize </style>.
     *
     * The </style> neutralization is bypass-proof: it loops until no more
     * matches are found, preventing reconstruction attacks
     * (e.g. "</sty</stylele>" reconstructing to "</style>").
     *
     * @param string $css Raw CSS input.
     * @return string Sanitized CSS.
     */
    private static function sanitize_css(string $css): string {
        // Strip PHP tags.
        $css = preg_replace('/<\?(=|php)(.+?)\?>/is', '', $css);

        // Strip script tags.
        $css = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $css);

        // Neutralize </style> — the only way to break out of a <style> block.
        $previous = null;
        while ($previous !== $css) {
            $previous = $css;
            $css      = preg_replace('#</\s*style#i', '', $css);
        }

        return $css;
    }

    // =========================================================================
    // add-custom-css (Pro only)
    // =========================================================================

    /**
     * Registers the add-custom-css ability.
     */
    private static function register_add_custom_css(): void {
        $name = 'novamira-adrianv2/add-custom-css';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Add Custom CSS', 'novamira-adrianv2'),
            'description'         => __('Adds custom CSS to a specific element or to the entire page. Requires Elementor Pro. For element-level CSS, use the keyword "selector" as a placeholder for the element\'s CSS wrapper (e.g. "selector .heading { color: red; }" or "selector:hover { transform: scale(1.05); }"). For page-level CSS, omit element_id. Appends to existing CSS by default; set replace=true to overwrite.', 'novamira-adrianv2'),
            'category'            => 'adrianv2-elementor',
            'execute_callback'    => [__CLASS__, 'execute_add_custom_css'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [
                        'type'        => 'integer',
                        'description' => __('The post/page ID.', 'novamira-adrianv2'),
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => __('Optional element ID to apply CSS to. If omitted, CSS is applied at the page level.', 'novamira-adrianv2'),
                    ],
                    'css'        => [
                        'type'        => 'string',
                        'description' => __('CSS rules to add. Use "selector" as the element wrapper placeholder for element-level CSS.', 'novamira-adrianv2'),
                    ],
                    'replace'    => [
                        'type'        => 'boolean',
                        'description' => __('If true, replaces existing custom CSS instead of appending. Default: false.', 'novamira-adrianv2'),
                    ],
                ],
                'required'   => ['post_id', 'css'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'target'  => ['type' => 'string'],
                    'css'     => ['type' => 'string'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * Executes the add-custom-css ability.
     *
     * @param array $input The input parameters.
     * @return array|\WP_Error
     */
    public static function execute_add_custom_css($input) {
        $post_id    = absint($input['post_id'] ?? 0);
        $element_id = sanitize_text_field($input['element_id'] ?? '');
        $css        = $input['css'] ?? '';
        $replace    = !empty($input['replace']);

        if (!$post_id || $css === '') {
            return new \WP_Error('missing_params', __('post_id and css are required.', 'novamira-adrianv2'));
        }

        $css = self::sanitize_css($css);

        if ($element_id !== '') {
            // Element-level custom CSS.
            $page = self::read_page($post_id);
            if ($page['error'] !== null) {
                return new \WP_Error('read_failed', $page['error']);
            }

            $element = self::find_element($page['elements'], $element_id);
            if ($element === null) {
                return new \WP_Error('element_not_found', __('Element not found.', 'novamira-adrianv2'));
            }

            $existing_css = $element['settings']['custom_css'] ?? '';
            $new_css      = $replace ? $css : trim($existing_css . "\n" . $css);

            $updated = self::update_element_settings(
                $page['elements'],
                $element_id,
                ['custom_css' => $new_css]
            );

            if (!$updated) {
                return new \WP_Error('update_failed', __('Failed to update element settings.', 'novamira-adrianv2'));
            }

            $save = self::write_page($post_id, $page['elements']);
            if (is_wp_error($save)) {
                return $save;
            }

            return [
                'success' => true,
                'target'  => 'element:' . $element_id,
                'css'     => $new_css,
            ];
        }

        // Page-level custom CSS.
        $page_settings = self::get_page_settings($post_id);

        $existing_css = $page_settings['custom_css'] ?? '';
        $new_css      = $replace ? $css : trim($existing_css . "\n" . $css);

        $result = self::save_page_settings($post_id, ['custom_css' => $new_css]);
        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'target'  => 'page:' . $post_id,
            'css'     => $new_css,
        ];
    }

    // =========================================================================
    // add-custom-js (Free — uses HTML widget)
    // =========================================================================

    /**
     * Registers the add-custom-js ability.
     */
    private static function register_add_custom_js(): void {
        $name = 'novamira-adrianv2/add-custom-js';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Add Custom JavaScript', 'novamira-adrianv2'),
            'description'         => __('Adds a custom JavaScript snippet to a page by inserting an HTML widget containing a <script> tag. Works with free Elementor (no Pro required). The JS code is automatically wrapped in <script> tags — do NOT include them yourself. Use wrap_dom_ready=true to wrap in a DOMContentLoaded listener. For site-wide JS, use add-code-snippet instead (requires Pro).', 'novamira-adrianv2'),
            'category'            => 'adrianv2-elementor',
            'execute_callback'    => [__CLASS__, 'execute_add_custom_js'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'        => [
                        'type'        => 'integer',
                        'description' => __('The post/page ID.', 'novamira-adrianv2'),
                    ],
                    'parent_id'      => [
                        'type'        => 'string',
                        'description' => __('Parent container element ID.', 'novamira-adrianv2'),
                    ],
                    'js'             => [
                        'type'        => 'string',
                        'description' => __('JavaScript code to inject. Do NOT include <script> tags — they are added automatically.', 'novamira-adrianv2'),
                    ],
                    'position'       => [
                        'type'        => 'integer',
                        'description' => __('Insert position within parent. -1 = append (default).', 'novamira-adrianv2'),
                    ],
                    'wrap_dom_ready' => [
                        'type'        => 'boolean',
                        'description' => __('Wrap the code in a DOMContentLoaded listener. Default: false.', 'novamira-adrianv2'),
                    ],
                ],
                'required'   => ['post_id', 'parent_id', 'js'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'element_id' => ['type' => 'string'],
                    'post_id'    => ['type' => 'integer'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * Executes the add-custom-js ability.
     *
     * @param array $input The input parameters.
     * @return array|\WP_Error
     */
    public static function execute_add_custom_js($input) {
        $post_id        = absint($input['post_id'] ?? 0);
        $parent_id      = sanitize_text_field($input['parent_id'] ?? '');
        $js             = $input['js'] ?? '';
        $position       = (int) ($input['position'] ?? -1);
        $wrap_dom_ready = !empty($input['wrap_dom_ready']);

        if (!$post_id || $parent_id === '' || $js === '') {
            return new \WP_Error('missing_params', __('post_id, parent_id, and js are required.', 'novamira-adrianv2'));
        }

        // Strip any existing script tags the caller may have included.
        $js = preg_replace('/<\/?script[^>]*>/i', '', $js);

        // Optionally wrap in DOMContentLoaded.
        if ($wrap_dom_ready) {
            $js = "document.addEventListener('DOMContentLoaded', function() {\n" . $js . "\n});";
        }

        $html_content = "<script>\n" . $js . "\n</script>";

        $page = self::read_page($post_id);
        if ($page['error'] !== null) {
            return new \WP_Error('read_failed', $page['error']);
        }

        // Create an HTML widget element (v3 widget, works with free Elementor).
        $widget = [
            'id'         => self::generate_id(),
            'elType'     => 'widget',
            'widgetType' => 'html',
            'isInner'    => false,
            'settings'   => ['html' => $html_content],
            'elements'   => [],
        ];

        $ok = self::insert_element($page['elements'], $parent_id, $widget, $position);
        if (!$ok) {
            return new \WP_Error(
                'parent_not_found',
                sprintf(
                    /* translators: %s: parent element ID */
                    __('Parent element "%s" not found.', 'novamira-adrianv2'),
                    $parent_id
                )
            );
        }

        $save = self::write_page($post_id, $page['elements']);
        if (is_wp_error($save)) {
            return $save;
        }

        return [
            'element_id' => $widget['id'],
            'post_id'    => $post_id,
        ];
    }

    // =========================================================================
    // add-code-snippet (Pro only — site-wide)
    // =========================================================================

    /**
     * Registers the add-code-snippet ability.
     */
    private static function register_add_code_snippet(): void {
        $name = 'novamira-adrianv2/add-code-snippet';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Add Code Snippet', 'novamira-adrianv2'),
            'description'         => __('Creates a site-wide Custom Code snippet using Elementor Pro. Injects CSS or JavaScript into the <head>, after <body> open, or before </body> close on ALL pages. Use this for analytics scripts, site-wide CSS overrides, meta tags, or tracking pixels. Requires Elementor Pro and manage_options capability.', 'novamira-adrianv2'),
            'category'            => 'adrianv2-custom-code',
            'execute_callback'    => [__CLASS__, 'execute_add_code_snippet'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'title'         => [
                        'type'        => 'string',
                        'description' => __('Descriptive title for the snippet (e.g. "Google Analytics", "Global CSS overrides").', 'novamira-adrianv2'),
                    ],
                    'code'          => [
                        'type'        => 'string',
                        'description' => __('The full code to inject. Include <script>, <style>, or <meta> tags as needed.', 'novamira-adrianv2'),
                    ],
                    'location'      => [
                        'type'        => 'string',
                        'enum'        => ['head', 'body_start', 'body_end'],
                        'description' => __('Where to inject: "head" = <head> tag, "body_start" = after <body>, "body_end" = before </body>. Default: head.', 'novamira-adrianv2'),
                    ],
                    'priority'      => [
                        'type'        => 'integer',
                        'description' => __('Load order priority (1-10, lower = earlier). Default: 1.', 'novamira-adrianv2'),
                    ],
                    'status'        => [
                        'type'        => 'string',
                        'enum'        => ['publish', 'draft'],
                        'description' => __('Post status. "publish" = active immediately. "draft" = saved but not active. Default: publish.', 'novamira-adrianv2'),
                    ],
                    'ensure_jquery' => [
                        'type'        => 'boolean',
                        'description' => __('If true, ensures jQuery is loaded before this snippet runs. Default: false.', 'novamira-adrianv2'),
                    ],
                ],
                'required'   => ['title', 'code'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'snippet_id' => ['type' => 'integer'],
                    'title'      => ['type' => 'string'],
                    'location'   => ['type' => 'string'],
                    'priority'   => ['type' => 'integer'],
                    'status'     => ['type' => 'string'],
                    'edit_url'   => ['type' => 'string'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * Executes the add-code-snippet ability.
     *
     * @param array $input The input parameters.
     * @return array|\WP_Error
     */
    public static function execute_add_code_snippet($input) {
        $title         = sanitize_text_field($input['title'] ?? '');
        $code          = $input['code'] ?? '';
        $location_key  = sanitize_key($input['location'] ?? 'head');
        $priority      = absint($input['priority'] ?? 1);
        $status        = sanitize_key($input['status'] ?? 'publish');
        $ensure_jquery = !empty($input['ensure_jquery']);

        if ($title === '' || $code === '') {
            return new \WP_Error('missing_params', __('title and code are required.', 'novamira-adrianv2'));
        }

        // Map user-friendly location names to Elementor's internal values.
        $location_map = [
            'head'       => 'elementor_head',
            'body_start' => 'elementor_body_start',
            'body_end'   => 'elementor_body_end',
        ];

        $elementor_location = $location_map[$location_key] ?? 'elementor_head';

        // Clamp priority to 1-10.
        $priority = max(1, min(10, $priority));

        // Validate status.
        if (!in_array($status, ['publish', 'draft'], true)) {
            $status = 'publish';
        }

        // Create the elementor_snippet CPT post.
        $post_id = wp_insert_post(
            [
                'post_title'  => $title,
                'post_type'   => 'elementor_snippet',
                'post_status' => $status,
            ],
            true
        );

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Set the custom code meta fields (matching Elementor Pro's Custom Code module).
        update_post_meta($post_id, '_elementor_location', $elementor_location);
        update_post_meta($post_id, '_elementor_priority', $priority);
        update_post_meta($post_id, '_elementor_code', $code);
        update_post_meta($post_id, '_elementor_template_type', 'code_snippet');
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        // Set ensure_jquery extra option if requested.
        if ($ensure_jquery) {
            update_post_meta($post_id, '_elementor_extra_options', ['ensure_jquery']);
        }

        $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');

        return [
            'snippet_id' => $post_id,
            'title'      => $title,
            'location'   => $location_key,
            'priority'   => $priority,
            'status'     => $status,
            'edit_url'   => $edit_url,
        ];
    }

    // =========================================================================
    // list-code-snippets (Pro only — read-only)
    // =========================================================================

    /**
     * Registers the list-code-snippets ability.
     */
    private static function register_list_code_snippets(): void {
        $name = 'novamira-adrianv2/list-code-snippets';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('List Code Snippets', 'novamira-adrianv2'),
            'description'         => __('Lists all existing Elementor Pro Custom Code snippets with their titles, locations, priorities, and statuses. Requires Elementor Pro.', 'novamira-adrianv2'),
            'category'            => 'adrianv2-custom-code',
            'execute_callback'    => [__CLASS__, 'execute_list_code_snippets'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'location' => [
                        'type'        => 'string',
                        'enum'        => ['head', 'body_start', 'body_end'],
                        'description' => __('Optional filter by location.', 'novamira-adrianv2'),
                    ],
                    'status'   => [
                        'type'        => 'string',
                        'enum'        => ['publish', 'draft', 'any'],
                        'description' => __('Filter by post status. Default: any.', 'novamira-adrianv2'),
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'snippets' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'       => ['type' => 'integer'],
                                'title'    => ['type' => 'string'],
                                'location' => ['type' => 'string'],
                                'priority' => ['type' => 'integer'],
                                'status'   => ['type' => 'string'],
                                'code'     => ['type' => 'string'],
                                'edit_url' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'count'    => ['type' => 'integer'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * Executes the list-code-snippets ability.
     *
     * @param array $input The input parameters.
     * @return array|\WP_Error
     */
    public static function execute_list_code_snippets($input) {
        $location_filter = sanitize_key($input['location'] ?? '');
        $status_filter   = sanitize_key($input['status'] ?? 'any');

        $location_map = [
            'head'       => 'elementor_head',
            'body_start' => 'elementor_body_start',
            'body_end'   => 'elementor_body_end',
        ];

        $location_labels = [
            'elementor_head'       => 'head',
            'elementor_body_start' => 'body_start',
            'elementor_body_end'   => 'body_end',
        ];

        $query_args = [
            'post_type'      => 'elementor_snippet',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ($status_filter !== 'any' && $status_filter !== '') {
            $query_args['post_status'] = $status_filter;
        } else {
            $query_args['post_status'] = ['publish', 'draft'];
        }

        if ($location_filter !== '' && isset($location_map[$location_filter])) {
            $query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                [
                    'key'   => '_elementor_location',
                    'value' => $location_map[$location_filter],
                ],
            ];
        }

        $posts    = get_posts($query_args);
        $snippets = [];

        foreach ($posts as $post) {
            $raw_location = get_post_meta($post->ID, '_elementor_location', true);
            $priority     = absint(get_post_meta($post->ID, '_elementor_priority', true));
            $code         = get_post_meta($post->ID, '_elementor_code', true);

            $snippets[] = [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'location' => $location_labels[$raw_location] ?? $raw_location,
                'priority' => $priority ? $priority : 1,
                'status'   => $post->post_status,
                'code'     => $code ? $code : '',
                'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
            ];
        }

        return [
            'snippets' => $snippets,
            'count'    => count($snippets),
        ];
    }
}
