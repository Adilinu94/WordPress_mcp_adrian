<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ClonerLabs;

use Novamira\AdrianV2\Helpers\Guards;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Convert_HTML_To_Elementor
 *
 * Ability: `novamira-adrianv2/convert-html-to-elementor`
 *
 * BUG #14 FIX: The underlying html-to-elementor-widget-plan ability uses
 * `target_surface` (not `target`). This wrapper translates the parameter.
 *
 * @package Novamira_AdrianV2
 * @since   1.3.0
 */
final class Convert_HTML_To_Elementor {

    public static function register(): void {
        wp_register_ability( 'novamira-adrianv2/convert-html-to-elementor', [
            'label'       => 'Convert HTML to Elementor (ClonerLabs)',
            'description' =>
                'Converts raw HTML (ClonerLabs fallback widgets) into Elementor elements. '
                . 'BUG #14 FIX: Translates `target` → `target_surface` for the delegate ability.',
            'category'    => 'adrianv2-clonerlabs',

            'input_schema' => [
                'type'       => 'object',
                'required'   => [ 'html' ],
                'properties' => [
                    'html'      => [ 'type' => 'string', 'description' => 'Raw HTML to convert.' ],
                    'target'    => [ 'type' => 'string', 'enum' => [ 'v3', 'v4' ], 'default' => 'v3' ],
                    'max_nodes' => [ 'type' => 'integer', 'default' => 250 ],
                    'post_id'   => [ 'type' => 'integer', 'description' => 'If provided, appends result to this page.' ],
                ],
            ],

            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'elements' => [ 'type' => 'array' ],
                    'summary'  => [ 'type' => 'string' ],
                    'error'    => [ 'type' => 'string' ],
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
        $input     = $input ?? [];
        $html      = (string) ( $input['html']      ?? '' );
        $target    = in_array( $input['target'] ?? 'v3', [ 'v3', 'v4' ], true ) ? $input['target'] : 'v3';
        $max_nodes = (int)    ( $input['max_nodes'] ?? 250 );
        $post_id   = (int)    ( $input['post_id']   ?? 0 );

        if ( empty( $html ) ) {
            return [ 'success' => false, 'error' => 'html is required.' ];
        }

        // BUG #14 FIX: `target_surface`, not `target`.
        $delegate_result = wp_execute_ability( 'novamira-adrianv2/html-to-elementor-widget-plan', [
            'html'           => $html,
            'target_surface' => $target,
            'max_nodes'      => $max_nodes,
        ] );

        if ( ! is_array( $delegate_result ) || empty( $delegate_result['success'] ) ) {
            $err = is_array( $delegate_result ) ? ( $delegate_result['error'] ?? 'Unknown error.' ) : 'Delegate ability failed.';
            return [ 'success' => false, 'error' => $err ];
        }

        $elements = $delegate_result['elements'] ?? [];

        // Optionally append to existing page.
        if ( $post_id > 0 && ! empty( $elements ) ) {
            $existing = Guards::get_elementor_data( $post_id );
            if ( $existing !== false ) {
                Guards::save_elementor_data( $post_id, array_merge( $existing, $elements ) );
            }
        }

        return [
            'success'  => true,
            'elements' => $elements,
            'summary'  => sprintf(
                'Converted HTML to %d Elementor element(s) (%s).',
                count( $elements ),
                strtoupper( $target )
            ),
        ];
    }
}
