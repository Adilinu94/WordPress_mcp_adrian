<?php
/**
 * Test: V4_Color_Contrast_22 — WCAG 2.2 Compliance
 *
 * Verifies the WCAG 2.2-specific constants and methods:
 *   - 2.5.8 Target Size (Minimum): 24×24px
 *   - 2.4.11 Focus Appearance: 3:1 contrast ratio
 *
 * @package Novamira\AdrianV2\Tests
 * @since 1.2.0
 */

declare(strict_types=1);

use Novamira\AdrianV2\Helpers\V4_Color_Contrast;
use Novamira\AdrianV2\Helpers\V4_Color_Contrast_22;
use PHPUnit\Framework\TestCase;

/**
 * WCAG 2.2 Color Contrast Tests (FIX-15).
 */
#[CoversClass(V4_Color_Contrast_22::class)]
#[CoversClass(V4_Color_Contrast::class)]
class V4ColorContrast22Test extends TestCase
{
    // ── WCAG 2.2 Constants ──────────────────────────────────────────────────

    public function test_target_size_min_is_24(): void
    {
        $this->assertSame(24, V4_Color_Contrast_22::TARGET_SIZE_MIN,
            'WCAG 2.2 §2.5.8 requires minimum 24×24px target size');
    }

    public function test_focus_appearance_contrast_is_3(): void
    {
        $this->assertSame(3.0, V4_Color_Contrast_22::FOCUS_APPEARANCE_CONTRAST,
            'WCAG 2.2 §2.4.11 requires 3:1 focus appearance contrast');
    }

    // ── 2.5.8 Target Size ───────────────────────────────────────────────────

    public function test_passes_target_size_exact_24(): void
    {
        $this->assertTrue(
            V4_Color_Contrast_22::passes_target_size(24, 24),
            '24×24 should pass (exact minimum)'
        );
    }

    public function test_passes_target_size_larger(): void
    {
        $this->assertTrue(
            V4_Color_Contrast_22::passes_target_size(48, 48),
            '48×48 should pass (> minimum)'
        );
    }

    public function test_passes_target_size_fails_smaller(): void
    {
        $this->assertFalse(
            V4_Color_Contrast_22::passes_target_size(20, 20),
            '20×20 should fail (< 24×24)'
        );
    }

    public function test_passes_target_size_fails_one_dimension(): void
    {
        $this->assertFalse(
            V4_Color_Contrast_22::passes_target_size(30, 20),
            '30×20 should fail (one dimension < 24)'
        );
        $this->assertFalse(
            V4_Color_Contrast_22::passes_target_size(20, 30),
            '20×30 should fail (one dimension < 24)'
        );
    }

    // ── 2.4.11 Focus Appearance ─────────────────────────────────────────────

    public function test_passes_focus_appearance_bw(): void
    {
        // Black (#000000) on white (#ffffff) → 21:1 > 3:1
        $this->assertTrue(
            V4_Color_Contrast_22::passes_focus_appearance('#000000', '#ffffff'),
            'Black on white (21:1) should pass 3:1 focus minimum'
        );
    }

    public function test_passes_focus_appearance_fails_low_contrast(): void
    {
        // #767676 on #777777 → ~1.0:1 (very low contrast)
        $this->assertFalse(
            V4_Color_Contrast_22::passes_focus_appearance('#767676', '#777777'),
            '#767676 on #777777 (~1.0:1) should fail 3:1 focus minimum'
        );
    }

    public function test_passes_focus_appearance_barely_passes(): void
    {
        // #767676 on #ffffff → ~4.5:1 > 3:1
        $this->assertTrue(
            V4_Color_Contrast_22::passes_focus_appearance('#767676', '#ffffff'),
            '#767676 on #ffffff (~4.5:1) should pass 3:1'
        );
    }

    public function test_passes_focus_appearance_barely_fails(): void
    {
        // #959595 on #ffffff → ~2.995:1 < 3:1 (WCAG relative luminance threshold 0.04045)
        // L = 0.3006, ratio = 1.05 / 0.3506 ≈ 2.995
        // The old test color #949494 gives ~3.03:1 which actually PASSES 3:1.
        $ratio = V4_Color_Contrast_22::contrast_ratio('#959595', '#ffffff');
        $this->assertLessThan(3.0, $ratio,
            "Expected contrast ratio < 3.0 for #959595 on #fff, got {$ratio}");

        $this->assertFalse(
            V4_Color_Contrast_22::passes_focus_appearance('#959595', '#ffffff'),
            '#959595 on #ffffff (~2.995:1) should fail 3:1'
        );
    }

    // ── Contrast Ratio — boundary values ─────────────────────────────────────

    public function test_contrast_ratio_black_on_white(): void
    {
        $ratio = V4_Color_Contrast_22::contrast_ratio('#000000', '#ffffff');
        $this->assertEqualsWithDelta(21.0, $ratio, 0.1,
            'Black on white should be ~21:1');
    }

    public function test_contrast_ratio_white_on_white(): void
    {
        $ratio = V4_Color_Contrast_22::contrast_ratio('#ffffff', '#ffffff');
        $this->assertEqualsWithDelta(1.0, $ratio, 0.01,
            'White on white should be 1:1');
    }

    public function test_contrast_ratio_blue_on_white(): void
    {
        // #0e2a3b (dark blue) on #ffffff
        $ratio = V4_Color_Contrast_22::contrast_ratio('#0e2a3b', '#ffffff');
        $this->assertGreaterThan(
            V4_Color_Contrast::AA_NORMAL,
            $ratio,
            "Dark blue (#0e2a3b) on white should meet AA normal (4.5:1), got {$ratio}"
        );
    }

    public function test_contrast_ratio_short_hex(): void
    {
        // #fff = #ffffff, same math
        $ratioShort = V4_Color_Contrast_22::contrast_ratio('#000', '#fff');
        $ratioLong  = V4_Color_Contrast_22::contrast_ratio('#000000', '#ffffff');
        $this->assertEqualsWithDelta($ratioLong, $ratioShort, 0.01,
            '3-digit hex should yield same ratio as 6-digit');
    }

    // ── WCAG 2.1 compatibility (delegates to V4_Color_Contrast) ────────────

    public function test_aa_normal_is_4_5(): void
    {
        $this->assertSame(4.5, V4_Color_Contrast::AA_NORMAL,
            'WCAG AA normal text contrast must be 4.5:1');
    }

    public function test_aa_large_is_3_0(): void
    {
        $this->assertSame(3.0, V4_Color_Contrast::AA_LARGE,
            'WCAG AA large text contrast must be 3.0:1');
    }

    public function test_aaa_normal_is_7_0(): void
    {
        $this->assertSame(7.0, V4_Color_Contrast::AAA_NORMAL,
            'WCAG AAA normal text contrast must be 7.0:1');
    }

    // ── Edge cases ───────────────────────────────────────────────────────────

    public function test_passes_target_size_zero(): void
    {
        $this->assertFalse(
            V4_Color_Contrast_22::passes_target_size(0, 0),
            '0×0 should fail'
        );
    }

    public function test_passes_focus_appearance_same_color(): void
    {
        // Same color → 1:1 ratio → fails
        $this->assertFalse(
            V4_Color_Contrast_22::passes_focus_appearance('#222222', '#222222'),
            'Same color (1:1) should fail 3:1'
        );
    }
}
