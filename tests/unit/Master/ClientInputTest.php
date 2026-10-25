<?php

declare(strict_types=1);

namespace Tests\Unit\Master;

use App\Libraries\Master\ClientInput;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class ClientInputTest extends CIUnitTestCase
{
    /**
     * @param array<string, string> $over
     *
     * @return array<string, string>
     */
    private function valid(array $over = []): array
    {
        return $over + [
            'name'                => 'All4Net',
            'address_line1'       => 'Hlavna 1',
            'address_line2'       => '',
            'postal_code'         => '81101',
            'city'                => 'Bratislava',
            'country'             => 'SK',
            'identifier_type'     => 'local_id',
            'identifier_value'    => '12345678',
            'tax_id'              => '',
            'vat_id'              => '',
            'invoice_email'       => 'fakturacia@all4net.example',
            'cc_emails'           => '',
            'hourly_rate'         => '27',
            'currency'            => 'EUR',
            'doc_language'        => '',
            'due_days'            => '',
            'billing_mode'        => 'domestic',
            'counts_toward_limit' => '1',
        ];
    }

    /**
     * @param array<string, string> $over
     *
     * @return array<string, string>
     */
    private function errorsFor(array $over): array
    {
        return ClientInput::validate($this->valid($over));
    }

    public function testValidClient(): void
    {
        $this->assertSame([], $this->errorsFor([]));
        $n = ClientInput::normalize($this->valid());
        $this->assertSame('27.00', $n['hourly_rate']);
        $this->assertNull($n['doc_language']);
        $this->assertNull($n['due_days']);
        $this->assertTrue($n['counts_toward_limit']);
        $this->assertSame([], $n['cc_emails']);
    }

    public function testRateWithComma(): void
    {
        $this->assertSame([], $this->errorsFor(['hourly_rate' => '27,5']));
        $this->assertSame('27.50', ClientInput::normalize($this->valid(['hourly_rate' => '27,5']))['hourly_rate']);
        $this->assertSame('1200.00', ClientInput::normalize($this->valid(['hourly_rate' => '1 200']))['hourly_rate']);
    }

    #[DataProvider('provideBadRate')]
    public function testBadRate(string $rate): void
    {
        $this->assertArrayHasKey('hourly_rate', $this->errorsFor(['hourly_rate' => $rate]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBadRate(): iterable
    {
        yield 'three decimals' => ['27.555'];

        yield 'negative' => ['-1'];

        yield 'text' => ['abc'];

        yield 'empty' => [''];
    }

    public function testEuB2bRequiresVatId(): void
    {
        $errors = $this->errorsFor(['billing_mode' => 'eu_b2b', 'country' => 'DE', 'identifier_type' => 'none', 'identifier_value' => '']);
        $this->assertSame(lang('Clients.errorEuB2bVat'), $errors['vat_id']);
        $this->assertSame([], $this->errorsFor(['billing_mode' => 'eu_b2b', 'country' => 'DE', 'vat_id' => 'de123456789', 'identifier_type' => 'none', 'identifier_value' => '']));
    }

    public function testIdentifierNoneRejectsValue(): void
    {
        $errors = $this->errorsFor(['identifier_type' => 'none', 'identifier_value' => '123']);
        $this->assertSame(lang('Clients.errorIdentifierNone'), $errors['identifier_value']);
        $n = ClientInput::normalize($this->valid(['identifier_type' => 'none', 'identifier_value' => '']));
        $this->assertNull($n['identifier_value']);
    }

    public function testEuVatNormalizedToUppercase(): void
    {
        $values = $this->valid(['identifier_type' => 'eu_vat', 'identifier_value' => 'de 123456789', 'country' => 'DE']);
        $n      = ClientInput::normalize($values);
        $this->assertSame('DE123456789', $n['identifier_value']);
        $this->assertSame([], ClientInput::validate($values));
    }

    public function testLocalIdPerCountry(): void
    {
        $this->assertArrayHasKey('identifier_value', $this->errorsFor(['country' => 'PL', 'identifier_value' => '123']));
        $this->assertSame([], $this->errorsFor(['country' => 'PL', 'identifier_value' => '5260250274']));
        $this->assertArrayHasKey('identifier_value', $this->errorsFor(['country' => 'SK', 'identifier_value' => '1234']));
    }

    public function testUnknownCountry(): void
    {
        $this->assertArrayHasKey('country', $this->errorsFor(['country' => 'XX']));
    }

    public function testBadChoices(): void
    {
        $this->assertArrayHasKey('currency', $this->errorsFor(['currency' => 'USD']));
        $this->assertArrayHasKey('billing_mode', $this->errorsFor(['billing_mode' => 'x']));
        $this->assertArrayHasKey('doc_language', $this->errorsFor(['doc_language' => 'de']));
        $this->assertArrayHasKey('due_days', $this->errorsFor(['due_days' => '400']));
        $this->assertArrayHasKey('invoice_email', $this->errorsFor(['invoice_email' => 'bad']));
        $this->assertArrayHasKey('invoice_email', $this->errorsFor(['invoice_email' => "a@x.sk\r\nBcc: b@x.sk"]));
        $this->assertArrayHasKey('name', $this->errorsFor(['name' => '']));
    }

    public function testFromPostWhitelist(): void
    {
        $request = $this->request(['name' => 'X', 'id' => '99', 'archived_at' => '2026-01-01', 'created_at' => 'x']);
        $values  = ClientInput::fromPost($request);

        $this->assertSame('X', $values['name']);
        $this->assertArrayNotHasKey('id', $values);
        $this->assertArrayNotHasKey('archived_at', $values);
        $this->assertArrayNotHasKey('created_at', $values);
        $this->assertSame('0', $values['counts_toward_limit']);
        $this->assertFalse(ClientInput::normalize(array_merge($values, ['hourly_rate' => '1']))['counts_toward_limit']);

        $values = ClientInput::fromPost($this->request(['counts_toward_limit' => '1']));
        $this->assertSame('1', $values['counts_toward_limit']);
    }

    /**
     * @param array<string, string> $post
     */
    private function request(array $post): IncomingRequest
    {
        $config  = new App();
        $request = new IncomingRequest($config, new SiteURI($config), null, new UserAgent());
        $request->setGlobal('post', $post);

        return $request;
    }
}
