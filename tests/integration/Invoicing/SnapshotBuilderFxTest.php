<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Fx\FxQuote;
use App\Libraries\Fx\RateQuote;
use App\Libraries\Invoicing\InvoiceCalculator;
use App\Libraries\Invoicing\InvoiceParts;
use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Invoicing\PdfFormat;
use App\Libraries\Invoicing\SnapshotBuilder;
use App\Libraries\Master\BankAccountService;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class SnapshotBuilderFxTest extends PgTestCase
{
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $setup
     *
     * @return array<string, mixed>
     */
    private function build(array $setup, ?FxQuote $fx, ?string $lang = null): array
    {
        if ($lang !== null) {
            $this->owner()->query('UPDATE clients SET doc_language = :l: WHERE id = :id:', ['l' => $lang, 'id' => $setup['client']]);
        }

        $client = $this->rw()->query('SELECT * FROM clients WHERE id = :id:', ['id' => $setup['client']])->getRowArray();
        $entity = $this->rw()->query('SELECT * FROM entities WHERE id = :id:', ['id' => $setup['entity']])->getRowArray();
        $parts  = (new InvoiceParts($this->rw()))->unlocked($setup['client'], '2026-10', $client['portal_url_template']);

        return (new SnapshotBuilder($this->rw()))->build($client, $entity, $parts, '2026-10', '2026-10-27', '2026-10-31', '2026-11-10', null, 'transfer', null, $fx);
    }

    private function nbp(string $rate = '4.4090'): FxQuote
    {
        return FxQuote::fromRate(new RateQuote('NBP', 'EUR', $rate, 1, '2026-10-27', '209/A/NBP/2026', 'multiply'), 'delivery_date', 'previous_business_day', '2026-10-31', 'PLN', 'EUR');
    }

    public function testNbpSnapshotCarriesFxBlock(): void
    {
        $snap = $this->build($this->fxPlEurSetup(), $this->nbp(), 'sk');
        $fx   = $snap['fx'];

        $this->assertSame(3, $snap['schema']);
        $this->assertSame('4761.72', $fx['total']);
        $this->assertSame('4.40900000', $fx['rate']);
        $this->assertSame('4.4090', $fx['rate_display']);
        $this->assertFalse($fx['manual']);
        $this->assertSame('Kurz NBP 1 EUR = 4,4090 PLN', $fx['text']['rate_line']);
        $this->assertSame('z ' . PdfFormat::date('2026-10-27', 'sk') . ', tab. 209/A/NBP/2026', $fx['text']['date_line']);
        $this->assertSame('Spolu v PLN', $fx['text']['total_label']);
        $this->assertSame(PdfFormat::amount('4761.72', 'PLN', 'sk'), $fx['text']['total_value']);
        $this->assertSame('invoice_total_half_up_2', $snap['rounding']['fx']);
    }

    public function testTextIsInDocumentLanguage(): void
    {
        $setup = $this->fxPlEurSetup();

        $pl = $this->build($setup, $this->nbp(), 'pl')['fx']['text'];
        $this->assertStringStartsWith('Kurs NBP 1 EUR = 4,4090 PLN', $pl['rate_line']);
        $this->assertSame('Razem w PLN', $pl['total_label']);

        $en = $this->build($setup, $this->nbp(), 'en')['fx']['text'];
        $this->assertSame('Exchange rate NBP 1 EUR = 4.4090 PLN', $en['rate_line']);
        $this->assertSame('Total in PLN', $en['total_label']);

        $cs = $this->build($setup, $this->nbp(), 'cs')['fx']['text'];
        $this->assertStringStartsWith('Kurz NBP', $cs['rate_line']);
        $this->assertSame('Celkem v PLN', $cs['total_label']);
    }

    public function testEcbDivideForSkEntity(): void
    {
        $setup = $this->issueSetup(['client' => ['currency' => 'PLN']]);

        (new BankAccountService($this->rw(), new AuditLogger($this->rw())))->add($setup['entity'], [
            'label'      => 'Konto PLN',
            'iban'       => 'PL27114020040000300201355387',
            'swift'      => 'BREXPLPW',
            'currency'   => 'PLN',
            'is_default' => false,
        ]);

        $fx   = FxQuote::fromRate(new RateQuote('ECB', 'PLN', '4.3945', 1, '2026-10-29', null, 'divide'), 'delivery_date', 'previous_business_day', '2026-10-31', 'EUR', 'PLN');
        $snap = $this->build($setup, $fx, 'sk');

        $this->assertSame('245.76', $snap['fx']['total']);
        $this->assertSame('Kurz ECB 1 EUR = 4,3945 PLN', $snap['fx']['text']['rate_line']);
        $this->assertStringNotContainsString('tab.', $snap['fx']['text']['date_line']);
    }

    public function testManualRate(): void
    {
        $fx   = FxQuote::manual('4.3000', '2026-10-28', 'multiply', 'delivery_date', 'previous_business_day', '2026-10-31', 'PLN', 'EUR', null);
        $snap = $this->build($this->fxPlEurSetup(), $fx, 'sk');

        $this->assertTrue($snap['fx']['manual']);
        $this->assertSame('Ručne zadaný kurz 1 EUR = 4,3000 PLN', $snap['fx']['text']['rate_line']);
        $this->assertSame('platí ku dňu ' . PdfFormat::date('2026-10-28', 'sk'), $snap['fx']['text']['date_line']);
    }

    public function testWithoutFxIsNullAndSchemaThree(): void
    {
        $snap = $this->build($this->fxPlEurSetup(), null);

        $this->assertNull($snap['fx']);
        $this->assertSame(3, $snap['schema']);
        $this->assertArrayNotHasKey('fx', $snap['rounding']);
    }

    public function testOverflowIsRejected(): void
    {
        try {
            $this->build($this->fxPlEurSetup(), $this->nbp('99999999.99999999'));
            $this->fail('Expected IssueRejected');
        } catch (IssueRejected $e) {
            $this->assertSame('fx_overflow', $e->reason);
        }
    }

    public function testCurrencyMismatchThrows(): void
    {
        $fx = FxQuote::fromRate(new RateQuote('NBP', 'USD', '4.0000', 1, '2026-10-27', '209/A/NBP/2026', 'multiply'), 'delivery_date', 'previous_business_day', '2026-10-31', 'PLN', 'USD');

        $this->expectException(LogicException::class);
        $this->build($this->fxPlEurSetup(), $fx);
    }

    public function testPreviewTokenFollowsRate(): void
    {
        $setup = $this->fxPlEurSetup();

        $a = InvoiceCalculator::previewToken($this->build($setup, $this->nbp('4.4090')));
        $b = InvoiceCalculator::previewToken($this->build($setup, $this->nbp('4.4090')));
        $c = InvoiceCalculator::previewToken($this->build($setup, $this->nbp('4.4091')));

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }
}
