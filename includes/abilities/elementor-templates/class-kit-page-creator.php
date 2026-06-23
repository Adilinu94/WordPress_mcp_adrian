<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Page_Creator — creates WordPress posts from Kit_Manifest template entries.
 *
 * Each call to create_all() iterates the normalised template list from Kit_Manifest
 * and inserts one post per template. Returns a map of { template_id => post_id }
 * so subsequent phases (menus, site configurator) can resolve references.
 *
 * SEO meta is written for Yoast SEO (WPSEO_VERSION) and Rank Math (RANK_MATH_VERSION)
 * if those plugins are active. No hard dependency on either.
 *
 * @since 1.7.0
 */
class Kit_Page_Creator {

	/**
	 * Create posts for all templates in the manifest.
	 *
	 * @param Kit_Manifest $manifest
	 * @param string       $import_session_id  Short identifier written to _novamira_kit_imported.
	 * @param bool         $dry_run            Count without writing anything.
	 * @return array {
	 *   'created'  => array<string, int>,   // { template_id => post_id }
	 *   'results'  => array[],
	 *   'errors'   => string[],
	 * }
	 */
	public static function create_all(
		Kit_Manifest $manifest,
		string $import_session_id = '',
		bool $dry_run = false
	): array {
		$id_map  = [];
		$results = [];
		$errors  = [];

		foreach ( $manifest->get_templates() as $tpl ) {
			// Skip the global-styles entry — it's imported into the kit, not as a page.
			if ( 'global-styles' === $tpl['type'] ) {
				continue;
			}

			$result = self::create_one( $tpl, $import_session_id, $dry_run );

			if ( isset( $result['error'] ) ) {
				$errors[] = $result['error'];
				continue;
			}

			$id_map[ $tpl['id'] ] = $result['post_id'];
			$results[]            = $result;

			// Write SEO meta after post exists.
			if ( ! $dry_run && ! empty( $tpl['seo'] ) ) {
				self::write_seo_meta( $result['post_id'], $tpl['seo'] );
			}
		}

		return [
			'created' => $id_map,
			'results' => $results,
			'errors'  => $errors,
		];
	}

	/**
	 * Create a single post from a normalised template entry.
	 *
	 * @param array  $tpl               Normalised template from Kit_Manifest::get_templates().
	 * @param string $import_session_id
	 * @param bool   $dry_run
	 * @return array  Either { post_id, template_id, title, post_type, edit_url }
	 *               or     { error: string }.
	 */
	public static function create_one( array $tpl, string $import_session_id, bool $dry_run ): array {
		$post_type   = $tpl['post_type'];
		$title       = $tpl['title'];
		$template_id = $tpl['id'];

		if ( $dry_run ) {
			return [
				'post_id'     => 0,
				'template_id' => $template_id,
				'title'       => $title,
				'post_type'   => $post_type,
				'edit_url'    => '',
				'dry_run'     => true,
			];
		}

		$page_template = $tpl['page_settings']['wp_page_template'] ?? 'elementor_header_footer';

		$post_id = wp_insert_post(
			[
				'post_title'     => $title,
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'post_name'      => $template_id,
				'page_template'  => $page_template,
				'meta_input'     => [
					'_elementor_template_type'   => $tpl['type'],
					'_elementor_edit_mode'        => 'builder',
					'_novamira_kit_imported'      => $import_session_id,
					'_novamira_kit_template_ref'  => $template_id,
				],
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return [ 'error' => "Failed to create '{$title}': " . $post_id->get_error_message() ];
		}

		// Write _elementor_data via raw update to avoid wp_slash corruption on large JSON.
		if ( ! empty( $tpl['content'] ) ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $tpl['content'] ) ) );
		}

		if ( ! empty( $tpl['conditions'] ) ) {
			update_post_meta( $post_id, '_elementor_conditions', wp_slash( wp_json_encode( $tpl['conditions'] ) ) );
		}

		if ( ! empty( $tpl['page_settings'] ) ) {
			update_post_meta( $post_id, '_elementor_page_settings', wp_slash( wp_json_encode( $tpl['page_settings'] ) ) );
		}

		// Elementor version tag.
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		return [
			'post_id'     => $post_id,
			'template_id' => $template_id,
			'title'       => $title,
			'post_type'   => $post_type,
			'edit_url'    => admin_url( "post.php?post={$post_id}&action=elementor" ),
		];
	}

	/**
	 * Write SEO meta for Yoast SEO and Rank Math (if active).
	 *
	 * @param int   $post_id
	 * @param array $seo  From manifest template seo field.
	 */
	public static function write_seo_meta( int $post_id, array $seo ): void {
		if ( defined( 'WPSEO_VERSION' ) ) {
			if ( ! empty( $seo['yoast_title'] ) ) {
				update_post_meta( $post_id, '_yoast_wpseo_title', $seo['yoast_title'] );
			}
			if ( ! empty( $seo['yoast_description'] ) ) {
				update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo['yoast_description'] );
			}
			if ( ! empty( $seo['focus_keyword'] ) ) {
				update_post_meta( $post_id, '_yoast_wpseo_focuskw', $seo['focus_keyword'] );
			}
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			if ( ! empty( $seo['rankmath_title'] ) ) {
				update_post_meta( $post_id, 'rank_math_title', $seo['rankmath_title'] );
			}
			if ( ! empty( $seo['rankmath_description'] ) ) {
				update_post_meta( $post_id, 'rank_math_description', $seo['rankmath_description'] );
			}
			if ( ! empty( $seo['focus_keyword'] ) ) {
				update_post_meta( $post_id, 'rank_math_focus_keyword', $seo['focus_keyword'] );
			}
		}
	}

	/**
	 * Resolve a template reference (e.g. "homepage") to its post ID via post meta.
	 * Used by Kit_Site_Configurator and Kit_Menu_Builder after create_all().
	 *
	 * @param string $template_ref
	 * @return int|null
	 */
	public static function resolve_template_ref( string $template_ref ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_novamira_kit_template_ref'
				   AND meta_value = %s
				 ORDER BY post_id DESC
				 LIMIT 1",
				$template_ref
			)
		);

		return $post_id ? (int) $post_id : null;
	}
}
