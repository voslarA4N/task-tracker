<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Reporting\LimitRejected;
use App\Libraries\Reporting\LimitService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\TurnoverFixtures;

/**
 * @internal
 */
#[Group('integration')]
final class LimitServiceTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        $this->resetMasterTables();
        parent::tearDown();
    }

    private function clean(): void
    {
        $this->owner()->query('DELETE FROM turnover_limits');
        $this->owner()->query("DELETE FROM audit_log WHERE entity_type = 'turnover_limit'");
    }

    private function service(): LimitService
    {
        return new LimitService($this->rw(), new AuditLogger($this->rw()));
    }

    /**
     * @param array<string, string> $o
     *
     * @return array<string, string>
     */
    private function input(array $o = []): array
    {
        return $o + ['country' => 'SK', 'year' => '2026', 'name' => 'Registrácia DPH', 'amount' => '50 000,00', 'currency' => 'EUR', 'calc_method' => 'calendar_year', 'thresholds' => '80, 100', 'revision' => ''];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audits(): array
    {
        return $this->owner()->query("SELECT * FROM audit_log WHERE entity_type = 'turnover_limit' ORDER BY id")->getResultArray();
    }

    public function testCreate(): void
    {
        $id  = $this->service()->create($this->input());
        $row = $this->service()->find($id);

        $this->assertNotNull($row);
        $this->assertFalse($row['is_seed']);
        $this->assertSame(1, $row['revision']);
        $this->assertSame([80, 100], $row['thresholds']);
        $this->assertSame('50000.00', $row['amount']);

        $audits = $this->audits();
        $this->assertCount(1, $audits);
        $this->assertSame('create', $audits[0]['action']);
        $this->assertNull($audits[0]['before_data']);
        $after = json_decode((string) $audits[0]['after_data'], true);
        $this->assertSame('Registrácia DPH', $after['name']);
        $this->assertSame('50000.00', $after['amount']);
    }

    public function testCreateInvalidAmount(): void
    {
        try {
            $this->service()->create($this->input(['amount' => '0']));
            $this->fail('invalid expected');
        } catch (LimitRejected $e) {
            $this->assertSame('invalid', $e->reason);
            $this->assertSame('Limits.errAmount', $e->fields['amount']);
        }

        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM turnover_limits')->getRow()->c);
    }

    public function testDuplicate(): void
    {
        $this->service()->create($this->input());

        try {
            $this->service()->create($this->input());
            $this->fail('duplicate expected');
        } catch (LimitRejected $e) {
            $this->assertSame('duplicate', $e->reason);
        }

        $this->service()->create($this->input(['name' => 'Oslobodenie']));
        $this->assertSame(2, (int) $this->owner()->query('SELECT count(*) AS c FROM turnover_limits')->getRow()->c);
    }

    public function testUpdateSeedRow(): void
    {
        $id = $this->insertLimit('SK', 2026, 'Registrácia DPH', '49790.00', 'EUR', 'calendar_year', '80,100', true);

        $this->service()->update($id, $this->input(['revision' => '1']));

        $row = $this->service()->find($id);
        $this->assertSame('50000.00', $row['amount']);
        $this->assertFalse($row['is_seed']);
        $this->assertSame(2, $row['revision']);

        $audits = $this->audits();
        $this->assertCount(1, $audits);
        $this->assertSame('update', $audits[0]['action']);
        $before = json_decode((string) $audits[0]['before_data'], true);
        $after  = json_decode((string) $audits[0]['after_data'], true);
        $this->assertSame('49790.00', $before['amount']);
        $this->assertTrue($before['is_seed']);
        $this->assertFalse($after['is_seed']);
    }

    public function testUpdateStale(): void
    {
        $id = $this->insertLimit('SK', 2026, 'Registrácia DPH', '49790.00', 'EUR', 'calendar_year');
        $this->service()->update($id, $this->input(['revision' => '1']));

        try {
            $this->service()->update($id, $this->input(['revision' => '1', 'amount' => '60000']));
            $this->fail('stale expected');
        } catch (LimitRejected $e) {
            $this->assertSame('stale', $e->reason);
        }

        $this->assertSame('50000.00', $this->service()->find($id)['amount']);
    }

    public function testUpdateNotFound(): void
    {
        $this->expectException(LimitRejected::class);
        $this->expectExceptionMessage('not_found');
        $this->service()->update(999999, $this->input(['revision' => '1']));
    }

    public function testUpdateDuplicateName(): void
    {
        $this->insertLimit('SK', 2026, 'A', '1000.00', 'EUR', 'calendar_year');
        $id = $this->insertLimit('SK', 2026, 'B', '1000.00', 'EUR', 'calendar_year');

        try {
            $this->service()->update($id, $this->input(['name' => 'A', 'revision' => '1']));
            $this->fail('duplicate expected');
        } catch (LimitRejected $e) {
            $this->assertSame('duplicate', $e->reason);
        }
    }

    public function testDelete(): void
    {
        $id = $this->insertLimit('SK', 2026, 'Registrácia DPH', '49790.00', 'EUR', 'calendar_year');

        try {
            $this->service()->delete($id, 5);
            $this->fail('stale expected');
        } catch (LimitRejected $e) {
            $this->assertSame('stale', $e->reason);
        }

        $deleted = $this->service()->delete($id, 1);
        $this->assertSame('Registrácia DPH', $deleted['name']);
        $this->assertNull($this->service()->find($id));

        $audits = $this->audits();
        $this->assertSame('delete', $audits[0]['action']);
        $this->assertNull($audits[0]['after_data']);
        $this->assertNotNull($audits[0]['before_data']);
    }

    public function testCurrencyWarning(): void
    {
        $this->assertNull($this->service()->currencyWarning('SK', 'PLN', '2026-11-02'));

        $this->createEntity('SK');
        $this->assertSame('EUR', $this->service()->currencyWarning('SK', 'PLN', '2026-11-02'));
        $this->assertNull($this->service()->currencyWarning('SK', 'EUR', '2026-11-02'));
        $this->assertNull($this->service()->currencyWarning('CZ', 'CZK', '2026-11-02'));
    }
}
