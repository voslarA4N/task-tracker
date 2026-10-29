<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

use App\Libraries\Delivery\InvoiceMailer;
use App\Libraries\Delivery\SendRejected;
use App\Libraries\Mail\AppMailer;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\TestLogger;
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
final class InvoiceMailerIdempotencyTest extends PgTestCase
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

    public function testSecondSendWithSameKeyIsReplay(): void
    {
        $inv = $this->issuedInvoice();
        $cmd = $this->sendCommand($inv['id']);

        $first  = $this->mailer()->send($cmd);
        $second = $this->mailer()->send($cmd);

        $this->assertFalse($first->replay);
        $this->assertTrue($second->replay);
        $this->assertSame($first->sendId, $second->sendId);
        $this->assertSame($first->messageId, $second->messageId);
        $this->assertSame(1, $this->emailFactoryCalls);
        $this->assertSame(1, $this->sendCount());
    }

    public function testUniqueViolationOnInsertMapsToReplay(): void
    {
        $inv = $this->issuedInvoice();
        $cmd = $this->sendCommand($inv['id']);
        $db  = $this->owner();

        // sprava "predbehne" nas zapis: ina instancia zapise rovnaky request_key tesne pred nasim INSERT-om
        $mailer = new class ($this->rw(), $this->capturingMailer(), $db) extends InvoiceMailer {
            /**
             * @param BaseConnection<mixed, mixed> $rw
             * @param BaseConnection<mixed, mixed> $owner
             */
            public function __construct(BaseConnection $rw, AppMailer $mailer, private readonly BaseConnection $owner)
            {
                parent::__construct($rw, $mailer);
            }

            protected function insertSend(array $row): int
            {
                $this->owner->query(
                    "INSERT INTO invoice_sends (invoice_id, kind, sent_at, transport, counts_as_sent, request_key, message_id, created_at)
                     VALUES (:i:, 'invoice', now(), 'smtp', true, CAST(:k: AS uuid), '<winner@vostack.dev>', now())",
                    ['i' => $row['invoice_id'], 'k' => $row['request_key']],
                );

                return parent::insertSend($row);
            }
        };

        $r = $mailer->send($cmd);

        $this->assertTrue($r->replay);
        $this->assertSame('<winner@vostack.dev>', $r->messageId);
        $this->assertSame(1, $this->sendCount());
    }

    public function testDbFailureAfterSmtpIsLoggedWithoutBody(): void
    {
        $inv    = $this->issuedInvoice();
        $mailer = new class ($this->rw(), $this->capturingMailer()) extends InvoiceMailer {
            protected function insertSend(array $row): int
            {
                throw new DatabaseException('connection lost');
            }
        };

        try {
            $mailer->send($this->sendCommand($inv['id'], o: ['body' => 'TAJNETELO']));
            $this->fail('Expected SendRejected');
        } catch (SendRejected $e) {
            $this->assertSame('record_failed', $e->reason);
        }

        $this->assertTrue(TestLogger::didLog('critical', 'mail.sent_unrecorded invoice=' . $inv['id'] . ' message_id=<', false));
        $this->assertFalse(TestLogger::didLog('critical', 'TAJNETELO', false));
        $this->assertSame(0, $this->sendCount());
    }
}
