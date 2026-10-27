<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\MoneyFormat;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * Core Value: jedna suma z InvoiceCalculator na obrazovke, vo faktúre, v kópii údajov aj v PDF.
 *
 * @internal
 */
#[Group('feature')]
final class InvoicingScreenTest extends AuthTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
        Services::injectMock('invoiceIssuer', $this->issuer(new MpdfInvoiceInv2Renderer()));
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    /**
     * @return array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int}
     */
    private function setup221(): array
    {
        return $this->issueSetup([
            'client' => ['name' => 'All4Net'],
            'parts'  => [['1.11', '2026-10', ['rate' => '33.33']], ['2.22', '2026-10', ['rate' => '33.33']], ['3.33', '2026-10', ['rate' => '33.33']]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formFrom(string $html): array
    {
        preg_match_all('/<input type="hidden" name="([a-z_\[\]]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER);
        $out = [];

        foreach ($m as $row) {
            if ($row[1] === 'part_ids[]') {
                $out['part_ids'][] = $row[2];
            } elseif ($row[1] !== csrf_token()) {
                $out[$row[1]] = html_entity_decode($row[2]);
            }
        }

        return $out;
    }

    private function issueFromScreen(int $client): int
    {
        $form = $this->formFrom($this->body($this->get('/invoices/new?client=' . $client . '&month=2026-10')));
        $this->withSession();
        $this->withHeaders(['HX-Request' => 'true'])->call('post', '/invoices/issue', $form + [csrf_token() => csrf_hash()])->assertStatus(200);

        return (int) $this->owner()->query("SELECT id FROM invoices WHERE number = '202610001'")->getRow()->id;
    }

    public function testScreenShowsSameTotalEverywhere(): void
    {
        $s     = $this->setup221();
        $html  = $this->body($this->get('/invoices/new?client=' . $s['client'] . '&month=2026-10'));
        $money = esc(MoneyFormat::format('221.98', 'EUR'));

        $this->assertSame(4, substr_count($html, $money));
        $this->assertStringContainsString('class="inv-summary-total"', $html);
        $this->assertMatchesRegularExpression('/inv-summary.*' . preg_quote($money, '/') . '/s', $html);
        $this->assertStringContainsString('inv-total', $html);
        $this->assertStringContainsString('master-sticky-actions', $html);
        $this->assertStringContainsString('id="inv-confirm"', $html);
        $this->assertStringContainsString('Vystaviť faktúru pre All4Net?', $html);
    }

    public function testIssuedInvoiceTotalsMatchEverywhere(): void
    {
        $s  = $this->setup221();
        $id = $this->issueFromScreen($s['client']);

        $this->assertSame('221.98', (string) $this->owner()->query('SELECT total FROM invoices WHERE id = :i:', ['i' => $id])->getRow()->total);
        $this->assertSame('221.98', (string) $this->owner()->query('SELECT sum(amount) AS s FROM invoice_lines WHERE invoice_id = :i:', ['i' => $id])->getRow()->s);
        $this->assertSame('221.98', (string) $this->owner()->query("SELECT snapshot->'totals'->>'total' AS t FROM invoices WHERE id = :i:", ['i' => $id])->getRow()->t);

        $detail = $this->body($this->get('/invoices/' . $id));
        $this->assertStringContainsString(esc(MoneyFormat::format('221.98', 'EUR')), $detail);
    }

    public function testPdfTextContainsSameTotal(): void
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext is not available');
        }

        $s  = $this->setup221();
        $id = $this->issueFromScreen($s['client']);

        $doc = (new DocumentStore($this->rw()))->read($id);
        $this->assertNotNull($doc);

        $file = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($file, $doc->bytes);
        $text = str_replace(["\u{00A0}", "\u{202F}"], ' ', (string) shell_exec('pdftotext ' . escapeshellarg($file) . ' -'));
        unlink($file);

        $this->assertStringContainsString('221,98 €', $text);
    }

    public function testConfirmSheetIsAccessibleAndGuardsDoubleClick(): void
    {
        $s    = $this->setup221();
        $html = $this->body($this->get('/invoices/new?client=' . $s['client'] . '&month=2026-10'));

        $this->assertStringContainsString('hx-disabled-elt="this"', $html);
        $this->assertStringContainsString('aria-labelledby="inv-confirm-title"', $html);
        $this->assertStringContainsString('hx-post="/invoices/issue"', $html);
        $this->assertStringContainsString('data-bs-target="#inv-confirm"', $html);
        $this->assertStringNotContainsString('style="', $html);
    }

    public function testConfirmSheetRepeatsBackdateWarning(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);
        $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]));

        $html = $this->body($this->get('/invoices/new?client=' . $s['client'] . '&month=2026-10&issue_date=2026-10-20'));

        $this->assertStringContainsString('Faktúra sa vystaví so spätným dátumom', $html);
    }
}
