<?php

declare(strict_types=1);

namespace Tests\Integration\Receivables;

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
final class PaymentVoidTest extends PgTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

    private int $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-11-05T10:00:00+01:00'));
        $this->resetMasterTables();

        $entity        = $this->createEntity('SK');
        $client        = $this->createClient();
        $alloc         = $this->createAllocation($this->createTask($client), '8.00', '2026-10', ['rate' => '135.00', 'amount' => '1080.00']);
        $this->invoice = $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc]);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function expectReject(string $reason, callable $fn): void
    {
        try {
            $fn();
        } catch (PaymentRejected $e) {
            $this->assertSame($reason, $e->reason);

            return;
        }

        $this->fail('Expected PaymentRejected ' . $reason);
    }

    public function testVoidRestoresBalanceAndIsAudited(): void
    {
        $s   = new PaymentService($this->rw());
        $pay = $s->record($this->invoice, null, '2026-11-05', null, Uuid::v4(), null);
        $this->assertSame('paid', $pay->statusAfter);

        $this->assertSame($this->invoice, $s->void($pay->id, '  Zadané omylom ', null));

        $row = (new InvoiceStatusQueries($this->rw()))->forInvoice($this->invoice, '2026-11-05');
        $this->assertSame('1080.00', $row['balance']);
        $this->assertSame('issued', $row['status']);

        $audit = $this->owner()->query("SELECT after_data FROM audit_log WHERE entity_type = 'payment_void' AND action = 'create'")->getRowArray();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('Zadané omylom', (string) $audit['after_data']);

        $list = (new InvoiceStatusQueries($this->rw()))->payments($this->invoice);
        $this->assertCount(1, $list);
        $this->assertTrue($list[0]['voided']);
        $this->assertSame('Zadané omylom', $list[0]['void_reason']);
        $this->assertNotNull($list[0]['voided_at']);

        // storno nic nemaze: znovu sa da zapisat cely zostatok
        $this->assertSame('1080.00', $s->record($this->invoice, null, '2026-11-05', null, Uuid::v4(), null)->amount);
    }

    public function testVoidRules(): void
    {
        $s   = new PaymentService($this->rw());
        $pay = $s->record($this->invoice, '100', '2026-11-05', null, Uuid::v4(), null);

        $this->expectReject('empty_reason', static fn () => $s->void($pay->id, '', null));
        $this->expectReject('empty_reason', static fn () => $s->void($pay->id, "   \t", null));
        $this->expectReject('note_too_long', static fn () => $s->void($pay->id, str_repeat('x', 201), null));
        $this->expectReject('not_found', static fn () => $s->void(999999, 'x', null));

        $s->void($pay->id, 'omyl', null);
        $this->expectReject('already_voided', static fn () => $s->void($pay->id, 'znova', null));
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM payment_voids')->getRow()->c);
    }
}
