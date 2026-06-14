<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Media;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Ability: Batch Media Upload
 *
 * Uploads multiple media files to the WordPress Media Library in one call.
 * Accepts base64-encoded content for each file. Replaces N sequential
 * adrians-media-upload calls for the Framer V4 pipeline asset upload step.
 *
 * Max 30 files per call, max 10MB per file.
 */
class Batch_Media_Upload
{
    private const MAX_FILES = 30;
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    public static function register(): void
    {
        \wp_register_ability('novamira-adrianv2/batch-media-upload', [
            'label'               => 'Batch Media Upload',
            'description'         => 'Uploads multiple media files to the WordPress Media Library in one call. Accepts base64-encoded content. Max 30 files, max 10MB each. Replaces N sequential adrians-media-upload calls.',
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'files' => [
                        'type'        => 'array',
                        'description' => 'Array of files to upload.',
                        'maxItems'    => self::MAX_FILES,
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'filename'      => ['type' => 'string', 'description' => 'Target filename (e.g. hero-image.jpg).'],
                                'mime_type'     => ['type' => 'string', 'description' => 'MIME type (e.g. image/jpeg).'],
                                'content_base64' => ['type' => 'string', 'description' => 'Base64-encoded file content.'],
                                'alt_text'      => ['type' => 'string', 'description' => 'Optional alt text for images.'],
                                'title'         => ['type' => 'string', 'description' => 'Optional media title.'],
                            ],
                            'required' => ['filename', 'content_base64'],
                        ],
                    ],
                ],
                'required' => ['files'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'  => ['type' => 'boolean'],
                    'uploaded' => ['type' => 'integer'],
                    'failed'   => ['type' => 'integer'],
                    'results'  => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'filename'    => ['type' => 'string'],
                                'wp_media_id' => ['type' => ['integer', 'null']],
                                'wp_url'      => ['type' => ['string', 'null']],
                                'error'       => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
        ]);
    }

    public static function execute(mixed $params): array
    {
        $files = $params['files'] ?? [];

        if (empty($files)) {
            return ['success' => false, 'error' => 'files array must not be empty.'];
        }
        if (count($files) > self::MAX_FILES) {
            return ['success' => false, 'error' => sprintf('Maximum %d files per call.', self::MAX_FILES)];
        }

        $results  = [];
        $uploaded = 0;
        $failed   = 0;

        // Ensure upload helpers are loaded (admin context required)
        if (!function_exists('wp_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        foreach ($files as $file) {
            $filename = $file['filename'] ?? '';
            $b64      = $file['content_base64'] ?? '';
            $mime     = $file['mime_type'] ?? 'application/octet-stream';
            $alt      = $file['alt_text'] ?? '';
            $title    = $file['title'] ?? '';

            // ── FIX-16: Filename sanitization for batch uploads ──
            $filename = sanitize_file_name($filename);
            if ($filename === '' || str_starts_with($filename, '.')) {
                $results[] = [
                    'filename'    => $file['filename'] ?? '',
                    'wp_media_id' => null,
                    'wp_url'      => null,
                    'error'       => 'Invalid filename — must not be empty, dot-prefixed, or contain path components.',
                ];
                $failed++;
                continue;
            }

            if (empty($b64)) {
                $results[] = [
                    'filename'    => $filename,
                    'wp_media_id' => null,
                    'wp_url'      => null,
                    'error'       => 'filename and content_base64 are required.',
                ];
                $failed++;
                continue;
            }

            $content = base64_decode($b64, true);
            if ($content === false) {
                $results[] = [
                    'filename'    => $filename,
                    'wp_media_id' => null,
                    'wp_url'      => null,
                    'error'       => 'Invalid base64 content.',
                ];
                $failed++;
                continue;
            }

            if (strlen($content) > self::MAX_FILE_SIZE_BYTES) {
                $results[] = [
                    'filename'    => $filename,
                    'wp_media_id' => null,
                    'wp_url'      => null,
                    'error'       => sprintf('File exceeds %d MB limit.', self::MAX_FILE_SIZE_BYTES / (1024 * 1024)),
                ];
                $failed++;
                continue;
            }

            // Write to temp file
            $tmp = \wp_tempnam($filename);
            if (!$tmp) {
                $results[] = [
                    'filename'    => $filename,
                    'wp_media_id' => null,
                    'wp_url'      => null,
                    'error'       => 'Could not create temp file.',
                ];
                $failed++;
                continue;
            }

            file_put_contents($tmp, $content);

            // Build file array for wp_handle_sideload
            $file_array = [
                'name'     => $filename,
                'tmp_name' => $tmp,
                'type'     => $mime,
                'size'     => strlen($content),
            ];

            // Upload via WordPress
            $attachment_id = self::upload_file($file_array, $title, $alt);

            // Clean up temp file
            @unlink($tmp);

            if (is_wp_error($attachment_id)) {
                $results[] = [
                    'filename'    => $filename,
                    'wp_media_id' => null,
                    'wp_url'      => null,
                    'error'       => $attachment_id->get_error_message(),
                ];
                $failed++;
                continue;
            }

            $wp_url = \wp_get_attachment_url($attachment_id);
            $results[] = [
                'filename'    => $filename,
                'wp_media_id' => $attachment_id,
                'wp_url'      => $wp_url ?: null,
                'error'       => null,
            ];
            $uploaded++;
        }

        return [
            'success'  => $failed === 0,
            'uploaded' => $uploaded,
            'failed'   => $failed,
            'results'  => $results,
        ];
    }

    /**
     * Upload a file to the WordPress Media Library.
     */
    private static function upload_file(array $file_array, string $title, string $alt): int|\WP_Error
    {
        // Ensure upload directory exists
        if (!function_exists('wp_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Use wp_handle_sideload (no HTTP request needed)
        $overrides = ['test_form' => false, 'mimes' => self::get_allowed_mimes()];
        $result    = wp_handle_sideload($file_array, $overrides);

        if (isset($result['error'])) {
            return new \WP_Error('upload_failed', $result['error']);
        }

        $filepath = $result['file'];
        $url      = $result['url'];
        $mime     = $result['type'];

        // Create attachment
        $attachment = [
            'post_mime_type' => $mime,
            'post_title'     => sanitize_file_name($title ?: basename($file_array['name'])),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($attachment, $filepath);
        if (\is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Generate metadata
        $metadata = wp_generate_attachment_metadata($attachment_id, $filepath);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Set alt text
        if (!empty($alt)) {
            \update_post_meta($attachment_id, '_wp_attachment_image_alt', \sanitize_text_field($alt));
        }

        return $attachment_id;
    }

    /**
     * Get allowed MIME types including SVG and fonts.
     */
    private static function get_allowed_mimes(): array
    {
        $mimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif'          => 'image/gif',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'avif'         => 'image/avif',
            'bmp'          => 'image/bmp',
            'ico'          => 'image/x-icon',
            'woff'         => 'font/woff',
            'woff2'        => 'font/woff2',
            'ttf'          => 'font/ttf',
            'otf'          => 'font/otf',
            'mp4'          => 'video/mp4',
            'webm'         => 'video/webm',
            'pdf'          => 'application/pdf',
        ];

        return apply_filters('upload_mimes', $mimes);
    }
}

add_action('wp_abilities_api_init', [Batch_Media_Upload::class, 'register']);
