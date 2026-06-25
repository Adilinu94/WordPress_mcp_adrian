<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Setup_V4_Foundation
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/setup-v4-foundation', [
            'label'       => 'Setup V4 Foundation',
            'description' => 'Run this BEFORE batch-build-page. Ensures e-flexbox-base and e-div-block-base global classes exist (padding: 0), then returns a complete context object with all variable IDs grouped by type and all global class IDs with labels. Use the returned IDs directly in batch-build-page settings. Solves the default Flexbox padding problem documented in Elementor GitHub Discussion #32154.',
            'category'    => 'adrianv2-elementor',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'create_missing' => [
                        'type' => 'boolean',
                        'description' => 'If true (default), creates missing base classes automatically. Set to false to only read current state.',
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success'      => ['type' => 'boolean'],
                    'base_classes' => ['type' => 'object', 'description' => 'Status of e-flexbox-base and e-div-block-base'],
                    'variables'    => ['type' => 'object', 'description' => 'All variables grouped: colors{}, fonts{}, sizes{}'],
                    'classes'      => ['type' => 'object', 'description' => 'All global classes as label->id map'],
                    'quick_ref'    => ['type' => 'object', 'description' => 'Most-used IDs by semantic name for instant use in batch-build-page'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        // V4 guard (1.1.0): V4 foundation only makes sense on V4-capable sites.
        if (!\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error(
                'v4_required',
                sprintf(
                    __('%s requires Elementor 4.0+. Detected version: %s.', 'novamira-adrianv2'),
                    'setup-v4-foundation',
                    \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_version_string()
                )
            );
        }

        $create_missing = $input['create_missing'] ?? true;
        $kit_id = \Elementor\Plugin::$instance->kits_manager->get_active_id();

        // 1. Load variables - meta kann JSON-String oder Array sein
        $vars_raw = get_post_meta($kit_id, '_elementor_global_variables', true);
        if (is_string($vars_raw)) {
            $decoded = json_decode($vars_raw, true);
            $vars    = is_array($decoded['data'] ?? null) ? $decoded['data'] : (is_array($decoded) ? $decoded : []);
        } elseif (is_array($vars_raw)) {
            $vars = $vars_raw['data'] ?? $vars_raw;
        } else {
            $vars = [];
        }

        $colors = [];
        $fonts  = [];
        $sizes  = [];
        foreach ($vars as $id => $v) {
            $label = $v['label'] ?? $id;
            switch ($v['type'] ?? '') {
                case 'global-color-variable':
                    $colors[$label] = $id;
                    break;
                case 'global-font-variable':
                    $fonts[$label] = $id;
                    break;
                case 'global-size-variable':
                    $sizes[$label] = $id;
                    break;
            }
        }

        // 2. Load global classes - order kann ["order"=>[...]] oder flach sein
        $order_raw = get_post_meta($kit_id, '_elementor_global_classes_order', true);
        $labels    = get_post_meta($kit_id, '_elementor_global_classes_labels', true);
        $order_arr = is_array($order_raw) ? ($order_raw['order'] ?? $order_raw) : [];
        $labels    = is_array($labels) ? $labels : [];

        $classes_by_label = [];
        $classes_by_id    = [];
        foreach ($order_arr as $cid) {
            $lbl = $labels[$cid] ?? $cid;
            $classes_by_label[$lbl] = $cid;
            $classes_by_id[$cid]    = $lbl;
        }

        // 3. Ensure base classes exist
        $zero_padding = self::zeroPaddingVariants();
        $base_needed  = ['e-flexbox-base', 'e-div-block-base'];
        $base_status  = [];

        foreach ($base_needed as $class_label) {
            if (isset($classes_by_label[$class_label])) {
                $base_status[$class_label] = ['status' => 'exists', 'id' => $classes_by_label[$class_label]];
            } elseif ($create_missing) {
                $new_id = self::createGlobalClass($kit_id, $class_label, $zero_padding);
                if ($new_id) {
                    $classes_by_label[$class_label] = $new_id;
                    $classes_by_id[$new_id]         = $class_label;
                    $base_status[$class_label] = ['status' => 'created', 'id' => $new_id];
                } else {
                    $base_status[$class_label] = ['status' => 'error', 'id' => null];
                }
            } else {
                $base_status[$class_label] = ['status' => 'missing', 'id' => null];
            }
        }

        // 4. Quick reference
        $quick_ref = [
            'base_classes' => [
                'flexbox_base'   => $classes_by_label['e-flexbox-base'] ?? null,
                'div_block_base' => $classes_by_label['e-div-block-base'] ?? null,
            ],
            'colors' => [
                'primary'     => self::find($colors, ['primary-color', 'primary']),
                'secondary'   => self::find($colors, ['secondary-color', 'secondary']),
                'text'        => self::find($colors, ['text-color', 'text', 'bfee4dc']),
                'accent'      => self::find($colors, ['accent-color', 'accent']),
                'white'       => self::find($colors, ['white', 'white-color']),
                'black'       => self::find($colors, ['black', 'nero', 'dark']),
                'transparent' => self::find($colors, ['transparent']),
                'border'      => self::find($colors, ['border', 'border-color']),
            ],
            'fonts' => [
                'heading' => self::find($fonts, ['manrope', 'font-heading', 'barlow']),
                'body'    => self::find($fonts, ['roboto', 'font-body', 'poppins']),
                'accent'  => self::find($fonts, ['ibm-plex-sans', 'font-accent', 'barlow']),
            ],
            'sizes' => [
                'xs'   => self::find($sizes, ['size-xs', 'size-12px']),
                'sm'   => self::find($sizes, ['size-sm', 'size-13px']),
                'md'   => self::find($sizes, ['size-md', 'size-14px']),
                'lg'   => self::find($sizes, ['size-lg', 'size-15px']),
                'xl'   => self::find($sizes, ['size-xl', 'size-17px']),
                '2xl'  => self::find($sizes, ['size-2xl', 'size-20px']),
                '3xl'  => self::find($sizes, ['size-3xl', 'size-24px']),
                '4xl'  => self::find($sizes, ['size-4xl', 'size-25px']),
                '5xl'  => self::find($sizes, ['size-5xl', 'size-30px']),
                'hero' => self::find($sizes, ['size-52px', 'size-78px', 'size-5xl']),
            ],
            'typography_classes' => [
                'primary'    => $classes_by_label['primary'] ?? null,
                'secondary'  => $classes_by_label['secondary'] ?? null,
                'text'       => $classes_by_label['text'] ?? null,
                'accent'     => $classes_by_label['accent'] ?? null,
                'heading'    => $classes_by_label['heading'] ?? null,
                'header-1'   => $classes_by_label['header-1'] ?? null,
                'header-2'   => $classes_by_label['header-2'] ?? null,
                'header-3'   => $classes_by_label['header-3'] ?? null,
                'subheading' => $classes_by_label['subheading'] ?? null,
                'link'       => $classes_by_label['link'] ?? null,
            ],
        ];

        return [
            'success'      => true,
            'base_classes' => $base_status,
            'variables'    => ['colors' => $colors, 'fonts' => $fonts, 'sizes' => $sizes],
            'classes'      => $classes_by_label,
            'quick_ref'    => $quick_ref,
            'usage_hint'   => 'Add quick_ref.base_classes.flexbox_base to every e-flexbox classes array to reset default padding.',
        ];
    }

    // ---------------------------------------------------------------------------
    // Private Helpers
    // ---------------------------------------------------------------------------

    private static function zeroPaddingVariants(): array
    {
        $zero = ['$$type' => 'size', 'value' => ['size' => 0, 'unit' => 'px']];
        $dims = [
            '$$type' => 'dimensions',
            'value'  => [
                'block-start'  => $zero,
                'block-end'    => $zero,
                'inline-start' => $zero,
                'inline-end'   => $zero,
            ],
        ];
        return [
            ['meta' => ['breakpoint' => 'desktop', 'state' => null], 'props' => ['padding' => $dims], 'custom_css' => null],
            ['meta' => ['breakpoint' => 'tablet', 'state' => null], 'props' => ['padding' => $dims], 'custom_css' => null],
            ['meta' => ['breakpoint' => 'mobile', 'state' => null], 'props' => ['padding' => $dims], 'custom_css' => null],
        ];
    }

    private static function createGlobalClass($kit_id, $label, $variants)
    {
        // Generate unique class ID (gc- + 16 hex chars)
        $id = 'gc-' . substr(md5(uniqid($label, true)), 0, 16);

        // Store class data
        $class_data = [
            'id'       => $id,
            'label'    => $label,
            'type'     => 'class',
            'variants' => $variants,
        ];
        update_post_meta($kit_id, '_elementor_global_class_' . $id, wp_json_encode($class_data));

        // Update order - bestehende Struktur exakt beibehalten
        $cur_order = get_post_meta($kit_id, '_elementor_global_classes_order', true);
        if (is_array($cur_order) && isset($cur_order['order'])) {
            $cur_order['order'][] = $id;
        } elseif (is_array($cur_order)) {
            $cur_order[] = $id;
        } else {
            $cur_order = [$id];
        }
        update_post_meta($kit_id, '_elementor_global_classes_order', $cur_order);

        // Update labels
        $cur_labels = get_post_meta($kit_id, '_elementor_global_classes_labels', true);
        $cur_labels = is_array($cur_labels) ? $cur_labels : [];
        $cur_labels[$id] = $label;
        update_post_meta($kit_id, '_elementor_global_classes_labels', $cur_labels);

        // Clear cache
        Guards::invalidate_elementor_cache((int) $kit_id);

        return $id;
    }

    /**
     * Find first matching key from a list of candidates in an associative array.
     */
    private static function find(array $map, array $candidates)
    {
        foreach ($candidates as $key) {
            if (isset($map[$key])) {
                return $map[$key];
            }
        }
        return null;
    }
}

add_action('wp_abilities_api_init', [Setup_V4_Foundation::class, 'register']);
