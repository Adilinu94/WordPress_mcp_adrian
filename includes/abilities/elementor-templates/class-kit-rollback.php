<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Rollback — snapshot and undo for kit imports.
 *
 * Snapshots are stored as a JSON-encoded array in the WP option
 * `_novamira_kit_snapshots` (a list, newest first, capped at 10 entries).
 *
 * Rollback covers:
 * - Delete all posts created during this import (_novamira_kit_imported = session_id).
 * - Restore design system (Kit_Manifest globals → Elementor kit post).
 * - Restore WP site settings (blogname, page_on_front, permalink, theme, menus).
 * - Deactivate (not delete) auto-installed plugins.
 *
 * @since 1.7.0
 */
class Kit_Rollback {

	const OPTION_KEY    = '_novamira_kit_snapshots';
	const MAX_SNAPSHOTS = 10;

	/**
	 * Capture a pre-import snapshot.
	 *
	 * @param string       $kit_name
	 * @param Kit_Manifest $manifest
	 * @return string  snapshot_id  (e.g. "kit_20260621_1542")
	 */
	public static function create_snapshot( string $kit_name, Kit_Manifest $manifest ): string {
		$snapshot_id = 'kit_' . date( 'Ymd_Hi' ) . '_' . substr( uniqid(), -4 );

		$snapshot = [
			'id'        => $snapshot_id,
			'kit_name'  => $kit_name,
			'timestamp' => gmdate( 'c' ),
			'pre'       => [
				'settings' => [
					'blogname'            => get_option( 'blogname' ),
					'blogdescription'     => get_option( 'blogdescription' ),
					'page_on_front'       => (int) get_option( 'page_on_front' ),
					'page_for_posts'      => (int) get_option( 'page_for_posts' ),
					'show_on_front'       => get_option( 'show_on_front' ),
					'permalink_structure' => get_option( 'permalink_structure' ),
					'timezone_string'     => get_option( 'timezone_string' ),
				],
				'theme'              => get_option( 'stylesheet' ),
				'nav_menu_locations' => get_theme_mod( 'nav_menu_locations', [] ),
				'plugins_installed'  => [],  // filled by Kit_Plugin_Installer
			],
			'plugins_installed' => [],  // populated during import
			'posts_created'     => [],  // populated during import
			'menus_created'     => [],  // populated during import
		];

		self::store_snapshot( $snapshot );

		return $snapshot_id;
	}

	/**
	 * Record which posts were created during import.
	 * Call this after Kit_Page_Creator::create_all().
	 *
	 * @param string             $snapshot_id
	 * @param array<string, int> $id_map  { template_ref => post_id }
	 */
	public static function record_posts( string $snapshot_id, array $id_map ): void {
		self::update_snapshot( $snapshot_id, 'posts_created', array_values( $id_map ) );
	}

	/**
	 * Record which plugins were installed during import.
	 *
	 * @param string   $snapshot_id
	 * @param string[] $plugin_files  e.g. ["elementskit-lite/elementskit-lite.php"]
	 */
	public static function record_plugins( string $snapshot_id, array $plugin_files ): void {
		self::update_snapshot( $snapshot_id, 'plugins_installed', $plugin_files );
	}

	/**
	 * Record which menu IDs were created.
	 *
	 * @param string  $snapshot_id
	 * @param int[]   $menu_ids
	 */
	public static function record_menus( string $snapshot_id, array $menu_ids ): void {
		self::update_snapshot( $snapshot_id, 'menus_created', $menu_ids );
	}

