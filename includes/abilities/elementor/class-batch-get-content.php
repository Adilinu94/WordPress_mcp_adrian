<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

if (!defined('ABSPATH')) {
    exit();
}

class Batch_Get_Content {

    public static function register(): void {
        wp_register_ability('novamira-adrianv2/batch-get-content', [
            'label'       => 'Batch Get Elementor Content',
            'description' => 'Reads the Elementor element tree for multiple posts in one call. mode=skeleton (default) returns id/elType/widgetType/children only. mode=full returns all settings. mode=settings returns skeleton + settings for leaf widgets. Max 50 post_ids per call. Replaces N elementor-get-content calls for GV_ID_DRIFT checks, dependency graphs, and cross-page audits.',
            'category'    => 'adrianv2-elementor',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_ids' => [
                        'type'        => 'array',
                        'description' => 'WordPress post IDs to fetch (max 50).',
                        'items'       => ['type' => 'integer'],
                        'maxItems'    => 50,
                    ],
                    'mode' => [
                        'type'        => 'string',
                        'description' => 'skeleton (default) | full | settings',
                        'enum'        => ['skeleton', 'full', 'settings'],
                        'default'     => 'skeleton',
                    ],
                ],
                'required' => ['post_ids'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'count'   => ['type' => 'integer'],
                    'pages'   => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'post_id'       => ['type' => 'integer'],
                                'post_title'    => ['type' => 'string'],
                                'template_type' => ['type' => 'string'],
                                'element_count' => ['type' => 'integer'],
                                'content'       => ['type' => 'array'],
                                'error'         => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    public static function execute($input = null): array {
        $post_ids = array_map('intval', (array) ($input['post_ids'] ?? []));
        $mode     = $input['mode'] ?? 'skeleton';

        if (empty($post_ids)) {
            return ['success' => false, 'error' => 'post_ids must not be empty.'];
        }
        if (count($post_ids) > 50) {
            return ['success' => false, 'error' => 'Maximum 50 post IDs per call.'];
        }

        $pages = [];
        foreach ($post_ids as $pid) {
            $post = get_post($pid);
            if (!$post) {
                $pages[] = ['post_id' => $pid, 'post_title' => null, 'template_type' => null, 'element_count' => 0, 'content' => [], 'error' => 'Post not found.'];
                continue;
            }
            $raw = get_post_meta($pid, '_elementor_data', true);
            if (empty($raw)) {
                $pages[] = ['post_id' => $pid, 'post_title' => $post->post_title, 'template_type' => self::tpl($pid), 'element_count' => 0, 'content' => [], 'error' => 'No Elementor data.'];
                continue;
            }
            $tree = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $pages[] = ['post_id' => $pid, 'post_title' => $post->post_title, 'template_type' => self::tpl($pid), 'element_count' => 0, 'content' => [], 'error' => 'JSON error: ' . json_last_error_msg()];
                continue;
            }
            $content = match ($mode) {
                'skeleton' => array_map([self::class, 'skeleton'], $tree),
                'settings' => array_map([self::class, 'withSettings'], $tree),
                default    => $tree,
            };
            $pages[] = ['post_id' => $pid, 'post_title' => $post->post_title, 'template_type' => self::tpl($pid), 'element_count' => self::countEl($tree), 'content' => $content, 'error' => null];
        }
        return ['success' => true, 'count' => count($pages), 'pages' => $pages];
    }

    private static function tpl(int $pid): string {
        return get_post_meta($pid, '_elementor_template_type', true) ?: 'page';
    }

    private static function skeleton(array $el): array {
        $out = [];
        foreach (['id','elType','widgetType','isInner'] as $k) {
            if (isset($el[$k])) $out[$k] = $el[$k];
        }
        if (!empty($el['children'])) $out['children'] = array_map([self::class, 'skeleton'], $el['children']);
        return $out;
    }

    private static function withSettings(array $el): array {
        $out = [];
        foreach (['id','elType','widgetType'] as $k) {
            if (isset($el[$k])) $out[$k] = $el[$k];
        }
        if (empty($el['children']) && !empty($el['settings'])) $out['settings'] = $el['settings'];
        if (!empty($el['children'])) $out['children'] = array_map([self::class, 'withSettings'], $el['children']);
        return $out;
    }

    private static function countEl(array $els): int {
        $n = 0;
        foreach ($els as $el) {
            $n++;
            if (!empty($el['children'])) $n += self::countEl($el['children']);
        }
        return $n;
    }
}

add_action('wp_abilities_api_init', [Batch_Get_Content::class, 'register']);
