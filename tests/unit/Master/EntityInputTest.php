<?php

declare(strict_types=1);

namespace Tests\Unit\Master;

use App\Libraries\Master\Country;
use App\Libraries\Master\EntityInput;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class EntityInputTest extends CIUnitTestCase
{
    private const TODAY = '2026-10-25';

    /**
     * @param array<string, string> $over
     *
     * @return array<string, string>
     */
    private function sk(array $over = []): array
    {
        return $over + [
            'trade_name'    => 'Jan Novak',
            'address_line1' => 'Hlavna 1',
            'address_line2' => '',
            'postal_code'   => '81101',
            'city'          => 'Bratislava',
            'company_id'    => '12345678',
            'regon'         => '',
            'tax_id'        => '2020123456',
            'vat_id'        => 'SK2020123456',
            'registry_note' => '',
            'email'         => 'jan@example.test',
            'phone'         => '+421 900 123 456',
            'doc_language'  => 'sk',
            'vat_regime'    => 'sk_non_vat',
            'due_days'      => '14',
        ];
    }

    /**
     * @param array<string, string> $over
     *
     * @return array<string, string>
     */
    private function pl(array $over = []): array
    {
        return $over + [
            'trade_name'    => 'Jan Kowalski',
            'address_line1' => 'Dluga 1',
            'address_line2' => '',
            'postal_code'   => '00-001',
            'city'          => 'Warszawa',
            'company_id'    => '5260250274',
            'regon'         => '123456785',
            'tax_id'        => '',
            'vat_id'        => 'PL5260250274',
            'registry_note' => 'CEIDG',
            'email'         => 'jan@example.pl',
            'phone'         => '',
            'doc_language'  => 'pl',
            'vat_regime'    => 'pl_exempt_113_1',
            'due_days'      => '14',
        ];
    }

    public function testValidSkAndPlHaveNoErrors(): void
    {
        $this->assertSame([], EntityInput::validateVersion($this->sk(), Country::SK));
        $this->assertSame([], EntityInput::validateVersion($this->pl(), Country::PL));
    }

    public function testVatIdPrefixMustMatchCountry(): void
    {
        $this->assertArrayHasKey('vat_id', EntityInput::validateVersion($this->sk(['vat_id' => 'PL5260250274']), Country::SK));
    }

    public function testRegonOnlyForPoland(): void
    {
        $this->assertArrayHasKey('regon', EntityInput::validateVersion($this->sk(['regon' => '123456785']), Country::SK));
    }

    public function testRegimeMustBelongToCountry(): void
    {
        $this->assertArrayHasKey('vat_regime', EntityInput::validateVersion($this->sk(['vat_regime' => 'pl_exempt_113_1']), Country::SK));
    }

    public function testEmailWithLineBreakIsRejected(): void
    {
        $this->assertArrayHasKey('email', EntityInput::validateVersion($this->sk(['email' => "a@example.test\r\nBcc: x@example.test"]), Country::SK));
    }

    public function testDueDaysRange(): void
    {
        $this->assertArrayHasKey('due_days', EntityInput::validateVersion($this->sk(['due_days' => '400']), Country::SK));
    }

    public function testBaseDates(): void
    {
        $base = ['name_internal' => 'Moja firma', 'country' => 'SK', 'currency' => 'EUR', 'active_from' => '2026-01-01', 'active_to' => ''];
        $this->assertSame([], EntityInput::validateBase($base, self::TODAY, static fn (string $c): bool => in_array($c, ['EUR', 'PLN', 'CZK'], true)));
        $this->assertArrayHasKey('active_to', EntityInput::validateBase(['active_to' => '2025-12-31'] + $base, self::TODAY, static fn (string $c): bool => in_array($c, ['EUR', 'PLN', 'CZK'], true)));

        foreach (['2026-02-30', '1999-12-31', '2027-10-27'] as $bad) {
            $this->assertArrayHasKey('active_from', EntityInput::validateBase(['active_from' => $bad] + $base, self::TODAY, static fn (string $c): bool => in_array($c, ['EUR', 'PLN', 'CZK'], true)), $bad);
        }

        $this->assertSame([], EntityInput::validateBase(['active_from' => '2027-10-26'] + $base, self::TODAY, static fn (string $c): bool => in_array($c, ['EUR', 'PLN', 'CZK'], true)));
    }

    public function testNormalizeVersion(): void
    {
        $out = EntityInput::normalizeVersion(['vat_id' => ' sk 2020 123 456 ', 'phone' => '', 'email' => 'Jan@Example.TEST'] + $this->sk());

        $this->assertSame('SK2020123456', $out['vat_id']);
        $this->assertNull($out['phone']);
        $this->assertSame('jan@example.test', $out['email']);
    }

    public function testVersionFromPostIgnoresForgedKeys(): void
    {
        $request = new IncomingRequest(config('App'), new SiteURI(config('App')), null, new UserAgent());
        $request->setGlobal('post', $this->sk() + ['country' => 'PL', 'currency' => 'PLN', 'id' => '5']);

        $out = EntityInput::versionFromPost($request);

        $this->assertArrayNotHasKey('country', $out);
        $this->assertArrayNotHasKey('currency', $out);
        $this->assertArrayNotHasKey('id', $out);
        $this->assertSame('Jan Novak', $out['trade_name']);
    }
}
