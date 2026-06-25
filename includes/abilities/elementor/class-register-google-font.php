<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/register-google-font
 *
 * Typed, testable replacement for the raw novamira/execute-php calls that
 * site-clone-to-v3's fonts-plugin-adapter uses to register Google Fonts
 * through the Olympus Google Fonts plugin (olympus-google-fonts).
 *
 * Supports all three registration modes the adapter needs:
 *   - "google"  — adds to ogf_load_fonts theme-mod (e.g. "Inter:400,700")
 *   - "system"  — adds to ogf_system_fonts theme-mod
 *   - "detect"  — returns plugin status and current font list (read-only)
 *
 * @since 1.7.1
 */
class Register_Google_Font {

	const OGF_PLUGIN_FILE = 'olympus-google-fonts/olympus-google-fonts.php';
	const OGF_TAXONOMY    = 'ogf_custom_fonts';

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/register-google-font',
			[
				'label'       => 'Register Google Font (Fonts Plugin)',
				'description' => 'Typed replacement for raw execute-php font-registration calls. Three modes: detect (read plugin status + current fonts), google (register a Google Font family via ogf_load_fonts theme-mod), system (register a system font via ogf_system_fonts theme-mod). Requires the Olympus Google Fonts plugin (olympus-google-fonts) or compatible Fonts Plugin.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'mode' ],
					'properties' => [
						'mode' => [
							'type'        => 'string',
							'enum'        => [ 'detect', 'google', 'system' ],
							'description' => 'detect — read plugin status; google — register Google Font; system — register system/OS font.',
						],
						'family' => [
							'type'        => 'string',
							'description' => 'Font family name (e.g. "Inter", "Roboto"). Required for google and system modes.',
						],
						'weights' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'integer' ],
							'default'     => [ 400, 700 ],
							'description' => 'Font weights to load for google mode (e.g. [400, 700]).',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success'   => [ 'type' => 'boolean' ],
						'mode'      => [ 'type' => 'string' ],
						'family'    => [ 'type' => 'string' ],
						'active'    => [ 'type' => 'boolean', 'description' => 'detect: plugin is active.' ],
						'version'   => [ 'type' => 'string',  'description' => 'detect: plugin version.' ],
						'fonts'     => [ 'type' => 'array',   'description' => 'detect: currently registered fonts.' ],
						'font_spec' => [ 'type' => 'string',  'description' => 'google: spec added (e.g. "Inter:400,700").' ],
						'skipped'   => [ 'type' => 'boolean', 'description' => 'true if family was already registered.' ],
						'error'     => [ 'type' => 'string' ],
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
		$mode    = trim( (string) ( $input['mode']   ?? 'detect' ) );
		$family  = trim( (string) ( $input['family'] ?? '' ) );
		$weights = array_map( 'intval', (array) ( $input['weights'] ?? [ 400, 700 ] ) );
		$weights = array_filter( $weights );
		$weights = empty( $weights ) ? [ 400, 700 ] : array_values( $weights );

		switch ( $mode ) {
			case 'detect':
				return self::detect();

			case 'google':
				if ( $family === '' ) {
					return [ 'success' => false, 'error' => 'family is required for google mode.' ];
				}
				return self::register_google( $family, $weights );

			case 'system':
				if ( $family === '' ) {
					return [ 'success' => false, 'error' => 'family is required for system mode.' ];
				}
				return self::register_system( $family );

			default:
				return [ 'success' => false, 'error' => "Unknown mode '{$mode}'. Use detect, google, or system." ];
		}
	}

	// -------------------------------------------------------------------------

	private static function detect(): array {
		$active  = function_exists( 'is_plugin_active' ) && is_plugin_active( self::OGF_PLUGIN_FILE );
		$version = defined( 'OGF_VERSION' ) ? OGF_VERSION : null;

		$google_fonts  = get_theme_mod( 'ogf_load_fonts', [] );
		$system_fonts  = get_theme_mod( 'ogf_system_fonts', [] );
		$font_display  = get_theme_mod( 'ogf_font_display', null );

		return [
			'success'       => true,
			'mode'          => 'detect',
			'active'        => $active,
			'version'       => $version,
			'fonts'         => is_array( $google_fonts ) ? $google_fonts : [],
			'system_fonts'  => is_array( $system_fonts ) ? $system_fonts : [],
			'font_display'  => $font_display,
		];
	}

	/**
	 * Register a Google Font by adding it to the ogf_load_fonts theme-mod.
	 * Skips if the family is already present. Sets font-display: swap.
	 */
	private static function register_google( string $family, array $weights ): array {
		$spec  = $family . ':' . implode( ',', $weights );
		$mods  = get_theme_mod( 'ogf_load_fonts', [] );
		$mods  = is_array( $mods ) ? $mods : [];

		// Check for existing entry with same family prefix.
		foreach ( $mods as $entry ) {
			if ( str_starts_with( (string) $entry, $family . ':' ) ) {
				return [
					'success'   => true,
					'mode'      => 'google',
					'family'    => $family,
					'font_spec' => $spec,
					'skipped'   => true,
				];
			}
		}

		$mods[] = $spec;
		set_theme_mod( 'ogf_load_fonts', $mods );
		set_theme_mod( 'ogf_font_display', 'swap' );

		return [
			'success'   => true,
			'mode'      => 'google',
			'family'    => $family,
			'font_spec' => $spec,
			'skipped'   => false,
		];
	}

	/**
	 * Register a system/OS font in the ogf_system_fonts theme-mod.
	 */
	private static function register_system( string $family ): array {
		$mods = get_theme_mod( 'ogf_system_fonts', [] );
		$mods = is_array( $mods ) ? $mods : [];

		if ( in_array( $family, $mods, true ) ) {
			return [
				'success' => true,
				'mode'    => 'system',
				'family'  => $family,
				'skipped' => true,
			];
		}

		$mods[] = $family;
		set_theme_mod( 'ogf_system_fonts', $mods );

		return [
			'success' => true,
			'mode'    => 'system',
			'family'  => $family,
			'skipped' => false,
		];
	}
}
