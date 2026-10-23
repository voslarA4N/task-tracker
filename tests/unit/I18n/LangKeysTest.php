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
final class LangKeysTest extends CIUnitTestCase
{
    public function testAllUsedKeysExistInSk(): void
    {
        $missing = [];
        $it      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match_all("/lang\\(\\s*'([A-Z][A-Za-z]+)\\.([A-Za-z0-9_.]+)'/", $src, $m, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($m as $hit) {
                $key = $hit[1] . '.' . $hit[2];
                if (lang($key, [], 'sk') === $key) {
                    $missing[] = $key . ' (' . $file->getPathname() . ')';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Chybajuce prekladove kluce v sk');
    }

    public function testSkAndEnHaveSameFilesAndKeys(): void
    {
        $sk = $this->load(APPPATH . 'Language/sk');
        $en = $this->load(APPPATH . 'Language/en');

        $this->assertSame(array_keys($sk), array_keys($en), 'Rozdielne subory sk/en');
        foreach ($sk as $name => $keys) {
            $this->assertSame($keys, $en[$name], "Rozdielne kluce v {$name}");
        }
    }

    public function testValidationMessagesAreTranslated(): void
    {
        $sk = lang('Validation.required', ['field' => 'X'], 'sk');
        $en = lang('Validation.required', ['field' => 'X'], 'en');

        $this->assertNotSame($en, $sk);
        $this->assertStringContainsString('X', $sk);
    }

    /**
     * @return array<string, list<string>>
     */
    private function load(string $dir): array
    {
        $out = [];
        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        foreach ($files as $f) {
            $out[basename($f)] = $this->flatten(require $f);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $a
     *
     * @return list<string>
     */
    private function flatten(array $a, string $prefix = ''): array
    {
        $keys = [];

        foreach ($a as $k => $v) {
            $full = $prefix . $k;
            if (is_array($v)) {
                array_push($keys, ...$this->flatten($v, $full . '.'));
            } else {
                $keys[] = $full;
            }
        }
        sort($keys);

        return $keys;
    }
}
