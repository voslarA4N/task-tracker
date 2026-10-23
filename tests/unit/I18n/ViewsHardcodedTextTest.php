<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @internal
 */
#[Group('unit')]
final class ViewsHardcodedTextTest extends CIUnitTestCase
{
    /**
     * Technicke vynimky (napr. kody mien). Pridavat len s odovodnenim v komentari.
     *
     * @var list<string>
     */
    /** @var list<string> */
    private static array $allowlist = [];

    private const ATTRS = ['title', 'alt', 'placeholder', 'aria-label', 'aria-description', 'label'];

    public function testViewsHaveNoHardcodedText(): void
    {
        $problems = [];
        $it       = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH . 'Views', RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php'
                || str_ends_with($path, 'errors/html/error_exception.php')
                || str_contains($path, 'errors/cli/')) {
                continue;
            }

            $src = (string) file_get_contents($path);

            // Nahradi PHP bloky, komentare, script a style rovnako dlhym prazdnym retazcom (zachova riadky).
            $blank = static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]);
            $clean = preg_replace_callback('/<\?(?:php|=).*?\?>|<!--.*?-->|<script\b.*?<\/script>|<style\b.*?<\/style>/s', $blank, $src) ?? $src;

            // Text medzi tagmi.
            if (preg_match_all('/>([^<]+)(?=<)/', $clean, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[1] as [$text, $offset]) {
                    $decoded = html_entity_decode($text);
                    if (preg_match('/\p{L}{2,}/u', $decoded) === 1 && ! in_array(trim($decoded), self::$allowlist, true)) {
                        $problems[] = "{$path}:" . (substr_count($clean, "\n", 0, $offset) + 1) . ' text "' . trim($decoded) . '"';
                    }
                }
            }

            // Text pred prvym tagom / za poslednym tagom (mimo tagov).
            foreach ([trim(strstr($clean, '<', true) ?: '')] as $lead) {
                if ($lead !== '' && preg_match('/\p{L}{2,}/u', $lead) === 1) {
                    $problems[] = "{$path}:1 text \"{$lead}\"";
                }
            }

            // Atributy s textom.
            $re = '/\s(' . implode('|', self::ATTRS) . ')\s*=\s*(["\'])(.*?)\2/s';
            if (preg_match_all($re, $clean, $am, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($am[3] as $i => [$val, $offset]) {
                    if (preg_match('/\p{L}{2,}/u', html_entity_decode($val)) === 1 && ! in_array(trim($val), self::$allowlist, true)) {
                        $problems[] = "{$path}:" . (substr_count($clean, "\n", 0, $offset) + 1) . " atribut {$am[1][$i][0]}=\"{$val}\"";
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
