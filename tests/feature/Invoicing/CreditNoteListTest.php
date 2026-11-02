<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
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
final class CreditNoteListTest extends AuthTestCase
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

    private function advanceClock(string $iso): void
    {
        Services::injectMock('clock', new FrozenClock($iso));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function pair(): array
    {
        $s    = $this->issueSetup();
        $orig = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
        $cn   = $this->issueCreditNote($orig);
        $this->advanceClock('2026-12-31T10:00:00+01:00');

        return [$orig, $cn->number, '202610001'];
    }

    public function testRowsForCreditNoteAndCancelledInvoice(): void
    {
        [, $cnNumber, $origNumber] = $this->pair();
        $html                      = $this->body($this->get('/invoices'));

        $this->assertStringContainsString('Dobropis', $html);
        $this->assertStringContainsString($cnNumber, $html);
        $this->assertStringContainsString('opravuje ' . $origNumber, $html);
        $this->assertStringContainsString('Stornovaná', $html);
        $this->assertStringContainsString('dobropis ' . $cnNumber, $html);
        $this->assertStringNotContainsString('Po splatnosti ·', $html);
        $this->assertMatchesRegularExpression('/inv-row-amount[^>]*>-1/u', $html);
        $this->assertDoesNotMatchRegularExpression('/inv-row-amount[^>]*text-danger/', $html);
    }

    public function testFilterOptionsAndSelected(): void
    {
        $html = $this->body($this->get('/invoices?status=cancelled'));

        $this->assertStringContainsString('option value="cancelled" selected', $html);
        $this->assertStringContainsString('option value="credit_note"', $html);
        $this->assertStringContainsString('>Dobropis</option>', $html);
    }

    public function testOverdueFilterExcludesCancelledAndCreditNote(): void
    {
        [, $cnNumber] = $this->pair();
        $html         = $this->body($this->get('/invoices?status=overdue'));

        $this->assertStringNotContainsString($cnNumber, $html);
        $this->assertStringNotContainsString('202610001', $html);
    }
}
