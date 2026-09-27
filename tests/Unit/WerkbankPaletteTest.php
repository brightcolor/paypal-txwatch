<?php

namespace Tests\Unit;

use App\Support\WerkbankPalette;
use PHPUnit\Framework\TestCase;

/**
 * Filament's colour scales in the workbench palette: complete, well formed,
 * and every text shade reaches the contrast target of the design system on
 * the surfaces of its mode. Surfaces come from public/css/werkbank.css, so a
 * change there is measured too.
 */
class WerkbankPaletteTest extends TestCase
{
    private const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    /** AA with the reserve the design system asks for. */
    private const TARGET = 4.8;

    /** Surface tokens text sits on: cards, table heads, page ground. */
    private const SURFACES = ['--bc-surface', '--bc-head', '--bc-ground'];

    public function test_every_scale_is_complete_and_well_formed(): void
    {
        $this->assertSame(['primary', 'gray', 'success', 'warning', 'danger', 'info'], array_keys(WerkbankPalette::colors()));

        foreach (WerkbankPalette::colors() as $name => $scale) {
            $this->assertSame(self::SHADES, array_keys($scale), "{$name}: Stufen fehlen oder stehen in falscher Reihenfolge.");

            foreach ($scale as $shade => $rgb) {
                $this->assertMatchesRegularExpression('/^\d{1,3}, \d{1,3}, \d{1,3}$/', $rgb, "{$name}-{$shade}: Format „r, g, b“ erwartet.");
                $this->assertLessThanOrEqual(255, max(array_map('intval', explode(', ', $rgb))), "{$name}-{$shade}: Kanal über 255.");
            }
        }
    }

    public function test_text_shades_reach_the_contrast_target_in_both_modes(): void
    {
        [$light, $dark] = self::surfaces();

        // Filament sets coloured text in 600 on light surfaces and 400 on dark
        // ones; quiet gray text in the views is text-gray-500 dark:text-gray-400.
        $pairs = [];
        foreach (array_keys(WerkbankPalette::colors()) as $name) {
            $pairs[] = [$name, 600, $light];
            $pairs[] = [$name, 400, $dark];
        }
        $pairs[] = ['gray', 500, $light];

        $failures = [];
        foreach ($pairs as [$name, $shade, $surfaces]) {
            $text = WerkbankPalette::colors()[$name][$shade];

            foreach ($surfaces as $token => $surface) {
                $ratio = self::contrast($text, $surface);
                if ($ratio < self::TARGET) {
                    $failures[] = sprintf('%s-%d auf %s (%s): %.2f:1', $name, $shade, $token, $surface, $ratio);
                }
            }
        }

        $this->assertSame([], $failures, 'Unter ' . self::TARGET . ":1:\n" . implode("\n", $failures));
    }

    public function test_the_contrast_measure_matches_known_pairs(): void
    {
        $this->assertEqualsWithDelta(21.0, self::contrast('0, 0, 0', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, self::contrast('255, 255, 255', '#ffffff'), 0.01);
        // Grey #767676 on white is the textbook AA edge case.
        $this->assertEqualsWithDelta(4.54, self::contrast('118, 118, 118', '#ffffff'), 0.01);
    }

    /**
     * Surface colours of both modes from werkbank.css: :root holds the light
     * values, .dark the dark ones.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private static function surfaces(): array
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/css/werkbank.css');

        $modes = [];
        foreach (['light' => ':root', 'dark' => '.dark'] as $mode => $selector) {
            preg_match('/^' . preg_quote($selector, '/') . '\s*\{(.*?)^\}/ms', $css, $block);
            self::assertNotEmpty($block, "Block {$selector} fehlt in werkbank.css.");

            foreach (self::SURFACES as $token) {
                preg_match('/' . preg_quote($token, '/') . ':\s*(#[0-9a-f]{6})\s*;/i', $block[1], $value);
                self::assertNotEmpty($value, "{$token} fehlt im Block {$selector} von werkbank.css.");
                $modes[$mode][$token] = $value[1];
            }
        }

        return [$modes['light'], $modes['dark']];
    }

    /** WCAG contrast of an 'r, g, b' text colour on a #rrggbb surface. */
    private static function contrast(string $rgb, string $hex): float
    {
        $text = self::luminance(array_map('intval', explode(', ', $rgb)));
        $surface = self::luminance(array_map('hexdec', str_split(ltrim($hex, '#'), 2)));

        return (max($text, $surface) + 0.05) / (min($text, $surface) + 0.05);
    }

    /** @param array<int, int> $channels */
    private static function luminance(array $channels): float
    {
        [$r, $g, $b] = array_map(static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
