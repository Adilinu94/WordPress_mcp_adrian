<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Ensure_Atomic_Experiments
 *
 * Ability: `novamira-adrianv2/ensure-atomic-experiments`
 *
 * Reads and optionally writes Elementor experiment flags.
 *
 * Eliminates the pipeline's `ensure-elementor-experiments.js` preflight script.
 *
 * Elementor experiment flags are stored as:
 *   get_option( 'elementor_experiment-{name}' )  → 'active' | 'inactive' | '' (default)
 *
 * Key experiments for V4 Atomic builds:
 *   - e_atomic_elements      : Atomic Widgets (V4 engine)
 *   - e_nested_atomic_repeaters : Required for nested-accordion / repeater V4
 *   - e_optimized_css_loading : CSS performance optimization
 *   - e_font_icon_svg        : SVG icon rendering
 *
 * @package Novamira_AdrianV2
 * @since   1.5.0
 */
final class Ensure_Atomic_Experiments {

    /** Experiments required for a full V4 Atomic build. */
    public const REQUIRED_FOR_V4 = [
        'e_atomic_elements',
        'e_nested_atomic_repeaters',
    ];

    /** All known Elementor experiments relevant to this plugin. */
    public const KNOWN_EXPERIMENTS = [
        'e_atomic_elements'             => 'Atomic Widgets (V4 engine — required for e-heading, e-button etc.)',
        'e_nested_atomic_repeaters'     => 'Nested Atomic Repeaters (required for nested-accordion V4)',
        'e_optimized_css_loading'       => 'Optimized CSS Loading (performance)',
        'e_font_icon_svg'               => 'SVG Icon Rendering',
        'e_container'                   => 'Flexbox Container (V3 layout engine)',
        'e_container_grid'              => 'Grid Container',
        'e_global_styleguide'           => 'Global Styleguide (Design Tokens in editor)',
        'e_element_cache'               => 'Element Cache (frontend performance)',
        'e_lazyload'                    => 'Lazy Load (images + iframes)',
        'e_image_loading_optimization'  => 'Image Loading Optimization',
    ];

