<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\RegenerateFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class InvoiceRegenerateTest extends AuthTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;
    use RegenerateFixtures;

    private const ISSUED_AT = '2026-11-01T10:00:00+01:00';

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at(self::ISSUED_AT);
        $this->resetMasterTables();
        $this->owner()->query('UPDATE document_settings SET regen_invoice_hours = 72 WHERE id = 1');
        Services::injectMock('creditNoteIssuer', $this->creditNoteIssuer());
        Services::injectMock('documentRegenerator', $this->regenerator());
        $user         = $this->createUser('owner@example.test');
        $this->userId = (int) $user->id;
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        $this->owner()->query('UPDATE document_settings SET regen_invoice_hours = 72 WHERE id = 1');
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Services::injectMock('clock', new FrozenClock($time));
        $_SESSION['last_activity'] = service('clock')->now()->getTimestamp();
    }

    private function issued(): int
    {
        $s = $this->issueSetup();

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-11-01', 'deliveryDate' => '2026-10-31', 'dueDate' => '2026-11-15']))->id;
    }

    private function versions(int $id, string $kind): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_documents WHERE invoice_id = :i: AND kind = :k:', ['i' => $id, 'k' => $kind])->getRow()->c;
    }

    private function postRegen(string $path, mixed $expected = '1', bool $csrf = true): TestResponse
    {
        unset($_SESSION['app_notice']);
        $this->withSession();
        $data = ($expected === null ? [] : ['expected_version' => $expected]) + ($csrf ? [csrf_token() => csrf_hash()] : []);

        return $this->call('post', $path, $data);
    }

    /**
     * @return array{type: string, message: string}
     */
    private function notice(): array
    {
        $n = $_SESSION['app_notice'] ?? null;
        $this->assertIsArray($n);

        return $n;
    }

    public function testRegenerateReportCreatesVersionAndAuditsUser(): void
    {
        $id = $this->issued();

        $r = $this->postRegen('/invoices/' . $id . '/regenerate/report');
        $r->assertStatus(302);
        $this->assertStringEndsWith('/invoices/' . $id, $r->response()->getHeaderLine('Location'));
        $this->assertSame(2, $this->versions($id, 'report'));

        $n = $this->notice();
        $this->assertSame('success', $n['type']);
        $this->assertSame(lang('Invoices.regenDone', [lang('Invoices.versionsKindReport'), 2, 'rep-2']), $n['message']);

        $audit = $this->owner()->query("SELECT user_id FROM audit_log WHERE entity_type = 'invoice' AND entity_id = :i: AND action = 'regenerate_document'", ['i' => $id])->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertSame($this->userId, (int) $audit[0]['user_id']);
    }

    public function testPostWithoutCsrfIsForbidden(): void
    {
        $id = $this->issued();

        $this->postRegen('/invoices/' . $id . '/regenerate/report', '1', false)->assertStatus(403);
        $this->assertSame(1, $this->versions($id, 'report'));
    }

    public function testAnonymousPostRedirectsToLogin(): void
    {
        $id = $this->issued();
        auth()->logout();
        $_SESSION = [];

        $r = $this->postRegen('/invoices/' . $id . '/regenerate/report');
        $r->assertStatus(302);
        $this->assertStringEndsWith('/login', $r->response()->getHeaderLine('Location'));
        $this->assertSame(1, $this->versions($id, 'report'));
    }

    public function testInvoiceRegenerateInWindowAndRejectedAfter(): void
    {
        $id = $this->issued();

        $this->postRegen('/invoices/' . $id . '/regenerate/invoice')->assertStatus(302);
        $this->assertSame(2, $this->versions($id, 'invoice'));

        $this->at('2026-11-04T10:00:01+01:00');
        $this->postRegen('/invoices/' . $id . '/regenerate/invoice', '2')->assertStatus(302);

        $n = $this->notice();
        $this->assertSame('danger', $n['type']);
        $this->assertSame(lang('Invoices.regenErrorWindow'), $n['message']);
        $this->assertSame(2, $this->versions($id, 'invoice'));
    }

    public function testStaleExpectedVersionWarns(): void
    {
        $id = $this->issued();
        $this->postRegen('/invoices/' . $id . '/regenerate/report')->assertStatus(302);

        $this->postRegen('/invoices/' . $id . '/regenerate/report', '1')->assertStatus(302);
        $n = $this->notice();
        $this->assertSame('warning', $n['type']);
        $this->assertSame(lang('Invoices.regenErrorStale'), $n['message']);
        $this->assertSame(2, $this->versions($id, 'report'));
    }

    public function testInvalidExpectedVersionIsRejected(): void
    {
        $id = $this->issued();

        foreach (['abc', null, '', '-1', '1.5'] as $bad) {
            $this->postRegen('/invoices/' . $id . '/regenerate/report', $bad)->assertStatus(302);
            $n = $this->notice();
            $this->assertSame('danger', $n['type']);
            $this->assertSame(lang('Invoices.regenErrorInput'), $n['message']);
        }

        $this->assertSame(1, $this->versions($id, 'report'));
    }

    public function testExternalInvoiceNotRegenerableAndUnknownIs404(): void
    {
        $s   = $this->issueSetup();
        $ext = $this->insertExternalInvoice($s['client'], '2026-10', $s['parts']);

        $this->postRegen('/invoices/' . $ext . '/regenerate/report')->assertStatus(302);
        $n = $this->notice();
        $this->assertSame('danger', $n['type']);
        $this->assertSame(lang('Invoices.regenErrorNotRegenerable'), $n['message']);

        try {
            $this->assertSame(404, $this->postRegen('/invoices/99999999/regenerate/report')->response()->getStatusCode());
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testRegenerateRoutesAreNotReachableByGet(): void
    {
        $id = $this->issued();

        try {
            $this->assertSame(404, $this->get('/invoices/' . $id . '/regenerate/report')->response()->getStatusCode());
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $this->versions($id, 'report'));
    }

    public function testLatestEtagAfterRegenerationAndVersionDownload(): void
    {
        $id = $this->issued();
        $v1 = (string) $this->owner()->query("SELECT sha256 FROM invoice_documents WHERE invoice_id = :i: AND kind = 'report' AND version = 1", ['i' => $id])->getRow()->sha256;
        $this->postRegen('/invoices/' . $id . '/regenerate/report')->assertStatus(302);
        $v2 = trim((string) $this->owner()->query("SELECT sha256 FROM invoice_documents WHERE invoice_id = :i: AND kind = 'report' AND version = 2", ['i' => $id])->getRow()->sha256);
        $v1 = trim($v1);

        $latest = $this->get('/invoices/' . $id . '/report')->response();
        $this->assertSame('"' . $v2 . '"', $latest->getHeaderLine('ETag'));
        $this->assertStringContainsString('no-store', $latest->getHeaderLine('Cache-Control'));

        $old = $this->get('/invoices/' . $id . '/report/v/1');
        $old->assertStatus(200);
        $this->assertSame('"' . $v1 . '"', $old->response()->getHeaderLine('ETag'));
        $this->assertStringContainsString('-v1.pdf', $old->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $old->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $old->response()->getHeaderLine('X-Content-Type-Options'));

        $this->get('/invoices/' . $id . '/pdf/v/1')->assertStatus(200);
    }

    public function testMissingVersionAndCreditNoteReportAre404(): void
    {
        $id = $this->issued();
        $cn = $this->issueCreditNote($id);

        foreach (['/invoices/' . $id . '/report/v/9', '/invoices/' . $id . '/pdf/v/2', '/invoices/' . $cn->id . '/report/v/1'] as $url) {
            try {
                $this->assertSame(404, $this->get($url)->response()->getStatusCode());
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testVersionDownloadIsScopedToInvoice(): void
    {
        $a = $this->issued();
        $this->postRegen('/invoices/' . $a . '/regenerate/report')->assertStatus(302);

        $s = $this->issueSetup(['parts' => [['5.00', '2026-10']]]);
        $b = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-11-01', 'deliveryDate' => '2026-10-31', 'dueDate' => '2026-11-15']))->id;

        $r = $this->get('/invoices/' . $b . '/report/v/1');
        $r->assertStatus(200);
        $this->assertStringContainsString((string) $this->numberOf($b), $r->response()->getHeaderLine('Content-Disposition'));

        try {
            $this->assertSame(404, $this->get('/invoices/' . $b . '/report/v/2')->response()->getStatusCode());
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function detail(int $id): string
    {
        $r = $this->get('/invoices/' . $id);
        $r->assertStatus(200);

        return (string) $r->response()->getBody();
    }

    public function testDetailShowsBothFormsInWindow(): void
    {
        $id   = $this->issued();
        $html = $this->detail($id);

        foreach (['report', 'invoice'] as $kind) {
            $this->assertMatchesRegularExpression(
                '#<form method="post" action="/invoices/' . $id . '/regenerate/' . $kind . '" data-submit-once[^>]*>\s*<input type="hidden" name="[^"]+" value="[^"]+"[^>]*>\s*<input type="hidden" name="expected_version" value="1">#',
                $html,
            );
        }

        $this->assertStringContainsString(lang('Invoices.regenReport'), $html);
        $this->assertStringContainsString('4. 11. 2026', $html);
        $this->assertStringContainsString('class="d-inline"', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testInvoiceFormHiddenAfterWindowReportFormStays(): void
    {
        $this->owner()->query('UPDATE document_settings SET regen_invoice_hours = 1 WHERE id = 1');
        $id = $this->issued();
        $this->at('2026-11-01T12:00:00+01:00');
        $html = $this->detail($id);

        $this->assertStringContainsString('/regenerate/report"', $html);
        $this->assertStringNotContainsString('/regenerate/invoice"', $html);
    }

    public function testInvoiceFormHiddenWhenWindowIsZero(): void
    {
        $this->owner()->query('UPDATE document_settings SET regen_invoice_hours = 0 WHERE id = 1');
        $id   = $this->issued();
        $html = $this->detail($id);

        $this->assertStringNotContainsString('/regenerate/invoice"', $html);
        $this->assertStringContainsString('/regenerate/report"', $html);
    }

    public function testRaisingSettingDoesNotReopenClosedInvoice(): void
    {
        $id = $this->issued();
        $this->owner()->query('UPDATE document_settings SET regen_invoice_hours = 8760 WHERE id = 1');
        $this->at('2026-11-04T10:00:01+01:00');
        $this->postRegen('/invoices/' . $id . '/regenerate/invoice')->assertStatus(302);

        $this->assertSame(lang('Invoices.regenErrorWindow'), $this->notice()['message']);
        $this->assertSame(1, $this->versions($id, 'invoice'));
    }

    public function testExternalAndSchema1HaveNoForms(): void
    {
        $s    = $this->issueSetup();
        $ext  = $this->insertExternalInvoice($s['client'], '2026-10', $s['parts']);
        $html = $this->detail($ext);
        $this->assertStringNotContainsString('/regenerate/', $html);

        $s2   = $this->issueSetup(['parts' => [['5.00', '2026-10']]]);
        $old  = $this->insertIssuedInvoiceRaw($s2['client'], $s2['entity'], '2026-10', $s2['parts']);
        $html = $this->detail($old);
        $this->assertStringNotContainsString('/regenerate/report', $html);
        $this->assertStringNotContainsString('/regenerate/invoice', $html);
    }

    public function testCreditNoteHasInvoiceFormOnly(): void
    {
        $id   = $this->issued();
        $cn   = $this->issueCreditNote($id);
        $html = $this->detail($cn->id);

        $this->assertStringContainsString('/invoices/' . $cn->id . '/regenerate/invoice"', $html);
        $this->assertStringNotContainsString('/regenerate/report"', $html);
    }

    public function testVersionHistoryAfterRegeneration(): void
    {
        $id = $this->issued();
        $this->postRegen('/invoices/' . $id . '/regenerate/report')->assertStatus(302);

        $shas = $this->owner()->query("SELECT version, sha256 FROM invoice_documents WHERE invoice_id = :i: AND kind = 'report' ORDER BY version", ['i' => $id])->getResultArray();
        $html = $this->detail($id);

        $this->assertStringContainsString(lang('Invoices.versionsTitle'), $html);
        $this->assertStringContainsString('/invoices/' . $id . '/report/v/2"', $html);
        $this->assertStringContainsString('/invoices/' . $id . '/report/v/1"', $html);
        $this->assertStringContainsString('/invoices/' . $id . '/pdf/v/1"', $html);

        foreach ($shas as $row) {
            $this->assertStringContainsString(substr(trim((string) $row['sha256']), 0, 12), $html);
        }

        $this->assertStringContainsString('<code>rep-2</code>', $html);
        $this->assertSame(2, substr_count($html, lang('Invoices.versionsLatest')));
        $this->assertStringContainsString('name="expected_version" value="2"', $html);
        $this->assertStringNotContainsString('<table', substr($html, (int) strpos($html, 'inv-versions-title')));
    }

    public function testSameTemplateNoticeOnlyWhenCurrent(): void
    {
        $s    = $this->issueSetup();
        $leg  = $this->insertLegacyRep1Invoice($s['client'], $s['entity'], '2026-10', $s['parts']);
        $html = $this->detail($leg);
        $this->assertStringContainsString('/regenerate/report"', $html);
        $this->assertStringNotContainsString(lang('Invoices.regenSameTemplate'), $html);

        $id = $this->issued();
        $this->assertStringContainsString(lang('Invoices.regenSameTemplate'), $this->detail($id));
    }

    private function numberOf(int $id): string
    {
        return (string) $this->owner()->query('SELECT number FROM invoices WHERE id = :i:', ['i' => $id])->getRow()->number;
    }
}
