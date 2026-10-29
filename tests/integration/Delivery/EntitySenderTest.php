<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Delivery\SenderIdentity;
use App\Libraries\Master\EntityService;
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
final class EntitySenderTest extends PgTestCase
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

    private function service(): EntityService
    {
        return new EntityService($this->rw(), new AuditLogger($this->rw()));
    }

    /**
     * @param array<string, mixed> $sender
     */
    private function save(int $id, array $sender): bool
    {
        return $this->service()->updateBase($id, array_merge(
            ['name_internal' => 'Moja firma SK', 'active_from' => '2026-01-01', 'active_to' => null],
            $sender,
        ));
    }

    public function testRwCanUpdateSenderColumnsButNotCountry(): void
    {
        $this->createEntity('SK');

        $this->rw()->query("UPDATE entities SET mail_from_email = 'a@vostack.dev', mail_reply_to = 'b@x.sk', mail_bcc = '[]'::jsonb");
        $this->assertSame('a@vostack.dev', $this->owner()->query('SELECT mail_from_email FROM entities')->getRow()->mail_from_email);
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE entities SET country = 'PL'"));
    }

    public function testDefaultsWhenColumnsAreNull(): void
    {
        $id = $this->createEntity('SK');
        $r  = (new SenderIdentity($this->rw()))->resolve($id);

        $this->assertSame('noreply@vostack.dev', $r['from_email']);
        $this->assertSame('Jan Novak', $r['from_name']);
        $this->assertSame('firma@example.test', $r['reply_to']);
        $this->assertSame(['firma@example.test'], $r['bcc']);
    }

    public function testConfiguredValuesAndEmptyBcc(): void
    {
        $id = $this->createEntity('SK');
        $this->save($id, [
            'mail_from_name' => 'All4Net faktúry', 'mail_from_email' => 'faktury@vostack.dev',
            'mail_reply_to'  => 'ucto@all4net.sk', 'mail_bcc' => '',
        ]);

        $r = (new SenderIdentity($this->rw()))->resolve($id);
        $this->assertSame('faktury@vostack.dev', $r['from_email']);
        $this->assertSame('All4Net faktúry', $r['from_name']);
        $this->assertSame('ucto@all4net.sk', $r['reply_to']);
        $this->assertSame([], $r['bcc']);
    }

    public function testUpdateWritesAuditOnceAndNoAuditWithoutChange(): void
    {
        $id = $this->createEntity('SK');
        $n  = fn (): int => (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'entity' AND action = 'update'")->getRow()->c;

        $this->assertTrue($this->save($id, ['mail_from_email' => 'faktury@vostack.dev', 'mail_bcc' => "a@x.sk\nb@x.sk"]));
        $this->assertSame(1, $n());

        $row = $this->owner()->query("SELECT before_data, after_data FROM audit_log WHERE entity_type = 'entity' AND action = 'update'")->getRowArray();
        $this->assertStringContainsString('mail_from_email', json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('faktury@vostack.dev', (string) $row['after_data']);

        $this->assertFalse($this->save($id, ['mail_from_email' => 'faktury@vostack.dev', 'mail_bcc' => "a@x.sk\nb@x.sk"]));
        $this->assertSame(1, $n());
    }

    public function testMailerUsesNewSenderAndSnapshotIsUnchanged(): void
    {
        $inv    = $this->issuedInvoice();
        $before = $this->owner()->query('SELECT snapshot::text AS s FROM invoices WHERE id = :i:', ['i' => $inv['id']])->getRow()->s;

        $this->save($inv['entity'], ['mail_from_email' => 'faktury@vostack.dev', 'mail_bcc' => '']);
        $this->mailer()->send($this->sendCommand($inv['id']));

        $this->assertStringContainsString('faktury@vostack.dev', (string) $this->lastEmail?->capturedHeaders);
        $row = $this->owner()->query('SELECT from_email, bcc FROM invoice_sends WHERE invoice_id = :i:', ['i' => $inv['id']])->getRowArray();
        $this->assertSame('faktury@vostack.dev', $row['from_email']);
        $this->assertSame([], json_decode((string) $row['bcc'], true));
        $this->assertSame($before, $this->owner()->query('SELECT snapshot::text AS s FROM invoices WHERE id = :i:', ['i' => $inv['id']])->getRow()->s);
    }

    public function testStoredAddressOutsideAllowedDomainsIsRejectedAtSend(): void
    {
        $inv = $this->issuedInvoice();
        $this->save($inv['entity'], ['mail_from_email' => 'faktury@vostack.dev']);

        $original                           = config('Email')->allowedFromDomains;
        config('Email')->allowedFromDomains = 'example.org';

        try {
            $this->mailer()->send($this->sendCommand($inv['id']));
            $this->fail('Expected SendRejected');
        } catch (\App\Libraries\Delivery\SendRejected $e) {
            $this->assertSame('sender_invalid', $e->reason);
        } finally {
            config('Email')->allowedFromDomains = $original;
        }

        $this->assertSame(0, $this->emailFactoryCalls);
    }
}
