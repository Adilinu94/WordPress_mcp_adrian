<?php
declare(strict_types=1);

/**
 * V4_Security — Security validation and sanitization for V4 atomic pages.
 *
 * Registers two abilities:
 *   1. v4-validate-security   — Walks a V4 page tree checking for XSS in
 *      custom CSS, dangerous URLs, and insecure content patterns.
 *   2. v4-sanitize-content    — Recursively sanitizes a V4 element tree:
 *      strips script tags, javascript: URIs, on* event handlers, and
 *      eval() patterns from all string values and custom CSS blocks.
 *
 * Architecture: Fully static, read-only validation + destructive sanitization.
 * Used as a pre-build guard in the Framer-to-Elementor-V4-Pipeline.
 *
 * @package Novamira_AdrianV2
 * @since   1.8.0
 */

namespace Novamira\AdrianV2\Abilities\V4Management;

use Novamira\AdrianV2\Helpers\Elementor_Version_Resolver;
use Novamira\AdrianV2\Helpers\Elementor_Data_Helpers;
use Novamira\AdrianV2\Helpers\Ability_Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static ability registrar for V4 security operations.
 */
class V4_Security {
    use Elementor_Data_Helpers;
    use Ability_Registry;

    /** @var string[] */
    private static array $ability_names = [];

    /**
     * XSS patterns to detect in CSS and text content.
     */
    private const XSS_PATTERNS = [
        'javascript:'  => '/javascript\s*:/i',
        'script_tag'   => '/<script/i',
        'eval_call'    => '/eval\s*\(/i',
        'expression'   => '/expression\s*\(/i',
        'event_handler'=> '/on\w+\s*=\s*["\']/i',
        'data_uri_js'  => '/data\s*:\s*text\/javascript/i',
        'vbscript'     => '/vbscript\s*:/i',
        'css_import_js'=> '/@import\s+url\s*\(\s*["\']?\s*javascript\s*:/i',
        'moz_binding'  => '/-moz-binding\s*:\s*url\s*\(/i',
        'behavior'     => '/behavior\s*:\s*url\s*\(/i',
        'escaped_js'   => '/jav\\\s*ascript\s*:/i',
    ];

    /**
     * Potentially dangerous URL schemes.
     */
    private const DANGEROUS_URL_SCHEMES = [
        'javascript:', 'data:text/html', 'data:text/javascript',
        'vbscript:',   'file:',         'chrome-extension:',
    ];

    /**
     * Maximum custom CSS length to scan.
     */
    private const MAX_CSS_LENGTH = 50000;

    /**
     * Register both abilities.
     */
    public static function register(): void {
        self::register_validate_security();
        self::register_sanitize_content();
    }

    // =========================================================================
    // v4-validate-security
    // =========================================================================

