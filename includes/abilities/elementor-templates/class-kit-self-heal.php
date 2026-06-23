<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Self_Heal — patches known Elementor/plugin bugs after a kit import.
 *
 * Patch A: checklist.js null-dereference
 *   Elementor checklist.min.js calls .parentElement on a querySelector result
 *   without a null-check, crashing when the Checklist button is absent.
 *   This patch wraps that access in a null-guard.
 *
 * Patch B: HFE CSS path fallback (pro-elements instead of elementor-pro)
 *   Header Footer Elementor resolves CSS paths via plugins_url().
 *   When elementor-pro is not installed but pro-elements is, those paths break.
 *   A plugins_url filter rewrites them at runtime (no file modification needed).
 *
 * @since 1.7.0
 */
class Kit_Self_Heal {

	/**
	 * Run all applicable patches.
	 *
	 * @param bool $dry_run  Check only, don't write.
	 * @return array  { applied: string[], skipped: string[], errors: string[] }
	 */
	public static function run_all( bool $dry_run = false ): array {
		$applied = [];
		$skipped = [];
		$errors  = [];

		// Patch A.
		$a = self::patch_checklist_js( $dry_run );
		if ( 'applied' === $a['status'] ) {
			$applied[] = 'checklist_null_guard';
		} elseif ( 'skipped' === $a['status'] ) {
			$skipped[] = 'checklist_null_guard: ' . ( $a['reason'] ?? '' );
		} elseif ( 'error' === $a['status'] ) {
			$errors[] = 'checklist_null_guard: ' . ( $a['reason'] ?? '' );
		}

		// Patch B — registered as filter, not a file patch.
		$b = self::register_hfe_css_filter( $dry_run );
		if ( 'applied' === $b['status'] ) {
			$applied[] = 'hfe_css_path_filter';
		} elseif ( 'skipped' === $b['status'] ) {
			$skipped[] = 'hfe_css_path_filter: ' . ( $b['reason'] ?? '' );
		}

		return [
			'applied' => $applied,
			'skipped' => $skipped,
			'errors'  => $errors,
		];
	}

	// -------------------------------------------------------------------------
	// Patch A: checklist.js null-guard
	// -------------------------------------------------------------------------

	/**
	 * Detect whether the checklist.js null-dereference bug is present.
	 *
	 * @return bool
	 */
	public static function checklist_bug_exists(): bool {
		$file = self::checklist_file_path();
		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}
		$content = file_get_contents( $file );
		return false !== strpos( $content, self::CHECKLIST_NEEDLE );
	}

	public static function patch_checklist_js( bool $dry_run = false ): array {
		if ( ! self::checklist_bug_exists() ) {
			return [ 'status' => 'skipped', 'reason' => 'Bug not present or file not found.' ];
		}

		if ( $dry_run ) {
			return [ 'status' => 'applied', 'dry_run' => true ];
		}

		$file    = self::checklist_file_path();
		$content = file_get_contents( $file );
		$patched = str_replace( self::CHECKLIST_NEEDLE, self::CHECKLIST_REPLACEMENT, $content );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $file, $patched );
		if ( false === $written ) {
			return [ 'status' => 'error', 'reason' => 'file_put_contents failed — check file permissions.' ];
		}

		return [ 'status' => 'applied' ];
	}

	/**
	 * Buggy string that crashes when the Checklist button doesn't exist in DOM.
	 */
	const CHECKLIST_NEEDLE = 'document.body.querySelector(\'[aria-label="Checklist"]\').parentElement.style.display';

	/**
	 * Null-safe replacement.
	 */
	const CHECKLIST_REPLACEMENT = 'null==document.body.querySelector(\'[aria-label="Checklist"]\')||(document.body.querySelector(\'[aria-label="Checklist"]\').parentElement.style.display';

	private static function checklist_file_path(): ?string {
		$candidates = [
			WP_PLUGIN_DIR . '/elementor/assets/js/checklist.min.js',
			WP_PLUGIN_DIR . '/elementor/assets/js/checklist.js',
		];
		foreach ( $candidates as $c ) {
			if ( file_exists( $c ) ) {
				return $c;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Patch B: HFE CSS path filter
	// -------------------------------------------------------------------------

	/**
	 * Register a plugins_url filter that rewrites elementor-pro → pro-elements
	 * for CSS assets when elementor-pro isn't installed but pro-elements is.
	 *
	 * This is a runtime filter, not a file patch — safe to register repeatedly.
	 */
	public static function register_hfe_css_filter( bool $dry_run = false ): array {
		// Only needed when elementor-pro dir is absent and pro-elements exists.
		$ep_dir  = WP_PLUGIN_DIR . '/elementor-pro';
		$pe_dir  = WP_PLUGIN_DIR . '/pro-elements';

		if ( is_dir( $ep_dir ) ) {
			return [ 'status' => 'skipped', 'reason' => 'elementor-pro exists, no rewrite needed.' ];
		}

		if ( ! is_dir( $pe_dir ) ) {
			return [ 'status' => 'skipped', 'reason' => 'pro-elements not installed, rewrite not applicable.' ];
		}

		if ( $dry_run ) {
			return [ 'status' => 'applied', 'dry_run' => true ];
		}

		if ( ! has_filter( 'plugins_url', [ self::class, 'rewrite_pro_css_url' ] ) ) {
			add_filter( 'plugins_url', [ self::class, 'rewrite_pro_css_url' ], 10, 3 );
		}

		return [ 'status' => 'applied' ];
	}

	/**
	 * Filter callback: rewrite elementor-pro CSS paths to pro-elements.
	 *
	 * @param string $url
	 * @param string $path
	 * @param string $plugin
	 * @return string
	 */
	public static function rewrite_pro_css_url( string $url, string $path, string $plugin ): string {
		if ( str_contains( $path, 'elementor-pro/assets/css/' )
			&& ! is_dir( WP_PLUGIN_DIR . '/elementor-pro' ) ) {
			return str_replace( 'elementor-pro/assets/css/', 'pro-elements/assets/css/', $url );
		}
		return $url;
	}
}
