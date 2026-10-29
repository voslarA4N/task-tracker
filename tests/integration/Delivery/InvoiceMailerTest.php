<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

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
final class InvoiceMailerTest extends PgTestCase
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

    private function rejection(callable $fn): SendRejected
    {
        try {
            $fn();
        } catch (SendRejected $e) {
            return $e;
        }

        $this->fail('Expected SendRejected');
    }

    public function testDraftUsesClientAddressesAndTemplateInDocLanguage(): void
    {
        $inv = $this->issuedInvoice(['cc_emails' => ['ucto@all4net.example']]);
        $d   = $this->mailer()->draft($inv['id'], 'invoice');

        $this->assertSame(['fakturacia@all4net.example'], $d['to']);
        $this->assertSame(['ucto@all4net.example'], $d['cc']);
        $this->assertSame('Faktúra 202610001', $d['subject']);
        $this->assertStringContainsString("\u{00A0}€", $d['body']);
        $this->assertCount(2, $d['attachments']);
        $this->assertSame('Faktura_202610001.pdf', $d['attachments'][0]['name']);
        $this->assertSame('Vykaz_202610001.pdf', $d['attachments'][1]['name']);
        $this->assertSame('sk', $d['language']);
    }

    public function testSendAttachesExactStoredBytesAndRecordsEverything(): void
    {
        $inv    = $this->issuedInvoice();
        $result = $this->mailer()->send($this->sendCommand($inv['id']));

        $this->assertFalse($result->replay);
        $this->assertTrue($result->countsAsSent);
        $this->assertSame(1, $this->emailFactoryCalls);

        foreach (['invoice', 'report'] as $kind) {
            $doc = $this->owner()->query("SELECT content, sha256 FROM invoice_documents WHERE invoice_id = :i: AND kind = '{$kind}'", ['i' => $inv['id']])->getRow();
            $this->assertStringContainsString(chunk_split(base64_encode(pg_unescape_bytea($doc->content)), 76, "\r\n"), $this->lastEmail->capturedBody);
        }

        $row = $this->owner()->query('SELECT * FROM invoice_sends')->getRowArray();
        $this->assertSame('invoice', $row['kind']);
        $this->assertSame('smtp', $row['transport']);
        $this->assertStringContainsString($row['message_id'], $this->lastEmail->capturedHeaders);
        $this->assertSame($row['message_id'], $result->messageId);
        // jsonb drží kľúče vo vlastnom poradí (cc pred to), preto kľúče porovnávame jednotlivo
        $recipients = json_decode($row['recipients'], true);
        $this->assertSame(['cc', 'to'], array_keys($recipients));
        $this->assertSame(['fakturacia@all4net.example'], $recipients['to']);
        $this->assertSame([], $recipients['cc']);
        $this->assertSame(['firma@example.test'], json_decode($row['bcc'], true));
        $this->assertSame('noreply@vostack.dev', $row['from_email']);
        $this->assertSame('Jan Novak', $row['from_name']);
        $this->assertSame('firma@example.test', $row['reply_to']);
        $this->assertSame(hash('sha256', "Dobry den,\n\nv prilohe faktura."), trim($row['body_sha256']));

        $attachments = json_decode($row['attachments'], true);
        $this->assertCount(2, $attachments);

        foreach ($attachments as $a) {
            $sha = trim((string) $this->owner()->query('SELECT sha256 FROM invoice_documents WHERE invoice_id = :i: AND kind = :k:', ['i' => $inv['id'], 'k' => $a['kind']])->getRow()->sha256);
            $this->assertSame($sha, $a['sha256']);
        }

        $audit = $this->owner()->query("SELECT after_data FROM audit_log WHERE entity_type = 'invoice_send'")->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString('prilohe', $audit[0]['after_data']);

        $this->assertSame('sent', $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = :i:', ['i' => $inv['id']])->getRow()->status);
    }

    public function testSchema1InvoiceHasOnlyOneAttachment(): void
    {
        $entity = $this->createEntity('SK');
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-10');
        $id     = $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc]);

        $this->assertCount(1, $this->mailer()->draft($id, 'invoice')['attachments']);
        $this->mailer()->send($this->sendCommand($id));
        $this->assertCount(1, json_decode((string) $this->owner()->query('SELECT attachments FROM invoice_sends')->getRow()->attachments, true));
    }

    public function testCountsAsSentDependsOnClientAddress(): void
    {
        $inv = $this->issuedInvoice();
        $a   = $this->mailer()->send($this->sendCommand($inv['id'], ['ja@vostack.dev']));
        $this->assertFalse($a->countsAsSent);
        $this->assertSame('issued', $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = :i:', ['i' => $inv['id']])->getRow()->status);

        $b = $this->mailer()->send($this->sendCommand($inv['id'], ['FAKTURACIA@all4net.example'], [], ['kind' => 'resend']));
        $this->assertTrue($b->countsAsSent);
        $this->assertSame('sent', $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = :i:', ['i' => $inv['id']])->getRow()->status);
    }

    public function testReminderNeverCountsAsSentAndHasOnlyInvoiceAttachment(): void
    {
        $inv = $this->issuedInvoice();
        $r   = $this->mailer()->send($this->sendCommand($inv['id'], o: ['kind' => 'reminder']));

        $this->assertFalse($r->countsAsSent);
        $this->assertCount(1, json_decode((string) $this->owner()->query('SELECT attachments FROM invoice_sends')->getRow()->attachments, true));
    }

    public function testLogTransportSendsNothingButRecords(): void
    {
        $this->configureMail('log');
        $inv = $this->issuedInvoice();
        $r   = $this->mailer()->send($this->sendCommand($inv['id']));

        $this->assertSame(0, $this->emailFactoryCalls);
        $this->assertSame('log', $r->transport);
        $row = $this->owner()->query('SELECT * FROM invoice_sends')->getRowArray();
        $this->assertSame('log', $row['transport']);
        $this->assertNotEmpty($row['message_id']);
        $this->assertNull($row['smtp_response']);
    }

    public function testExternalAndMissingInvoicesAreRejected(): void
    {
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '2.00', '2026-09');
        $ext    = $this->insertExternalInvoice($client, '2026-09', [$alloc], 'EXT-1');

        $this->assertSame('external', $this->rejection(fn () => $this->mailer()->send($this->sendCommand($ext)))->reason);
        $this->assertSame('not_found', $this->rejection(fn () => $this->mailer()->send($this->sendCommand(999999)))->reason);
    }

    public function testValidation(): void
    {
        $inv = $this->issuedInvoice();
        $id  = $inv['id'];
        $m   = $this->mailer();

        $this->assertSame('no_recipients', $this->rejection(fn () => $m->send($this->sendCommand($id, [])))->reason);

        $many = array_map(static fn (int $i): string => "a{$i}@klient.sk", range(1, 6));
        $this->assertSame('too_many_recipients', $this->rejection(fn () => $m->send($this->sendCommand($id, $many, array_map(static fn (int $i): string => "c{$i}@klient.sk", range(1, 5)))))->reason);

        $e = $this->rejection(fn () => $m->send($this->sendCommand($id, ["a@b.sk\r\nBcc: x@y.sk"])));
        $this->assertSame('invalid_address', $e->reason);
        $this->assertSame('to', $e->field);

        $e = $this->rejection(fn () => $m->send($this->sendCommand($id, ['ok@klient.sk'], ['nie-je-email'])));
        $this->assertSame('invalid_address', $e->reason);
        $this->assertSame('cc', $e->field);

        $this->assertSame('empty_content', $this->rejection(fn () => $m->send($this->sendCommand($id, o: ['subject' => '  '])))->reason);
        $this->assertSame('empty_content', $this->rejection(fn () => $m->send($this->sendCommand($id, o: ['body' => ''])))->reason);
        $this->assertSame('too_long', $this->rejection(fn () => $m->send($this->sendCommand($id, o: ['subject' => str_repeat('a', 201)])))->reason);
        $this->assertSame('too_long', $this->rejection(fn () => $m->send($this->sendCommand($id, o: ['body' => str_repeat('a', 4001)])))->reason);
        // riadiace znaky v predmete (WR-04): zaznam musi zodpovedat tomu, co odislo
        $this->assertSame('invalid_subject', $this->rejection(fn () => $m->send($this->sendCommand($id, o: ['subject' => "A\r\nBcc: x@evil.example"])))->reason);
        $this->assertSame('invalid_subject', $this->rejection(fn () => $m->send($this->sendCommand($id, o: ['subject' => "Faktura\t1"])))->reason);
        $this->assertSame('invalid_subject', $this->rejection(fn () => $m->send($this->sendCommand($id, o: ['subject' => "Faktura\x7F"])))->reason);

        $this->assertSame(0, $this->sendCount());
        $this->assertSame(0, $this->emailFactoryCalls);
    }

    public function testSenderDomainOutsideAllowlistIsRejected(): void
    {
        config('Email')->fromEmail = 'noreply@evil.example';
        $inv                       = $this->issuedInvoice();

        $this->assertSame('sender_invalid', $this->rejection(fn () => $this->mailer()->send($this->sendCommand($inv['id'])))->reason);
        $this->assertSame(0, $this->emailFactoryCalls);
    }

    public function testHistoryListsNewestFirstWithVoidFlag(): void
    {
        $inv = $this->issuedInvoice();
        $m   = $this->mailer();
        $m->send($this->sendCommand($inv['id']));
        $m->send($this->sendCommand($inv['id'], o: ['kind' => 'resend']));

        $h = $m->history($inv['id']);
        $this->assertCount(2, $h);
        $this->assertFalse($h[0]['voided']);
        $this->assertSame(['fakturacia@all4net.example'], $h[0]['recipients']['to']);
    }
}
