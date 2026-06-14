<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * V4_Color_Contrast — WCAG color-contrast math.
 *
 * Pure static helpers — no WordPress or Elementor dependency — so they run in
 * unit tests without stubs and are reusable by the A11y audit/fix tools and any
 * future brand-kit contrast checks. Implements the WCAG 2.1 relative-luminance
 * and contrast-ratio definitions.
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static WCAG contrast utilities.
 *
 * @since 1.0.0
 */
final class V4_Color_Contrast {

    /** WCAG AA contrast minimum for normal text. */
    const AA_NORMAL = 4.5;

    /** WCAG AA contrast minimum for large text (>= 18pt, or 14pt bold). */
    const AA_LARGE = 3.0;

    /** WCAG AAA contrast minimum for normal text. */
    const AAA_NORMAL = 7.0;

    /** WCAG AAA contrast minimum for large text. */
    const AAA_LARGE = 4.5;

    /**
     * Parses a CSS hex color to an [r, g, b] triplet (0-255).
     *
     * Accepts #RGB, #RRGGBB, and #RRGGBBAA (3-, 6-, 8-digit, with or without
     * the leading '#'). The alpha channel of an 8-digit hex is ignored.
     *
     * @param string $hex A CSS hex color.
     * @return int[]|null [r, g, b] or null if the string isn't a valid hex color.
     */
    public static function hex_to_rgb(string $hex): ?array {
        $hex = ltrim(trim($hex), '#');
        $len = strlen($hex);

        if (!ctype_xdigit($hex)) {
            return null;
        }

        if (3 === $len) {
            $r = hexdec(str_repeat($hex[0], 2));
            $g = hexdec(str_repeat($hex[1], 2));
            $b = hexdec(str_repeat($hex[2], 2));
        } elseif (6 === $len || 8 === $len) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        } else {
            return null;
        }

        return [(int) $r, (int) $g, (int) $b];
    }

    /**
     * WCAG 2.x relative luminance of an [r, g, b] triplet (0.0–1.0).
     *
     * Uses the WCAG 2.0/2.1/2.2 sRGB linearization threshold (0.04045).
     *
     * @param int[] $rgb [r, g, b] 0-255.
     * @return float
     */
    public static function relative_luminance(array $rgb): float {
        $channels = [];
        foreach ([0, 1, 2] as $i) {
            $cs = (isset($rgb[$i]) ? max(0, min(255, (int) $rgb[$i])) : 0) / 255;
            $channels[$i] = ($cs <= 0.04045)
                ? $cs / 12.92
                : pow(($cs + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Contrast ratio between two hex colors (1.0–21.0), or null if either is
     * not a parseable hex color.
     *
     * @param string $hex_a First color.
     * @param string $hex_b Second color.
     * @return float|null
     */
    public static function contrast_ratio(string $hex_a, string $hex_b): ?float {
        $a = self::hex_to_rgb($hex_a);
        $b = self::hex_to_rgb($hex_b);
        if (null === $a || null === $b) {
            return null;
        }
        $la = self::relative_luminance($a);
        $lb = self::relative_luminance($b);
        $lighter = max($la, $lb);
        $darker  = min($la, $lb);
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Whether a contrast ratio meets a WCAG threshold.
     *
     * @param float  $ratio The contrast ratio.
     * @param bool   $large Whether the text qualifies as "large".
     * @param string $level 'AA' (default) or 'AAA'.
     * @return bool
     */
    public static function passes(float $ratio, bool $large = false, string $level = 'AA'): bool {
        if ('AAA' === strtoupper($level)) {
            return $ratio >= ($large ? self::AAA_LARGE : self::AAA_NORMAL);
        }
        return $ratio >= ($large ? self::AA_LARGE : self::AA_NORMAL);
    }

    /**
     * Suggests an adjusted foreground hex that meets the target ratio against a
     * fixed background, by stepping the foreground toward black or white.
     *
     * @param string $fg_hex     Foreground (text) hex.
     * @param string $bg_hex     Background hex.
     * @param float  $target     Target ratio (default AA normal, 4.5).
     * @return string|null Adjusted #RRGGBB, the original if already passing, or null.
     */
    public static function suggest_adjusted(string $fg_hex, string $bg_hex, float $target = self::AA_NORMAL): ?string {
        $fg = self::hex_to_rgb($fg_hex);
        $bg = self::hex_to_rgb($bg_hex);
        if (null === $fg || null === $bg) {
            return null;
        }

        $current = self::contrast_ratio($fg_hex, $bg_hex);
        if (null !== $current && $current >= $target) {
            return self::rgb_to_hex($fg);
        }

        $bg_lum   = self::relative_luminance($bg);
        $toward   = ($bg_lum > 0.5) ? 0 : 255;
        $best_hex = null;

        for ($step = 1; $step <= 100; $step++) {
            $t   = $step / 100;
            $adj = [
                (int) round($fg[0] + ($toward - $fg[0]) * $t),
                (int) round($fg[1] + ($toward - $fg[1]) * $t),
                (int) round($fg[2] + ($toward - $fg[2]) * $t),
            ];
            $adj_hex = self::rgb_to_hex($adj);
            $ratio   = self::contrast_ratio($adj_hex, $bg_hex);
            if (null !== $ratio && $ratio >= $target) {
                $best_hex = $adj_hex;
                break;
            }
        }

        return $best_hex;
    }

    /**
     * Formats an [r, g, b] triplet as an uppercase #RRGGBB string.
     *
     * @param int[] $rgb [r, g, b] 0-255.
     * @return string
     */
    public static function rgb_to_hex(array $rgb): string {
        $clamp = static function ($v): int {
            return max(0, min(255, (int) $v));
        };
        return sprintf('#%02X%02X%02X', $clamp($rgb[0] ?? 0), $clamp($rgb[1] ?? 0), $clamp($rgb[2] ?? 0));
    }
}
