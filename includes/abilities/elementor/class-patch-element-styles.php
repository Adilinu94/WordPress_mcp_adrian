<?php

declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Patch_Element_Styles
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/patch-element-styles', [
            'label'       => 'Patch Element Styles',
            'description' => 'Surgically patches styles, settings, or custom_css of specific Elementor elements on an existing page by element ID. Use this instead of rebuilding the full page when fixing individual element appearance. Supports updating style props per breakpoint/state, adding custom_css, changing widget settings (title, svg, classes), and adding new style classes. Much faster than batch-build-page for incremental fixes. Always clears Elementor CSS cache after patching.',
            'category'    => 'adrianv2-elementor',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'Page/post ID to patch.'],
                    'patches' => [
                        'type' => 'array',
                        'description' => 'Array of patch operations, each targeting one element by ID.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'element_id' => ['type' => 'string', 'description' => 'Elementor element ID to target.'],
                                'style_id' => ['type' => 'string', 'description' => 'Which style key to patch (e.g. s-hero-bg-base). Must already exist on element.'],
                                'breakpoint' => ['type' => 'string', 'description' => 'Breakpoint to target: desktop | tablet | mobile. Default: desktop.'],
                                'state' => ['type' => 'string', 'description' => 'State to target: null | hover | focus | active. Default: null.'],
                                'props' => ['type' => 'object', 'description' => 'Style props to merge ($$type format). Merged on top of existing props.', 'additionalProperties' => true],
                                'custom_css' => ['type' => 'string', 'description' => 'Raw CSS to inject into this variant. Replaces existing custom_css for this variant.'],
                                'settings' => ['type' => 'object', 'description' => 'Widget settings to merge (flat or $$type format).', 'additionalProperties' => true],
                                'add_style' => ['type' => 'object', 'description' => 'New style object to add to element styles map. Key = style ID, value = full style object with variants.', 'additionalProperties' => true],
                                'add_class' => ['type' => 'string', 'description' => 'Class ID to append to settings.classes.value array.'],
                            ],
                            'required' => ['element_id'],
                        ],
                    ],
                ],
                'required' => ['post_id', 'patches'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success'         => ['type' => 'boolean'],
                    'patched_count'   => ['type' => 'integer'],
                    'not_found'       => ['type' => 'array', 'items' => ['type' => 'string']],
                    'permalink'       => ['type' => 'string'],
                    'error'           => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        // V3/V4 page version guard (1.1.0).
        $post_id = (int) ($input['post_id'] ?? 0);
        $opt_in  = (bool) ($input['opt_in'] ?? false);
        if ($post_id > 0 && !$opt_in) {
            $page_v4 = \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::page_is_v4($post_id);
            if (!$page_v4) {
                return new \WP_Error('page_version_mismatch', __('patch-element-styles operates on V4 atomic pages only. Use elementor-set-content for V3 pages. Pass opt_in: true to override.', 'novamira-adrianv2'));
            }
        }

        $post_id = (int) $input['post_id'];
        $patches = $input['patches'] ?? [];

        if (!$post_id || !get_post($post_id)) {
            return ['success' => false, 'error' => "Post {$post_id} not found."];
        }

        $raw  = get_post_meta($post_id, '_elementor_data', true);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Could not decode elementor_data.'];
        }

        $patched   = 0;
        $not_found = [];

        foreach ($patches as $patch) {
            $el_id = $patch['element_id'] ?? '';
            if (!$el_id) {
                continue;
            }

            $found = self::apply_patch($data, $el_id, $patch);
            if ($found) {
                $patched++;
            } else {
                $not_found[] = $el_id;
            }
        }

        $encoded = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        update_post_meta($post_id, '_elementor_data', wp_slash($encoded));

        Guards::invalidate_elementor_cache($post_id);

        return [
            'success'       => true,
            'patched_count' => $patched,
            'not_found'     => $not_found,
            'permalink'     => get_permalink($post_id),
        ];
    }

    private static function apply_patch(array &$elements, string $target, array $patch): bool
    {
        foreach ($elements as &$el) {
            if ($el['id'] === $target) {

                if (!empty($patch['settings']) && is_array($patch['settings'])) {
                    $el['settings'] = array_replace_recursive($el['settings'] ?? [], $patch['settings']);
                }

                if (!empty($patch['add_class'])) {
                    $cls = &$el['settings']['classes']['value'];
                    if (!is_array($cls)) {
                        $cls = [];
                    }
                    if (!in_array($patch['add_class'], $cls, true)) {
                        $cls[] = $patch['add_class'];
                    }
                }

                if (!empty($patch['add_style']) && is_array($patch['add_style'])) {
                    if (!isset($el['styles'])) {
                        $el['styles'] = [];
                    }
                    foreach ($patch['add_style'] as $style_id => $style_obj) {
                        $el['styles'][$style_id] = $style_obj;
                    }
                }

                $style_id   = $patch['style_id'] ?? null;
                $breakpoint = $patch['breakpoint'] ?? 'desktop';
                $state      = $patch['state'] ?? null;

                if ($style_id && isset($el['styles'][$style_id])) {
                    $found_variant = false;
                    foreach ($el['styles'][$style_id]['variants'] as &$variant) {
                        if (
                            (string) $variant['meta']['breakpoint'] === (string) $breakpoint &&
                            $variant['meta']['state'] === $state
                        ) {
                            if (!empty($patch['props'])) {
                                $variant['props'] = array_replace($variant['props'] ?? [], $patch['props']);
                            }
                            if (array_key_exists('custom_css', $patch)) {
                                $variant['custom_css'] = $patch['custom_css'];
                            }
                            $found_variant = true;
                            break;
                        }
                    }
                    if (!$found_variant && (!empty($patch['props']) || array_key_exists('custom_css', $patch))) {
                        $new_variant = [
                            'meta' => ['breakpoint' => $breakpoint, 'state' => $state],
                            'props' => $patch['props'] ?? [],
                            'custom_css' => (function ($c) {
                                return is_string($c) ? ['raw' => $c] : $c;
                            })($patch['custom_css'] ?? null),
                        ];
                        $el['styles'][$style_id]['variants'][] = $new_variant;
                    }
                }

                return true;
            }
            if (!empty($el['elements'])) {
                if (self::apply_patch($el['elements'], $target, $patch)) {
                    return true;
                }
            }
        }
        return false;
    }
}

add_action('wp_abilities_api_init', [Patch_Element_Styles::class, 'register']);
