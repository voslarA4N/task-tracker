<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\LegalProfile;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class LegalProfilePrintTest extends CIUnitTestCase
{
    public function testSlovakNonVatDomestic(): void
    {
        $p = LegalProfile::print('SK', 'sk_non_vat', 'domestic');

        $this->assertSame(['trade_name', 'address', 'company_id', 'tax_id', 'registry_note'], $p['supplier']);
        $this->assertSame(['name', 'address', 'identifier', 'tax_id'], $p['customer']);
        $this->assertSame(['issue_date', 'delivery_date', 'due_date', 'payment_method'], $p['meta']);
        $this->assertSame('variable_symbol', $p['payment_reference']);
        $this->assertFalse($p['line_vat_code']);
        $this->assertFalse($p['line_unit']);
        $this->assertTrue($p['vat_note']);
        $this->assertNotContains('vat_id', $p['supplier']);
    }

    public function testCzechSameShapeAsSlovak(): void
    {
        $this->assertSame(
            LegalProfile::print('SK', 'sk_non_vat', 'domestic'),
            LegalProfile::print('CZ', 'cz_non_vat', 'domestic'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function polishRegimes(): iterable
    {
        yield '113_1' => ['pl_exempt_113_1'];

        yield '113_9' => ['pl_exempt_113_9'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('polishRegimes')]
    public function testPolishExempt(string $regime): void
    {
        $p = LegalProfile::print('PL', $regime, 'domestic');

        $this->assertSame(['trade_name', 'address', 'tax_id'], $p['supplier']);
        $this->assertSame(['name', 'address', 'tax_id'], $p['customer']);
        $this->assertSame('invoice_number', $p['payment_reference']);
        $this->assertTrue($p['line_unit']);
        $this->assertTrue($p['line_vat_code']);
        $this->assertNotContains('company_id', $p['supplier']);
        $this->assertNotContains('registry_note', $p['supplier']);
    }

    public function testEuB2bAddsVatId(): void
    {
        $pl = LegalProfile::print('PL', 'pl_exempt_113_1', 'eu_b2b');
        $this->assertContains('vat_id', $pl['supplier']);
        $this->assertContains('vat_id', $pl['customer']);

        $sk = LegalProfile::print('SK', 'sk_non_vat', 'eu_b2b');
        $this->assertContains('vat_id', $sk['supplier']);
        $this->assertContains('vat_id', $sk['customer']);
        $this->assertSame('variable_symbol', $sk['payment_reference']);
    }

    public function testNonEuFallsBackToDomesticRules(): void
    {
        $this->assertSame(
            LegalProfile::print('PL', 'pl_exempt_113_1', 'domestic'),
            LegalProfile::print('PL', 'pl_exempt_113_1', 'non_eu'),
        );
    }

    public function testUnknownCountryOrRegimeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LegalProfile::print('DE', 'sk_non_vat', 'domestic');
    }

    public function testUnknownRegimeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LegalProfile::print('SK', 'xx', 'domestic');
    }
}
