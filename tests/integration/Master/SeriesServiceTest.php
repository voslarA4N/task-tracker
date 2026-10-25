<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\InvalidSeriesFormat;
use App\Libraries\Master\SeriesRejected;
use App\Libraries\Master\SeriesService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class SeriesServiceTest extends PgTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        parent::tearDown();
    }

    private function service(): SeriesService
    {
        return new SeriesService($this->rw(), new AuditLogger($this->rw()));
    }

    private function lockedService(): SeriesService
    {
        return new class ($this->rw(), new AuditLogger($this->rw())) extends SeriesService {
            public function isFormatLocked(int $seriesId): bool
            {
                return true;
            }
        };
    }

    private function auditCount(): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'invoice_series' AND action = 'update'")->getRow()->c;
    }

    private function rejectedReason(callable $fn): string
    {
        try {
            $fn();
        } catch (SeriesRejected $e) {
            return $e->reason;
        }

        $this->fail('Expected SeriesRejected.');
    }

    public function testNewEntityHasDefaultSeries(): void
    {
        $id   = $this->createEntity('SK');
        $list = $this->service()->listFor($id);

        $this->assertSame(['invoice', 'credit_note'], array_keys($list));
        $this->assertSame('RRRRMM###', $list['invoice']['format']);
        $this->assertSame(1, (int) $list['invoice']['initial_number']);
        $this->assertSame('DB-RRRRMM###', $list['credit_note']['format']);
        $this->assertSame(1, (int) $list['credit_note']['initial_number']);
        $this->assertNull($this->service()->find($id, 'foo'));
    }

    public function testUpdateWritesAndAudits(): void
    {
        $id = $this->createEntity('SK');
        $this->service()->update($id, 'invoice', 'FA-RRRR-####', '5');

        $row = $this->service()->find($id, 'invoice');
        $this->assertSame('FA-RRRR-####', $row['format']);
        $this->assertSame(5, (int) $row['initial_number']);
        $this->assertSame(1, $this->auditCount());

        $audit = $this->owner()->query("SELECT before_data, after_data FROM audit_log WHERE entity_type = 'invoice_series'")->getRow();
        $this->assertSame('RRRRMM###', json_decode((string) $audit->before_data, true)['format']);
        $this->assertSame('FA-RRRR-####', json_decode((string) $audit->after_data, true)['format']);
        $this->assertSame('5', (string) json_decode((string) $audit->after_data, true)['initial_number']);
    }

    public function testUnchangedValuesWriteNoAudit(): void
    {
        $id = $this->createEntity('SK');
        $this->service()->update($id, 'invoice', 'RRRRMM###', '1');

        $this->assertSame(0, $this->auditCount());
    }

    public function testSameFormatAsOtherKindRejected(): void
    {
        $id = $this->createEntity('SK');

        $this->assertSame('same_as_other', $this->rejectedReason(fn () => $this->service()->update($id, 'invoice', 'DB-RRRRMM###', '1')));
        $this->assertSame('RRRRMM###', $this->service()->find($id, 'invoice')['format']);
    }

    public function testInitialNumberOutOfRange(): void
    {
        $id = $this->createEntity('SK');

        foreach (['1000', '0', '', 'abc', '-1', '1.5', '9999999999'] as $bad) {
            $this->assertSame('initial_out_of_range', $this->rejectedReason(fn () => $this->service()->update($id, 'invoice', 'RRRRMM###', $bad)), $bad);
        }

        $this->assertSame(1, (int) $this->service()->find($id, 'invoice')['initial_number']);
    }

    public function testInvalidFormatPropagatesWithoutChange(): void
    {
        $id = $this->createEntity('SK');

        try {
            $this->service()->update($id, 'invoice', '###', '1');
            $this->fail('Expected InvalidSeriesFormat.');
        } catch (InvalidSeriesFormat $e) {
            $this->assertSame('no_year', $e->reason);
        }

        $this->assertSame('RRRRMM###', $this->service()->find($id, 'invoice')['format']);
        $this->assertSame(0, $this->auditCount());
    }

    public function testLockedSeriesRejectsFormatChangeButAllowsInitialNumber(): void
    {
        $id = $this->createEntity('SK');

        $this->assertSame('locked', $this->rejectedReason(fn () => $this->lockedService()->update($id, 'invoice', 'FA-RRRR###', '1')));
        $this->assertSame('RRRRMM###', $this->service()->find($id, 'invoice')['format']);

        $this->lockedService()->update($id, 'invoice', 'RRRRMM###', '7');
        $this->assertSame(7, (int) $this->service()->find($id, 'invoice')['initial_number']);
    }

    public function testNotLockedInPhaseThree(): void
    {
        $id     = $this->createEntity('SK');
        $series = $this->service()->find($id, 'invoice');

        $this->assertFalse($this->service()->isFormatLocked((int) $series['id']));
    }

    public function testOtherEntityIsNotAffected(): void
    {
        $a = $this->createEntity('SK');
        $b = $this->createEntity('PL');

        $this->service()->update($b, 'invoice', 'PL-RRRR###', '3');

        $this->assertSame('RRRRMM###', $this->service()->find($a, 'invoice')['format']);
        $this->assertSame(1, (int) $this->service()->find($a, 'invoice')['initial_number']);
    }
}
