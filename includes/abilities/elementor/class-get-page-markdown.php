<?php

declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Get_Page_Markdown
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/get-page-markdown', [
            'label'               => 'Get Page Markdown',
            'description'         => 'Returns the Markdown version of any Elementor page, including YAML frontmatter. Requires Elementor 4.1+ with the markdown_rendering experiment enabled.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'WordPress post ID of the page to render as Markdown.',
                    ],
                    'include_frontmatter' => [
                        'type'        => 'boolean',
                        'description' => 'Include YAML frontmatter in the full output. Default: true.',
                    ],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => ['type' => 'boolean'],
                    'data'        => [
                        'type'       => 'object',
                        'properties' => [
                            'post_id'      => ['type' => 'integer'],
                            'post_title'   => ['type' => 'string'],
                            'permalink'    => ['type' => 'string'],
                            'frontmatter'  => ['type' => 'object'],
                            'body'         => ['type' => 'string'],
                            'full_markdown'=> ['type' => 'string'],
                            'is_cached'    => ['type' => 'boolean'],
                        ],
                    ],
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
        $post_id              = (int) $input['post_id'];
        $include_frontmatter  = $input['include_frontmatter'] ?? true;

        $post = get_post($post_id);

        if (!$post) {
            return [
                'success' => false,
                'error'   => sprintf('Post with ID %d not found.', $post_id),
            ];
        }

        if ('publish' !== $post->post_status && 'draft' !== $post->post_status) {
            return [
                'success' => false,
                'error'   => sprintf('Post status "%s" is not supported.', $post->post_status),
            ];
        }

        $document = \Elementor\Plugin::instance()->documents->get($post_id);

        if (!$document || !$document->is_built_with_elementor()) {
            return [
                'success' => false,
                'error'   => 'This post is not built with Elementor.',
            ];
        }

        $markdown_check = Guards::ensure_markdown_rendering_active();
        if (is_wp_error($markdown_check)) {
            return [
                'success' => false,
                'error'   => $markdown_check->get_error_message(),
            ];
        }

        try {
            $renderer = new \Elementor\Modules\MarkdownRender\Markdown_Renderer();
            $full_markdown = $renderer->render($document);

            $cache_meta = get_post_meta($post_id, '_elementor_markdown_cache', true);
            $is_cached  = !empty($cache_meta) && is_array($cache_meta)
                && !empty($cache_meta['timeout']) && time() <= $cache_meta['timeout'];

            $frontmatter = [];
            $body        = $full_markdown;

            if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $full_markdown, $matches)) {
                $raw_frontmatter = $matches[1];
                $body            = trim($matches[2]);

                foreach (explode("\n", $raw_frontmatter) as $line) {
                    if (preg_match('/^(\w+):\s*"(.*)"$/', trim($line), $fm)) {
                        $frontmatter[$fm[1]] = $fm[2];
                    }
                }
            }

            $result = [
                'success' => true,
                'data'    => [
                    'post_id'    => $post_id,
                    'post_title' => $post->post_title,
                    'permalink'  => get_permalink($post_id),
                    'frontmatter'=> $frontmatter,
                    'body'       => $body,
                    'is_cached'  => $is_cached,
                ],
            ];

            if ($include_frontmatter) {
                $result['data']['full_markdown'] = $full_markdown;
            }

            return $result;

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}

add_action('wp_abilities_api_init', [Get_Page_Markdown::class, 'register']);
