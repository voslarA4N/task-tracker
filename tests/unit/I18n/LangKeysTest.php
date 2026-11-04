<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['pl' => ['pl'], 'cs' => ['cs'], 'en' => ['en']];
    }

    #[DataProvider('locales')]
    public function testLocaleFilesHaveSameKeysAsSk(string $locale): void
    {
        $sk     = $this->load(APPPATH . 'Language/sk');
        $actual = $this->load(APPPATH . 'Language/' . $locale);

        foreach ($actual as $name => $keys) {
            $this->assertArrayHasKey($name, $sk, "Subor {$name} v {$locale} neexistuje v sk");
            $this->assertSame($sk[$name], $keys, "Rozdielne kluce v {$locale}/{$name}");
        }
    }

    #[DataProvider('locales')]
    public function testPlaceholdersMatchSk(string $locale): void
    {
        $sk     = $this->values(APPPATH . 'Language/sk');
        $actual = $this->values(APPPATH . 'Language/' . $locale);

        foreach ($actual as $name => $pairs) {
            foreach ($pairs as $key => $value) {
                $this->assertSame(
                    $this->placeholders($sk[$name][$key] ?? ''),
                    $this->placeholders($value),
                    "Zastupne symboly {$locale}/{$name}:{$key}",
                );
            }
        }
    }

    #[DataProvider('locales')]
    public function testNoEmptyValues(string $locale): void
    {
        $empty = [];

        foreach (['sk', $locale] as $dir) {
            foreach ($this->values(APPPATH . 'Language/' . $dir) as $name => $pairs) {
                foreach ($pairs as $key => $value) {
                    if (trim($value) === '') {
                        $empty[] = "{$dir}/{$name}:{$key}";
                    }
                }
            }
        }

        $this->assertSame([], $empty, 'Prazdne hodnoty');
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        // ICU plural vetvy (one{...}, few{...}) sa medzi jazykmi lisia, porovnavaju sa iba nazvy argumentov.
        preg_match_all('/\{\s*(\d+|[A-Za-z_]\w*)\s*[,}]/', $value, $m);
        $found = $m[1];
        sort($found);

        return $found;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function values(string $dir): array
    {
        $out   = [];
        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        foreach ($files as $f) {
            $out[basename($f)] = $this->flattenValues(require $f);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $a
     *
     * @return array<string, string>
     */
    private function flattenValues(array $a, string $prefix = ''): array
    {
        $out = [];

        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $out += $this->flattenValues($v, $prefix . $k . '.');
            } else {
                $out[$prefix . $k] = (string) $v;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function load(string $dir): array
    {
        $out   = [];
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
