<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/upload-asset
 *
 * Downloads a remote file (asset_url) and sideloads it into the WordPress
 * media library. Returns the attachment ID and local URL so callers can
 * immediately reference the media in Elementor settings.
 *
 * Designed to match the interface expected by site-clone-to-v3's
 * image-broken real-fixer (real-fixers.ts):
 *   { asset_url, filename, page_id }
 *   → { id, url, filename, attachment_id }
 *
 * Also serves as a typed replacement for raw novamira/upload_asset calls.
 *
 * @since 1.7.1
 */
class Upload_Asset {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/upload-asset',
			[
				'label'       => 'Upload Asset from URL',
				'description' => 'Download a remote file (image, PDF, SVG …) from asset_url and sideload it into the WordPress media library. Returns the local attachment ID and URL for use in Elementor settings (e.g. image { id, url }). Skips duplicate sideloads — if the filename already exists in the library it returns the existing attachment. Optional page_id attaches the media to a specific post.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'asset_url' ],
					'properties' => [
						'asset_url' => [
							'type'        => 'string',
							'description' => 'Publicly accessible URL of the file to download and import.',
						],
						'filename' => [
							'type'        => 'string',
							'default'     => '',
							'description' => 'Override for the saved filename (including extension). Defaults to the basename of asset_url.',
						],
						'title' => [
							'type'        => 'string',
							'default'     => '',
							'description' => 'Attachment title. Defaults to filename without extension.',
						],
						'alt_text' => [
							'type'        => 'string',
							'default'     => '',
							'description' => 'Alt text for image attachments.',
						],
						'page_id' => [
							'type'        => 'integer',
							'default'     => 0,
							'description' => 'Optional parent post ID to attach the media to.',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success'       => [ 'type' => 'boolean' ],
						'id'            => [ 'type' => 'integer', 'description' => 'Attachment ID.' ],
						'attachment_id' => [ 'type' => 'integer', 'description' => 'Alias for id.' ],
						'url'           => [ 'type' => 'string',  'description' => 'Local media URL.' ],
						'filename'      => [ 'type' => 'string' ],
						'mime_type'     => [ 'type' => 'string' ],
						'reused'        => [ 'type' => 'boolean', 'description' => 'True when an existing attachment was returned instead of downloading.' ],
						'error'         => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			]
		);
	}

	public static function execute( ?array $input ): array {
		$asset_url = trim( (string) ( $input['asset_url'] ?? '' ) );
		$filename  = trim( (string) ( $input['filename']  ?? '' ) );
		$title     = trim( (string) ( $input['title']     ?? '' ) );
		$alt_text  = trim( (string) ( $input['alt_text']  ?? '' ) );
		$page_id   = (int) ( $input['page_id'] ?? 0 );

		if ( $asset_url === '' ) {
			return [ 'success' => false, 'error' => 'asset_url is required.' ];
		}

		// Derive filename from URL if not supplied.
		if ( $filename === '' ) {
			$filename = basename( (string) parse_url( $asset_url, PHP_URL_PATH ) );
		}
		if ( $filename === '' ) {
			$filename = 'asset-' . substr( md5( $asset_url ), 0, 8 );
		}

		// Check if an attachment with this filename already exists (dedup).
		$existing = self::find_existing_attachment( $filename );
		if ( $existing ) {
			return array_merge( $existing, [ 'reused' => true ] );
		}

		// Ensure required WP admin files are available.
		self::require_sideload_includes();

		// Download to a temp file.
		$tmp = download_url( $asset_url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return [
				'success' => false,
				'error'   => 'download_url failed: ' . $tmp->get_error_message(),
			];
		}

		// Prepare file for sideload.
		$file_array = [
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		];

		// Handle the sideload.
		$attachment_id = media_handle_sideload( $file_array, $page_id, $title ?: null );

		// Clean up temp file on error.
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return [
				'success' => false,
				'error'   => 'media_handle_sideload failed: ' . $attachment_id->get_error_message(),
			];
		}

		// Set alt text.
		if ( $alt_text !== '' ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		$local_url = (string) wp_get_attachment_url( $attachment_id );
		$mime      = (string) get_post_mime_type( $attachment_id );

		return [
			'success'       => true,
			'id'            => $attachment_id,
			'attachment_id' => $attachment_id,
			'url'           => $local_url,
			'filename'      => basename( $local_url ),
			'mime_type'     => $mime,
			'reused'        => false,
		];
	}

	// -------------------------------------------------------------------------

	/**
	 * Look up an existing attachment by filename in the uploads folder.
	 *
	 * @param string $filename
	 * @return array|null  Ready-to-return result array or null if not found.
	 */
	private static function find_existing_attachment( string $filename ): ?array {
		global $wpdb;

		$sanitized = sanitize_file_name( $filename );
		$like      = '%/' . $wpdb->esc_like( $sanitized );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				  WHERE meta_key = '_wp_attached_file'
				    AND meta_value LIKE %s
				  ORDER BY post_id DESC
				  LIMIT 1",
				$like
			)
		);

		if ( ! $id ) {
			return null;
		}

		$url  = (string) wp_get_attachment_url( $id );
		$mime = (string) get_post_mime_type( $id );

		return [
			'success'       => true,
			'id'            => $id,
			'attachment_id' => $id,
			'url'           => $url,
			'filename'      => $sanitized,
			'mime_type'     => $mime,
		];
	}

	private static function require_sideload_includes(): void {
		foreach ( [
			ABSPATH . 'wp-admin/includes/media.php',
			ABSPATH . 'wp-admin/includes/file.php',
			ABSPATH . 'wp-admin/includes/image.php',
		] as $file ) {
			if ( ! function_exists( 'media_handle_sideload' ) || ! function_exists( 'download_url' ) ) {
				require_once $file;
			}
		}
	}
}
