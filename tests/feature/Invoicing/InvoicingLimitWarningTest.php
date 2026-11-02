<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Reporting\LimitPrecheck;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\AuthTestCase;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\FakeRateHttpClient;
use Tests\Support\FxFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\TurnoverFixtures;

/**
 * Predkontrola limitu obratu v nahlade faktury (LIM-04, D-16, CUR-04).
 *
 * @internal
 */
#[Group('feature')]
final class InvoicingLimitWarningTest extends AuthTestCase
{
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;
    use CreditNoteFixtures;
    use TurnoverFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
        Services::injectMock('cache', new MockCache());
        Services::injectMock('rateHttpClient', new FakeRateHttpClient());
        Services::injectMock('invoiceIssuer', $this->issuer());
        $this->resetMasterTables();
        $this->owner()->query('DELETE FROM turnover_limits');
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        $this->owner()->query('DELETE FROM turnover_limits');
        $this->restoreFxSettings();
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function body(TestResponse $r): string
    {
        return (string) $r->response()->getBody();
    }

    /**
     * @param array<string, mixed> $client
     *
     * @return array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int}
     */
    private function setup1000(array $client = []): array
    {
        return $this->issueSetup(['client' => $client, 'parts' => [['40.00', '2026-10', ['rate' => '25.00']]]]);
    }

    /**
     * @param array{entity: int, client: int} $s
     */
    private function preview(array $s, string $delivery = '2026-10-31'): string
    {
        return $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/fragments/invoice-preview?client=' . $s['client'] . '&month=2026-10&entity=' . $s['entity'] . '&issue_date=2026-10-27&delivery_date=' . $delivery));
    }

    private function skLimit(): void
    {
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
    }

    public function testWarnsWhenCrossing80(): void
    {
        $s = $this->setup1000();
        $this->skLimit();
        $this->turnoverInvoice($s['entity'], $s['client'], '2026-06-01', '39500.00');

        $html = $this->preview($s);

        $this->assertStringContainsString('Vystavenie prekročí prah limitu.', $html);
        $this->assertStringContainsString('79,0', $html);
        $this->assertStringContainsString('81,0', $html);
        $this->assertStringContainsString('Upozornenie nebráni vystaveniu.', $html);
        $this->assertMatchesRegularExpression('#<a href="/settings/limits" target="_blank" rel="noopener">#', $html);
        $this->assertStringContainsString('data-can-issue="1"', $html);
    }

    public function testCritAt100(): void
    {
        $s = $this->setup1000();
        $this->skLimit();
        $this->turnoverInvoice($s['entity'], $s['client'], '2026-06-01', '49500.00');

        $html = $this->preview($s);

        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('Vystavenie prekročí limit obratu.', $html);
    }

    public function testAlreadyOver(): void
    {
        $s = $this->setup1000();
        $this->skLimit();
        $this->turnoverInvoice($s['entity'], $s['client'], '2026-06-01', '45000.00');

        $this->assertStringContainsString('Limit už je prekročený', $this->preview($s));
    }

    public function testNoWarningBelowThreshold(): void
    {
        $s = $this->setup1000();
        $this->skLimit();
        $this->turnoverInvoice($s['entity'], $s['client'], '2026-06-01', '10000.00');

        $this->assertStringNotContainsString('prah limitu', $this->preview($s));
    }

    public function testExcludedClient(): void
    {
        $s = $this->setup1000(['counts_toward_limit' => false]);
        $this->skLimit();
        $this->turnoverInvoice($s['entity'], $s['client'], '2026-06-01', '49500.00');

        $html = $this->preview($s);

        $this->assertStringNotContainsString('prah limitu', $html);
        $this->assertStringNotContainsString('limit obratu', $html);
    }

    public function testForeignCurrencyWithoutRate(): void
    {
        $s = $this->fxPlEurSetup();
        $this->insertLimit('PL', 2026, 'Limit PL', '200000.00', 'PLN', 'calendar_year');

        $html = $this->preview($s);

        $this->assertStringContainsString('Predkontrola limitu nie je dostupná', $html);
    }

    public function testFailureIsSafe(): void
    {
        $s = $this->setup1000();
        Services::injectMock('limitPrecheck', new LimitPrecheck(static function (): never {
            throw new RuntimeException('boom');
        }));

        $html = $this->preview($s);

        $this->assertStringContainsString('Predkontrolu limitu sa nepodarilo vypočítať. Vystavenie to neovplyvní.', $html);
        $this->assertStringContainsString('data-can-issue="1"', $html);
    }

    public function testRollingWindowUsesDeliveryDate(): void
    {
        $s = $this->setup1000();
        $this->insertLimit('SK', 2026, 'Kĺzavý', '10000.00', 'EUR', 'rolling_12m');
        $this->turnoverInvoice($s['entity'], $s['client'], '2025-11-15', '7600.00');

        $this->assertStringContainsString('prah limitu', $this->preview($s, '2026-10-31'));
    }

    public function testRollingWindowExcludesOldTurnover(): void
    {
        $s = $this->setup1000();
        $this->insertLimit('SK', 2026, 'Kĺzavý', '10000.00', 'EUR', 'rolling_12m');
        $this->turnoverInvoice($s['entity'], $s['client'], '2025-10-15', '7600.00');

        $this->assertStringNotContainsString('prah limitu', $this->preview($s, '2026-10-31'));
    }

    public function testIssueStillSucceedsAndStoresNothingNew(): void
    {
        $s = $this->setup1000();
        $this->skLimit();
        $this->turnoverInvoice($s['entity'], $s['client'], '2026-06-01', '39500.00');

        $html = $this->preview($s);
        $this->assertStringContainsString('prah limitu', $html);

        preg_match_all('/<input type="hidden" name="([a-z_\[\]]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER);
        $form = [];

        foreach ($m as $row) {
            if ($row[1] === 'part_ids[]') {
                $form['part_ids'][] = $row[2];
            } elseif (! in_array($row[1], ['csrf_test_name', csrf_token()], true)) {
                $form[$row[1]] = html_entity_decode($row[2]);
            }
        }

        $count  = static fn (self $t): string => json_encode($t->owner()->query('SELECT (SELECT count(*) FROM turnover_limits) AS l, (SELECT count(*) FROM entity_opening_turnover) AS o')->getRowArray(), JSON_THROW_ON_ERROR);
        $before = $count($this);

        $this->withSession();
        $result = $this->withHeaders(['HX-Request' => 'true'])->call('post', '/invoices/issue', $form + [csrf_token() => csrf_hash()]);

        $result->assertStatus(200);
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS n FROM invoices WHERE number NOT LIKE 'T%'")->getRow()->n);
        $this->assertSame($before, $count($this));
    }
}
