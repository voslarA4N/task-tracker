<?php

declare(strict_types=1);

namespace Tests\Integration\Fx;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Fx\FxSettingsRejected;
use App\Libraries\Fx\FxSettingsService;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FxFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class FxSettingsServiceTest extends PgTestCase
{
    use FxFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreFxSettings();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
    }

    protected function tearDown(): void
    {
        $this->restoreFxSettings();
        Services::resetSingle('clock');
        parent::tearDown();
    }

    private function service(): FxSettingsService
    {
        return new FxSettingsService($this->rw(), new AuditLogger($this->rw()));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->service()->all() as $s) {
            $rows[$s['country']] = ['source' => $s['source'], 'date_basis' => $s['date_basis'], 'day_rule' => $s['day_rule'], 'revision' => $s['revision']];
        }

        return $rows;
    }

    private function auditCount(): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'fx_settings'")->getRow()->c;
    }

    public function testDefaults(): void
    {
        $all = $this->service()->all();
        $this->assertSame(['SK', 'PL', 'CZ'], array_column($all, 'country'));
        $this->assertSame(['ECB', 'NBP', 'CNB'], array_column($all, 'source'));
        $this->assertSame([1, 1, 1], array_column($all, 'revision'));
    }

    public function testUpdateChangesRowBumpsRevisionAndAudits(): void
    {
        $base = $this->auditCount();
        $rows = $this->rows();
        $rows['PL']['date_basis'] = 'issue_date';

        $this->assertTrue($this->service()->updateAll($rows));

        $pl = $this->service()->forCountry('PL');
        $this->assertSame('issue_date', $pl['date_basis']);
        $this->assertSame(2, $pl['revision']);
        $this->assertSame(1, $this->service()->forCountry('SK')['revision']);
        $this->assertSame(1, $this->service()->forCountry('CZ')['revision']);

        $at = $this->owner()->query("SELECT updated_at FROM fx_country_settings WHERE country = 'PL'")->getRow()->updated_at;
        $this->assertStringStartsWith('2026-10-30 10:00:00', (string) $at);

        $this->assertSame($base + 1, $this->auditCount());
        $audit = $this->owner()->query("SELECT * FROM audit_log WHERE entity_type = 'fx_settings' ORDER BY id DESC LIMIT 1")->getRowArray();
        $this->assertSame('update', $audit['action']);
        $before = json_decode((string) $audit['before_data'], true);
        $after  = json_decode((string) $audit['after_data'], true);
        $this->assertSame('delivery_date', $before['date_basis']);
        $this->assertSame('issue_date', $after['date_basis']);
    }

    public function testNoChangeReturnsFalse(): void
    {
        $base = $this->auditCount();
        $this->assertFalse($this->service()->updateAll($this->rows()));
        $this->assertSame($base, $this->auditCount());
        $this->assertSame(1, $this->service()->forCountry('PL')['revision']);
    }

    public function testStaleRejected(): void
    {
        $rows                 = $this->rows();
        $rows['PL']['source'] = 'ECB';
        $this->service()->updateAll($rows);

        $again                 = $this->rows();
        $again['PL']['source'] = 'CNB';
        $again['PL']['revision'] = 1;

        try {
            $this->service()->updateAll($again);
            $this->fail('stale expected');
        } catch (FxSettingsRejected $e) {
            $this->assertSame('stale', $e->reason);
        }

        $this->assertSame('ECB', $this->service()->forCountry('PL')['source']);
    }

    public function testInvalidRejected(): void
    {
        $rows                 = $this->rows();
        $rows['PL']['source'] = 'XYZ';
        $rows['CZ']['day_rule'] = '';

        try {
            $this->service()->updateAll($rows);
            $this->fail('invalid expected');
        } catch (FxSettingsRejected $e) {
            $this->assertSame('invalid', $e->reason);
            $this->assertSame(['PL.source', 'CZ.day_rule'], $e->fields);
        }

        $this->assertSame('NBP', $this->service()->forCountry('PL')['source']);

        $missing = $this->rows();
        unset($missing['SK']);

        try {
            $this->service()->updateAll($missing);
            $this->fail('invalid expected');
        } catch (FxSettingsRejected $e) {
            $this->assertSame('invalid', $e->reason);
        }
    }
}
