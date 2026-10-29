<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

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
final class ResendTest extends PgTestCase
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

    private function invoiceStatus(int $id): string
    {
        return (string) $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = :i:', ['i' => $id])->getRow()->status;
    }

    public function testResendSendsSameBytesAndSha256AsFirstSend(): void
    {
        $inv = $this->issuedInvoice();
        $this->mailer()->send($this->sendCommand($inv['id']));
        $this->mailer()->send($this->sendCommand($inv['id'], ['ja@gmail.com'], [], ['kind' => 'resend']));

        foreach (['invoice', 'report'] as $kind) {
            $doc = $this->owner()->query("SELECT content FROM invoice_documents WHERE invoice_id = :i: AND kind = '{$kind}'", ['i' => $inv['id']])->getRow();
            $this->assertStringContainsString(chunk_split(base64_encode(pg_unescape_bytea($doc->content)), 76, "\r\n"), $this->lastEmail->capturedBody);
        }

        $rows = $this->owner()->query('SELECT kind, attachments, recipients, counts_as_sent FROM invoice_sends ORDER BY id')->getResultArray();
        $this->assertCount(2, $rows);

        $first  = json_decode($rows[0]['attachments'], true);
        $second = json_decode($rows[1]['attachments'], true);
        $this->assertCount(2, $second);

        foreach ($second as $i => $a) {
            $stored = trim((string) $this->owner()->query('SELECT sha256 FROM invoice_documents WHERE invoice_id = :i: AND kind = :k:', ['i' => $inv['id'], 'k' => $a['kind']])->getRow()->sha256);
            $this->assertSame($stored, $a['sha256']);
            $this->assertSame($first[$i]['sha256'], $a['sha256']);
        }

        $this->assertSame('resend', $rows[1]['kind']);
        $this->assertSame(['ja@gmail.com'], json_decode($rows[1]['recipients'], true)['to']);
        $this->assertContains($rows[1]['counts_as_sent'], [false, 'f']);
        $this->assertSame('sent', $this->invoiceStatus($inv['id']));
        $this->assertSame(2, (int) $this->owner()->query('SELECT send_count FROM invoice_status_v WHERE id = :i:', ['i' => $inv['id']])->getRow()->send_count);
    }

    public function testResendOnlyToOtherAddressKeepsIssued(): void
    {
        $inv = $this->issuedInvoice();
        $this->mailer()->send($this->sendCommand($inv['id'], ['ja@gmail.com'], [], ['kind' => 'resend']));
        $this->assertSame('issued', $this->invoiceStatus($inv['id']));

        $this->mailer()->send($this->sendCommand($inv['id'], ['fakturacia@all4net.example'], [], ['kind' => 'resend']));
        $this->assertSame('sent', $this->invoiceStatus($inv['id']));
    }

    public function testResendDraftDefaults(): void
    {
        $inv = $this->issuedInvoice(['cc_emails' => ['ucto@all4net.example']]);
        $d   = $this->mailer()->draft($inv['id'], 'resend');

        $this->assertSame(['fakturacia@all4net.example'], $d['to']);
        $this->assertSame([], $d['cc']);
        $this->assertSame('Faktúra 202610001', $d['subject']);
        $this->assertCount(2, $d['attachments']);
        $this->assertContains('ucto@all4net.example', $d['client_emails']);
    }

    public function testResendWorksForEndedEntity(): void
    {
        $inv = $this->issuedInvoice();
        $this->owner()->query("UPDATE entities SET active_to = '2026-01-01' WHERE id = :i:", ['i' => $inv['entity']]);

        $this->mailer()->send($this->sendCommand($inv['id'], ['ja@gmail.com'], [], ['kind' => 'resend']));
        $this->assertSame(1, $this->sendCount());
    }
}
