<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Media_Handler — download kit media and replace source URLs in Elementor data.
 *
 * Strategies:
 *   download       — fetch each file, insert into WP media library, rewrite URLs in _elementor_data.
 *   url_reference  — rewrite source_base_url → site uploads URL without downloading.
 *   skip           — no changes.
 *
 * @since 1.7.0
 */
class Kit_Media_Handler {

	/**
	 * Process all media from the manifest for a set of created posts.
	 *
	 * @param Kit_Manifest       $manifest
	 * @param array<string, int> $id_map    { template_ref => post_id }
	 * @param string             $strategy  'download' | 'url_reference' | 'skip'
	 * @param bool               $dry_run
	 * @return array  { downloaded, skipped, errors, url_map }
	 */
	public static function process(
		Kit_Manifest $manifest,
		array $id_map,
		string $strategy = 'download',
		bool $dry_run = false
	): array {
		if ( 'skip' === $strategy ) {
			return [ 'downloaded' => 0, 'skipped' => 0, 'errors' => [], 'url_map' => [] ];
		}

		$media_list  = $manifest->get_media();
		$url_map     = [];
		$downloaded  = 0;
		$skipped     = 0;
		$errors      = [];

		foreach ( $media_list as $media ) {
			$url      = $media['url'] ?? '';
			$filename = $media['filename'] ?? '';
			$ref      = $media['ref'] ?? $filename;

			if ( ! $url || ! $filename ) {
				$errors[] = "Skipped entry with missing url or filename: " . json_encode( $media );
				continue;
			}

			if ( $dry_run ) {
				$url_map[ $ref ] = '(dry_run)';
				$downloaded++;
				continue;
			}

			if ( 'url_reference' === $strategy ) {
				// Rewrite source domain to local uploads URL without downloading.
				$local_url       = self::rewrite_to_local( $url );
				$url_map[ $ref ] = $local_url;
				$skipped++;
				continue;
			}

			// download strategy.
			$already = self::find_by_filename( $filename );
			if ( $already ) {
				$url_map[ $ref ] = wp_get_attachment_url( $already );
				$skipped++;
				continue;
			}

			$attachment_id = self::download_and_insert( $url, $filename );
			if ( is_wp_error( $attachment_id ) ) {
				$errors[] = "'{$filename}': " . $attachment_id->get_error_message();
				continue;
			}

			$url_map[ $ref ] = wp_get_attachment_url( $attachment_id );
			$downloaded++;
		}

		// Replace URLs in all created posts' _elementor_data.
		if ( ! $dry_run && ! empty( $url_map ) ) {
			foreach ( $id_map as $post_id ) {
				self::replace_urls_in_post( (int) $post_id, $url_map );
			}
		}

		return [
			'downloaded' => $downloaded,
			'skipped'    => $skipped,
			'errors'     => $errors,
			'url_map'    => $url_map,
		];
	}

	// -------------------------------------------------------------------------

	/**
	 * Download a file from a URL and insert into the WP media library.
	 *
	 * @param string $url
	 * @param string $filename
	 * @return int|\WP_Error  Attachment ID.
	 */
	public static function download_and_insert( string $url, string $filename ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp,
		];

		$id = media_handle_sideload( $file_array, 0, $filename );

		if ( file_exists( $tmp ) ) {
			@unlink( $tmp );
		}

		return $id;
	}

	/**
	 * Replace source URLs with local URLs inside a post's _elementor_data.
	 *
	 * @param int                $post_id
	 * @param array<string, string> $url_map  { ref_or_original_url => local_url }
	 */
	public static function replace_urls_in_post( int $post_id, array $url_map ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
			$post_id
		) );

		if ( ! $raw ) {
			return;
		}

		$replaced = $raw;
		foreach ( $url_map as $original => $local ) {
			if ( $original && $local && $original !== $local ) {
				$replaced = str_replace( $original, $local, $replaced );
				// Also replace URL-encoded form (for JSON string context).
				$replaced = str_replace(
					addslashes( $original ),
					addslashes( $local ),
					$replaced
				);
			}
		}

		if ( $replaced !== $raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_value' => $replaced ],
				[ 'post_id' => $post_id, 'meta_key' => '_elementor_data' ],
				[ '%s' ],
				[ '%d', '%s' ]
			);
			delete_post_meta( $post_id, '_elementor_css' );
		}
	}

	/**
	 * Find an existing attachment by filename (post_name match).
	 *
	 * @param string $filename
	 * @return int|null  Attachment ID.
	 */
	private static function find_by_filename( string $filename ): ?int {
		$slug  = pathinfo( $filename, PATHINFO_FILENAME );
		$query = new \WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'name'           => sanitize_title( $slug ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );

		return $query->have_posts() ? (int) $query->posts[0] : null;
	}

	/**
	 * Rewrite a remote media URL to the local uploads URL (without downloading).
	 * Falls back to the original URL if it can't be mapped.
	 */
	private static function rewrite_to_local( string $url ): string {
		$parsed = parse_url( $url );
		if ( ! isset( $parsed['path'] ) ) {
			return $url;
		}

		// Strip everything up to /wp-content/uploads/ and prepend local base.
		$uploads_pos = strpos( $parsed['path'], '/wp-content/uploads/' );
		if ( false === $uploads_pos ) {
			return $url;
		}

		$relative = substr( $parsed['path'], $uploads_pos + strlen( '/wp-content/uploads/' ) );
		return trailingslashit( wp_upload_dir()['baseurl'] ) . $relative;
	}
}
