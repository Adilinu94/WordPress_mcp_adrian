<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Batch_Class
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/batch-class', [
            'label'               => 'Batch Global Class',
            'description'         => 'Applies or removes a Global Class on multiple atomic v4 elements in a single operation. Much faster than calling apply/remove for each element individually.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => ['type' => 'integer', 'description' => 'The page/post ID containing the elements.'],
                    'element_ids' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'List of Elementor element IDs to operate on.',
                    ],
                    'action'      => [
                        'type' => 'string',
                        'enum' => ['apply', 'remove'],
                        'description' => 'Whether to apply or remove the class.',
                    ],
                    'class_id'    => ['type' => 'string', 'description' => 'The Global Class ID to apply or remove. For remove action, pass "*" to remove ALL classes from every listed element.'],
                ],
                'required'   => ['post_id', 'element_ids', 'action', 'class_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data'    => ['type' => 'object'],
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
        $post_id     = $input['post_id'];
        $element_ids = $input['element_ids'];
        $action      = $input['action'];
        $class_id    = $input['class_id'];

        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'data' => ['message' => "Post $post_id not found."]];
        }

        $raw  = get_post_meta($post_id, '_elementor_data', true);
        $tree = json_decode($raw, true);
        if (!is_array($tree)) {
            return ['success' => false, 'data' => ['message' => 'Elementor data not found or invalid.']];
        }

        $remove_all = ($action === 'remove' && $class_id === '*');
        $results    = [];

        $walk = function (&$elements) use (&$walk, $element_ids, $action, $class_id, $remove_all, &$results) {
            foreach ($elements as &$el) {
                $el_id = $el['id'] ?? null;
                if ($el_id && in_array($el_id, $element_ids, true)) {
                    $classes = &$el['settings']['classes'];

                    // normalize classes to v4 wrapped array format
                    if (!is_array($classes) || !isset($classes['value']) || !is_array($classes['value'])) {
                        $classes = ['$$type' => 'classes', 'value' => []];
                    }

                    $before = $classes['value'];

                    if ($remove_all) {
                        $classes['value'] = [];
                        $results[] = ['element_id' => $el_id, 'action' => 'remove', 'removed' => $before, 'remaining' => []];
                    } elseif ($action === 'remove') {
                        $key = array_search($class_id, $classes['value'], true);
                        if ($key !== false) {
                            array_splice($classes['value'], $key, 1);
                            $results[] = ['element_id' => $el_id, 'action' => 'remove', 'removed' => [$class_id], 'remaining' => $classes['value']];
                        } else {
                            $results[] = ['element_id' => $el_id, 'action' => 'remove', 'removed' => [], 'remaining' => $classes['value'], 'note' => "Class '$class_id' not present -- idempotent."];
                        }
                    } else { // apply
                        if (!in_array($class_id, $classes['value'], true)) {
                            $classes['value'][] = $class_id;
                            $results[] = ['element_id' => $el_id, 'action' => 'apply', 'added' => $class_id, 'all_classes' => $classes['value']];
                        } else {
                            $results[] = ['element_id' => $el_id, 'action' => 'apply', 'added' => null, 'all_classes' => $classes['value'], 'note' => "Class '$class_id' already present -- idempotent."];
                        }
                    }
                }

                if (!empty($el['elements'])) {
                    $walk($el['elements']);
                }
            }
        };

        $walk($tree);

        // report elements not found
        $found_ids = array_column($results, 'element_id');
        foreach ($element_ids as $eid) {
            if (!in_array($eid, $found_ids, true)) {
                $results[] = ['element_id' => $eid, 'error' => 'Element not found in post.'];
            }
        }

        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($tree)));

        Guards::invalidate_elementor_cache($post_id);

        $changed = count(array_filter($results, fn($r) => !isset($r['error']) && !isset($r['note'])));

        return [
            'success' => true,
            'data'    => [
                'post_id'    => $post_id,
                'action'     => $action,
                'class_id'   => $class_id,
                'total'      => count($element_ids),
                'changed'    => $changed,
                'results'    => $results,
            ],
        ];
    }
}

add_action('wp_abilities_api_init', [Batch_Class::class, 'register']);
