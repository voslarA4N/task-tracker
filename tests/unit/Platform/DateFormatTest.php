<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Libraries\Platform\DateFormat;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Datumy UI podla jazyka rozhrania. Zdrojom pravdy je ICU (INTL_ICU_VERSION v kontajneri php).
 *
 * @internal
 */
#[Group('unit')]
final class DateFormatTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        service('request')->setLocale('sk');
        parent::tearDown();
    }

    private static function normalize(string $s): string
    {
        return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $s);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provider(): iterable
    {
        yield 'sk' => ['sk', '4. 11. 2026'];

        yield 'cs' => ['cs', '4. 11. 2026'];

        yield 'pl' => ['pl', '4 lis 2026'];

        yield 'en' => ['en', 'Nov 4, 2026'];
    }

    #[DataProvider('provider')]
    public function testDate(string $locale, string $expected): void
    {
        $this->assertSame($expected, self::normalize(DateFormat::date('2026-11-04', $locale)));
    }

    public function testPolishEndOfMonth(): void
    {
        $out = DateFormat::date('2026-01-31', 'pl');

        $this->assertStringContainsString('2026', $out);
        $this->assertStringContainsString('31', $out);
    }

    public function testInvalidInputReturnedUnchanged(): void
    {
        $this->assertSame('xx', DateFormat::date('xx', 'sk'));
    }

    public function testDefaultsToRequestLocale(): void
    {
        service('request')->setLocale('pl');

        $this->assertSame('4 lis 2026', self::normalize(DateFormat::date('2026-11-04')));
    }
}
