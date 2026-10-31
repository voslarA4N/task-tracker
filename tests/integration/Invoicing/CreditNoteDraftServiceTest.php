<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Invoicing\CreditNoteDraftService;
use App\Libraries\Invoicing\CreditNoteInput;
use App\Libraries\Invoicing\CreditNoteRejected;
use App\Libraries\Invoicing\CreditNoteSnapshotBuilder;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Uuid;
use App\Libraries\Receivables\PaymentService;
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
final class CreditNoteDraftServiceTest extends PgTestCase
{
    use CreditNoteFixtures;
    use FxFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));
        Services::injectMock('rateHttpClient', new FakeRateHttpClient());
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        $this->restoreFxSettings();
        Services::reset();
        parent::tearDown();
    }

    private function service(): CreditNoteDraftService
    {
        return new CreditNoteDraftService($this->rw());
    }

    private function issuedInvoice(): int
    {
        $s = $this->issueSetup(['parts' => [['40.00', '2026-10'], ['10.00', '2026-10']]]);

        return $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
    }

    public function testDraftWithoutTypeAndReason(): void
    {
        $orig = $this->issuedInvoice();

        $d = $this->service()->draft($orig, null, '', null);

        $this->assertSame('2026-10-31', $d['issue_date']);
        $this->assertNull($d['token']);
        $this->assertSame(2, $d['release_count']);
        $this->assertMatchesRegularExpression('/^DB-202610001$/', $d['number_preview']);
        $this->assertSame('0.00', $d['sum_check']);
        $this->assertSame('0.00', $d['paid_amount']);
        $this->assertFalse($d['has_fx']);
        $this->assertNull($d['backdate']);
        $this->assertCount(2, $d['lines']);

        foreach ($d['lines'] as $line) {
            $this->assertStringStartsWith('-', $line['amount']);
        }
    }

    public function testTokenMatchesIssuerFixtureToken(): void
    {
        $orig = $this->issuedInvoice();

        $d   = $this->service()->draft($orig, '2026-10-31', 'Chybná sadzba', CorrectionType::AmountError);
        $cmd = $this->creditNoteCommand($orig);

        $this->assertSame($cmd->previewToken, $d['token']);
    }

    public function testPaidAmountAfterPayment(): void
    {
        $orig = $this->issuedInvoice();
        (new PaymentService($this->rw()))->record($orig, '100.00', '2026-10-30', null, Uuid::v4(), null);

        $this->assertSame('100.00', $this->service()->draft($orig, null, '', null)['paid_amount']);
    }

    public function testBackdateWarningAfterExistingCreditNoteInSeries(): void
    {
        $s    = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);
        $a    = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]))->id;
        $b    = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]]))->id;
        $this->issueCreditNote($a, ['issueDate' => '2026-10-31']);

        $d = $this->service()->draft($b, '2026-10-30', '', null);

        $this->assertNotNull($d['backdate']);
        $this->assertSame('2026-10-31', $d['backdate']['issue_date']);
    }

    public function testSecondNumberPreviewAndNoWrites(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);
        $a = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]))->id;
        $b = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][1]]))->id;
        $this->issueCreditNote($a);

        $before = $this->owner()->query('SELECT series_id, period_key, last_value FROM invoice_counters ORDER BY series_id, period_key')->getResultArray();
        $d      = $this->service()->draft($b, null, '', null);
        $after  = $this->owner()->query('SELECT series_id, period_key, last_value FROM invoice_counters ORDER BY series_id, period_key')->getResultArray();

        $this->assertStringEndsWith('002', $d['number_preview']);
        $this->assertSame($before, $after);
    }

    public function testRejectsExternalAlreadyCreditedAndMissing(): void
    {
        $orig = $this->issuedInvoice();
        $cn   = $this->issueCreditNote($orig);

        try {
            $this->service()->draft($orig, null, '', null);
            $this->fail('expected already_credited');
        } catch (CreditNoteRejected $e) {
            $this->assertSame('already_credited', $e->reason);
            $this->assertSame($cn->id, $e->creditNoteId);
            $this->assertSame($cn->number, $e->creditNoteNumber);
        }

        try {
            $this->service()->draft($cn->id, null, '', null);
            $this->fail('expected not_creditable');
        } catch (CreditNoteRejected $e) {
            $this->assertSame('not_creditable', $e->reason);
        }

        try {
            $this->service()->draft(999999, null, '', null);
            $this->fail('expected not_found');
        } catch (CreditNoteRejected $e) {
            $this->assertSame('not_found', $e->reason);
        }
    }

    public function testForeignCurrencyInvoiceExposesFx(): void
    {
        $s = $this->fxPlEurSetup();
        $this->seedRate('NBP', 'EUR', '2026-10-31', '2026-10-27', '4.4090', 1, '209/A/NBP/2026');
        $orig = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts'], ['issueDate' => '2026-10-31']))->id;

        $d = $this->service()->draft($orig, null, '', null);

        $this->assertTrue($d['has_fx']);
        $this->assertIsArray($d['fx']);
        $this->assertSame('0.00', $d['sum_check']);
    }

    public function testReleaseLink(): void
    {
        $orig = $this->issuedInvoice();

        $this->assertSame('/invoicing?client=' . $this->clientOf($orig) . '&month=2026-10', $this->service()->releaseLink($orig));
        $this->assertNull($this->service()->releaseLink(999999));
    }

    private function clientOf(int $id): int
    {
        return (int) $this->owner()->query('SELECT client_id FROM invoices WHERE id = :i:', ['i' => $id])->getRow()->client_id;
    }

    public function testReplayFor(): void
    {
        $orig = $this->issuedInvoice();
        $key  = $this->uuid4();
        $cn   = $this->issueCreditNote($orig, ['requestKey' => $key]);

        $this->assertSame(['id' => $cn->id, 'number' => $cn->number], $this->service()->replayFor($orig, $key));
        $this->assertNull($this->service()->replayFor($orig, $this->uuid4()));
        $this->assertNull($this->service()->replayFor($orig, 'not-a-uuid'));
    }

    public function testPreviewTokenHelperMatchesBuilder(): void
    {
        $this->assertSame(64, strlen(CreditNoteSnapshotBuilder::previewToken(['a' => 1])));
    }

    /**
     * @return array<string, string>
     */
    private function valid(): array
    {
        return ['issue_date' => '2026-10-31', 'reason' => 'Chyba', 'correction_type' => 'amount_error'];
    }

    public function testInputValidatesOk(): void
    {
        $this->assertSame([], CreditNoteInput::validate($this->valid(), '2026-10-27', false));
    }

    public function testInputTypeErrors(): void
    {
        foreach (['', 'zzz'] as $type) {
            $errors = CreditNoteInput::validate(['correction_type' => $type] + $this->valid(), '2026-10-27', false);
            $this->assertSame(lang('CreditNotes.errorType'), $errors['correction_type']);
        }

        $errors = CreditNoteInput::validate(['correction_type' => 'fx_error'] + $this->valid(), '2026-10-27', false);
        $this->assertSame(lang('CreditNotes.errorType'), $errors['correction_type']);
        $this->assertSame([], CreditNoteInput::validate(['correction_type' => 'fx_error'] + $this->valid(), '2026-10-27', true));
    }

    public function testInputReasonErrors(): void
    {
        $this->assertSame(lang('CreditNotes.errorReasonRequired'), CreditNoteInput::validate(['reason' => '  '] + $this->valid(), '2026-10-27', false)['reason']);
        $this->assertSame(lang('CreditNotes.errorReasonTooLong'), CreditNoteInput::validate(['reason' => str_repeat('a', 501)] + $this->valid(), '2026-10-27', false)['reason']);
        $this->assertSame([], CreditNoteInput::validate(['reason' => str_repeat('a', 500)] + $this->valid(), '2026-10-27', false));
    }

    public function testInputDateErrors(): void
    {
        $this->assertSame(lang('CreditNotes.errorDate'), CreditNoteInput::validate(['issue_date' => '2026-13-01'] + $this->valid(), '2026-10-27', false)['issue_date']);
        $this->assertArrayHasKey('issue_date', CreditNoteInput::validate(['issue_date' => '2026-10-26'] + $this->valid(), '2026-10-27', false));
        $this->assertStringContainsString('Dátum dobropisu nemôže byť skorší', CreditNoteInput::validate(['issue_date' => '2026-10-26'] + $this->valid(), '2026-10-27', false)['issue_date']);
        $this->assertStringContainsString('najviac 7', CreditNoteInput::validate(['issue_date' => '2026-11-08'] + $this->valid(), '2026-10-27', false)['issue_date']);
        $this->assertSame([], CreditNoteInput::validate(['issue_date' => '2026-11-07'] + $this->valid(), '2026-10-27', false));
    }

    public function testInputToCommand(): void
    {
        $v   = $this->valid() + ['preview_token' => str_repeat('a', 64), 'request_key' => $this->uuid4(), 'confirm_backdate' => '1'];
        $cmd = CreditNoteInput::toCommand($v, 5, 3);

        $this->assertSame(5, $cmd->originalId);
        $this->assertSame(CorrectionType::AmountError, $cmd->correctionType);
        $this->assertTrue($cmd->confirmBackdate);
        $this->assertSame(3, $cmd->userId);
    }
}
