<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

use App\Libraries\Delivery\ManualSendService;
use App\Libraries\Delivery\SendRejected;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DeliveryFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class ManualSendServiceTest extends PgTestCase
{
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
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

    private function service(): ManualSendService
    {
        return new ManualSendService($this->rw());
    }

    private function invoiceStatus(int $id): string
    {
        return (string) $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = :i:', ['i' => $id])->getRow()->status;
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

    public function testMarkSentInsertsManualRecordAndChangesStatus(): void
    {
        $inv = $this->issuedInvoice();
        $id  = $this->service()->markSent($inv['id'], '2026-10-27', ' Odovzdané osobne ', $this->uuid4(), null);

        $row = $this->owner()->query('SELECT kind, transport, counts_as_sent, recipients, note, sent_at FROM invoice_sends WHERE id = :i:', ['i' => $id])->getRowArray();
        $this->assertSame('manual', $row['kind']);
        $this->assertSame('none', $row['transport']);
        $this->assertContains($row['counts_as_sent'], [true, 't']);
        $this->assertSame(['to' => [], 'cc' => []], json_decode((string) $row['recipients'], true));
        $this->assertSame('Odovzdané osobne', $row['note']);
        $this->assertStringStartsWith('2026-10-27 10:00:00', (string) $row['sent_at']);
        $this->assertSame('sent', $this->invoiceStatus($inv['id']));

        $audit = $this->owner()->query("SELECT after_data FROM audit_log WHERE entity_type = 'invoice_send' AND action = 'create'")->getRowArray();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('manual', (string) $audit['after_data']);
    }

    public function testPastDateUsesNoonBratislava(): void
    {
        $inv = $this->issuedInvoice();
        $id  = $this->service()->markSent($inv['id'], '2026-10-20', null, $this->uuid4(), null);

        $sentAt = (string) $this->owner()->query("SELECT to_char(sent_at AT TIME ZONE 'Europe/Bratislava', 'YYYY-MM-DD HH24:MI:SS') AS s FROM invoice_sends WHERE id = :i:", ['i' => $id])->getRow()->s;
        $this->assertSame('2026-10-20 12:00:00', $sentAt);
    }

    public function testDateAndNoteValidation(): void
    {
        $inv = $this->issuedInvoice();
        $s   = $this->service();

        $this->assertSame('future_date', $this->reason(fn () => $s->markSent($inv['id'], '2026-10-28', null, $this->uuid4(), null)));
        $this->assertSame('invalid_date', $this->reason(fn () => $s->markSent($inv['id'], '2026-13-01', null, $this->uuid4(), null)));
        $this->assertSame('note_too_long', $this->reason(fn () => $s->markSent($inv['id'], '2026-10-27', str_repeat('a', 201), $this->uuid4(), null)));
        $this->assertSame(0, $this->sendCount());
    }

    public function testExternalMissingAndAlreadySent(): void
    {
        $inv    = $this->issuedInvoice();
        $client = $inv['client'];
        $alloc  = $this->createAllocation($this->createTask($client), '2.00', '2026-09');
        $ext    = $this->insertExternalInvoice($client, '2026-09', [$alloc], 'EXT-1');
        $s      = $this->service();

        $this->assertSame('external', $this->reason(fn () => $s->markSent($ext, '2026-10-27', null, $this->uuid4(), null)));
        $this->assertSame('not_found', $this->reason(fn () => $s->markSent(999999, '2026-10-27', null, $this->uuid4(), null)));

        $s->markSent($inv['id'], '2026-10-27', null, $this->uuid4(), null);
        $this->assertSame('already_sent', $this->reason(fn () => $s->markSent($inv['id'], '2026-10-27', null, $this->uuid4(), null)));
    }

    public function testAlreadySentByEmailIsRejected(): void
    {
        $inv = $this->issuedInvoice();
        $this->mailer()->send($this->sendCommand($inv['id']));

        $this->assertSame('sent', $this->invoiceStatus($inv['id']));
        $this->assertSame('already_sent', $this->reason(fn () => $this->service()->markSent($inv['id'], '2026-10-27', null, $this->uuid4(), null)));
    }

    public function testSameRequestKeyWritesOnce(): void
    {
        $inv = $this->issuedInvoice();
        $key = $this->uuid4();
        $a   = $this->service()->markSent($inv['id'], '2026-10-27', null, $key, null);
        $b   = $this->service()->markSent($inv['id'], '2026-10-27', null, $key, null);

        $this->assertSame($a, $b);
        $this->assertSame(1, $this->sendCount());
    }

    public function testVoidRestoresStatusAndIsAudited(): void
    {
        $inv = $this->issuedInvoice();
        $id  = $this->service()->markSent($inv['id'], '2026-10-27', null, $this->uuid4(), null);

        $this->assertSame($inv['id'], $this->service()->voidManual($id, ' Omyl ', null));
        $this->assertSame('issued', $this->invoiceStatus($inv['id']));
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS n FROM invoice_send_voids')->getRow()->n);
        $this->assertSame(1, $this->sendCount());

        $audit = $this->owner()->query("SELECT after_data FROM audit_log WHERE entity_type = 'invoice_send_void' AND action = 'create'")->getRowArray();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('Omyl', (string) $audit['after_data']);

        $this->assertSame('already_voided', $this->reason(fn () => $this->service()->voidManual($id, 'Znova', null)));
        $this->assertSame('issued', $this->invoiceStatus($inv['id']));
    }

    public function testVoidRejectsNonManualAndEmptyReason(): void
    {
        $inv = $this->issuedInvoice();
        $this->mailer()->send($this->sendCommand($inv['id']));
        $sendId = (int) $this->owner()->query('SELECT id FROM invoice_sends ORDER BY id DESC LIMIT 1')->getRow()->id;

        $this->assertSame('not_manual', $this->reason(fn () => $this->service()->voidManual($sendId, 'Omyl', null)));
        $this->assertSame('empty_reason', $this->reason(fn () => $this->service()->voidManual($sendId, '   ', null)));
        $this->assertSame('not_found', $this->reason(fn () => $this->service()->voidManual(999999, 'Omyl', null)));
        $this->assertSame('sent', $this->invoiceStatus($inv['id']));
    }
}
