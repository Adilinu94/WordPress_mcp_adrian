<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Site_Configurator — applies WordPress site settings from a Kit_Manifest.
 *
 * Covers: site name/tagline, timezone, date format, permalink structure,
 * front page / posts page, theme activation + mods, default content cleanup.
 *
 * All methods are idempotent: running twice leaves the site in the same state.
 *
 * @since 1.7.0
 */
class Kit_Site_Configurator {

	/**
	 * Apply all site settings from the manifest.
	 *
	 * @param Kit_Manifest        $manifest
	 * @param array<string, int>  $id_map    { template_ref => post_id } from Kit_Page_Creator.
	 * @param bool                $dry_run
	 * @return array  Status report.
	 */
	public static function configure(
		Kit_Manifest $manifest,
		array $id_map = [],
		bool $dry_run = false
	): array {
		$report = [];

		$settings = $manifest->get_settings();
		if ( ! empty( $settings ) ) {
			$report['site_settings'] = self::apply_site_settings( $settings, $dry_run );
		}

		if ( ! empty( $settings['front_page'] ) ) {
			$report['front_page'] = self::set_front_page( $settings, $id_map, $dry_run );
		}

		$theme = $manifest->get_theme_config();
		if ( ! empty( $theme ) ) {
			$report['theme'] = self::apply_theme( $theme, $dry_run );
		}

		$cleanup = $manifest->get_cleanup_config();
		if ( ! empty( $cleanup ) ) {
			$report['cleanup'] = self::run_cleanup( $cleanup, $dry_run );
		}

		return $report;
	}

	// -------------------------------------------------------------------------

	public static function apply_site_settings( array $settings, bool $dry_run ): array {
		$applied = [];

		$map = [
			'site_name'    => 'blogname',
			'site_tagline' => 'blogdescription',
			'timezone'     => 'timezone_string',
			'date_format'  => 'date_format',
		];

		foreach ( $map as $key => $option ) {
			if ( isset( $settings[ $key ] ) ) {
				if ( ! $dry_run ) {
					update_option( $option, $settings[ $key ] );
				}
				$applied[ $option ] = $settings[ $key ];
			}
		}

		if ( ! empty( $settings['permalink_structure'] ) ) {
			if ( ! $dry_run ) {
				global $wp_rewrite;
				$wp_rewrite->set_permalink_structure( $settings['permalink_structure'] );
				update_option( 'permalink_structure', $settings['permalink_structure'] );
				flush_rewrite_rules();
			}
			$applied['permalink_structure'] = $settings['permalink_structure'];
		}

		return $applied;
	}

	public static function set_front_page( array $settings, array $id_map, bool $dry_run ): array {
		$report = [];

		$front_ref = $settings['front_page'] ?? null;
		if ( $front_ref ) {
			$front_id = $id_map[ $front_ref ] ?? Kit_Page_Creator::resolve_template_ref( $front_ref );
			if ( $front_id ) {
				if ( ! $dry_run ) {
					update_option( 'show_on_front', 'page' );
					update_option( 'page_on_front', $front_id );
				}
				$report['page_on_front'] = $front_id;
			} else {
				$report['page_on_front_error'] = "Template ref '{$front_ref}' not found.";
			}
		}

		$posts_ref = $settings['posts_page'] ?? null;
		if ( $posts_ref ) {
			$posts_id = $id_map[ $posts_ref ] ?? Kit_Page_Creator::resolve_template_ref( $posts_ref );
			if ( $posts_id ) {
				if ( ! $dry_run ) {
					update_option( 'page_for_posts', $posts_id );
				}
				$report['page_for_posts'] = $posts_id;
			}
		}

		return $report;
	}

	public static function apply_theme( array $theme, bool $dry_run ): array {
		$report = [];

		if ( ! empty( $theme['stylesheet'] ) ) {
			if ( ! $dry_run ) {
				switch_theme( $theme['stylesheet'] );
			}
			$report['theme_activated'] = $theme['stylesheet'];
		}

		foreach ( $theme['mods'] ?? [] as $key => $value ) {
			// Logo is resolved to attachment ID by the media handler before this runs.
			// If it's still a string (filename ref), skip — caller is responsible.
			if ( is_int( $value ) || ( ! is_string( $value ) ) ) {
				if ( ! $dry_run ) {
					set_theme_mod( $key, $value );
				}
				$report['mods'][ $key ] = $value;
			}
		}

		return $report;
	}

	public static function run_cleanup( array $cleanup, bool $dry_run ): array {
		$deleted = [];

		if ( ! empty( $cleanup['delete_default_posts'] ) ) {
			// Post ID 1 = "Hello World" (default WP post).
			if ( ! $dry_run ) {
				wp_delete_post( 1, true );
			}
			$deleted[] = 'hello-world-post';

			$sample = get_page_by_path( 'sample-page' );
			if ( $sample ) {
				if ( ! $dry_run ) {
					wp_delete_post( $sample->ID, true );
				}
				$deleted[] = 'sample-page';
			}

			// Deactivate hello-dolly and akismet (cosmetic default plugins).
			foreach ( [ 'hello-dolly/hello.php', 'akismet/akismet.php' ] as $plugin_file ) {
				if ( is_plugin_active( $plugin_file ) ) {
					if ( ! $dry_run ) {
						deactivate_plugins( $plugin_file );
					}
					$deleted[] = "deactivated:{$plugin_file}";
				}
			}
		}

		if ( ! empty( $cleanup['delete_default_comment'] ) ) {
			if ( ! $dry_run ) {
				wp_delete_comment( 1, true );
			}
			$deleted[] = 'default-comment';
		}

		if ( ! empty( $cleanup['delete_default_tags'] ) ) {
			$uncategorized = get_term_by( 'slug', 'uncategorized', 'post_tag' );
			if ( $uncategorized ) {
				if ( ! $dry_run ) {
					wp_delete_term( $uncategorized->term_id, 'post_tag' );
				}
				$deleted[] = 'uncategorized-tag';
			}
		}

		return [ 'deleted' => $deleted ];
	}
}