    private static function register_validate_security(): void {
        $name = 'novamira-adrianv2/v4-validate-security';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('V4 Validate Security', 'novamira-adrianv2'),
            'description'         => __(
                'Walks a V4 element tree checking for security issues: '
                . 'XSS patterns in custom CSS, dangerous URLs, event handler '
                . 'injection, and eval() calls. Returns a detailed issue list '
                . 'with paths to affected elements. Use before publishing '
                . 'externally-sourced V4 trees.',
                'novamira-adrianv2'
            ),
            'category'            => 'adrianv2-v4-management',
            'execute_callback'    => [self::class, 'execute_validate_security'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __('Post ID to validate (reads _elementor_data).', 'novamira-adrianv2'),
                    ],
                    'tree'    => [
                        'type'        => 'array',
                        'description' => __('V4 element tree to validate directly (alternative to post_id).', 'novamira-adrianv2'),
                    ],
                    'strict'  => [
                        'type'        => 'boolean',
                        'default'     => true,
                        'description' => __('Enable strict mode: also checks URL schemes and text content.', 'novamira-adrianv2'),
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'valid'     => ['type' => 'boolean'],
                    'post_id'   => ['type' => 'integer'],
                    'issues'    => ['type' => 'array'],
                    'stats'     => ['type' => 'object'],
                    'checks'    => ['type' => 'array'],
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
     * Execute v4-validate-security.
     *
     * @param array|null $input
     * @return array|\WP_Error
     */
    public static function execute_validate_security($input = null) {
        $input   = $input ?? [];
        $post_id = (int) ($input['post_id'] ?? 0);
        $tree    = $input['tree'] ?? null;
        $strict  = (bool) ($input['strict'] ?? true);

        // Resolve tree from post_id or direct input.
        if ($post_id > 0 && $tree === null) {
            $page = self::read_page($post_id);
            if ($page['error'] !== null) {
                return new \WP_Error('read_failed', $page['error']);
            }
            $tree = $page['elements'];
        }

        if (!is_array($tree)) {
            return new \WP_Error('invalid_tree', __('Tree must be an array of elements.', 'novamira-adrianv2'));
        }

        $issues = [];
        $stats  = [
            'elements_scanned'  => 0,
            'styles_scanned'    => 0,
            'css_blocks_scanned'=> 0,
            'urls_scanned'      => 0,
        ];

        $walk = function (array $nodes, string $path) use (&$walk, &$issues, &$stats, $strict): void {
            foreach ($nodes as $index => $node) {
                if (!is_array($node)) {
                    continue;
                }

                $stats['elements_scanned']++;
                $el_path = $path . '[' . $index . ']';
                $el_id   = $node['id'] ?? 'unknown';

                // Check styles for XSS in custom CSS.
                if (!empty($node['styles']) && is_array($node['styles'])) {
                    foreach ($node['styles'] as $style_id => $style_def) {
                        if (!is_array($style_def)) {
                            continue;
                        }
                        $stats['styles_scanned']++;

                        $variants = $style_def['variants'] ?? [];
                        foreach ($variants as $v_idx => $variant) {
                            if (!is_array($variant)) {
                                continue;
                            }

                            $custom_css = $variant['custom_css'] ?? null;
                            if ($custom_css !== null && $custom_css !== '') {
                                $css_string = is_array($custom_css)
                                    ? ($custom_css['raw'] ?? '')
                                    : (string) $custom_css;
                                $stats['css_blocks_scanned']++;

                                if (strlen($css_string) > self::MAX_CSS_LENGTH) {
                                    $issues[] = [
                                        'type'     => 'css_too_large',
                                        'severity' => 'warning',
                                        'element'  => $el_id,
                                        'path'     => $el_path . '.styles.' . $style_id,
                                        'message'  => sprintf(
                                            'Custom CSS block is %d bytes (limit: %d). Skipping deep scan.',
                                            strlen($css_string),
                                            self::MAX_CSS_LENGTH
                                        ),
                                    ];
                                    continue;
                                }

                                $css_issues = self::scan_css_for_xss($css_string, $el_path . '.styles.' . $style_id, $el_id);
                                $issues = array_merge($issues, $css_issues);
                            }
                        }
                    }
                }

                // Check settings for dangerous URLs (strict mode).
                if ($strict && !empty($node['settings']) && is_array($node['settings'])) {
                    $url_issues = self::scan_settings_for_urls($node['settings'], $el_path, $el_id);
                    $issues = array_merge($issues, $url_issues);
                    $stats['urls_scanned'] += count($url_issues);
                }

                // Recurse into children.
                $children = $node['elements'] ?? [];
                if (!empty($children) && is_array($children)) {
                    $walk($children, $el_path . '.elements');
                }
            }
        };

        $walk($tree, 'root');

        $checks_performed = ['custom_css_xss'];
        if ($strict) {
            $checks_performed[] = 'malicious_urls';
        }

        return [
            'valid'   => empty($issues),
            'post_id' => $post_id > 0 ? $post_id : null,
            'issues'  => $issues,
            'stats'   => $stats,
            'checks'  => $checks_performed,
            'summary' => empty($issues)
                ? sprintf(
                    'No security issues found in %d elements (%d styles, %d CSS blocks).',
                    $stats['elements_scanned'],
                    $stats['styles_scanned'],
                    $stats['css_blocks_scanned']
                )
                : sprintf(
                    '%d security issue(s) found in %d elements.',
                    count($issues),
                    $stats['elements_scanned']
                ),
        ];
    }

    /**
     * Scan a CSS string for XSS patterns.
     *
     * @param string $css
     * @param string $path
     * @param string $el_id
     * @return array
     */
    private static function scan_css_for_xss(string $css, string $path, string $el_id): array {
        $issues = [];

        foreach (self::XSS_PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $css)) {
                // Extract context around the match.
                $context = '';
                if (preg_match($pattern, $css, $matches, PREG_OFFSET_CAPTURE)) {
                    $pos  = (int) $matches[0][1];
                    $start = max(0, $pos - 30);
                    $len   = min(strlen($css) - $start, 80);
                    $context = '…' . substr($css, $start, $len) . '…';
                }

                $issues[] = [
                    'type'     => 'xss_' . $name,
                    'severity' => 'error',
                    'element'  => $el_id,
                    'path'     => $path,
                    'message'  => sprintf(
                        'Potentially dangerous pattern "%s" found in custom CSS.',
                        $name
                    ),
                    'context'  => $context,
                ];
            }
        }

        return $issues;
    }

