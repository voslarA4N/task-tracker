<?php

declare(strict_types=1);

namespace Tests\Integration\Receivables;

use App\Libraries\Delivery\TemplateContext;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Uuid;
use App\Libraries\Receivables\InvoiceStatusQueries;
use App\Libraries\Receivables\PaymentRejected;
use App\Libraries\Receivables\PaymentService;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class PaymentServiceTest extends PgTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

    private int $invoice;
    private int $client;
    private int $entity;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-11-05T10:00:00+01:00'));
        $this->resetMasterTables();

        $this->entity  = $this->createEntity('SK');
        $this->client  = $this->createClient();
        $alloc         = $this->createAllocation($this->createTask($this->client), '8.00', '2026-10', ['rate' => '135.00', 'amount' => '1080.00']);
        $this->invoice = $this->insertIssuedInvoiceRaw($this->client, $this->entity, '2026-10', [$alloc]);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function service(): PaymentService
    {
        return new PaymentService($this->rw());
    }

    private function expectReject(string $reason, callable $fn): PaymentRejected
    {
        try {
            $fn();
        } catch (PaymentRejected $e) {
            $this->assertSame($reason, $e->reason);

            return $e;
        }

        $this->fail('Expected PaymentRejected ' . $reason);
    }

    private function paymentCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM payments')->getRow()->c;
    }

    public function testParseAmount(): void
    {
        $this->assertSame('1080.00', PaymentService::parseAmount('1 080,00'));
        $this->assertSame('1080.50', PaymentService::parseAmount("1\u{00A0}080.5"));
        $this->assertSame('0.50', PaymentService::parseAmount('0,5'));
        $this->assertNull(PaymentService::parseAmount('12,345'));
        $this->assertNull(PaymentService::parseAmount('abc'));
        $this->assertNull(PaymentService::parseAmount(''));
        $this->assertSame('-5.00', PaymentService::parseAmount('-5'));
    }

    public function testDefaultAmountIsWholeBalance(): void
    {
        $r = $this->service()->record($this->invoice, null, '2026-11-05', null, Uuid::v4(), null);

        $this->assertSame('1080.00', $r->amount);
        $this->assertSame('0.00', $r->balanceAfter);
        $this->assertSame('paid', $r->statusAfter);
        $this->assertFalse($r->replay);
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'payment' AND action = 'create'")->getRow()->c);
    }

    public function testPartialPaymentsThenRest(): void
    {
        $first = $this->service()->record($this->invoice, '500,50', '2026-11-04', 'prvá', Uuid::v4(), null);
        $this->assertSame('500.50', $first->amount);
        $this->assertSame('579.50', $first->balanceAfter);
        $this->assertSame('partially_paid', $first->statusAfter);

        $second = $this->service()->record($this->invoice, null, '2026-11-05', null, Uuid::v4(), null);
        $this->assertSame('579.50', $second->amount);
        $this->assertSame('paid', $second->statusAfter);
        $this->assertSame(2, $this->paymentCount());
    }

    public function testRejections(): void
    {
        $s = $this->service();

        $this->expectReject('non_positive', fn () => $s->record($this->invoice, '0', '2026-11-05', null, Uuid::v4(), null));
        $this->expectReject('non_positive', fn () => $s->record($this->invoice, '-5', '2026-11-05', null, Uuid::v4(), null));
        $this->expectReject('invalid_amount', fn () => $s->record($this->invoice, '12,345', '2026-11-05', null, Uuid::v4(), null));
        $over = $this->expectReject('overpaid', fn () => $s->record($this->invoice, '1080.01', '2026-11-05', null, Uuid::v4(), null));
        $this->assertSame('1080.00', $over->balance);
        $this->expectReject('not_found', static fn () => $s->record(999999, null, '2026-11-05', null, Uuid::v4(), null));
        $this->expectReject('note_too_long', fn () => $s->record($this->invoice, '1', '2026-11-05', str_repeat('a', 201), Uuid::v4(), null));
        // dolna hranica = datum vystavenia 2026-10-27 (WR-06)
        $this->expectReject('before_issue', fn () => $s->record($this->invoice, '1', '2026-10-26', null, Uuid::v4(), null));
        $this->expectReject('before_issue', fn () => $s->record($this->invoice, '1', '2025-10-27', null, Uuid::v4(), null));
        $this->assertSame(0, $this->paymentCount());

        $this->assertFalse($s->record($this->invoice, '1', '2026-10-27', null, Uuid::v4(), null)->replay);
        $s->record($this->invoice, null, '2026-11-05', null, Uuid::v4(), null);
        $this->expectReject('already_paid', fn () => $s->record($this->invoice, '1', '2026-11-05', null, Uuid::v4(), null));
    }

    public function testExternalInvoiceIsNotPayable(): void
    {
        $alloc    = $this->createAllocation($this->createTask($this->client), '2.00', '2026-09');
        $external = $this->insertExternalInvoice($this->client, '2026-09', [$alloc], 'EXT-1');

        $this->expectReject('not_payable', fn () => $this->service()->record($external, null, '2026-11-05', null, Uuid::v4(), null));
    }

    public function testDateRulesUseBratislavaDay(): void
    {
        $s = $this->service();
        $this->expectReject('future_date', fn () => $s->record($this->invoice, '1', '2026-11-06', null, Uuid::v4(), null));
        $this->expectReject('invalid_date', fn () => $s->record($this->invoice, '1', '2026-02-30', null, Uuid::v4(), null));

        // 2026-11-05 00:30 Bratislava = 2026-11-04 23:30 UTC: dnes je uz 5. november
        Services::injectMock('clock', new FrozenClock('2026-11-05T00:30:00+01:00'));
        $r = $s->record($this->invoice, '1', '2026-11-05', null, Uuid::v4(), null);
        $this->assertFalse($r->replay);
        $this->expectReject('future_date', fn () => $s->record($this->invoice, '1', '2026-11-06', null, Uuid::v4(), null));
    }

    public function testDoubleClickWithSameRequestKeyWritesOnce(): void
    {
        $key = Uuid::v4();
        $a   = $this->service()->record($this->invoice, '100', '2026-11-05', null, $key, null);
        $b   = $this->service()->record($this->invoice, '100', '2026-11-05', null, $key, null);

        $this->assertFalse($a->replay);
        $this->assertTrue($b->replay);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, $this->paymentCount());
    }

    public function testAdvisoryLockIsTakenBeforeBalanceRead(): void
    {
        // owner() ma iné prihlasovacie údaje, teda skutočne samostatnú reláciu (rovnaké údaje by znovu použili ten istý pg_connect).
        $other = $this->owner();
        $rw    = $this->rw();
        $id    = $this->invoice;
        $try   = static fn (): bool => in_array((string) $other->query("SELECT pg_try_advisory_xact_lock(hashtext('payments'), {$id}) AS l")->getRow()->l, ['t', '1', 'true'], true);

        $rw->transStart();

        try {
            $this->service()->record($this->invoice, '100', '2026-11-05', null, Uuid::v4(), null);

            // Druhe spojenie nezoberie zamok, kym prva transakcia nie je dokoncena.
            $other->transStart();
            $held = $try();
            $other->transComplete();
            $this->assertFalse($held);
        } finally {
            $rw->transComplete();

            if ($other->transDepth > 0) {
                $other->transRollback();
            }
        }

        $other->transStart();
        $free = $try();
        $other->transComplete();
        $this->assertTrue($free);

        // Po commite prveho zapisu druhy vidi aktualny zostatok.
        $this->expectReject('overpaid', fn () => $this->service()->record($this->invoice, '1000', '2026-11-05', null, Uuid::v4(), null));
    }

    public function testImpactPreview(): void
    {
        $s = $this->service();
        $this->assertSame(['amount' => '1080.00', 'balance_after' => '0.00', 'status_after' => 'paid'], $s->impact($this->invoice, null));
        $this->assertSame(['amount' => '80.00', 'balance_after' => '1000.00', 'status_after' => 'partially_paid'], $s->impact($this->invoice, '80'));
        $this->assertSame(['amount' => '0.00', 'balance_after' => '1080.00', 'status_after' => 'issued'], $s->impact($this->invoice, 'abc'));
        $this->assertSame(['amount' => '0.00', 'balance_after' => '1080.00', 'status_after' => 'issued'], $s->impact($this->invoice, '5000'));
        $this->assertSame(0, $this->paymentCount());
    }

    public function testOverdueUsesPassedDay(): void
    {
        $q = new InvoiceStatusQueries($this->rw());

        $row = $q->forInvoice($this->invoice, '2026-11-10');
        $this->assertNotNull($row);
        $this->assertFalse($row['overdue']);
        $this->assertSame(0, $row['days_overdue']);
        $this->assertSame('1080.00', $row['balance']);
        $this->assertSame('EUR', $row['currency']);

        $row = $q->forInvoice($this->invoice, '2026-11-11');
        $this->assertTrue($row['overdue']);
        $this->assertSame(1, $row['days_overdue']);

        $this->service()->record($this->invoice, '100', '2026-11-05', null, Uuid::v4(), null);
        $row = $q->forInvoice($this->invoice, '2026-11-20');
        $this->assertSame('partially_paid', $row['status']);
        $this->assertTrue($row['overdue']);
        $this->assertSame(10, $row['days_overdue']);

        $this->service()->record($this->invoice, null, '2026-11-05', null, Uuid::v4(), null);
        $row = $q->forInvoice($this->invoice, '2026-11-20');
        $this->assertSame('paid', $row['status']);
        $this->assertFalse($row['overdue']);
        $this->assertNull($q->forInvoice(999999, '2026-11-20'));
    }

    public function testPaymentsListNewestFirst(): void
    {
        $s = $this->service();
        $s->record($this->invoice, '100', '2026-11-01', null, Uuid::v4(), null);
        $second = $s->record($this->invoice, '200', '2026-11-03', 'pozn', Uuid::v4(), null);

        $list = (new InvoiceStatusQueries($this->rw()))->payments($this->invoice);
        $this->assertCount(2, $list);
        $this->assertSame($second->id, $list[0]['id']);
        $this->assertSame('200.00', $list[0]['amount']);
        $this->assertSame('pozn', $list[0]['note']);
        $this->assertFalse($list[0]['voided']);
    }

    public function testTemplateContextBalanceUsesOutstanding(): void
    {
        $this->service()->record($this->invoice, '300', '2026-11-05', null, Uuid::v4(), null);
        $balance = (string) $this->owner()->query('SELECT balance FROM invoice_status_v WHERE id = :i:', ['i' => $this->invoice])->getRow()->balance;

        $values = TemplateContext::values(['currency' => 'EUR', 'total' => '1080.00', 'balance' => $balance, 'doc_language' => 'sk', 'due_date' => '2026-11-10'], '2026-11-05');
        $this->assertSame("780,00\u{00A0}€", $values['balance']);
        $this->assertSame("1\u{00A0}080,00\u{00A0}€", $values['total']);
    }
}
