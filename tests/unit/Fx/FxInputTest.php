<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Libraries\Fx\FxInput;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class FxInputTest extends CIUnitTestCase
{
    private const TODAY = '2026-10-30';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validRates(): iterable
    {
        yield 'comma' => ['4,3', '4.3'];

        yield 'leading zeros' => ['004.3000', '4.3000'];

        yield 'zero point' => ['0.5', '0.5'];

        yield 'eight decimals' => ['4.12345678', '4.12345678'];

        yield 'spaces' => ['  4.3 ', '4.3'];
    }

    #[DataProvider('validRates')]
    public function testValidRates(string $in, string $out): void
    {
        $i = FxInput::fromArray(['fx_mode' => 'manual', 'fx_rate' => $in], self::TODAY);
        $this->assertSame($out, $i->rate);
        $this->assertSame([], $i->errors);
        $this->assertTrue($i->isManual());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRates(): iterable
    {
        foreach (['0', '-1', 'abc', '1e3', '4.3.1', '', '0.0', '4.', '12345678901'] as $v) {
            yield 'v ' . $v => [$v];
        }
    }

    #[DataProvider('invalidRates')]
    public function testInvalidRates(string $in): void
    {
        $i = FxInput::fromArray(['fx_mode' => 'manual', 'fx_rate' => $in], self::TODAY);
        $this->assertSame('invalid', $i->errors['fx_rate']);
        $this->assertNull($i->rate);
        $this->assertFalse($i->isManual());
    }

    public function testTooManyDecimals(): void
    {
        $i = FxInput::fromArray(['fx_mode' => 'manual', 'fx_rate' => '4.123456789'], self::TODAY);
        $this->assertSame('decimals', $i->errors['fx_rate']);
    }

    public function testDates(): void
    {
        $mk = static fn (string $d): FxInput => FxInput::fromArray(['fx_mode' => 'manual', 'fx_rate' => '4.3', 'fx_date' => $d], self::TODAY);

        $this->assertSame('date_future', $mk('2026-10-31')->errors['fx_date']);
        $this->assertSame('date_old', $mk('2025-10-29')->errors['fx_date']);
        $this->assertSame('date_invalid', $mk('2026-02-30')->errors['fx_date']);
        $this->assertSame('2025-10-30', $mk('2025-10-30')->date);
        $this->assertNull($mk('')->date);
        $this->assertSame([], $mk('')->errors);
    }

    public function testNonManualModeIsAuto(): void
    {
        $i = FxInput::fromArray(['fx_mode' => 'x', 'fx_rate' => '0', 'fx_retry' => '1', 'fx_anchor' => '2026-10-31'], self::TODAY);
        $this->assertSame('auto', $i->mode);
        $this->assertSame([], $i->errors);
        $this->assertTrue($i->retry);
        $this->assertSame('2026-10-31', $i->anchor);
        $this->assertFalse($i->isManual());
        $this->assertFalse(FxInput::fromArray([], self::TODAY)->retry);
        $this->assertSame('auto', FxInput::auto()->mode);
    }

    public function testOverlongValueIsDropped(): void
    {
        $i = FxInput::fromArray(['fx_mode' => 'manual', 'fx_rate' => str_repeat('1', 33)], self::TODAY);
        $this->assertSame('invalid', $i->errors['fx_rate']);
    }
}