    /**
     * Scan element settings for dangerous URL patterns.
     *
     * @param array  $settings
     * @param string $path
     * @param string $el_id
     * @return array
     */
    private static function scan_settings_for_urls(array $settings, string $path, string $el_id): array {
        $issues = [];

        $check_value = function ($value, string $key_path) use (&$check_value, &$issues, $el_id): void {
            if (is_string($value)) {
                $lower = strtolower(trim($value));
                foreach (self::DANGEROUS_URL_SCHEMES as $scheme) {
                    if (str_starts_with($lower, $scheme)) {
                        $issues[] = [
                            'type'     => 'dangerous_url',
                            'severity' => 'error',
                            'element'  => $el_id,
                            'path'     => $key_path,
                            'message'  => sprintf(
                                'Dangerous URL scheme "%s" detected.',
                                $scheme
                            ),
                            'value'    => substr($value, 0, 100),
                        ];
                    }
                }

                // Check for event handler attributes in HTML content.
                if (preg_match('/on\w+\s*=/i', $value)) {
                    $issues[] = [
                        'type'     => 'xss_event_handler',
                        'severity' => 'error',
                        'element'  => $el_id,
                        'path'     => $key_path,
                        'message'  => 'Event handler attribute detected in content.',
                    ];
                }
            } elseif (is_array($value)) {
                foreach ($value as $k => $v) {
                    $check_value($v, $key_path . '.' . $k);
                }
            }
        };

        foreach ($settings as $key => $value) {
            $check_value($value, $path . '.settings.' . $key);
        }

        return $issues;
    }

    // =========================================================================
    // v4-sanitize-content
    // =========================================================================

