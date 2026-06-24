<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ClonerLabs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ClonerLabs_Style_Minifier
 *
 * Strips getComputedStyle() noise from ClonerLabs exports.
 *
 * FIX #7:  Never remove `var(--e-global-color-*)` or `globals/colors?id=*` refs.
 * FIX #7:  Never touch `__globals__` settings keys.
 * FIX #16: Skip `isLocked: true` containers entirely (nested-accordion children).
 *
 * @package Novamira_AdrianV2
 * @since   1.3.0
 */
final class ClonerLabs_Style_Minifier {

    public static function clean( array $elements ): array {
        return array_map( [ self::class, 'clean_element' ], $elements );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function clean_element( array $el ): array {
        // FIX #16: isLocked containers (e.g. nested-accordion children) — skip entirely.
        if ( ! empty( $el['isLocked'] ) ) {
            return $el;
        }

        if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
            $el['settings'] = self::clean_settings( $el['settings'] );
        }
        if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
            $el['elements'] = self::clean( $el['elements'] );
        }
        return $el;
    }

    private static function clean_settings( array $s ): array {
        // FIX #7: Never touch __globals__ — these are global color/typography bindings.
        $globals_backup = $s['__globals__'] ?? null;

        // ── Spacing ──
        if ( self::is_zero_spacing( $s['padding'] ?? null ) )      unset( $s['padding'] );
        if ( self::is_zero_spacing( $s['margin'] ?? null ) )       unset( $s['margin'] );

        // ── Gap ──
        $gap = $s['gap'] ?? null;
        if ( is_array( $gap ) && (float) ( $gap['size'] ?? 0 ) === 0.0 ) {
            unset( $s['gap'] );
        }

        // ── Border ──
        if ( ( $s['border_border'] ?? '' ) === '' ) {
            unset( $s['border_border'], $s['border_width'], $s['border_color'] );
        }

        // ── Border radius ──
        if ( self::is_zero_spacing( $s['border_radius'] ?? null ) ) {
            unset( $s['border_radius'] );
        }

        // ── Box shadow ──
        if ( ( $s['box_shadow_box_shadow_type'] ?? '' ) === '' ) {
            unset( $s['box_shadow_box_shadow_type'], $s['box_shadow_box_shadow'] );
        }

        // ── Background (empty or transparent/white default) ──
        // FIX #7: Only remove if NOT a global color reference.
        $bg       = $s['background_background'] ?? '';
        $bg_color = $s['background_color']      ?? '';
        $bg_img   = $s['background_image']['url'] ?? '';
        $bg_is_default_color = in_array( $bg_color, [ '', '#FFFFFF', '#ffffff', 'rgba(0,0,0,0)' ], true )
                               && ! self::is_global_color_ref( $bg_color );

        if ( $bg === '' || ( $bg === 'classic' && $bg_is_default_color && $bg_img === '' ) ) {
            unset( $s['background_background'], $s['background_color'] );
        }

        // ── Typography defaults ──
        $size = $s['typography_font_size']['size'] ?? '';
        if ( $size === '' || $size === null ) unset( $s['typography_font_size'] );

        $weight = $s['typography_font_weight'] ?? '';
        if ( $weight === '400' || $weight === '' ) unset( $s['typography_font_weight'] );

        $family = $s['typography_font_family'] ?? '';
        if ( $family === '' ) unset( $s['typography_font_family'] );

        // ── Empty string keys (except protected color keys) ──
        $never_remove = [ 'text_color', 'color', '__globals__' ];
        foreach ( $s as $key => $value ) {
            if ( in_array( $key, $never_remove, true ) ) continue;
            if ( $value === '' || $value === null ) {
                unset( $s[ $key ] );
            }
        }

        // FIX #7: Restore __globals__ if it was set.
        if ( $globals_backup !== null ) {
            $s['__globals__'] = $globals_backup;
        }

        return $s;
    }

    /**
     * FIX #7: Detect global color references that must never be stripped.
     */
    private static function is_global_color_ref( mixed $value ): bool {
        if ( ! is_string( $value ) ) return false;
        return str_starts_with( $value, 'var(--e-global-color' )
            || str_starts_with( $value, 'globals/colors?id=' );
    }

    private static function is_zero_spacing( mixed $v ): bool {
        if ( ! is_array( $v ) ) return false;
        return (float) ( $v['top']    ?? 0 ) === 0.0
            && (float) ( $v['right']  ?? 0 ) === 0.0
            && (float) ( $v['bottom'] ?? 0 ) === 0.0
            && (float) ( $v['left']   ?? 0 ) === 0.0;
    }
}
