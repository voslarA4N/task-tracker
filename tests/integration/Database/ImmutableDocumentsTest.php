<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\ReportFixtures;
use Tests\Support\TurnoverFixtures;

/**
 * NFR-09, D-11: vystavene doklady sa nedaju zmazat ani zmenit, ani opravnenim (app_rw), ani triggerom (app_owner).
 * Matica DELETE/TRUNCATE/UPDATE nad NEPRAZDNYMI tabulkami (truncate_guard povoli TRUNCATE prazdnej tabulky,
 * preto test najprv naplni data) a katalogovy poistny test proti novej nechranenej tabulke.
 *
 * Akceptovane zvyskove riziko (RESEARCH Pitfall 7, T-12-12): app_owner moze vykonat
 * ALTER TABLE ... DISABLE TRIGGER alebo DROP TABLE. Je to viditelny zasah vlastnika schemy (migracie su v gite)
 * a obnovu zabezpecuju denne sifrovane zalohy a rocny archiv. Test to zamerne neoveruje.
 *
 * @internal
 */
#[Group('integration')]
final class ImmutableDocumentsTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;
    use ReportFixtures;

    public const PROTECTED_TABLES = [
        'invoices', 'invoice_lines', 'invoice_documents', 'invoice_counters', 'invoice_sends',
        'invoice_send_voids', 'payments', 'payment_voids', 'invoice_regen_windows',
    ];

    /**
     * Tabulky s FK na invoices/invoice_documents, ktore nie su doklad (dovod je povinny).
     */
    public const EXEMPT_TABLES = [
        'billing_allocations' => 'fakturacne casti, zamknute billing_allocations_guard, nie doklad',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        $entity = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $client = $this->createClient();
        $a      = $this->reportInvoice($entity, $client, '2026-10-05', '1000.00');
        $b      = $this->reportInvoice($entity, $client, '2026-10-12', '200.00');
        $this->turnoverCreditNote($b, '2026-10-20');

        $o = $this->owner();
        $o->query("INSERT INTO invoice_sends (invoice_id, kind, sent_at, transport, counts_as_sent, created_at) VALUES (:i:, 'invoice', now(), 'smtp', true, now())", ['i' => $a]);
        $o->query("INSERT INTO invoice_send_voids (send_id, reason, voided_at) SELECT id, 'x', now() FROM invoice_sends WHERE invoice_id = :i:", ['i' => $a]);
        $payment = $this->reportPayment($a, '2026-10-30', '10.00');
        $this->voidPayment($payment);

        if ((int) $o->query('SELECT count(*) AS c FROM invoice_regen_windows')->getRow()->c === 0) {
            $o->query("INSERT INTO invoice_regen_windows (invoice_id, hours, regen_until, created_at) VALUES (:i:, 72, now(), now())", ['i' => $a]);
        }

        if ((int) $o->query('SELECT count(*) AS c FROM invoice_counters')->getRow()->c === 0) {
            $o->query("INSERT INTO invoice_counters (series_id, period_key, last_value) SELECT id, '2099', 1 FROM invoice_series LIMIT 1");
        }
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tables(): iterable
    {
        foreach (self::PROTECTED_TABLES as $t) {
            yield $t => [$t];
        }
    }

    private function rowCount(string $t): int
    {
        return (int) $this->owner()->query("SELECT count(*) AS c FROM {$t}")->getRow()->c;
    }

    private function updateSql(string $t): string
    {
        if ($t === 'invoice_counters') {
            return 'UPDATE invoice_counters SET last_value = last_value';
        }

        $col = $this->owner()->query(
            "SELECT attname FROM pg_attribute WHERE attrelid = CAST(:t: AS regclass) AND attnum > 0 AND NOT attisdropped AND attidentity = '' ORDER BY attnum LIMIT 1",
            ['t' => $t],
        )->getRow()->attname;

        return "UPDATE {$t} SET {$col} = {$col}";
    }

    /**
     * @param callable(): mixed $fn
     */
    private function inRolledBackTx(callable $fn): void
    {
        $o = $this->owner();
        // CI4 v transakcii chybu dotazu inak nevyhodi (vrati false), test ju musi vidiet ako vynimku.
        $o->transException(true);
        $o->transBegin();

        try {
            $fn();
        } finally {
            $o->transRollback();
            $o->transException(false);
        }
    }

    #[DataProvider('tables')]
    public function testRwCannotDeleteTruncateOrUpdate(string $t): void
    {
        $before = $this->rowCount($t);
        $this->assertGreaterThan(0, $before, "{$t} musi byt naplnena");

        $this->assertPermissionDenied(fn () => $this->rw()->query("DELETE FROM {$t}"));
        $this->assertPermissionDenied(fn () => $this->rw()->query("TRUNCATE {$t}"));

        if ($t === 'invoice_counters') {
            // UPDATE last_value je grantovany, ale counter_guard dovoli iba +1.
            $this->assertDbError(fn () => $this->rw()->query($this->updateSql($t)));
        } else {
            $this->assertPermissionDenied(fn () => $this->rw()->query($this->updateSql($t)));
        }

        $this->assertSame($before, $this->rowCount($t));
    }

    #[DataProvider('tables')]
    public function testOwnerCannotDeleteTruncateOrUpdate(string $t): void
    {
        $before = $this->rowCount($t);
        $this->assertGreaterThan(0, $before, "{$t} musi byt naplnena");

        $this->assertDbError(fn () => $this->inRolledBackTx(fn () => $this->owner()->query("DELETE FROM {$t} WHERE true")));
        $this->assertDbError(fn () => $this->inRolledBackTx(fn () => $this->owner()->query("TRUNCATE {$t}")));
        $this->assertDbError(fn () => $this->inRolledBackTx(fn () => $this->owner()->query("TRUNCATE {$t} CASCADE")));
        $this->assertDbError(fn () => $this->inRolledBackTx(fn () => $this->owner()->query($this->updateSql($t))));

        if ($t === 'invoice_counters') {
            $this->assertDbError(fn () => $this->inRolledBackTx(fn () => $this->owner()->query('UPDATE invoice_counters SET last_value = last_value + 5')));
        }

        $this->assertSame($before, $this->rowCount($t));
    }

    public function testCreditNotesCannotBeDeleted(): void
    {
        $this->assertGreaterThan(0, (int) $this->owner()->query("SELECT count(*) AS c FROM invoices WHERE type = 'credit_note'")->getRow()->c);
        $this->assertPermissionDenied(fn () => $this->rw()->query("DELETE FROM invoices WHERE type = 'credit_note'"));
        $this->assertDbError(fn () => $this->inRolledBackTx(fn () => $this->owner()->query("DELETE FROM invoices WHERE type = 'credit_note'")));
    }

    public function testEveryTableReferencingDocumentsIsProtectedOrExempt(): void
    {
        $rows = $this->owner()->query(
            "SELECT DISTINCT c.conrelid::regclass::text AS tbl
               FROM pg_constraint c
              WHERE c.contype = 'f' AND c.confrelid IN ('invoices'::regclass, 'invoice_documents'::regclass)",
        )->getResultArray();

        foreach ($rows as $r) {
            $t = $r['tbl'];
            $this->assertTrue(
                in_array($t, self::PROTECTED_TABLES, true) || isset(self::EXEMPT_TABLES[$t]),
                "Tabulka {$t} ma FK na invoices/invoice_documents, ale nie je v PROTECTED_TABLES (BEFORE DELETE + BEFORE TRUNCATE ochrana) ani v EXEMPT_TABLES s dovodom.",
            );
        }
    }

    #[DataProvider('tables')]
    public function testProtectedTablesHaveTriggersAndNoDeleteGrant(string $t): void
    {
        // tgtype bity: 1 = ROW, 2 = BEFORE, 8 = DELETE, 16 = UPDATE, 32 = TRUNCATE
        $has = static fn (self $self, int $mask, int $level): bool => (int) $self->owner()->query(
            'SELECT count(*) AS c FROM pg_trigger WHERE tgrelid = CAST(:t: AS regclass) AND NOT tgisinternal AND (tgtype & 2) = 2 AND (tgtype & :m:) = :m: AND (tgtype & 1) = :l:',
            ['t' => $t, 'm' => $mask, 'l' => $level],
        )->getRow()->c > 0;

        $this->assertTrue($has($this, 8, 1), "{$t}: chyba BEFORE DELETE row trigger");
        $this->assertTrue($has($this, 32, 0), "{$t}: chyba BEFORE TRUNCATE trigger");

        foreach (['DELETE', 'TRUNCATE'] as $priv) {
            $granted = $this->owner()->query('SELECT has_table_privilege(\'app_rw\', :t:, :p:) AS g', ['t' => $t, 'p' => $priv])->getRow()->g;
            $this->assertContains($granted, [false, 'f'], "{$t}: app_rw ma {$priv}");
        }
    }
}
