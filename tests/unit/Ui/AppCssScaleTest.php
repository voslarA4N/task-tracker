<?php

declare(strict_types=1);

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Strazca stupnice odsadenia a typografie (UI-SPEC 12.1, D-12).
 *
 * @internal
 */
#[Group('unit')]
final class AppCssScaleTest extends TestCase
{
    private const FORBIDDEN_SPACING = [10, 12, 14, 18, 20];
    private const ALLOWED_FONTS     = [12, 16, 20, 28];

    /**
     * @return list<array{int, string, string}> riadok, vlastnost, hodnota
     */
    private function declarations(): array
    {
        $path = dirname(__DIR__, 3) . '/public/assets/css/app.css';
        $css  = (string) file_get_contents($path);

        // Komentare nahradime rovnakym poctom novych riadkov, aby sedeli cisla riadkov.
        $css = (string) preg_replace_callback('#/\*.*?\*/#s', static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $css);

        $out = [];
        if (preg_match_all('/([a-z-]+)\s*:\s*([^;{}]+)(?=[;}])/i', $css, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $line  = substr_count($css, "\n", 0, $match[1][1]) + 1;
                $out[] = [$line, strtolower($match[1][0]), trim($match[2][0])];
            }
        }

        return $out;
    }

    public function testNoOffScaleSpacing(): void
    {
        $problems = [];

        foreach ($this->declarations() as [$line, $prop, $value]) {
            if (preg_match('/^(padding|margin|gap|row-gap|column-gap)(-[a-z]+)*$/', $prop) !== 1) {
                continue;
            }
            // Len celé hodnoty v px (napr. 12px), desatinné ako 12.5px sa nezachytia.
            if (preg_match_all('/(?<![\d.])(\d+)px/', $value, $px) > 0) {
                foreach ($px[1] as $n) {
                    if (in_array((int) $n, self::FORBIDDEN_SPACING, true)) {
                        $problems[] = "{$line}: {$prop}: {$value}";
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $problems, "Mimo stupnice (4, 8, 16, 24, 32, 48, 64):\n" . implode("\n", $problems));
    }

    public function testOnlyScaleFontSizes(): void
    {
        $problems = [];

        foreach ($this->declarations() as [$line, $prop, $value]) {
            if ($prop !== 'font-size') {
                continue;
            }
            if (preg_match_all('/(\d+(?:\.\d+)?)px/', $value, $px) > 0) {
                foreach ($px[1] as $n) {
                    if (! in_array((int) $n, self::ALLOWED_FONTS, true) || ! ctype_digit($n)) {
                        $problems[] = "{$line}: {$prop}: {$value}";
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $problems, "Velkost pisma mimo 12/16/20/28:\n" . implode("\n", $problems));
    }

    public function testMoreTilesGrid(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/app.css');
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $css = (string) preg_replace('/\s+/', ' ', $css);

        $this->assertSame(1, preg_match_all('/(?<![\w-])\.more-grid\s*\{([^}]*)\}/', (string) preg_replace('/@media[^{]*\{[^{}]*\{[^}]*\}\s*\}/', '', $css), $g), 'Pravidlo .more-grid musi byt prave jedno.');
        $this->assertMatchesRegularExpression('/display\s*:\s*grid/', $g[1][0], 'Viac: .more-grid musi byt grid');
        $this->assertStringContainsString('repeat(2,', $g[1][0], 'Viac: 2 stlpce na mobile');
        $this->assertSame(1, preg_match('/@media \(min-width: 768px\) \{ \.more-grid \{[^}]*repeat\(3,/', $css), 'Viac: 3 stlpce od 768px');
        $this->assertSame(1, preg_match('/@media \(min-width: 992px\) \{ \.more-grid \{[^}]*repeat\(4,/', $css), 'Viac: 4 stlpce od 992px');

        $this->assertSame(1, preg_match_all('/(?<![\w-])\.more-tile\s*\{([^}]*)\}/', $css, $t), 'Pravidlo .more-tile musi byt prave jedno.');
        $this->assertMatchesRegularExpression('/flex-direction\s*:\s*column/', $t[1][0], 'Viac: dlazdica ma byt stlpec');
        $this->assertSame(1, preg_match('/min-height\s*:\s*(\d+)px/', $t[1][0], $h), 'Viac: chyba min-height v px');
        $this->assertGreaterThanOrEqual(48, (int) $h[1], 'Viac: dotykovy cielik aspon 48px');
        $this->assertSame(1, preg_match('/\.more-tile:focus-visible\s*\{[^}]*outline/', $css), 'Viac: dlazdica musi mat viditelny focus');
        $this->assertStringNotContainsString('.more-item', $css, 'Povodne pravidlo .more-item ma byt odstranene');
    }
}
