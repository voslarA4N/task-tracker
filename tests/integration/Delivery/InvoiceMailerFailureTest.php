<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

use App\Libraries\Delivery\SendRejected;
use App\Libraries\Invoicing\DocumentIntegrityError;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\StoredDocument;
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
final class InvoiceMailerFailureTest extends PgTestCase
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

    private function snapshotOfState(int $id): string
    {
        $inv  = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $id])->getRowArray();
        $docs = $this->owner()->query('SELECT kind, sha256, md5(content) AS m FROM invoice_documents WHERE invoice_id = :i: ORDER BY kind', ['i' => $id])->getResultArray();

        return json_encode([$inv, $docs], JSON_THROW_ON_ERROR);
    }

    public function testSmtpFailureWritesNothingAndLeavesInvoiceUntouched(): void
    {
        $inv    = $this->issuedInvoice();
        $before = $this->snapshotOfState($inv['id']);
        $audits = (int) $this->owner()->query('SELECT count(*) AS c FROM audit_log')->getRow()->c;

        $this->nextEmailResult = false;

        try {
            $this->mailer()->send($this->sendCommand($inv['id']));
            $this->fail('Expected SendRejected');
        } catch (SendRejected $e) {
            $this->assertSame('smtp_failed', $e->reason);
        }

        $this->assertSame(0, $this->sendCount());
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'invoice_send'")->getRow()->c);
        $this->assertSame($audits, (int) $this->owner()->query('SELECT count(*) AS c FROM audit_log')->getRow()->c);
        $this->assertSame($before, $this->snapshotOfState($inv['id']));
        $this->assertSame('issued', $this->owner()->query('SELECT status FROM invoice_status_v WHERE id = :i:', ['i' => $inv['id']])->getRow()->status);
    }

    public function testBrokenAttachmentSendsNothing(): void
    {
        $inv   = $this->issuedInvoice();
        $store = new class ($this->rw()) extends DocumentStore {
            public function read(int $invoiceId, string $kind = 'invoice'): ?StoredDocument
            {
                throw new DocumentIntegrityError('boom');
            }
        };

        try {
            $this->mailer($store)->send($this->sendCommand($inv['id']));
            $this->fail('Expected SendRejected');
        } catch (SendRejected $e) {
            $this->assertSame('attachments_failed', $e->reason);
        }

        $this->assertSame(0, $this->emailFactoryCalls);
        $this->assertSame(0, $this->sendCount());
    }
}
