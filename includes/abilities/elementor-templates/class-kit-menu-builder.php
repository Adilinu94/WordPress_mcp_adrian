<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Menu_Builder — creates WordPress nav menus from a Kit_Manifest.
 *
 * Supports target formats:
 *   "page:homepage"      → resolves to imported page post_id
 *   "url:https://..."    → custom link
 *   "home"               → home_url()
 *   "category:blog"      → category term link
 *
 * @since 1.7.0
 */
class Kit_Menu_Builder {

	/**
	 * Create all menus from the manifest.
	 *
	 * @param Kit_Manifest       $manifest
	 * @param array<string, int> $id_map   { template_ref => post_id } from Kit_Page_Creator.
	 * @param bool               $dry_run
	 * @return array  { created: [], errors: [] }
	 */
	public static function create_all(
		Kit_Manifest $manifest,
		array $id_map = [],
		bool $dry_run = false
	): array {
		$created = [];
		$errors  = [];

		foreach ( $manifest->get_menus() as $menu_config ) {
			$result = self::create_menu( $menu_config, $id_map, $dry_run );
			if ( isset( $result['error'] ) ) {
				$errors[] = $result['error'];
			} else {
				$created[] = $result;
			}
		}

		return [ 'created' => $created, 'errors' => $errors ];
	}

	/**
	 * Create a single nav menu.
	 *
	 * @param array              $menu_config  One entry from manifest menus[].
	 * @param array<string, int> $id_map
	 * @param bool               $dry_run
	 * @return array
	 */
	public static function create_menu( array $menu_config, array $id_map, bool $dry_run ): array {
		$name     = $menu_config['name'] ?? 'Unnamed Menu';
		$location = $menu_config['location'] ?? '';
		$items    = $menu_config['items'] ?? [];

		if ( $dry_run ) {
			return [
				'name'       => $name,
				'location'   => $location,
				'items'      => count( $items ),
				'dry_run'    => true,
			];
		}

		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			return [ 'error' => "Failed to create menu '{$name}': " . $menu_id->get_error_message() ];
		}

		$item_count = 0;
		foreach ( $items as $item ) {
			$args = self::resolve_target( $item['target'] ?? 'url:#', $id_map );
			wp_update_nav_menu_item(
				$menu_id,
				0,
				[
					'menu-item-title'     => $item['title'] ?? '',
					'menu-item-url'       => $args['url'],
					'menu-item-type'      => $args['type'],
					'menu-item-object'    => $args['object'],
					'menu-item-object-id' => $args['object_id'],
					'menu-item-status'    => 'publish',
				]
			);
			$item_count++;
		}

		if ( $location ) {
			$locations              = get_theme_mod( 'nav_menu_locations', [] );
			$locations[ $location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		return [
			'name'     => $name,
			'menu_id'  => $menu_id,
			'location' => $location,
			'items'    => $item_count,
		];
	}

	/**
	 * Parse a menu item target string into wp_update_nav_menu_item args.
	 *
	 * @param string             $target  E.g. "page:homepage", "url:https://…", "home".
	 * @param array<string, int> $id_map
	 * @return array { url, type, object, object_id }
	 */
	public static function resolve_target( string $target, array $id_map ): array {
		// Defaults for a plain custom link.
		$result = [
			'url'       => '#',
			'type'      => 'custom',
			'object'    => 'custom',
			'object_id' => 0,
		];

		if ( 'home' === $target ) {
			$result['url'] = home_url( '/' );
			return $result;
		}

		$colon = strpos( $target, ':' );
		if ( false === $colon ) {
			return $result;
		}

		$prefix = substr( $target, 0, $colon );
		$value  = substr( $target, $colon + 1 );

		switch ( $prefix ) {
			case 'url':
				$result['url'] = $value;
				break;

			case 'page':
				$post_id = $id_map[ $value ] ?? Kit_Page_Creator::resolve_template_ref( $value );
				if ( $post_id ) {
					$result['url']       = get_permalink( $post_id ) ?: '#';
					$result['type']      = 'post_type';
					$result['object']    = 'page';
					$result['object_id'] = $post_id;
				}
				break;

			case 'post':
				$post_id = (int) $value;
				if ( $post_id > 0 ) {
					$result['url']       = get_permalink( $post_id ) ?: '#';
					$result['type']      = 'post_type';
					$result['object']    = 'post';
					$result['object_id'] = $post_id;
				}
				break;

			case 'category':
				$term = get_term_by( 'slug', $value, 'category' );
				if ( $term ) {
					$result['url']       = get_term_link( $term ) ?: '#';
					$result['type']      = 'taxonomy';
					$result['object']    = 'category';
					$result['object_id'] = $term->term_id;
				}
				break;
		}

		return $result;
	}
}
