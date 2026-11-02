<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\FakeRateHttpClient;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class CreditNoteFxTest extends PgTestCase
{
    use CreditNoteFixtures;
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private FakeRateHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
        $this->http = new FakeRateHttpClient();
        Services::injectMock('rateHttpClient', $this->http);
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        $this->restoreFxSettings();
        Services::reset();
        parent::tearDown();
    }

    private function issuedForeign(): int
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-30', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-30']))->id;
    }

    public function testCopiesFxFromOriginal(): void
    {
        $orig = $this->issuedForeign();
        $cn   = $this->issueCreditNote($orig);

        $a = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $orig])->getRowArray();
        $b = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $cn->id])->getRowArray();

        foreach (['fx_rate', 'fx_source', 'fx_date', 'fx_table_no', 'fx_date_basis'] as $col) {
            $this->assertSame($a[$col], $b[$col], $col);
        }
        $this->assertSame('-4761.72', $b['total_entity_ccy']);
        $this->assertSumsToZero($orig, $cn->id);
        $this->assertSame([], $this->http->calls);
    }

    public function testRateChangesDoNotAffectCreditNote(): void
    {
        $orig = $this->issuedForeign();
        $this->owner()->query("UPDATE exchange_rate_cache SET rate = '9.0000', rate_published = '9.0000'");
        $this->owner()->query("UPDATE fx_country_settings SET source = 'ECB' WHERE country = 'PL'");

        $cn = $this->issueCreditNote($orig);

        $this->assertSumsToZero($orig, $cn->id);
        $this->assertSame('4.40900000', $this->owner()->query('SELECT fx_rate FROM invoices WHERE id = :i:', ['i' => $cn->id])->getRow()->fx_rate);
        $this->assertSame([], $this->http->calls);
    }
}
