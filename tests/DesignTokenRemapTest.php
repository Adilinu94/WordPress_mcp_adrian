<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Abilities\Elementor\Design_Token_Remap;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Design_Token_Remap — validates GV ID replacement logic.
 *
 * Tests only cover the pure-array methods (no WP functions required):
 * remap_prop, walk_variants, walk_element_styles, walk_tree.
 *
 * @covers \Novamira\AdrianV2\Abilities\Elementor\Design_Token_Remap
 */
final class DesignTokenRemapTest extends TestCase
{
    // -----------------------------------------------------------------
    // remap_prop
    // -----------------------------------------------------------------

    public function test_remap_prop_bare_id_replaced(): void
    {
        $prop      = [ '$$type' => 'global-color-variable', 'value' => 'e-gv-OLD111' ];
        $remap_map = [ 'e-gv-OLD111' => 'e-gv-NEW222' ];
        $count     = 0;

        Design_Token_Remap::remap_prop( $prop, $remap_map, $count );

        $this->assertSame( 'e-gv-NEW222', $prop['value'] );
        $this->assertSame( 1, $count );
    }

    public function test_remap_prop_var_wrapper_preserved(): void
    {
        $prop      = [ '$$type' => 'global-color-variable', 'value' => 'var(--e-gv-OLD111)' ];
        $remap_map = [ 'e-gv-OLD111' => 'e-gv-NEW222' ];
        $count     = 0;

        Design_Token_Remap::remap_prop( $prop, $remap_map, $count );

        $this->assertSame( 'var(--e-gv-NEW222)', $prop['value'] );
        $this->assertSame( 1, $count );
    }

    public function test_remap_prop_no_match_unchanged(): void
    {
        $prop      = [ '$$type' => 'global-color-variable', 'value' => 'e-gv-UNKNOWN' ];
        $remap_map = [ 'e-gv-OTHER' => 'e-gv-NEW222' ];
        $count     = 0;

        Design_Token_Remap::remap_prop( $prop, $remap_map, $count );

        $this->assertSame( 'e-gv-UNKNOWN', $prop['value'] );
        $this->assertSame( 0, $count );
    }

    public function test_remap_prop_inline_color_not_touched(): void
    {
        $prop      = [ '$$type' => 'color', 'value' => '#FF0000' ];
        $remap_map = [ 'e-gv-OLD111' => 'e-gv-NEW222' ];
        $count     = 0;

        Design_Token_Remap::remap_prop( $prop, $remap_map, $count );

        $this->assertSame( '#FF0000', $prop['value'] );
        $this->assertSame( 0, $count );
    }

    public function test_remap_prop_global_class_not_touched(): void
    {
        // gc-* IDs must never be touched — different namespace.
        $prop      = [ '$$type' => 'global-class', 'value' => 'gc-abc123' ];
        $remap_map = [ 'gc-abc123' => 'gc-new456' ];
        $count     = 0;

        Design_Token_Remap::remap_prop( $prop, $remap_map, $count );

        // 'global-class' does not contain 'variable' → no change.
        $this->assertSame( 'gc-abc123', $prop['value'] );
        $this->assertSame( 0, $count );
    }

    public function test_remap_prop_size_variable_remapped(): void
    {
        // Other GV types (e-gv-*) are also remapped — generic by design.
        $prop      = [ '$$type' => 'global-size-variable', 'value' => 'e-gv-SIZE111' ];
        $remap_map = [ 'e-gv-SIZE111' => 'e-gv-SIZE222' ];
        $count     = 0;

        Design_Token_Remap::remap_prop( $prop, $remap_map, $count );

        $this->assertSame( 'e-gv-SIZE222', $prop['value'] );
        $this->assertSame( 1, $count );
    }

    // -----------------------------------------------------------------
    // walk_variants
    // -----------------------------------------------------------------

    public function test_walk_variants_remaps_all_variants(): void
    {
        $variants = [
            [
                'meta'  => [ 'breakpoint' => null, 'state' => null ],
                'props' => [
                    'color'            => [ '$$type' => 'global-color-variable', 'value' => 'e-gv-OLD111' ],
                    'background-color' => [ '$$type' => 'global-color-variable', 'value' => 'e-gv-OLD222' ],
                ],
            ],
            [
                'meta'  => [ 'breakpoint' => 'tablet', 'state' => null ],
                'props' => [
                    'color' => [ '$$type' => 'global-color-variable', 'value' => 'e-gv-OLD111' ],
                ],
            ],
        ];
        $remap_map = [ 'e-gv-OLD111' => 'e-gv-NEW111', 'e-gv-OLD222' => 'e-gv-NEW222' ];
        $count     = 0;

        Design_Token_Remap::walk_variants( $variants, $remap_map, $count );

        $this->assertSame( 'e-gv-NEW111', $variants[0]['props']['color']['value'] );
        $this->assertSame( 'e-gv-NEW222', $variants[0]['props']['background-color']['value'] );
        $this->assertSame( 'e-gv-NEW111', $variants[1]['props']['color']['value'] );
        $this->assertSame( 3, $count );
    }

