<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Remove_Global_Class
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/remove-global-class', [
            'label'               => 'Remove Global Class',
            'description'         => 'Removes one class, several classes, or all classes from an Elementor atomic v4 element without rewriting the full element tree manually.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID of the Elementor page/template.',
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => 'The element ID (data-id) to remove the class from.',
                    ],
                    'class_id' => [
                        'type'        => 'string',
                        'description' => 'Class ID to remove. Pass "*" to remove all classes. For multiple classes, prefer class_ids.',
                    ],
                    'class_ids' => [
                        'type'        => 'array',
                        'description' => 'Optional list of class IDs to remove.',
                        'items'       => ['type' => 'string'],
                    ],
                    'preserve_local_styles' => [
                        'type'        => 'boolean',
                        'description' => 'When class_id="*", keep classes that correspond to local per-element style IDs. Default: false.',
                    ],
                ],
                'required'   => ['post_id', 'element_id', 'class_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data'    => [
                        'type'       => 'object',
                        'properties' => [
                            'removed_classes'   => ['type' => 'array', 'items' => ['type' => 'string']],
                            'remaining_classes' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'element_id'        => ['type' => 'string'],
                            'post_id'           => ['type' => 'integer'],
                            'changed'           => ['type' => 'boolean'],
                            'note'              => ['type' => 'string'],
                        ],
                    ],
                    'error'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        if (!\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error('v4_required', sprintf(__('%s requires Elementor 4.0+. Detected version: %s.', 'novamira-adrianv2'), 'remove-global-class', \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_version_string()));
        }
        $post_id    = (int) $input['post_id'];
        $element_id = isset($input['element_id']) ? (string) $input['element_id'] : '';
        $class_id   = isset($input['class_id']) ? (string) $input['class_id'] : '';

        if ('' === $element_id) {
            return ['success' => false, 'error' => 'element_id is required.'];
        }

        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'error' => sprintf('Post with ID %d not found.', $post_id)];
        }

        $data = self::readElementorData($post_id);
        if (null === $data) {
            return ['success' => false, 'error' => 'Post has no valid Elementor data.'];
        }

        $element =& self::findElementRef($data, $element_id);
        if (null === $element) {
            return ['success' => false, 'error' => sprintf('Element "%s" not found in post %d.', $element_id, $post_id)];
        }

        $current_classes = self::getClasses($element);
        $remove_all      = '*' === $class_id;
        $targets         = self::targets($input, $class_id);
        $local_style_ids = isset($element['styles']) && is_array($element['styles']) ? array_keys($element['styles']) : [];
        $preserve_local  = !empty($input['preserve_local_styles']);
        $removed         = [];
        $remaining       = [];

        foreach ($current_classes as $class) {
            $is_local_style = in_array($class, $local_style_ids, true);
            $should_remove  = $remove_all ? !($preserve_local && $is_local_style) : in_array($class, $targets, true);

            if ($should_remove) {
                $removed[] = $class;
            } else {
                $remaining[] = $class;
            }
        }

        $removed   = array_values(array_unique($removed));
        $remaining = array_values(array_unique($remaining));

        if (empty($removed)) {
            return [
                'success' => true,
                'data'    => [
                    'removed_classes'   => [],
                    'remaining_classes' => $current_classes,
                    'element_id'        => $element_id,
                    'post_id'           => $post_id,
                    'changed'           => false,
                    'note'              => $remove_all ? 'No classes were present on this element.' : 'Requested class was not applied to this element.',
                ],
            ];
        }

        self::setClasses($element, $remaining);
        self::writeElementorData($post_id, $data);
        Guards::invalidate_elementor_cache($post_id);

        return [
            'success' => true,
            'data'    => [
                'removed_classes'   => $removed,
                'remaining_classes' => $remaining,
                'element_id'        => $element_id,
                'post_id'           => $post_id,
                'changed'           => true,
            ],
        ];
    }

    private static function readElementorData($post_id): ?array
    {
        $data = get_post_meta((int) $post_id, '_elementor_data', true);
        if (is_string($data) && '' !== $data) {
            $data = json_decode($data, true);
        }
        return is_array($data) ? $data : null;
    }

    private static function writeElementorData($post_id, array $data): void
    {
        update_post_meta((int) $post_id, '_elementor_data', wp_slash(wp_json_encode($data)));
        update_post_meta((int) $post_id, '_elementor_edit_mode', 'builder');

        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta((int) $post_id, '_elementor_version', ELEMENTOR_VERSION);
        }
    }

    private static function &findElementRef(array &$elements, $element_id)
    {
        $null = null;
        foreach ($elements as &$element) {
            if (isset($element['id']) && $element['id'] === $element_id) {
                return $element;
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $found =& self::findElementRef($element['elements'], $element_id);
                if (null !== $found) {
                    return $found;
                }
            }
        }
        return $null;
    }

    private static function getClasses(array $element): array
    {
        $classes = $element['settings']['classes'] ?? [];

        if (isset($classes['$$type'], $classes['value']) && 'classes' === $classes['$$type']) {
            $classes = $classes['value'];
        } elseif (isset($classes['value'])) {
            $classes = $classes['value'];
        }

        if (is_string($classes)) {
            $classes = preg_split('/\s+/', trim($classes));
        }

        if (!is_array($classes)) {
            return [];
        }

        $classes = array_map('strval', $classes);
        $classes = array_filter(array_map('trim', $classes));
        return array_values(array_unique($classes));
    }

    private static function setClasses(array &$element, array $classes): void
    {
        if (!isset($element['settings']) || !is_array($element['settings'])) {
            $element['settings'] = [];
        }

        $element['settings']['classes'] = [
            '$$type' => 'classes',
            'value'  => array_values($classes),
        ];
    }

    private static function targets(array $input, $class_id): array
    {
        $targets = [];
        if (isset($input['class_ids']) && is_array($input['class_ids'])) {
            $targets = array_merge($targets, array_map('strval', $input['class_ids']));
        }
        if ('' !== $class_id && '*' !== $class_id) {
            $targets[] = $class_id;
        }
        $targets = array_filter(array_map('trim', $targets));
        return array_values(array_unique($targets));
    }
}

add_action('wp_abilities_api_init', [Remove_Global_Class::class, 'register']);
