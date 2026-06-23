<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Plugin_Installer — check plugin status and install from WordPress.org.
 *
 * Rules:
 * - Already active + version OK  → skip, status "already_active"
 * - Installed but not active      → activate only
 * - Not installed, source=wordpress → install + activate
 * - Not on .org / source=custom   → report as "missing_premium", no install
 * - No filesystem write access    → abort with WP_Error
 * - No downgrade/update: if active but version too old, report only, don't touch
 *
 * @since 1.7.0
 */
class Kit_Plugin_Installer {

	/**
	 * Check status of all required plugins without installing anything.
	 *
	 * @param array[] $required  From Kit_Manifest::get_required_plugins().
	 * @return array  { ready: [], needs_install: [], needs_activate: [], too_old: [], not_found: [] }
	 */
	public static function check( array $required ): array {
		$result = [
			'ready'          => [],
			'needs_install'  => [],
			'needs_activate' => [],
			'too_old'        => [],
			'not_found'      => [],
		];

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $required as $plugin ) {
			$slug        = $plugin['slug'] ?? '';
			$min_version = $plugin['min_version'] ?? '';
			$source      = $plugin['source'] ?? 'wordpress';

			if ( ! $slug ) {
				continue;
			}

			$file    = self::find_plugin_file( $slug, $all_plugins );
			$is_active   = $file && is_plugin_active( $file );
			$installed   = $file !== null;
			$current_ver = $installed ? ( $all_plugins[ $file ]['Version'] ?? '0' ) : '0';

			if ( $is_active ) {
				if ( $min_version && version_compare( $current_ver, $min_version, '<' ) ) {
					$result['too_old'][] = array_merge( $plugin, [
						'installed_version' => $current_ver,
						'plugin_file'       => $file,
					] );
				} else {
					$result['ready'][] = array_merge( $plugin, [
						'installed_version' => $current_ver,
						'plugin_file'       => $file,
					] );
				}
			} elseif ( $installed ) {
				$result['needs_activate'][] = array_merge( $plugin, [
					'installed_version' => $current_ver,
					'plugin_file'       => $file,
				] );
			} elseif ( 'wordpress' === $source ) {
				$result['needs_install'][] = $plugin;
			} else {
				$result['not_found'][] = $plugin;
			}
		}

		return $result;
	}

	/**
	 * Install and activate all missing plugins from WordPress.org.
	 * Activate already-installed-but-inactive plugins.
	 * Report premium / not-found plugins without installing.
	 *
	 * @param array[] $required  From Kit_Manifest::get_required_plugins().
	 * @param bool    $dry_run
	 * @return array  { installed: [], activated: [], skipped: [], missing_premium: [], errors: [] }
	 */
	public static function install_all( array $required, bool $dry_run = false ): array {
		$status = self::check( $required );
		$result = [
			'installed'       => [],
			'activated'       => [],
			'skipped'         => [],
			'missing_premium' => [],
			'errors'          => [],
		];

		// Already active and OK.
		foreach ( $status['ready'] as $p ) {
			$result['skipped'][] = [
				'slug'    => $p['slug'],
				'action'  => 'already_active',
				'version' => $p['installed_version'],
			];
		}

		// Active but too old — report, don't update.
		foreach ( $status['too_old'] as $p ) {
			$result['skipped'][] = [
				'slug'              => $p['slug'],
				'action'            => 'active_but_outdated',
				'installed_version' => $p['installed_version'],
				'min_version'       => $p['min_version'],
			];
		}

		// Premium / not on .org.
		foreach ( $status['not_found'] as $p ) {
			$result['missing_premium'][] = [
				'slug' => $p['slug'],
				'name' => $p['name'],
				'url'  => $p['url'] ?? '',
			];
		}

		if ( $dry_run ) {
			foreach ( $status['needs_install'] as $p ) {
				$result['installed'][] = [ 'slug' => $p['slug'], 'dry_run' => true ];
			}
			foreach ( $status['needs_activate'] as $p ) {
				$result['activated'][] = [ 'slug' => $p['slug'], 'dry_run' => true ];
			}
			return $result;
		}

		// Activate installed-but-inactive.
		foreach ( $status['needs_activate'] as $p ) {
			$err = activate_plugin( $p['plugin_file'] );
			if ( is_wp_error( $err ) ) {
				$result['errors'][] = "Activate '{$p['slug']}': " . $err->get_error_message();
			} else {
				$result['activated'][] = [
					'slug'    => $p['slug'],
					'action'  => 'activated',
					'version' => $p['installed_version'],
				];
			}
		}

		// Install from WordPress.org.
		if ( ! empty( $status['needs_install'] ) ) {
			$fs_check = self::check_filesystem();
			if ( is_wp_error( $fs_check ) ) {
				foreach ( $status['needs_install'] as $p ) {
					$result['errors'][] = "Cannot install '{$p['slug']}': " . $fs_check->get_error_message();
				}
				return $result;
			}

			self::load_upgrader_classes();

			foreach ( $status['needs_install'] as $p ) {
				$install = self::install_from_wordpress_org( $p['slug'] );
				if ( is_wp_error( $install ) ) {
					$result['errors'][] = "Install '{$p['slug']}': " . $install->get_error_message();
					continue;
				}
				$result['installed'][] = [
					'slug'    => $p['slug'],
					'action'  => 'installed_activated',
					'version' => $install['version'] ?? '',
				];
			}
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Find the plugin file (relative to plugins dir) for a given slug.
	 *
	 * @param string $slug
	 * @param array  $all_plugins  From get_plugins().
	 * @return string|null  e.g. "elementskit-lite/elementskit-lite.php"
	 */
	public static function find_plugin_file( string $slug, array $all_plugins ): ?string {
		// Exact match: slug/slug.php
		$guess = "{$slug}/{$slug}.php";
		if ( isset( $all_plugins[ $guess ] ) ) {
			return $guess;
		}

		// Fallback: any plugin file whose directory matches the slug.
		foreach ( array_keys( $all_plugins ) as $file ) {
			if ( str_starts_with( $file, "{$slug}/" ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Install a single plugin from WordPress.org and activate it.
	 *
	 * @param string $slug
	 * @return array|\WP_Error  { version: string } on success.
	 */
	private static function install_from_wordpress_org( string $slug ) {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$api = plugins_api( 'plugin_information', [
			'slug'   => $slug,
			'fields' => [ 'download_link' => true, 'versions' => false ],
		] );

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new \WP_Error( 'install_failed', "Installation of '{$slug}' returned false." );
		}

		// Activate.
		$all_plugins = get_plugins();
		$file        = self::find_plugin_file( $slug, $all_plugins );
		if ( $file ) {
			$err = activate_plugin( $file );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
		}

		return [ 'version' => $api->version ?? '' ];
	}

	/**
	 * Verify WP filesystem is writable (required for plugin installation).
	 *
	 * @return true|\WP_Error
	 */
	private static function check_filesystem() {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$ok = WP_Filesystem();
		if ( ! $ok ) {
			return new \WP_Error( 'no_filesystem', 'No filesystem write access. Configure FS_METHOD or FTP credentials.' );
		}
		return true;
	}

	private static function load_upgrader_classes(): void {
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		}
	}
}
