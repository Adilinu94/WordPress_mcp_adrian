<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Media;

if (!defined('ABSPATH')) {
    exit();
}

class Media_Upload
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/media-upload', [
            'label'               => 'Media Upload',
            'description'         => 'Uploads a file directly to the WordPress media library via base64-encoded content. Returns attachment ID, URL, and thumbnail URLs.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'base64_content' => [
                        'type'        => 'string',
                        'description' => 'Base64-encoded file content.',
                    ],
                    'filename'       => [
                        'type'        => 'string',
                        'description' => 'Desired filename including extension (e.g. "photo.jpg"). Must include a valid file extension.',
                    ],
                    'title'          => [
                        'type'        => 'string',
                        'description' => 'Attachment title. Defaults to the filename without extension.',
                    ],
                    'caption'        => [
                        'type'        => 'string',
                        'description' => 'Optional caption for the attachment.',
                    ],
                    'alt_text'       => [
                        'type'        => 'string',
                        'description' => 'Optional alt text for images.',
                    ],
                    'description'    => [
                        'type'        => 'string',
                        'description' => 'Optional description for the attachment.',
                    ],
                    'parent_post_id' => [
                        'type'        => 'integer',
                        'description' => 'Optional parent post ID to attach this media to.',
                    ],
                ],
                'required'   => ['base64_content', 'filename'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data'    => ['type' => 'object'],
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
                    'idempotent'  => false,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $base64_content = $input['base64_content'];
        $filename       = $input['filename'];
        $title          = $input['title'] ?? null;
        $caption        = $input['caption'] ?? '';
        $alt_text       = $input['alt_text'] ?? '';
        $description    = $input['description'] ?? '';
        $parent_post_id = $input['parent_post_id'] ?? 0;

        // ── FIX-16: Filename sanitization (path-traversal, dot-prefix, extension whitelist) ──
        $guard = self::guard_filename($filename);
        if (is_array($guard)) {
            return $guard;
        }
        $filename = $guard;
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Strip data URL prefix if present (e.g. "data:image/png;base64,")
        if (str_contains($base64_content, ',')) {
            $parts = explode(',', $base64_content, 2);
            if (count($parts) === 2 && str_contains($parts[0], 'base64')) {
                $base64_content = $parts[1];
            }
        }

        // Decode base64
        $file_content = base64_decode($base64_content, true);
        if ($file_content === false) {
            return ['success' => false, 'error' => 'Invalid base64 content — could not decode.'];
        }

        if (strlen($file_content) === 0) {
            return ['success' => false, 'error' => 'Decoded file content is empty.'];
        }

        // ── FIX-17: MIME-type validation (magic-bytes + finfo_buffer defense-in-depth) ──
        $content_error = self::guard_file_content($file_content, $ext);
        if ($content_error !== null) {
            return ['success' => false, 'error' => $content_error];
        }
        $mime_error = self::guard_mime_buffer($file_content, $ext);
        if ($mime_error !== null) {
            return ['success' => false, 'error' => $mime_error];
        }

        // Check file size (max 64MB by default, WordPress default)
        $max_bytes = wp_max_upload_size();
        if (strlen($file_content) > $max_bytes) {
            return ['success' => false, 'error' => 'File too large. Max: ' . size_format($max_bytes)];
        }

        // Get MIME type from extension
        $wp_filetype = wp_check_filetype($filename);
        $mime_type   = $wp_filetype['type'];
        if (!$mime_type) {
            return ['success' => false, 'error' => "Unrecognized file extension: .$ext"];
        }

        // Prepare upload
        $upload = wp_upload_bits($filename, null, $file_content);
        if (!empty($upload['error'])) {
            return ['success' => false, 'error' => 'Upload failed: ' . $upload['error']];
        }

        // Prepare attachment data
        $attachment = [
            'post_mime_type' => $mime_type,
            'post_title'     => $title ?: pathinfo($filename, PATHINFO_FILENAME),
            'post_content'   => $description,
            'post_excerpt'   => $caption,
            'post_status'    => 'inherit',
            'post_parent'    => $parent_post_id,
        ];

        // Insert attachment
        $attach_id = wp_insert_attachment($attachment, $upload['file'], $parent_post_id);
        if (is_wp_error($attach_id)) {
            @unlink($upload['file']);
            return ['success' => false, 'error' => 'Failed to create attachment: ' . $attach_id->get_error_message()];
        }

        // Generate attachment metadata (thumbnails, etc.)
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Set alt text for images
        if ($alt_text && str_starts_with($mime_type, 'image/')) {
            update_post_meta($attach_id, '_wp_attachment_image_alt', $alt_text);
        }

        // Get thumbnails
        $thumbnails = [];
        $is_image = str_starts_with($mime_type, 'image/');
        if ($is_image) {
            $sizes = ['thumbnail', 'medium', 'medium_large', 'large', 'full'];
            foreach ($sizes as $size) {
                $src = wp_get_attachment_image_src($attach_id, $size);
                if ($src) {
                    $thumbnails[$size] = [
                        'url'    => $src[0],
                        'width'  => $src[1],
                        'height' => $src[2],
                    ];
                }
            }
        }

        return [
            'success' => true,
            'data'    => [
                'attachment_id'  => $attach_id,
                'url'            => wp_get_attachment_url($attach_id),
                'filename'       => basename($upload['file']),
                'mime_type'      => $mime_type,
                'file_size'      => strlen($file_content),
                'title'          => $attachment['post_title'],
                'edit_url'       => get_edit_post_link($attach_id, 'raw'),
                'thumbnails'     => $thumbnails,
                'is_image'       => $is_image,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // FIX-16: Filename Sanitization Guard (path-traversal + whitelist)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Sanitize and validate a filename for media upload.
     *
     * Layers:
     *   1. WordPress sanitize_file_name() — strips ../ and trailing slashes
     *   2. Defense-in-depth — explicit check for / and \ after sanitize
     *   3. Dot-prefix block — rejects .htaccess and similar
     *   4. Extension whitelist — only jpg, jpeg, png, gif, webp, svg, pdf, ico
     *
     * @param string $filename Raw filename from input.
     * @return string|array Sanitized filename or error array ['success'=>false,'error'=>string].
     */
    private static function guard_filename(string $filename): string|array
    {
        // Layer 1: WordPress sanitize
        $sanitized = sanitize_file_name($filename);

        // Layer 2: Check if sanitization produced an empty string
        if ($sanitized === '') {
            return ['success' => false, 'error' => 'Invalid filename after sanitization.'];
        }

        // Explicit check for remaining path separators
        if (str_contains($sanitized, '/') || str_contains($sanitized, '\\')) {
            return ['success' => false, 'error' => 'Filename contains invalid path components.'];
        }

        // Layer 3: Dot-prefix block
        if (str_starts_with($sanitized, '.')) {
            return ['success' => false, 'error' => 'Filename cannot start with a dot.'];
        }

        // Layer 4: Extension whitelist
        $ext = strtolower(pathinfo($sanitized, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'ico'];

        if ($ext === '') {
            return ['success' => false, 'error' => 'Filename must include a file extension.'];
        }

        if (!in_array($ext, $allowed, true)) {
            return ['success' => false, 'error' => "File extension '.$ext' is not allowed. Allowed: " . implode(', ', $allowed)];
        }

        return $sanitized;
    }

    // ─────────────────────────────────────────────────────────────────
    // FIX-17: MIME-Type Validation Guards (magic-bytes + finfo_buffer)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Validate file content against its claimed extension using magic-bytes.
     *
     * Precise format-specific header check. Returns null on match,
     * error string on mismatch.
     *
     * @param string $content Raw decoded file content.
     * @param string $ext     Lowercase file extension.
     * @return string|null Error message or null if valid.
     */
    private static function guard_file_content(string $content, string $ext): ?string
    {
        $header2 = substr($content, 0, 2);
        $header3 = substr($content, 0, 3);
        $header4 = substr($content, 0, 4);
        $header8 = substr($content, 0, 8);

        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                // JPEG: FF D8 FF
                if ($header3 !== "\xFF\xD8\xFF") {
                    return "File content does not match claimed extension '.$ext' (possible MIME-spoofing).";
                }
                break;

            case 'png':
                // PNG: 89 50 4E 47 0D 0A 1A 0A
                if ($header8 !== "\x89PNG\r\n\x1a\n") {
                    return "File content does not match claimed extension '.$ext' (possible MIME-spoofing).";
                }
                break;

            case 'gif':
                // GIF87a or GIF89a
                if ($header4 !== 'GIF8' || !in_array(substr($content, 4, 2), ['7a', '9a'], true)) {
                    return "File content does not match claimed extension '.$ext' (possible MIME-spoofing).";
                }
                break;

            case 'webp':
                // RIFF prefix: 52 49 46 46
                if ($header4 !== 'RIFF') {
                    return "File content does not match claimed extension '.$ext' (possible MIME-spoofing).";
                }
                break;

            case 'pdf':
                // %PDF
                if ($header4 !== '%PDF') {
                    return "File content does not match claimed extension '.$ext' (possible MIME-spoofing).";
                }
                break;

            case 'ico':
                // ICO: 00 00 01 00
                if ($header4 !== "\x00\x00\x01\x00") {
                    return "File content does not match claimed extension '.$ext' (possible MIME-spoofing).";
                }
                break;

            case 'svg':
                // SVG: text-based, scan first 200 bytes for <svg or <?xml
                $head200 = substr($content, 0, 200);
                if (!preg_match('/<svg[\s>]/i', $head200) && !preg_match('/<\?xml/i', $head200)) {
                    return 'File content does not appear to be valid SVG.';
                }
                break;

            default:
                // Extension already validated by guard_filename() — skip
                return null;
        }

        return null; // Passed
    }

    /**
     * Cross-validate file content using finfo_buffer (libmagic).
     *
     * Defense-in-depth layer alongside guard_file_content().
     * Fail-open: if finfo is unavailable, silently skip.
     *
     * @param string $content Raw decoded file content.
     * @param string $ext     Lowercase file extension.
     * @return string|null Error message or null if valid/skipped.
     */
    private static function guard_mime_buffer(string $content, string $ext): ?string
    {
        // Fail-open: skip if finfo extension is unavailable
        if (!function_exists('finfo_open') || !function_exists('finfo_buffer')) {
            return null;
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null; // Fail-open
        }

        $detected = @finfo_buffer($finfo, $content);
        @finfo_close($finfo);

        if ($detected === false || $detected === '') {
            return null; // Could not detect — skip
        }

        // Extension → acceptable MIME types
        $expected_mimes = [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'svg'  => ['image/svg+xml', 'application/xml', 'text/xml', 'text/plain'],
            'pdf'  => ['application/pdf'],
            'ico'  => ['image/x-icon', 'image/vnd.microsoft.icon', 'image/ico'],
        ];

        $acceptable = $expected_mimes[$ext] ?? null;
        if ($acceptable === null) {
            return null; // Unknown extension — skip finfo
        }

        if (!in_array($detected, $acceptable, true)) {
            return "File content MIME ($detected) does not match claimed extension '.$ext'.";
        }

        return null; // Passed
    }
}

add_action('wp_abilities_api_init', [Media_Upload::class, 'register']);
