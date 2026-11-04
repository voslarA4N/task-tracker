<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\DocumentSettingsRejected;
use App\Libraries\Invoicing\DocumentSettingsService;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class DocumentSettingsServiceTest extends PgTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
        Services::injectMock('clock', new FrozenClock('2026-10-30T10:00:00+01:00'));
    }

    protected function tearDown(): void
    {
        $this->reset();
        Services::resetSingle('clock');
        parent::tearDown();
    }

    private function reset(): void
    {
        $this->owner()->query('UPDATE document_settings SET regen_invoice_hours = 72, revision = 1 WHERE id = 1');
        $this->owner()->query("DELETE FROM audit_log WHERE entity_type = 'document_settings'");
    }

    private function service(): DocumentSettingsService
    {
        return new DocumentSettingsService($this->rw(), new AuditLogger($this->rw()));
    }

    public function testDefaultAndCurrent(): void
    {
        $this->assertSame(72, $this->service()->invoiceWindowHours());
        $this->assertSame(1, $this->service()->current()['revision']);
    }

    public function testMissingRowFailsLoudlyInBothReaders(): void
    {
        $this->owner()->query('DELETE FROM document_settings WHERE id = 1');

        try {
            foreach (['invoiceWindowHours', 'current'] as $method) {
                try {
                    $this->service()->{$method}();
                    $this->fail($method . ' should throw');
                } catch (DocumentSettingsRejected $e) {
                    $this->assertSame('not_found', $e->reason);
                }
            }
        } finally {
            $this->owner()->query('INSERT INTO document_settings (id, regen_invoice_hours, revision, updated_at) VALUES (1, 72, 1, now())');
        }
    }

    public function testUpdateChangesAuditsAndBumpsRevision(): void
    {
        $this->assertTrue($this->service()->update('48', 1));
        $this->assertSame(48, $this->service()->invoiceWindowHours());
        $this->assertSame(2, $this->service()->current()['revision']);

        $rows = $this->owner()->query("SELECT * FROM audit_log WHERE entity_type = 'document_settings'")->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame('update', $rows[0]['action']);
        $this->assertStringContainsString('72', (string) $rows[0]['before_data']);
        $this->assertStringContainsString('48', (string) $rows[0]['after_data']);
    }

    public function testUnchangedValueReturnsFalseWithoutAudit(): void
    {
        $this->service()->update('48', 1);
        $this->assertFalse($this->service()->update('48', 2));
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'document_settings'")->getRow()->c);
    }

    public function testBoundaries(): void
    {
        $this->assertTrue($this->service()->update('0', 1));
        $this->assertSame(0, $this->service()->invoiceWindowHours());
        $this->assertTrue($this->service()->update('8760', 2));
        $this->assertSame(8760, $this->service()->invoiceWindowHours());
    }

    #[DataProvider('provideInvalidRejected')]
    public function testInvalidRejected(string $value): void
    {
        try {
            $this->service()->update($value, 1);
            $this->fail('Expected rejection');
        } catch (DocumentSettingsRejected $e) {
            $this->assertSame('invalid', $e->reason);
            $this->assertSame(['regen_invoice_hours'], $e->fields);
        }

        $this->assertSame(72, $this->service()->invoiceWindowHours());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidRejected(): iterable
    {
        foreach (['8761', '-1', '1.5', 'abc', '', ' 12', '12345'] as $v) {
            yield 'v:' . $v => [$v];
        }
    }

    public function testStaleRevisionRejected(): void
    {
        $this->service()->update('48', 1);

        try {
            $this->service()->update('24', 1);
            $this->fail('Expected rejection');
        } catch (DocumentSettingsRejected $e) {
            $this->assertSame('stale', $e->reason);
        }

        $this->assertSame(48, $this->service()->invoiceWindowHours());
    }
}
