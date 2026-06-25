<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Reorder_Element {
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/reorder-element', [
            'label'               => 'Reorder Element',
            'description'         => 'Moves an Elementor element to a new position within or between parents, including root-level moves. Prevents circular moves and clears Elementor caches.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer', 'description' => 'The page/post ID containing the element.' ],
                    'element_id' => [ 'type' => 'string', 'description' => 'The Elementor element ID to move.' ],
                    'target_parent_id' => [ 'type' => 'string', 'description' => 'Target parent ID. Omit or pass empty string for root level.' ],
                    'target_position' => [ 'type' => 'integer', 'description' => 'Zero-based target position. Omit to append. Negative values count from the end.' ],
                ],
                'required'   => [ 'post_id', 'element_id' ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'data' => [ 'type' => 'object' ],
                    'error' => [ 'type' => 'string' ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            ],
        ]);
    }

    public static function execute($input = null) {
        $post_id = (int) $input['post_id'];
        $element_id = isset($input['element_id']) ? (string) $input['element_id'] : '';
        $target_parent_id = isset($input['target_parent_id']) ? (string) $input['target_parent_id'] : '';
        $target_position = array_key_exists('target_position', $input) ? (int) $input['target_position'] : null;

        if ('' === $element_id) {
            return ['success' => false, 'error' => 'element_id is required.'];
        }
        if (!get_post($post_id)) {
            return ['success' => false, 'error' => sprintf('Post with ID %d not found.', $post_id)];
        }

        $data = self::read_elementor_data($post_id);
        if (null === $data) {
            return ['success' => false, 'error' => 'Post has no valid Elementor data.'];
        }

        $source = self::find_location($data, $element_id);
        if (!$source) {
            return ['success' => false, 'error' => sprintf('Element "%s" not found.', $element_id)];
        }

        $moving = $source['element'];
        if ('' !== $target_parent_id && self::contains_element($moving['elements'] ?? [], $target_parent_id)) {
            return ['success' => false, 'error' => sprintf('Cannot move "%s" into its own descendant "%s".', $element_id, $target_parent_id)];
        }
        if ($target_parent_id === $element_id) {
            return ['success' => false, 'error' => 'Cannot move an element into itself.'];
        }

        $old_parent_id = $source['parent_id'];
        $old_position = $source['index'];
        self::remove_at_path($data, $source['path']);

        if ('' === $target_parent_id) {
            $target =& $data;
            $resolved_parent_id = '';
        } else {
            $target_location = self::find_location($data, $target_parent_id);
            if (!$target_location) {
                return ['success' => false, 'error' => sprintf('Target parent "%s" not found.', $target_parent_id)];
            }
            $parent =& self::get_element_ref_by_path($data, $target_location['path']);
            if (!isset($parent['elements']) || !is_array($parent['elements'])) {
                $parent['elements'] = [];
            }
            $target =& $parent['elements'];
            $resolved_parent_id = $target_parent_id;
        }

        $new_position = self::insert_at_position($target, $moving, $target_position);
        self::write_elementor_data($post_id, $data);
        Guards::invalidate_elementor_cache($post_id);

        return [
            'success' => true,
            'data'    => [
                'post_id' => $post_id,
                'element_id' => $element_id,
                'old_parent_id' => $old_parent_id,
                'old_position' => $old_position,
                'target_parent_id' => $resolved_parent_id,
                'new_position' => $new_position,
            ],
        ];
    }

    private static function read_elementor_data(int $post_id): ?array {
        $data = get_post_meta($post_id, '_elementor_data', true);
        if (is_string($data) && '' !== $data) {
            $data = json_decode($data, true);
        }
        return is_array($data) ? $data : null;
    }

    private static function write_elementor_data(int $post_id, array $data): void {
        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($data)));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
        }
    }

    private static function find_location(array $elements, string $element_id, array $path = [], string $parent_id = ''): ?array {
        foreach ($elements as $index => $element) {
            $current_path = array_merge($path, [$index]);
            if (isset($element['id']) && $element['id'] === $element_id) {
                return ['path' => $current_path, 'index' => $index, 'parent_id' => $parent_id, 'element' => $element];
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $found = self::find_location($element['elements'], $element_id, $current_path, $element['id'] ?? '');
                if ($found) {
                    return $found;
                }
            }
        }
        return null;
    }

    private static function &get_element_ref_by_path(array &$elements, array $path): array {
        $ref =& $elements;
        foreach ($path as $depth => $index) {
            $ref =& $ref[$index];
            if ($depth < count($path) - 1) {
                $ref =& $ref['elements'];
            }
        }
        return $ref;
    }

    private static function remove_at_path(array &$elements, array $path): void {
        $index = array_pop($path);
        if (empty($path)) {
            array_splice($elements, $index, 1);
            return;
        }
        $parent =& self::get_element_ref_by_path($elements, $path);
        array_splice($parent['elements'], $index, 1);
    }

    private static function contains_element(array $elements, string $element_id): bool {
        foreach ($elements as $element) {
            if (isset($element['id']) && $element['id'] === $element_id) {
                return true;
            }
            if (!empty($element['elements']) && is_array($element['elements']) && self::contains_element($element['elements'], $element_id)) {
                return true;
            }
        }
        return false;
    }

    private static function insert_at_position(array &$elements, array $moving, $position): int {
        $count = count($elements);
        if (null === $position) {
            $position = $count;
        } elseif ($position < 0) {
            $position = max(0, $count + 1 + $position);
        }
        $position = max(0, min((int) $position, $count));
        array_splice($elements, $position, 0, [$moving]);
        return $position;
    }
}

add_action('wp_abilities_api_init', [Reorder_Element::class, 'register']);