    private static function register_sanitize_content(): void {
        $name = 'novamira-adrianv2/v4-sanitize-content';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('V4 Sanitize Content', 'novamira-adrianv2'),
            'description'         => __(
                'Recursively sanitizes a V4 element tree: strips <script> tags, '
                . 'javascript: URIs, on* event handlers, eval() calls, and other '
                . 'dangerous patterns from all string values and custom CSS blocks. '
                . 'Returns the sanitized tree plus a change log.',
                'novamira-adrianv2'
            ),
            'category'            => 'adrianv2-v4-management',
            'execute_callback'    => [self::class, 'execute_sanitize_content'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'tree' => [
                        'type'        => 'array',
                        'description' => __('V4 element tree to sanitize.', 'novamira-adrianv2'),
                    ],
                ],
                'required'   => ['tree'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'tree'        => ['type' => 'array'],
                    'changes'     => ['type' => 'array'],
                    'sanitized'   => ['type' => 'boolean'],
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
     * Execute v4-sanitize-content.
     *
     * @param array|null $input
     * @return array|\WP_Error
     */
    public static function execute_sanitize_content($input = null) {
        $input = $input ?? [];
        $tree = $input['tree'] ?? [];

        if (!is_array($tree)) {
            return new \WP_Error('invalid_tree', __('Tree must be an array of elements.', 'novamira-adrianv2'));
        }

        $changes = [];

        $sanitize = function (array $nodes) use (&$sanitize, &$changes): array {
            $cleaned = [];

            foreach ($nodes as $index => $node) {
                if (!is_array($node)) {
                    $cleaned[] = $node;
                    continue;
                }

                $el_id = $node['id'] ?? ('index_' . $index);

                // Sanitize styles → custom CSS.
                if (isset($node['styles']) && is_array($node['styles'])) {
                    foreach ($node['styles'] as $style_id => &$style_def) {
                        if (!is_array($style_def) || !isset($style_def['variants'])) {
                            continue;
                        }

                        foreach ($style_def['variants'] as $v_idx => &$variant) {
                            if (!is_array($variant)) {
                                continue;
                            }

                            $custom_css = $variant['custom_css'] ?? null;
                            if ($custom_css === null || $custom_css === '') {
                                continue;
                            }

                            $css_string = is_array($custom_css)
                                ? ($custom_css['raw'] ?? '')
                                : (string) $custom_css;

                            $original = $css_string;
                            $css_string = self::sanitize_css($css_string);

                            if ($css_string !== $original) {
                                $changes[] = [
                                    'type'    => 'css_sanitized',
                                    'element' => $el_id,
                                    'style'   => $style_id,
                                    'variant' => $v_idx,
                                ];

                                if (is_array($custom_css)) {
                                    $variant['custom_css']['raw'] = $css_string;
                                } else {
                                    $variant['custom_css'] = $css_string;
                                }
                            }
                        }
                        unset($variant);
                    }
                    unset($style_def);
                }

                // Sanitize settings string values.
                if (isset($node['settings']) && is_array($node['settings'])) {
                    $node['settings'] = self::sanitize_array_values($node['settings'], $el_id, $changes);
                }

                // Recurse into children.
                if (isset($node['elements']) && is_array($node['elements'])) {
                    $node['elements'] = $sanitize($node['elements']);
                }

                $cleaned[] = $node;
            }

            return $cleaned;
        };

        $sanitized_tree = $sanitize($tree);
        $had_changes    = !empty($changes);

        return [
            'tree'      => $sanitized_tree,
            'changes'   => $changes,
            'sanitized' => $had_changes,
            'summary'   => $had_changes
                ? sprintf('Sanitized %d issue(s) in the element tree.', count($changes))
                : 'No dangerous patterns found — tree is clean.',
        ];
    }

    /**
     * Sanitize a CSS string: remove script tags, javascript: URIs, etc.
     *
     * @param string $css
     * @return string
     */
    private static function sanitize_css(string $css): string {
        // Remove script tags (multiline-safe).
        $css = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $css) ?? $css;

        // Remove javascript: URIs (multiline-safe).
        $css = preg_replace('/url\s*\(\s*["\']?\s*javascript\s*:[^)]*\)/is', 'url("")', $css) ?? $css;

        // Remove expression() calls (old IE CSS, multiline-safe).
        $css = preg_replace('/expression\s*\([^)]*\)/is', '', $css) ?? $css;

        // Remove eval() calls.
        $css = preg_replace('/eval\s*\([^)]*\)/is', '', $css) ?? $css;

        // Remove behavior: url(...) (old IE, multiline-safe).
        $css = preg_replace('/behavior\s*:\s*url\s*\([^)]*\)/is', '', $css) ?? $css;

        // Remove -moz-binding (legacy Firefox XBL).
        $css = preg_replace('/-moz-binding\s*:\s*url\s*\([^)]*\)/is', '', $css) ?? $css;

        return trim($css);
    }

    /**
     * Recursively sanitize string values in an array for XSS patterns.
     *
     * @param array  $arr
     * @param string $el_id
     * @param array  &$changes
     * @return array
     */
    private static function sanitize_array_values(array $arr, string $el_id, array &$changes): array {
        $cleaned = [];

        foreach ($arr as $key => $value) {
            if (is_string($value)) {
                $original = $value;
                // Strip script tags.
                $value = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $value) ?? $value;
                // Strip event handlers.
                $value = preg_replace('/\son\w+\s*=\s*["\'][^"\']*["\']/i', '', $value) ?? $value;
                // Strip javascript: URIs.
                $value = preg_replace('/javascript\s*:[^\s"\']*/i', '#removed', $value) ?? $value;

                if ($value !== $original) {
                    $changes[] = [
                        'type'    => 'value_sanitized',
                        'element' => $el_id,
                        'key'     => $key,
                    ];
                }
            } elseif (is_array($value)) {
                $value = self::sanitize_array_values($value, $el_id, $changes);
            }

            $cleaned[$key] = $value;
        }

        return $cleaned;
    }
}