    public static function register(): void {
        wp_register_ability( 'novamira-adrianv2/ensure-atomic-experiments', [
            'label'       => 'Ensure Atomic Experiments',
            'description' =>
                'Reads and optionally enables Elementor experiment flags. '
                . 'Use ensure=["e_atomic_elements","e_nested_atomic_repeaters"] to activate V4 Atomic. '
                . 'Always use dry_run=true first to preview changes. '
                . 'Eliminates the pipeline\'s ensure-elementor-experiments.js preflight.',
            'category'    => 'adrianv2-elementor',

            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'ensure'   => [
                        'type'        => 'array',
                        'items'       => [ 'type' => 'string' ],
                        'default'     => [],
                        'description' => 'Experiment keys to activate. Pass [] to only read current state.',
                    ],
                    'disable'  => [
                        'type'        => 'array',
                        'items'       => [ 'type' => 'string' ],
                        'default'     => [],
                        'description' => 'Experiment keys to deactivate.',
                    ],
                    'dry_run'  => [
                        'type'        => 'boolean',
                        'default'     => true,
                        'description' => 'If true (default!), preview changes without saving. Set false to actually write.',
                    ],
                    'preset'   => [
                        'type'        => 'string',
                        'enum'        => [ 'none', 'v4_atomic_full' ],
                        'default'     => 'none',
                        'description' => 'v4_atomic_full = enable all experiments required for a full V4 Atomic build.',
                    ],
                ],
            ],

            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'      => [ 'type' => 'boolean' ],
                    'dry_run'      => [ 'type' => 'boolean' ],
                    'current'      => [ 'type' => 'object', 'description' => 'All known experiments and current state.' ],
                    'changes'      => [ 'type' => 'array',  'description' => 'List of changes applied (or previewed).' ],
                    'v4_ready'     => [ 'type' => 'boolean', 'description' => 'True when all REQUIRED_FOR_V4 experiments are active.' ],
                    'missing_for_v4' => [ 'type' => 'array', 'description' => 'Experiments that must be active for V4 Atomic.' ],
                    'summary'      => [ 'type' => 'string' ],
                    'error'        => [ 'type' => 'string' ],
                ],
            ],

            'execute_callback'    => [ self::class, 'execute' ],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }

    public static function execute( ?array $input = null ): array {
        $input   = $input ?? [];
        $dry_run = (bool) ( $input['dry_run'] ?? true );  // default TRUE — safe
        $ensure  = (array) ( $input['ensure']  ?? [] );
        $disable = (array) ( $input['disable'] ?? [] );
        $preset  = (string) ( $input['preset'] ?? 'none' );

        // Expand preset.
        if ( $preset === 'v4_atomic_full' ) {
            $ensure = array_unique( array_merge( $ensure, self::REQUIRED_FOR_V4 ) );
        }

        // Read current state of all known experiments.
        $current = [];
        foreach ( self::KNOWN_EXPERIMENTS as $key => $label ) {
            $val = get_option( 'elementor_experiment-' . $key, '' );
            $current[ $key ] = [
                'label'  => $label,
                'state'  => $val === '' ? 'default' : $val,
                'active' => $val === 'active',
            ];
        }

        // Build change list.
        $changes = [];

        foreach ( $ensure as $key ) {
            $key = sanitize_key( $key );
            if ( empty( $key ) ) continue;

            $old = $current[ $key ]['state'] ?? ( get_option( 'elementor_experiment-' . $key, '' ) ?: 'default' );
            if ( $old !== 'active' ) {
                $changes[] = [ 'key' => $key, 'from' => $old, 'to' => 'active', 'action' => 'enable' ];
                if ( ! $dry_run ) {
                    update_option( 'elementor_experiment-' . $key, 'active' );
                }
                if ( isset( $current[ $key ] ) ) {
                    $current[ $key ]['state']  = $dry_run ? $old : 'active';
                    $current[ $key ]['active'] = ! $dry_run;
                }
            }
        }

        foreach ( $disable as $key ) {
            $key = sanitize_key( $key );
            if ( empty( $key ) ) continue;

            $old = $current[ $key ]['state'] ?? ( get_option( 'elementor_experiment-' . $key, '' ) ?: 'default' );
            if ( $old !== 'inactive' ) {
                $changes[] = [ 'key' => $key, 'from' => $old, 'to' => 'inactive', 'action' => 'disable' ];
                if ( ! $dry_run ) {
                    update_option( 'elementor_experiment-' . $key, 'inactive' );
                    // Flush Elementor cache on disable — some experiments affect CSS.
                    delete_option( 'elementor_experiment-' . $key . '_enabled_count' );
                }
                if ( isset( $current[ $key ] ) ) {
                    $current[ $key ]['state']  = $dry_run ? $old : 'inactive';
                    $current[ $key ]['active'] = false;
                }
            }
        }

        // Flush Elementor cache if any changes were written.
        if ( ! $dry_run && ! empty( $changes ) ) {
            delete_option( 'elementor_css_print_method' );  // force CSS regen
            do_action( 'elementor/experiment/feature_deactivation', 'novamira-flush' );
        }

        // V4 readiness check.
        $missing_for_v4 = [];
        foreach ( self::REQUIRED_FOR_V4 as $req ) {
            $is_active = ! $dry_run
                ? ( get_option( 'elementor_experiment-' . $req, '' ) === 'active' )
                : ( $current[ $req ]['active'] ?? false );
            if ( ! $is_active ) {
                $missing_for_v4[] = $req;
            }
        }
        $v4_ready = empty( $missing_for_v4 );

        $change_count = count( $changes );
        $summary = $dry_run
            ? sprintf( 'Dry run: %d change(s) previewed. V4 ready: %s.', $change_count, $v4_ready ? 'yes' : 'no' )
            : sprintf( '%d experiment(s) updated. V4 ready: %s.', $change_count, $v4_ready ? 'yes' : 'no' );

        return [
            'success'        => true,
            'dry_run'        => $dry_run,
            'current'        => $current,
            'changes'        => $changes,
            'v4_ready'       => $v4_ready,
            'missing_for_v4' => $missing_for_v4,
            'summary'        => $summary,
        ];
    }
}
