<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

use App\Libraries\Delivery\SendRejected;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\MoneyFormat;
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
final class ReminderMailerTest extends PgTestCase
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

    /**
     * Vystavena a odoslana faktura, hodiny posunute po splatnosti.
     *
     * @return array{id: int, due: string, total: string, mid: string}
     */
    private function sentOverdue(): array
    {
        $inv = $this->issuedInvoice();
        $this->mailer()->send($this->sendCommand($inv['id']));
        $row = $this->owner()->query('SELECT i.due_date, i.total, (SELECT message_id FROM invoice_sends WHERE invoice_id = i.id ORDER BY id LIMIT 1) AS mid FROM invoices i WHERE i.id = :i:', ['i' => $inv['id']])->getRowArray();
        Services::injectMock('clock', new FrozenClock('2026-12-01T10:00:00+01:00'));

        return ['id' => $inv['id'], 'due' => (string) $row['due_date'], 'total' => (string) $row['total'], 'mid' => (string) $row['mid']];
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

    private function payment(int $id, string $amount): void
    {
        $this->owner()->query('INSERT INTO payments (invoice_id, paid_on, amount, created_at) VALUES (:i:, :d:, :a:, now())', ['i' => $id, 'd' => '2026-11-02', 'a' => $amount]);
    }

    public function testDraftUsesReminderTemplateWithBalanceAndOnlyInvoiceAttachment(): void
    {
        $inv = $this->sentOverdue();
        $d   = $this->mailer()->draft($inv['id'], 'reminder');

        $this->assertStringStartsWith('Upomienka', $d['subject']);
        $this->assertStringContainsString('202610001', $d['subject']);
        $this->assertStringContainsString(MoneyFormat::format($inv['total'], 'EUR'), $d['body']);
        $this->assertCount(1, $d['attachments']);
        $this->assertSame('Faktura_202610001.pdf', $d['attachments'][0]['name']);
        $this->assertSame(['fakturacia@all4net.example'], $d['to']);
    }

    public function testSendIsThreadedAndRecordedAsReminder(): void
    {
        $inv = $this->sentOverdue();
        $this->mailer()->send($this->sendCommand($inv['id'], ['fakturacia@all4net.example'], [], ['kind' => 'reminder', 'subject' => 'Upomienka: faktura 202610001']));

        $doc = $this->owner()->query("SELECT content FROM invoice_documents WHERE invoice_id = :i: AND kind = 'invoice'", ['i' => $inv['id']])->getRow();
        $body = str_replace(["\r", "\n"], '', $this->lastEmail->capturedBody);
        $this->assertStringContainsString(str_replace("\r\n", '', chunk_split(base64_encode(pg_unescape_bytea($doc->content)), 76, "\r\n")), $body);
        $this->assertSame(1, substr_count($this->lastEmail->capturedBody, 'Content-Type: application/pdf'));
        $this->assertStringContainsString('In-Reply-To: ' . $inv['mid'], $this->lastEmail->capturedHeaders);
        $this->assertStringContainsString('References: ' . $inv['mid'], $this->lastEmail->capturedHeaders);

        $row = $this->owner()->query("SELECT kind, counts_as_sent, in_reply_to, sent_at FROM invoice_sends WHERE kind = 'reminder'")->getRowArray();
        $this->assertNotNull($row);
        $this->assertContains($row['counts_as_sent'], [false, 'f']);
        $this->assertSame($inv['mid'], $row['in_reply_to']);

        $st = $this->owner()->query('SELECT last_reminder_at, send_count FROM invoice_status_v WHERE id = :i:', ['i' => $inv['id']])->getRow();
        $this->assertNotNull($st->last_reminder_at);
        $this->assertSame(1, (int) $st->send_count);
    }

    public function testPartialPaymentShowsRemainingBalance(): void
    {
        $inv = $this->sentOverdue();
        $this->payment($inv['id'], '500.00');
        $d = $this->mailer()->draft($inv['id'], 'reminder');

        $this->assertStringContainsString(MoneyFormat::format(bcsub($inv['total'], '500.00', 2), 'EUR'), $d['body']);
    }

    public function testPaidInvoiceRejectedAndNothingSent(): void
    {
        $inv = $this->sentOverdue();
        $this->payment($inv['id'], $inv['total']);
        $before = $this->sendCount();

        $e = $this->rejection(fn () => $this->mailer()->send($this->sendCommand($inv['id'], ['fakturacia@all4net.example'], [], ['kind' => 'reminder'])));

        $this->assertSame('no_balance', $e->reason);
        $this->assertSame($before, $this->sendCount());
        $this->assertSame(0, $this->emailFactoryCalls - 1);
    }

    public function testNoThreadHeaderWithoutPriorEmailSend(): void
    {
        $inv = $this->issuedInvoice();
        $this->mailer()->send($this->sendCommand($inv['id'], ['fakturacia@all4net.example'], [], ['kind' => 'reminder']));

        $this->assertStringNotContainsString('In-Reply-To', $this->lastEmail->capturedHeaders);
        $this->assertNull($this->owner()->query("SELECT in_reply_to FROM invoice_sends WHERE kind = 'reminder'")->getRow()->in_reply_to);
    }

    public function testVoidedSendIgnoredForThread(): void
    {
        $inv = $this->sentOverdue();
        $this->owner()->query("INSERT INTO invoice_send_voids (send_id, reason, voided_at) SELECT id, 'x', now() FROM invoice_sends WHERE invoice_id = :i:", ['i' => $inv['id']]);
        $this->mailer()->send($this->sendCommand($inv['id'], ['fakturacia@all4net.example'], [], ['kind' => 'reminder']));

        $this->assertStringNotContainsString('In-Reply-To', $this->lastEmail->capturedHeaders);
    }
}
