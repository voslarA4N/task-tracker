<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

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
use Tests\Support\RegenerateFixtures;

/**
 * WR-02: odosiela sa presne verzia PDF, ktoru vlastnik potvrdil vo formulari.
 *
 * @internal
 */
#[Group('integration')]
final class InvoiceMailerConfirmedVersionTest extends PgTestCase
{
    use CreditNoteFixtures;
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;
    use RegenerateFixtures;

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
     * @param array<string, int> $confirmed
     */
    private function assertChanged(int $id, array $confirmed): void
    {
        try {
            $this->mailer()->send($this->sendCommand($id, o: ['confirmed' => $confirmed]));
            $this->fail('Expected attachments_changed');
        } catch (SendRejected $e) {
            $this->assertSame('attachments_changed', $e->reason);
        }

        $this->assertSame(0, $this->emailFactoryCalls);
        $this->assertSame(0, $this->sendCount());
    }

    public function testDraftExposesAttachmentVersions(): void
    {
        $inv = $this->issuedInvoice();
        $d   = $this->mailer()->draft($inv['id'], 'invoice');

        $this->assertSame(['invoice' => 1, 'report' => 1], array_column($d['attachments'], 'version', 'kind'));
    }

    public function testRegenerationAfterConfirmationRejectsSend(): void
    {
        $inv = $this->issuedInvoice();
        $this->mailer()->draft($inv['id'], 'invoice');

        $this->regenerator()->regenerate($inv['id'], 'report', 1);

        $this->assertChanged($inv['id'], ['invoice' => 1, 'report' => 1]);
    }

    public function testMissingConfirmationRejectsSend(): void
    {
        $inv = $this->issuedInvoice();

        $this->assertChanged($inv['id'], []);
        $this->assertChanged($inv['id'], ['invoice' => 1]);
    }

    public function testSendsConfirmedVersionWhenStillLatest(): void
    {
        $inv = $this->issuedInvoice();
        $this->regenerator()->regenerate($inv['id'], 'report', 1);

        $this->mailer()->send($this->sendCommand($inv['id'], o: ['confirmed' => ['invoice' => 1, 'report' => 2]]));

        $this->assertSame(1, $this->sendCount());
        $sent = json_decode((string) $this->owner()->query('SELECT attachments FROM invoice_sends')->getRow()->attachments, true);
        $sha  = trim((string) $this->owner()->query("SELECT sha256 FROM invoice_documents WHERE invoice_id = :i: AND kind = 'report' AND version = 2", ['i' => $inv['id']])->getRow()->sha256);
        $this->assertSame($sha, $sent[1]['sha256']);
    }

    public function testOlderConfirmedVersionIsNeverSent(): void
    {
        $inv = $this->issuedInvoice();
        $this->regenerator()->regenerate($inv['id'], 'report', 1);

        $this->assertChanged($inv['id'], ['invoice' => 1, 'report' => 1]);
    }
}
