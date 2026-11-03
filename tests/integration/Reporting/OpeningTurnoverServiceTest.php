<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Reporting\LimitRejected;
use App\Libraries\Reporting\OpeningTurnoverService;
use App\Libraries\Reporting\TurnoverCalculator;
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
final class OpeningTurnoverServiceTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;

    private int $sk;
    private int $pl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        $this->clean();
        $this->sk = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $this->pl = $this->createEntity('PL', ['active_from' => '2025-01-01']);
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
        $this->owner()->query('DELETE FROM entity_opening_turnover');
        $this->owner()->query("DELETE FROM audit_log WHERE entity_type = 'entity_opening_turnover'");
    }

    private function service(): OpeningTurnoverService
    {
        return new OpeningTurnoverService($this->rw(), new AuditLogger($this->rw()));
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    private function input(array $o = []): array
    {
        return $o + ['entity_id' => $this->sk, 'year' => '2026', 'amount' => '3 000,00', 'note' => 'Excel do 30. 9.', 'revision' => ''];
    }

    /**
     * @return list<string>
     */
    private function actions(): array
    {
        return array_column($this->owner()->query("SELECT action FROM audit_log WHERE entity_type = 'entity_opening_turnover' ORDER BY id")->getResultArray(), 'action');
    }

    public function testLifecycle(): void
    {
        $this->assertSame('created', $this->service()->save($this->input()));
        $row = $this->service()->find($this->sk, 2026);
        $this->assertSame('3000.00', $row['amount']);
        $this->assertSame(1, $row['revision']);
        $this->assertSame('Excel do 30. 9.', $row['note']);

        $id = (string) $row['id'];
        $this->assertSame('updated', $this->service()->save($this->input(['amount' => '3500', 'revision' => '1', 'opening_id' => $id])));
        $this->assertSame(2, $this->service()->find($this->sk, 2026)['revision']);

        try {
            $this->service()->save($this->input(['revision' => '1', 'opening_id' => $id]));
            $this->fail('stale expected');
        } catch (LimitRejected $e) {
            $this->assertSame('stale', $e->reason);
        }

        $this->assertSame('deleted', $this->service()->save($this->input(['amount' => '', 'revision' => '2', 'opening_id' => $id])));
        $this->assertNull($this->service()->find($this->sk, 2026));
        $this->assertSame(['create', 'update', 'delete'], $this->actions());
    }

    public function testRevisionIsBoundToLoadedRow(): void
    {
        $this->service()->save($this->input(['year' => '2025', 'amount' => '1000']));
        $this->service()->save($this->input(['year' => '2026', 'amount' => '2000']));
        $id2026 = (string) $this->service()->find($this->sk, 2026)['id'];

        // Formular nacital 2026 (rev 1), pouzivatel prepisal rok na 2025 (tiez rev 1).
        try {
            $this->service()->save($this->input(['year' => '2025', 'amount' => '9999', 'revision' => '1', 'opening_id' => $id2026]));
            $this->fail('stale expected');
        } catch (LimitRejected $e) {
            $this->assertSame('stale', $e->reason);
        }

        // Novy formular (bez revision) na existujuci riadok tiez nesmie prepisat.
        try {
            $this->service()->save($this->input(['year' => '2025', 'amount' => '9999']));
            $this->fail('stale expected');
        } catch (LimitRejected $e) {
            $this->assertSame('stale', $e->reason);
        }

        $this->assertSame('1000.00', $this->service()->find($this->sk, 2025)['amount']);
    }

    public function testDeletedRowIsNotSilentlyRecreated(): void
    {
        $this->service()->save($this->input());
        $id = (string) $this->service()->find($this->sk, 2026)['id'];
        $this->owner()->query('DELETE FROM entity_opening_turnover WHERE id = :id:', ['id' => (int) $id]);

        try {
            $this->service()->save($this->input(['amount' => '4000', 'revision' => '1', 'opening_id' => $id]));
            $this->fail('stale expected');
        } catch (LimitRejected $e) {
            $this->assertSame('stale', $e->reason);
        }

        $this->assertNull($this->service()->find($this->sk, 2026));
        $this->assertSame('noop', $this->service()->save($this->input(['amount' => '', 'revision' => '1', 'opening_id' => $id])));
    }

    public function testEmptyWithoutRecordIsNoop(): void
    {
        $this->assertSame('noop', $this->service()->save($this->input(['amount' => ''])));
        $this->assertSame([], $this->actions());
    }

    /**
     * @param array<string, mixed> $override
     */
    private function assertInvalid(array $override, string $field, string $key): void
    {
        try {
            $this->service()->save($this->input($override));
            $this->fail('invalid expected');
        } catch (LimitRejected $e) {
            $this->assertSame('invalid', $e->reason);
            $this->assertSame($key, $e->fields[$field]);
        }
    }

    public function testInvalidInputs(): void
    {
        $this->assertInvalid(['amount' => '-1'], 'amount', 'Limits.errOpeningAmount');
        $this->assertInvalid(['entity_id' => 987654], 'entity_id', 'Limits.errOpeningEntity');
        $this->assertInvalid(['year' => '1999'], 'year', 'Limits.errYear');
        $this->assertInvalid(['note' => str_repeat('x', 256)], 'note', 'Limits.errOpeningNote');
        $this->assertSame([], $this->actions());
    }

    public function testStoredAmountIsInEntityCurrencyNoCurrencyInput(): void
    {
        $this->service()->save($this->input(['currency' => 'USD']));
        $this->assertSame('3000.00', $this->service()->find($this->sk, 2026)['amount']);
    }

    public function testListForEntities(): void
    {
        $this->service()->save($this->input(['year' => '2025']));
        $this->service()->save($this->input(['year' => '2026']));
        $this->service()->save($this->input(['entity_id' => $this->pl]));

        $list = $this->service()->listForEntities([$this->sk, $this->pl]);
        $this->assertSame([[$this->sk, 2026], [$this->sk, 2025], [$this->pl, 2026]], array_map(static fn (array $r): array => [$r['entity_id'], $r['year']], $list));
        $this->assertSame([], $this->service()->listForEntities([]));
    }

    public function testCalculatorIncludesOpening(): void
    {
        $this->insertLimit('SK', 2026, 'Registrácia DPH', '50000.00', 'EUR', 'calendar_year');
        $this->service()->save($this->input());

        $limits = (new TurnoverCalculator($this->rw()))->forEntityId($this->sk, '2026-11-02');
        $this->assertNotNull($limits);
        $this->assertSame('3000.00', $limits->statuses[0]->openingTurnover);
    }
}
