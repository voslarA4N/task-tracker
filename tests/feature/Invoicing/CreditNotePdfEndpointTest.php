<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class CreditNotePdfEndpointTest extends AuthTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));
        Services::injectMock('creditNoteIssuer', $this->creditNoteIssuer());
        $this->resetMasterTables();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function docs(): array
    {
        $s     = $this->issueSetup(['parts' => [['40.00', '2026-10'], ['10.00', '2026-10']]]);
        $orig  = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]))->id;
        $plain = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]]))->id;

        return [$orig, $plain, 0];
    }

    private function assertNotFound(callable $request): void
    {
        try {
            $this->assertSame(404, $request()->response()->getStatusCode());
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testCreditNotePdfServedWithOwnFilename(): void
    {
        [$orig] = $this->docs();
        $cn     = $this->issueCreditNote($orig);
        $result = $this->get('/invoices/' . $cn->id . '/pdf');
        $r      = $result->response();

        $result->assertStatus(200);
        $this->assertSame('application/pdf', $r->getHeaderLine('Content-Type'));
        $this->assertSame('inline; filename="Dobropis_' . $cn->number . '.pdf"', $r->getHeaderLine('Content-Disposition'));
        $this->assertMatchesRegularExpression('/^inline; filename="Dobropis_DB-\d+\.pdf"$/', $r->getHeaderLine('Content-Disposition'));
        $this->assertSame('"' . hash('sha256', (string) $r->getBody()) . '"', $r->getHeaderLine('ETag'));
    }

    public function testCreditNoteReportIs404(): void
    {
        [$orig] = $this->docs();
        $cn     = $this->issueCreditNote($orig);

        $this->assertNotFound(fn () => $this->get('/invoices/' . $cn->id . '/report'));
    }

    public function testOriginalPdfUnchangedAfterCancel(): void
    {
        [$orig] = $this->docs();
        $before = $this->get('/invoices/' . $orig . '/pdf');
        $bytes  = (string) $before->response()->getBody();
        $etag   = $before->response()->getHeaderLine('ETag');

        $this->issueCreditNote($orig);

        $after = $this->get('/invoices/' . $orig . '/pdf');
        $this->assertSame($bytes, (string) $after->response()->getBody());
        $this->assertSame($etag, $after->response()->getHeaderLine('ETag'));
        $this->get('/invoices/' . $orig . '/report')->assertStatus(200);
    }

    public function testListStatusFilters(): void
    {
        [$orig, $plain] = $this->docs();
        $cn             = $this->issueCreditNote($orig);
        $numbers        = $this->owner()->query('SELECT id, number FROM invoices WHERE id IN (:a:, :b:)', ['a' => $orig, 'b' => $plain])->getResultArray();
        $num            = array_column($numbers, 'number', 'id');

        $cancelled = (string) $this->get('/invoices?status=cancelled')->response()->getBody();
        $this->assertStringContainsString((string) $num[$orig], $cancelled);
        $this->assertStringNotContainsString((string) $num[$plain], $cancelled);

        $notes = (string) $this->get('/invoices?status=credit_note')->response()->getBody();
        $this->assertStringContainsString($cn->number, $notes);
        $this->assertStringNotContainsString((string) $num[$plain], $notes);

        $unknown = (string) $this->get('/invoices?status=bogus')->response()->getBody();
        $this->assertStringContainsString((string) $num[$plain], $unknown);
        $this->assertStringContainsString($cn->number, $unknown);
    }
}
