<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

use App\Libraries\Delivery\ManualSendService;
use App\Libraries\Delivery\SendRejected;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class CreditNoteMailerTest extends PgTestCase
{
    use CreditNoteFixtures;
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));
        Services::injectMock('creditNoteIssuer', $this->creditNoteIssuer());
        $this->resetMasterTables();
        $this->configureMail();
    }

    protected function tearDown(): void
    {
        $this->resetMail();
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @return array{original: int, cn: int, number: string}
     */
    private function creditNote(): array
    {
        $inv = $this->issuedInvoice();
        $cn  = $this->issueCreditNote($inv['id']);

        return ['original' => $inv['id'], 'cn' => $cn->id, 'number' => $cn->number];
    }

    private function reason(callable $fn): string
    {
        try {
            $fn();
        } catch (SendRejected $e) {
            return $e->reason;
        }

        $this->fail('Expected SendRejected');
    }

    private function docStatus(int $id): string
    {
        return (string) $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = :i:', ['i' => $id])->getRow()->status;
    }

    public function testDraftUsesCreditNoteTemplateAndOnlyCreditNotePdf(): void
    {
        $c = $this->creditNote();
        $d = $this->mailer()->draft($c['cn'], 'invoice');

        $this->assertCount(1, $d['attachments']);
        $this->assertSame('invoice', $d['attachments'][0]['kind']);
        $this->assertSame('Dobropis_' . $c['number'] . '.pdf', $d['attachments'][0]['name']);
        $this->assertStringContainsString($c['number'], $d['subject']);
        $this->assertNotSame([], $d['to']);
    }

    public function testSendRecordsSendOnCreditNoteAndMarksSent(): void
    {
        $c = $this->creditNote();
        $this->mailer()->send($this->sendCommand($c['cn']));

        $row = $this->owner()->query('SELECT kind FROM invoice_sends WHERE invoice_id = :i:', ['i' => $c['cn']])->getRowArray();
        $this->assertSame('invoice', $row['kind']);
        $this->assertSame('sent', $this->docStatus($c['cn']));
    }

    public function testResendAfterSendPasses(): void
    {
        $c = $this->creditNote();
        $this->mailer()->send($this->sendCommand($c['cn']));
        $this->mailer()->send($this->sendCommand($c['cn'], ['fakturacia@all4net.example'], [], ['kind' => 'resend']));

        $this->assertSame(2, (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_sends WHERE invoice_id = :i:', ['i' => $c['cn']])->getRow()->c);
    }

    public function testReminderRejectedForCreditNoteAndCancelledInvoice(): void
    {
        $c = $this->creditNote();

        foreach ([$c['cn'], $c['original']] as $id) {
            $before = $this->sendCount();
            $reason = $this->reason(fn () => $this->mailer()->send($this->sendCommand($id, ['fakturacia@all4net.example'], [], ['kind' => 'reminder'])));
            $this->assertContains($reason, ['not_sendable', 'no_balance']);
            $this->assertSame($before, $this->sendCount());
        }
    }

    public function testCancelledInvoiceCanBeResentWithInvoiceAndReport(): void
    {
        $c = $this->creditNote();
        $this->assertSame('cancelled', $this->docStatus($c['original']));

        $d = $this->mailer()->draft($c['original'], 'resend');
        $this->assertSame(['invoice', 'report'], array_column($d['attachments'], 'kind'));
    }

    public function testManualMarkSentPassesForCreditNote(): void
    {
        $c  = $this->creditNote();
        $id = (new ManualSendService($this->rw()))->markSent($c['cn'], '2026-10-31', null, $this->uuid4(), null);

        $this->assertGreaterThan(0, $id);
        $this->assertSame('sent', $this->docStatus($c['cn']));
    }
}