    // -----------------------------------------------------------------
    // walk_element_styles
    // -----------------------------------------------------------------

    public function test_walk_element_styles_correct_v4_format(): void
    {
        $style_id = 'e-abc123-def456';
        $styles   = [
            $style_id => [
                'id'       => $style_id,
                'label'    => 'local',
                'type'     => 'class',
                'variants' => [
                    [
                        'meta'  => [ 'breakpoint' => null, 'state' => null ],
                        'props' => [
                            'color' => [ '$$type' => 'global-color-variable', 'value' => 'e-gv-OLD111' ],
                        ],
                        'custom_css' => null,
                    ],
                ],
            ],
        ];
        $remap_map = [ 'e-gv-OLD111' => 'e-gv-NEW111' ];
        $count     = 0;

        Design_Token_Remap::walk_element_styles( $styles, $remap_map, $count );

        $this->assertSame( 'e-gv-NEW111', $styles[ $style_id ]['variants'][0]['props']['color']['value'] );
        $this->assertSame( 1, $count );
    }

    // -----------------------------------------------------------------
    // walk_tree
    // -----------------------------------------------------------------

    public function test_walk_tree_nested_elements_all_remapped(): void
    {
        $style_id_outer = 'e-outer-style';
        $style_id_inner = 'e-inner-style';

        $tree = [
            [
                'id'     => 'outer',
                'elType' => 'e-flexbox',
                'styles' => [
                    $style_id_outer => [
                        'id'       => $style_id_outer,
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'  => [ 'breakpoint' => null, 'state' => null ],
                                'props' => [
                                    'background-color' => [ '$$type' => 'global-color-variable', 'value' => 'e-gv-BG111' ],
                                ],
                            ],
                        ],
                    ],
                ],
                'elements' => [
                    [
                        'id'     => 'inner',
                        'elType' => 'widget',
                        'styles' => [
                            $style_id_inner => [
                                'id'       => $style_id_inner,
                                'type'     => 'class',
                                'variants' => [
                                    [
                                        'meta'  => [ 'breakpoint' => null, 'state' => null ],
                                        'props' => [
                                            'color' => [ '$$type' => 'global-color-variable', 'value' => 'e-gv-TXT111' ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'elements' => [],
                    ],
                ],
            ],
        ];

        $remap_map = [
            'e-gv-BG111'  => 'e-gv-BG222',
            'e-gv-TXT111' => 'e-gv-TXT222',
        ];
        $count = 0;

        $updated = Design_Token_Remap::walk_tree( $tree, $remap_map, $count );

        $outer_props = $updated[0]['styles'][ $style_id_outer ]['variants'][0]['props'];
        $inner_props = $updated[0]['elements'][0]['styles'][ $style_id_inner ]['variants'][0]['props'];

        $this->assertSame( 'e-gv-BG222', $outer_props['background-color']['value'] );
        $this->assertSame( 'e-gv-TXT222', $inner_props['color']['value'] );
        $this->assertSame( 2, $count );
    }

    public function test_walk_tree_no_match_tree_unchanged(): void
    {
        $style_id = 'e-abc123';
        $tree     = [
            [
                'id'     => 'el1',
                'elType' => 'widget',
                'styles' => [
                    $style_id => [
                        'id'       => $style_id,
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'  => [ 'breakpoint' => null, 'state' => null ],
                                'props' => [
                                    'color' => [ '$$type' => 'global-color-variable', 'value' => 'e-gv-PRESENT' ],
                                ],
                            ],
                        ],
                    ],
                ],
                'elements' => [],
            ],
        ];

        $remap_map = [ 'e-gv-NOT-HERE' => 'e-gv-NEW' ];
        $count     = 0;

        $updated = Design_Token_Remap::walk_tree( $tree, $remap_map, $count );

        $this->assertSame( 'e-gv-PRESENT', $updated[0]['styles'][ $style_id ]['variants'][0]['props']['color']['value'] );
        $this->assertSame( 0, $count );
    }
}
