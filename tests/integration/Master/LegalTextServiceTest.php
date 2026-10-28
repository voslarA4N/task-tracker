<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\LegalTextRejected;
use App\Libraries\Master\LegalTextService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class LegalTextServiceTest extends PgTestCase
{
    private int $id        = 0;
    private int $auditBase = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = (int) $this->owner()->query("SELECT id FROM legal_texts WHERE country = 'SK' AND language = 'sk' AND key = 'vat_note'")->getRow()->id;
        $this->restore();
        $this->auditBase = $this->auditCount();
    }

    private function auditCount(): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'legal_text' AND entity_id = :id:", ['id' => $this->id])->getRow()->c;
    }

    protected function tearDown(): void
    {
        $this->restore();
        parent::tearDown();
    }

    private function restore(): void
    {
        $this->owner()->query("UPDATE legal_texts SET body = 'Nie som platiteľ DPH.', provisional = true, revision = 1 WHERE id = :id:", ['id' => $this->id]);
        $this->owner()->query("DELETE FROM legal_texts WHERE key = 'invoice_note'");
        $this->owner()->query("DELETE FROM legal_texts WHERE revision = 1 AND (language = 'cs' AND country = 'SK' OR billing_mode = 'non_eu')");
    }

    private function service(): LegalTextService
    {
        return new LegalTextService($this->rw(), new AuditLogger($this->rw()));
    }

    public function testUpdateBumpsRevisionAndAudits(): void
    {
        $this->assertSame(2, $this->service()->update($this->id, 'Nie som platiteľom DPH.', false, 1));

        $row = $this->owner()->query('SELECT * FROM legal_texts WHERE id = :id:', ['id' => $this->id])->getRowArray();
        $this->assertSame(2, (int) $row['revision']);
        $this->assertSame('Nie som platiteľom DPH.', $row['body']);
        $this->assertFalse(in_array($row['provisional'], [true, 't', '1', 1], true));

        $this->assertSame($this->auditBase + 1, $this->auditCount());
        $audit = $this->owner()->query("SELECT * FROM audit_log WHERE entity_type = 'legal_text' AND entity_id = :id: ORDER BY id DESC LIMIT 1", ['id' => $this->id])->getRowArray();
        $this->assertSame('update', $audit['action']);
        $this->assertStringContainsString('Nie som platiteľ DPH.', (string) $audit['before_data']);
        $this->assertStringContainsString('Nie som platiteľom DPH.', (string) $audit['after_data']);
        $this->assertStringContainsString('revision', (string) $audit['after_data']);
    }

    public function testNoChangeWritesNothing(): void
    {
        $this->assertSame(1, $this->service()->update($this->id, 'Nie som platiteľ DPH.', true, 1));
        $this->assertSame(1, (int) $this->owner()->query('SELECT revision FROM legal_texts WHERE id = :id:', ['id' => $this->id])->getRow()->revision);
        $this->assertSame($this->auditBase, $this->auditCount());
    }

    public function testStaleRevisionRejected(): void
    {
        $this->service()->update($this->id, 'Prvá zmena.', true, 1);

        try {
            $this->service()->update($this->id, 'Druhá zmena.', true, 1);
            $this->fail('stale expected');
        } catch (LegalTextRejected $e) {
            $this->assertSame('stale', $e->reason);
        }

        $this->assertSame('Prvá zmena.', $this->owner()->query('SELECT body FROM legal_texts WHERE id = :id:', ['id' => $this->id])->getRow()->body);
    }

    public function testValidation(): void
    {
        $this->assertReason('empty', fn () => $this->service()->update($this->id, '   ', true, 1));
        $this->assertReason('too_long', fn () => $this->service()->update($this->id, str_repeat('a', 2001), true, 1));
        $this->assertReason('not_found', fn () => $this->service()->update(999999999, 'x', true, 1));

        $this->owner()->query("INSERT INTO legal_texts (country, vat_regime, billing_mode, language, key, body, provisional, updated_at) VALUES ('SK', 'sk_non_vat', NULL, 'sk', 'invoice_note', 'Poznamka', true, now())");
        $note = (int) $this->owner()->query("SELECT id FROM legal_texts WHERE key = 'invoice_note'")->getRow()->id;
        $this->assertReason('too_long', fn () => $this->service()->update($note, str_repeat('a', 501), true, 1));
        $this->assertSame(2, $this->service()->update($note, str_repeat('a', 500), true, 1));
    }

    public function testListGroupedOrdersAndContainsProfiles(): void
    {
        $groups = $this->service()->listGrouped();
        $this->assertCount(4, $groups);
        $pl = array_values(array_filter($groups, static fn (array $g): bool => $g['country'] === 'PL'));
        $this->assertCount(2, $pl);
        $this->assertCount(2, $pl[0]['profiles']);
        $this->assertSame('sk', $pl[0]['texts'][0]['language']);
    }

    private function assertReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail($reason . ' expected');
        } catch (LegalTextRejected $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function testCreateAddsVariantAndAudits(): void
    {
        $base = $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'legal_text' AND action = 'create'")->getRow()->c;
        $id   = $this->service()->create('SK', 'sk_non_vat', null, 'cs', 'vat_note', 'Nejsem plátce DPH.', true);

        $row = $this->owner()->query('SELECT * FROM legal_texts WHERE id = :id:', ['id' => $id])->getRowArray();
        $this->assertSame(1, (int) $row['revision']);
        $this->assertSame('cs', $row['language']);
        $this->assertSame((int) $base + 1, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'legal_text' AND action = 'create'")->getRow()->c);

        $eff = $this->service()->effective('SK', 'sk_non_vat', null, 'cs', 'vat_note');
        $this->assertNotNull($eff);
        $this->assertSame('Nejsem plátce DPH.', $eff['body']);
        $this->assertFalse($eff['fallback']);
    }

    public function testEffectiveReportsFallback(): void
    {
        $eff = $this->service()->effective('SK', 'sk_non_vat', null, 'cs', 'vat_note');
        $this->assertNotNull($eff);
        $this->assertTrue($eff['fallback']);
        $this->assertSame('en', $eff['language']);
        $this->assertNull($this->service()->effective('SK', 'sk_non_vat', null, 'cs', 'invoice_note'));
    }

    public function testEffectiveForNonEuWithoutVariantFallsBackToGeneral(): void
    {
        $eff = $this->service()->effective('PL', 'pl_exempt_113_1', 'non_eu', 'cs', 'vat_note');
        $this->assertNotNull($eff);
        $this->assertTrue($eff['fallback']);
        $this->assertSame('en', $eff['language']);
    }

    public function testCreateDuplicateIsRejectedAndServiceStaysUsable(): void
    {
        $count = (int) $this->owner()->query('SELECT count(*) AS c FROM legal_texts')->getRow()->c;

        try {
            $this->service()->create('SK', 'sk_non_vat', null, 'sk', 'vat_note', 'Iné znenie', true);
            $this->fail('expected exists');
        } catch (LegalTextRejected $e) {
            $this->assertSame('exists', $e->reason);
        }

        $this->assertSame($count, (int) $this->owner()->query('SELECT count(*) AS c FROM legal_texts')->getRow()->c);
        $this->assertGreaterThan(0, $this->service()->create('SK', 'sk_non_vat', null, 'cs', 'vat_note', 'Nejsem plátce DPH.', true));
    }

    #[DataProvider('provideCreateRejectsInvalid')]
    public function testCreateRejectsInvalid(string $country, string $regime, ?string $mode, string $lang, string $key, string $body, string $reason): void
    {
        try {
            $this->service()->create($country, $regime, $mode, $lang, $key, $body, true);
            $this->fail('expected rejection');
        } catch (LegalTextRejected $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /**
     * @return iterable<string, array{string, string, ?string, string, string, string, string}>
     */
    public static function provideCreateRejectsInvalid(): iterable
    {
        yield 'key' => ['SK', 'sk_non_vat', null, 'cs', 'footer', 'x', 'invalid'];

        yield 'country' => ['DE', 'sk_non_vat', null, 'cs', 'vat_note', 'x', 'invalid'];

        yield 'language' => ['SK', 'sk_non_vat', null, 'de', 'vat_note', 'x', 'invalid'];

        yield 'mode' => ['SK', 'sk_non_vat', 'bogus', 'cs', 'vat_note', 'x', 'invalid'];

        yield 'note' => ['SK', 'sk_non_vat', null, 'cs', 'invoice_note', str_repeat('a', 501), 'too_long'];

        yield 'empty' => ['SK', 'sk_non_vat', null, 'cs', 'vat_note', '  ', 'empty'];
    }
}
