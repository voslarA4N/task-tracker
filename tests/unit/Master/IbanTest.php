<?php

declare(strict_types=1);

namespace Tests\Unit\Master;

use App\Libraries\Master\Iban;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class IbanTest extends CIUnitTestCase
{
    #[DataProvider('provideValidIbans')]
    public function testValidIbans(string $iban): void
    {
        $this->assertTrue(Iban::isValid($iban));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidIbans(): iterable
    {
        yield 'SK' => ['SK3112000000198742637541'];

        yield 'PL' => ['PL61109010140000071219812874'];

        yield 'CZ' => ['CZ6508000000192000145399'];

        yield 'DE' => ['DE89370400440532013000'];
    }

    public function testNormalizeStripsSpacesAndUppercases(): void
    {
        $this->assertSame('SK3112000000198742637541', Iban::normalize(' sk31 1200 0000 1987 4263 7541 '));
        $this->assertSame('SK3112000000198742637541', Iban::normalize('SK31-1200-0000-1987-4263-7541'));
    }

    public function testChangedDigitIsInvalid(): void
    {
        $this->assertFalse(Iban::isValid('SK3112000000198742637542'));
    }

    public function testWrongLengthForCountryIsInvalid(): void
    {
        $this->assertFalse(Iban::isValid('SK311200000019874263754'));
        $this->assertFalse(Iban::isValid('PL6110901014000007121981287'));
    }

    public function testGarbageIsInvalid(): void
    {
        $this->assertFalse(Iban::isValid(''));
        $this->assertFalse(Iban::isValid('12'));
        $this->assertFalse(Iban::isValid('sk3112000000198742637541'));
    }

    public function testFormatGroupsByFour(): void
    {
        $this->assertSame('SK31 1200 0000 1987 4263 7541', Iban::format('SK3112000000198742637541'));
    }
}
