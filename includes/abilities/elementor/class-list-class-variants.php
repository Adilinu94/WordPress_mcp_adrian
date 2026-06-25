<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Helpers;

if (!defined('ABSPATH')) {
    exit();
}

class List_Class_Variants
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/list-class-variants', [
            'label'               => 'List Class Variants',
            'description'         => 'List all variants for a specific Global Class, showing breakpoints, states, and CSS properties. Also supports listing all classes with their variant counts. Use this to inspect what responsive breakpoints a class has before editing.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'class_id' => ['type' => 'string', 'description' => 'ID of the Global Class to inspect. If omitted, lists all classes with variant counts.'],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'class_id'  => ['type' => 'string'],
                    'label'     => ['type' => 'string'],
                    'variants'  => ['type' => 'array'],
                    'classes'   => ['type' => 'array'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $class_id = $input['class_id'] ?? null;

        // Overview mode: all classes with variant counts
        if ($class_id === null) {
            $class_posts = get_posts([
                'post_type'      => 'e_global_class',
                'posts_per_page' => -1,
                'post_status'    => 'any',
            ]);
            $classes = [];
            foreach ($class_posts as $cp) {
                $cid  = get_post_meta($cp->ID, '_elementor_global_class_id', true);
                $data = get_post_meta($cp->ID, '_elementor_global_class_data', true);
                if (is_string($data)) {
                    $data = maybe_unserialize($data);
                }
                $variants = $data['variants'] ?? [];
                $bps = [];
                foreach ($variants as $v) {
                    $bp  = $v['meta']['breakpoint'] ?? 'default';
                    $st  = $v['meta']['state'] ?? null;
                    $bps[] = $st ? "$bp:$st" : $bp;
                }
                $classes[] = [
                    'class_id'      => $cid,
                    'label'         => $cp->post_title,
                    'variant_count' => count($variants),
                    'breakpoints'   => $bps,
                ];
            }
            return ['classes' => $classes, 'total' => count($classes)];
        }

        // Single class detail
        $post = Helpers::get_class_post($class_id);
        if (!$post) {
            return ['error' => "Class '$class_id' not found."];
        }

        $data = get_post_meta($post->ID, '_elementor_global_class_data', true);
        if (is_string($data)) {
            $data = maybe_unserialize($data);
        }
        $raw_variants = $data['variants'] ?? [];

        $variants = [];
        foreach ($raw_variants as $i => $v) {
            $props = [];
            foreach ($v['props'] ?? [] as $key => $val) {
                if (is_array($val) && isset($val['$$type'])) {
                    $props[$key] = ['type' => $val['$$type'], 'value' => $val['value'] ?? null];
                } else {
                    $props[$key] = $val;
                }
            }
            $variants[] = [
                'index'      => $i,
                'breakpoint' => $v['meta']['breakpoint'] ?? null,
                'state'      => $v['meta']['state'] ?? null,
                'props'      => $props,
            ];
        }

        return [
            'class_id' => $class_id,
            'label'    => $post->post_title,
            'variants' => $variants,
            'total'    => count($variants),
        ];
    }
}

add_action('wp_abilities_api_init', [List_Class_Variants::class, 'register']);
