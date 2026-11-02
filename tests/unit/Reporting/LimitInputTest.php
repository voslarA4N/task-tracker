<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Libraries\Reporting\LimitInput;
use App\Libraries\Reporting\LimitRejected;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class LimitInputTest extends CIUnitTestCase
{
    /**
     * @return array<string, string>
     */
    private static function valid(): array
    {
        return ['country' => 'SK', 'year' => '2026', 'name' => 'Registrácia DPH', 'amount' => '50000.00', 'currency' => 'EUR', 'calc_method' => 'calendar_year', 'thresholds' => '80,100', 'revision' => ''];
    }

    public function testNormalizeAmountAndThresholds(): void
    {
        $n = LimitInput::normalize(['amount' => "50\u{00A0}000,00", 'thresholds' => ' 80 ; 100 '] + self::valid());
        $this->assertSame('50000.00', $n['amount']);
        $this->assertSame('80,100', $n['thresholds']);
        $this->assertSame('49790', LimitInput::normalize(['amount' => '49790'] + self::valid())['amount']);
    }

    public function testValidPasses(): void
    {
        $this->assertSame([], LimitInput::validate(LimitInput::normalize(self::valid()), static fn (string $c): bool => true));
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('provideInvalid')]
    public function testInvalid(array $override, string $field, string $key): void
    {
        $errors = LimitInput::validate(LimitInput::normalize($override + self::valid()), static fn (string $c): bool => $c === 'EUR' || $c === 'PLN');
        $this->assertSame($key, $errors[$field] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'country' => [['country' => 'DE'], 'country', 'Limits.errCountry'];

        foreach (['1999', '2101', '20x6'] as $y) {
            yield 'year ' . $y => [['year' => $y], 'year', 'Limits.errYear'];
        }

        yield 'name empty' => [['name' => ''], 'name', 'Limits.errName'];

        yield 'name long' => [['name' => str_repeat('a', 121)], 'name', 'Limits.errName'];

        foreach (['0', '-5', '12345678901.00', 'abc'] as $a) {
            yield 'amount ' . $a => [['amount' => $a], 'amount', 'Limits.errAmount'];
        }

        yield 'currency' => [['currency' => 'USD'], 'currency', 'Limits.errCurrency'];

        yield 'method' => [['calc_method' => 'monthly'], 'calc_method', 'Limits.errMethod'];

        foreach (['', '0', '201', '80,100,120,150', '80,x', '80.5'] as $t) {
            yield 'thresholds ' . $t => [['thresholds' => $t], 'thresholds', 'Limits.errThresholds'];
        }
    }

    public function testThresholds(): void
    {
        $this->assertSame([80, 100], LimitInput::thresholds('100,80,80'));
        $this->assertSame([], LimitInput::validate(LimitInput::normalize(['thresholds' => '80,80'] + self::valid()), static fn (string $c): bool => true));
        $this->assertSame([80], LimitInput::thresholds('80,80'));
    }

    public function testToRow(): void
    {
        $row = LimitInput::toRow(LimitInput::normalize(self::valid()));
        $this->assertSame('50000.00', $row['amount']);
        $this->assertSame(2026, $row['year']);
        $this->assertSame('80,100', $row['thresholds_csv']);
    }

    public function testRejected(): void
    {
        $this->assertSame('stale', (new LimitRejected('stale'))->reason);
        $this->expectException(InvalidArgumentException::class);
        new LimitRejected('nope');
    }
}
