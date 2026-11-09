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
            if (preg_match_all('/(\d+(?:\.\d+)?)px/', $value, $px) > 0) {
                foreach ($px[1] as $n) {
                    if (in_array((int) $n, self::FORBIDDEN_SPACING, true) && (float) $n === (float) (int) $n) {
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
                    if (! in_array((int) $n, self::ALLOWED_FONTS, true) || (float) $n !== (float) (int) $n) {
                        $problems[] = "{$line}: {$prop}: {$value}";
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $problems, "Velkost pisma mimo 12/16/20/28:\n" . implode("\n", $problems));
    }
}