	/**
	 * Roll back a previous import.
	 *
	 * @param string $snapshot_id
	 * @return array  { success, actions: [] }
	 */
	public static function rollback( string $snapshot_id ): array {
		$snapshot = self::find_snapshot( $snapshot_id );
		if ( ! $snapshot ) {
			return [ 'success' => false, 'error' => "Snapshot '{$snapshot_id}' not found." ];
		}

		$actions = [];

		// 1. Delete all created posts.
		foreach ( $snapshot['posts_created'] ?? [] as $post_id ) {
			wp_delete_post( (int) $post_id, true );
			$actions[] = "deleted post #{$post_id}";
		}

		// 2. Delete created menus.
		foreach ( $snapshot['menus_created'] ?? [] as $menu_id ) {
			wp_delete_nav_menu( (int) $menu_id );
			$actions[] = "deleted menu #{$menu_id}";
		}

		// 3. Deactivate (not delete) installed plugins.
		$plugin_files = $snapshot['plugins_installed'] ?? [];
		if ( ! empty( $plugin_files ) ) {
			deactivate_plugins( $plugin_files );
			foreach ( $plugin_files as $f ) {
				$actions[] = "deactivated plugin {$f}";
			}
		}

		// 4. Restore WP settings.
		$pre = $snapshot['pre'] ?? [];
		foreach ( $pre['settings'] ?? [] as $option => $value ) {
			update_option( $option, $value );
		}
		$actions[] = 'restored site settings';

		// 5. Restore theme.
		if ( ! empty( $pre['theme'] ) ) {
			switch_theme( $pre['theme'] );
			$actions[] = "restored theme: {$pre['theme']}";
		}

		// 6. Restore nav menu locations.
		if ( isset( $pre['nav_menu_locations'] ) ) {
			set_theme_mod( 'nav_menu_locations', $pre['nav_menu_locations'] );
			$actions[] = 'restored nav_menu_locations';
		}

		// 7. Flush rewrite rules.
		flush_rewrite_rules();

		// 8. Remove snapshot from store.
		self::delete_snapshot( $snapshot_id );

		return [ 'success' => true, 'actions' => $actions ];
	}

	/**
	 * @param int $limit
	 * @return array[]
	 */
	public static function list_snapshots( int $limit = 10 ): array {
		$all = self::load_snapshots();
		return array_slice( $all, 0, $limit );
	}

	/**
	 * Remove snapshots older than N days.
	 *
	 * @param int $older_than_days
	 * @return int  Number of removed snapshots.
	 */
	public static function cleanup( int $older_than_days = 7 ): int {
		$all      = self::load_snapshots();
		$cutoff   = time() - ( $older_than_days * DAY_IN_SECONDS );
		$removed  = 0;
		$filtered = [];

		foreach ( $all as $snapshot ) {
			$ts = strtotime( $snapshot['timestamp'] ?? '' );
			if ( $ts && $ts < $cutoff ) {
				$removed++;
			} else {
				$filtered[] = $snapshot;
			}
		}

		if ( $removed > 0 ) {
			update_option( self::OPTION_KEY, $filtered );
		}

		return $removed;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	private static function load_snapshots(): array {
		$data = get_option( self::OPTION_KEY, [] );
		return is_array( $data ) ? $data : [];
	}

	private static function store_snapshot( array $snapshot ): void {
		$all = self::load_snapshots();
		array_unshift( $all, $snapshot );
		$all = array_slice( $all, 0, self::MAX_SNAPSHOTS );
		update_option( self::OPTION_KEY, $all );
	}

	private static function find_snapshot( string $snapshot_id ): ?array {
		foreach ( self::load_snapshots() as $s ) {
			if ( ( $s['id'] ?? '' ) === $snapshot_id ) {
				return $s;
			}
		}
		return null;
	}

	private static function update_snapshot( string $snapshot_id, string $key, array $value ): void {
		$all = self::load_snapshots();
		foreach ( $all as &$s ) {
			if ( ( $s['id'] ?? '' ) === $snapshot_id ) {
				$s[ $key ] = $value;
				break;
			}
		}
		update_option( self::OPTION_KEY, $all );
	}

	private static function delete_snapshot( string $snapshot_id ): void {
		$all      = self::load_snapshots();
		$filtered = array_filter( $all, fn( $s ) => ( $s['id'] ?? '' ) !== $snapshot_id );
		update_option( self::OPTION_KEY, array_values( $filtered ) );
	}
}
