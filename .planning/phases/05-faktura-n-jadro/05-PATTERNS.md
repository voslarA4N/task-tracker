# Phase 5: Fakturačné jadro - Pattern Map

**Mapped:** 2026-10-26
**Files analyzed:** 47 nových/upravených súborov
**Analogs found:** 43 / 47 (4 bez analógu, viď nižšie)

Zdroje: 05-CONTEXT.md, 05-RESEARCH.md, 05-UI-SPEC.md, kód fáz 1 až 4. Čísla riadkov sú z čítania 2026-10-26. Kde je uvedená len funkcia/sekcia, riadky sa môžu posunúť, hľadajte podľa symbolu.

## File Classification

### Migrácie (vždy nové súbory pod `app_owner`, predpona po `2026-10-26-000001`)

| Nový/upravený súbor | Role | Data Flow | Najbližší analóg | Match |
|---------------------|------|-----------|------------------|-------|
| `app/Database/Migrations/2026-10-27-000001_CreateInvoices.php` (`invoice_counters`, `invoices`, `invoice_lines`, `invoice_documents`, `immutable_guard`, deferred constraint trigger, `legal_profiles.vat_code`) | migration | CRUD (insert-only) | `2026-10-25-000008_CreateBillingAllocations.php` + `2026-10-25-000001_CreateAuditLog.php` + `2026-10-25-000005_CreateLegalProfiles.php` | exact (insert-only + trigger) |
| `app/Database/Migrations/2026-10-27-0000NN_LockAllocationsFk.php` (FK `invoice_id`, nový `alloc_guard`, `lock_allocations()` SECURITY DEFINER) | migration | CRUD / DB guard | `2026-10-25-000008_CreateBillingAllocations.php` (`alloc_guard`) | role-match |
| `app/Database/Migrations/2026-10-27-0000NN_CreateInvoiceStatusView.php` | migration | read (view) | `2026-10-25-000009_CreateTaskBillingView.php` | exact |

### Doména `app/Libraries/Invoicing/`

| Nový súbor | Role | Data Flow | Najbližší analóg | Match |
|------------|------|-----------|------------------|-------|
| `InvoiceIssuer.php` | service (jediný zapisovateľ) | CRUD v jednej transakcii | `Work/AllocationService.php` + `Work/Import/ImportRunner.php` | role-match (väčší rozsah) |
| `InvoiceCalculator.php` | utility | transform | `Platform/Money.php` (`timesHours`, `sum`) + `AllocationService::add` | role-match |
| `SnapshotBuilder.php` | service | transform (read-only) | `Master/LegalProfileResolver.php`, `EntityVersionResolver.php`, `BankAccountSelector.php` (vstupy) | partial |
| `NumberAllocator.php` | service | CRUD (upsert) | `Master/SeriesService.php` (zámok radu, `SeriesFormat`) | partial |
| `DocumentStore.php` | service | file-I/O (bytea) | `Audit/AuditLogger.php` (insert v tej istej transakcii) | partial |
| `InvoicePdfRenderer.php` (+ `PdfRenderer` interface, `MpdfInvoiceRenderer`) | service | transform | `scripts/spikes/mpdf.php`, `tests/integration/MpdfSpikeTest.php` | role-match |
| `IssueCommand.php` | DTO | — | `Work/AllocationInput.php` | partial |
| `IssueRejected.php` | exception | — | `Work/AllocationLocked.php`, `Master/LegalTextMissing.php`, `Master/SeriesRejected.php` | exact |
| `ExternalInvoiceLocker.php` | service | batch (CLI) | `Work/Import/ImportRunner.php` | role-match |
| `InvoiceQueries.php` | service (read) | request-response | `Work/TaskQueries.php` | exact |

### Controllery, routy, príkaz

| Nový/upravený súbor | Role | Data Flow | Najbližší analóg | Match |
|---------------------|------|-----------|------------------|-------|
| `app/Controllers/Invoicing.php` (náhľad, vystavenie) | controller | request-response + htmx | `Controllers/Allocations.php` | exact |
| `app/Controllers/Invoices.php` (zoznam, detail, PDF) | controller | request-response | `Controllers/Tasks.php` (index/show) | role-match |
| `app/Config/Routes/invoices.php` | route | — | `Config/Routes/allocations.php`, `Routes/tasks.php` | exact |
| `app/Commands/InvoicesLockHistorical.php` | command (CLI) | batch | `app/Commands/ImportExcel.php` | exact |
| `app/Libraries/Master/SeriesService.php` (`isFormatLocked`) | service (úprava) | read | sám súbor | — |
| `app/Views/layouts/_nav_items.php` (úprava) | view | — | sám súbor | — |
| `app/Views/tasks/_allocations.php` + `app/Libraries/Work/TaskQueries.php` (číslo faktúry pri zamknutej časti) | view/service (úprava) | read | sám súbor | — |

### Views, jazyk, CSS

| Nový súbor | Role | Data Flow | Najbližší analóg | Match |
|------------|------|-----------|------------------|-------|
| `app/Views/invoicing/index.php`, `_preview.php`, `_confirm_sheet.php`, `_move_sheet.php` | view | htmx fragment | `Views/tasks/_sheet.php`, `_alloc_form.php`, `tasks/_list.php` | exact |
| `app/Views/invoices/index.php`, `_list.php`, `show.php` | view | read | `Views/tasks/index.php`, `_list.php`, `show.php` | exact |
| `app/Views/pdf/invoice_inv1.php` | view (mPDF) | transform | žiadny (HTML pre mPDF) | no analog |
| `app/Language/{sk,en}/Invoicing.php`, `Invoices.php` | lang | — | `Language/sk/Allocations.php` | exact |
| `public/assets/css/app.css` (`.inv-*`) | css | — | existujúce `.work-*`, `.master-*` | role-match |

### Testy

| Nový súbor | Role | Data Flow | Najbližší analóg | Match |
|------------|------|-----------|------------------|-------|
| `tests/_support/IssueFixtures.php` | test support | — | `tests/_support/MasterFixtures.php` | exact |
| `tests/_support/FakePdfRenderer.php`, `FaultInjectingRenderer.php` | test support | — | žiadny | no analog |
| `tests/_support/issue_worker.php` | test support (proces) | batch | `MpdfSpikeTest::runSpike` (proc_open) | partial |
| `tests/unit/Invoicing/InvoiceCalculatorTest.php` | test | transform | `tests/unit/Platform/*` (Money testy) | role-match |
| `tests/integration/Invoicing/*Test.php` (`NumberAllocatorTest`, `IssueConcurrencyTest`, `CounterLockTest`, `IssueRollbackTest`, `IssueIdempotencyTest`, `BackdateTest`, `InvoiceImmutabilityTest`, `InvoiceSchemaTest`, `InvoiceDocumentTest`, `InvoiceImmutableAfterChangeTest`, `CurrencyGuardTest`, `LockHistoricalTest`) | test | integrácia pod `rw()` | `tests/integration/Work/AllocationGuardTest.php`, `AllocationServiceTest.php` | exact |
| `tests/feature/Invoicing/InvoicingScreenTest.php`, `InvoicingPreviewTest.php` | test | feature (htmx) | `tests/feature/Work/AllocationPanelTest.php` | exact |
| `tests/unit/Scripts/BackupScriptTest.php` | test | shell guard | `tests/unit/Scripts/DbResetGuardTest.php` | exact |
| Úpravy: `tests/integration/Work/AllocationGuardTest.php`, `Master/SeriesServiceTest`, `MasterFixtures::resetMasterTables()` | test (úprava) | — | sám súbor | — |

### Skripty a dokumentácia

| Nový súbor | Role | Najbližší analóg | Match |
|------------|------|------------------|-------|
| `scripts/backup.sh` | config/ops | `scripts/test-db.sh`, `scripts/db-reset.sh` (+ `lib-env-guard.sh`) | role-match |
| `docs/ops/backup.md`, doplnenie `docs/ops/import-excel.md` | doc | `docs/ops/import-excel.md` | exact |

---

## Pattern Assignments

### Migrácia `CreateInvoices` (migration, insert-only)

**Analóg:** `app/Database/Migrations/2026-10-25-000008_CreateBillingAllocations.php`

**Hlavička a trait** (riadky 1-16):
```php
declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

final class CreateBillingAllocations extends Migration
{
    use GrantsPrivileges;
```

**DDL cez heredoc + CHECK + index + COMMENT** (riadky 20-37, 51):
```php
$this->db->query(<<<'SQL'
    CREATE TABLE billing_allocations (
        id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
        ...
        amount numeric(12,2) NOT NULL,
        currency char(3) NOT NULL CHECK (currency IN ('EUR','PLN','CZK')),
        invoice_id bigint NULL,
        created_at timestamptz NOT NULL,
        CHECK (amount = round(hours * rate, 2))
    )
    SQL);
$this->db->query('CREATE INDEX alloc_unlocked_month_idx ON billing_allocations (billing_month, task_id) WHERE invoice_id IS NULL');
$this->db->query("COMMENT ON TABLE billing_allocations IS '...; lock order: tasks row FOR UPDATE first, then allocation; ...'");
```
Skopírovať: `invoice_lines.amount CHECK (amount = round(hours*rate,2))`, `COMMENT ON TABLE` pre každú novú tabuľku, `UNIQUE`/partial indexy rovnakým štýlom.

**Trigger funkcia** (riadky 38-50), vzor pre `immutable_guard()` a deferred constraint trigger:
```php
CREATE FUNCTION alloc_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.invoice_id IS NOT NULL THEN
        RAISE EXCEPTION 'allocation % is locked', OLD.id USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$
...
CREATE TRIGGER billing_allocations_guard BEFORE UPDATE OR DELETE ON billing_allocations FOR EACH ROW EXECUTE FUNCTION alloc_guard()
```
Text výnimky `... is locked` kontroluje `AllocationService::translate` (riadok 199) a testy (`/is locked/`), nemeniť znenie pri rozšírení `alloc_guard`.

**Insert-only GRANT** (vzor `2026-10-25-000001_CreateAuditLog.php`): default privileges z `docker/postgres/provision.sql` dávajú `app_rw` iba `SELECT, INSERT` (`ALTER DEFAULT PRIVILEGES ... GRANT SELECT, INSERT ON TABLES`), preto pre `invoices`, `invoice_lines`, `invoice_documents` **nevolať žiadny grant** a napísať komentár ako v audit_log (`-- Denník zmien je insert-only: zámerne bez grantMutable() aj grantUpdateColumns()`). Pre `invoice_counters` použiť `$this->grantUpdateColumns('invoice_counters', ['last_value'])` (trait `GrantsPrivileges`, `app/Database/Support/GrantsPrivileges.php`), nikdy `grantMutable`/`grantDelete`.

**Reference na uživateľov bez referenčnej akcie:** audit_log používa `REFERENCES users (id) ON DELETE SET NULL`; pre `invoices.created_by` to **nekopírovať** (RESEARCH Anti-pattern: použiť `NO ACTION`).

**`legal_profiles.vat_code`:** vzor seedu a komentára o predbežných hodnotách v `2026-10-25-000005_CreateLegalProfiles.php` (docblock: "Znenie aj mapovanie kodov su PREDBEZNE ... Oprava ide novou migraciou"). Tabuľka je pre `app_rw` read-only (`REVOKE INSERT ON legal_profiles, legal_texts FROM app_rw`), `ALTER TABLE ... ADD COLUMN vat_code` ide ako owner. Seed `UPDATE legal_profiles SET vat_code = ...` v tej istej migrácii.

**down():** `DROP TRIGGER IF EXISTS`, `DROP FUNCTION IF EXISTS`, `DROP TABLE IF EXISTS` v opačnom poradí (vzor riadky `down()` v CreateBillingAllocations).

---

### Migrácia `LockAllocationsFk` (migration, DB guard + SECURITY DEFINER)

**Analóg:** `CreateBillingAllocations.php` (poznámka v docblocku: "invoice_id zatial bez FK (invoices vznikne vo Phase 5)", "Vynimku z triggra pre dobropis doplni Phase 9").

- Nová verzia `alloc_guard()` ide cez `CREATE OR REPLACE FUNCTION` v **novej** migrácii, starú neupravovať. Zachovať vetvu `OLD.invoice_id IS NOT NULL` → `RAISE EXCEPTION 'allocation % is locked'` a pridať prechod `NULL -> NOT NULL` povolený len ak sa ostatné stĺpce nezmenili.
- FK: `ALTER TABLE billing_allocations ADD CONSTRAINT ... FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT`.
- `lock_allocations()` presne podľa RESEARCH Pattern 4 (SECURITY DEFINER, `SET search_path = public, pg_temp`, `REVOKE ALL ... FROM PUBLIC`, `GRANT EXECUTE ... TO app_rw`). V migrácii nepoužívať `$$` heredoc bez `<<<'SQL'` (nowdoc, aby PHP neinterpoloval `$$`), ako robí alloc_guard.
- NIKDY `GRANT UPDATE (invoice_id)` pre `app_rw`: komentár v migrácii `-- NIKDY invoice_id, task_id, currency.` (riadok 52) a `AllocationGuardTest` to overuje.

---

### Migrácia `invoice_status_v` (migration, view)

**Analóg:** `2026-10-25-000009_CreateTaskBillingView.php`

```php
$this->db->query(<<<'SQL'
    CREATE VIEW task_billing_v AS
    SELECT ...
    SQL);
$this->db->query('GRANT SELECT ON task_billing_v TO app_rw');
...
public function down(): void { $this->db->query('DROP VIEW IF EXISTS task_billing_v'); }
```
Nový pohľad bez `snapshot` a `content`; `GRANT SELECT ... TO app_rw` je povinný (pohľad nie je pokrytý default privileges pre tabuľky rovnakým spôsobom, existujúci vzor ho dáva explicitne). Pohľad `task_billing_v` sa nemení: `locked_count` začne po FK reálne fungovať bez zásahu.

---

### `InvoiceIssuer.php` (service, CRUD v jednej transakcii)

**Analóg:** `app/Libraries/Work/AllocationService.php`, `app/Libraries/Platform/Transaction.php`

**Konštruktor so zdieľaným spojením a auditom** (AllocationService riadky 17-37), kopírovať doslova vrátane docblock typov:
```php
/** @var BaseConnection<mixed, mixed> */
private readonly BaseConnection $db;
private readonly AuditLogger $audit;

/** @param BaseConnection<mixed, mixed>|null $db */
public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
{
    $this->db    = $db ?? Database::connect();
    $this->audit = $audit ?? new AuditLogger($this->db);
}
```
Pre `InvoiceIssuer` pridať rovnakým štýlom voliteľné vstrekovanie `PdfRenderer`, `NumberAllocator`, `SnapshotBuilder` (testy pod `rw()` a `FakePdfRenderer`).

**Transakčná hranica** (`Transaction.php` 30-70; použitie `AllocationService::add` riadok 41-45):
```php
$now = service('clock')->now()->format('Y-m-d H:i:sP');
return Transaction::run($this->db, function () use ($taskId, $values, $now): int { ... });
```
Čas vždy `service('clock')`, nikdy `new DateTime()`.

**Zámky a poradie** (AllocationService riadky 46-50 a 181-196): `FOR UPDATE OF t FOR SHARE OF c`, `lockRow`: najprv nezamknuté čítanie `task_id`, potom `SELECT id FROM tasks ... FOR UPDATE`, potom časť `FOR UPDATE`:
```php
$this->db->query('SELECT id FROM tasks WHERE id = :task: FOR UPDATE', ['task' => (int) $head['task_id']]);
$row = $this->db->query('SELECT * FROM billing_allocations WHERE id = :id: FOR UPDATE', ['id' => $id])->getRowArray();
```
Issuer poradie: tasks (ORDER BY id) → clients FOR SHARE → billing_allocations (ORDER BY id) → entities FOR SHARE → `invoice_series FOR UPDATE` → riadok čítača. Rad `invoice_series` sa zamyká ako v `SeriesService::update` (riadok 104): `SELECT * FROM invoice_series WHERE entity_id = :e: ORDER BY id FOR UPDATE`.

**Atómová kontrola počtu riadkov** (riadky 120-122, 142-144):
```php
if ($this->db->affectedRows() !== 1) { throw new AllocationLocked($id); }
```
V issueri: počet zamknutých riadkov z `lock_allocations()` musí sedieť s očakávaným, inak výnimka (rollback).

**Audit v tej istej transakcii:** `$this->audit->record('invoice', $id, 'issue', null, $after)` (AllocationService riadok 69: `record('billing_allocation', $id, 'create', null, $row)`). Názov entity musí zodpovedať `^[a-z_]{3,40}$` (AuditLogger `NAME_PATTERN`). Do auditu nedávať snapshot ani PDF bajty.

**Preklad DB chýb mimo transakcie** (riadky 108-150, `translate()` riadok 199):
```php
try {
    return Transaction::run($this->db, function () ... );
} catch (DatabaseException $e) {
    throw $this->translate($e, $id);
}
```
Vo vnútri `issue()` **nechytať** `DatabaseException` a pokračovať (PG aborted transaction, RESEARCH Pitfall 2); konverzia na `IssueRejected` len vo vonkajšom `try`.

**Nová závislosť na existujúcich resolveroch:** `EntityVersionResolver::atDate($entityId, $issueDate)` (hádže `VersionNotFound`), `LegalProfileResolver::profile/text` (hádže `LegalTextMissing`), `BankAccountSelector::forCurrency` (hádže `NoBankAccount`), `SeriesFormat::render/periodKey/maxSequence`. Chyby prekladať na `IssueRejected` s kódom, nikdy prázdny text (Phase 3 D-17).

---

### `InvoiceCalculator.php` (utility, transform)

**Analóg:** `Platform/Money.php` a výpočet v `AllocationService::add` (riadky 54-55)

```php
$amount = Money::of($rate)->timesHours($hours)->toDecimalString();
```
Pravidlá: `Money::of(string)` prijíma len reťazce (regex `^-?\d{1,10}(\.\d{1,2})?$`), `timesHours` = bcmath half-away-from-zero (`bcround(bcmul(..., 4), 2, RoundingMode::HalfAwayFromZero)`), `Money::sum(...$items)` na súčet zaokrúhlených riadkov, `compare/equals` na porovnanie. Žiadny `float`, žiadny `round()`/`number_format`. Výsledok porovnať s `billing_allocations.amount` (DB už má `CHECK`), pri nezhode vystavenie padne. Kalkulátor je čistá trieda bez DB, aby sa dala testovať ako unit.

---

### `NumberAllocator.php` (service, upsert čítača)

**Analóg:** `Master/SeriesService.php` (zámok radu, práca so `SeriesFormat`) a RESEARCH Pattern 1 (overený SQL). Žiadna existujúca služba upsert nerobí.

Použiť vzor dotazu z CI4 s `:name:` placeholdermi (vzor `AllocationService` riadky 58-68, `RETURNING id` cez `->getRow()->id`):
```php
$seq = (int) $this->db->query(
    'INSERT INTO invoice_counters (series_id, period_key, last_value) VALUES (:s:, :p:, :first:)
     ON CONFLICT (series_id, period_key) DO UPDATE SET last_value = invoice_counters.last_value + 1
     RETURNING last_value',
    [...],
)->getRow()->last_value;
```
Číslo: `$format->render($issueDate, $seq)`; `OverflowException` pri poradí nad `maxSequence()` previesť na `IssueRejected('sequence_overflow')`. Obdobie: `$format->periodKey($issueDate)`. Formát načítať `SeriesFormat::parse((string) $series['format'])`.

`SeriesService::collides`/`parse` hádže `InvalidSeriesFormat`: uložený neplatný formát = odmietnutie vystavenia, nie ticho.

---

### `DocumentStore.php` (service, bytea)

**Analóg:** `AuditLogger::record` (insert cez `$this->db->table(...)->insert()` na zdieľanom spojení) a RESEARCH Pattern 6 (overený round-trip). Pre `bytea` **nepoužívať Query Builder s binárnymi dátami**: `decode(:hex:, 'hex')` s `bin2hex($pdf)`, čítanie `pg_unescape_bytea($row['content'])`, vždy overiť `hash_equals($row['sha256'], hash('sha256', $bytes))`. JSONB cez `CAST(:snapshot: AS jsonb)` (nie `::jsonb`, kolízia s `:name:`). `AuditLogger::encode` ukazuje kódovanie: `json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)`.

---

### `InvoicePdfRenderer.php` (service, transform)

**Analóg:** `scripts/spikes/mpdf.php` (konfigurácia mPDF, `tempDir` = `writable/mpdf/...`, fonty) a `tests/integration/MpdfSpikeTest.php` (overenie `pdftotext`, diakritika `łńěťžô`). Pred písaním prečítať celý `scripts/spikes/mpdf.php` od riadku 60 (konfigurácia `new Mpdf([...])`), tu nebol vložený. Pravidlá z RESEARCH: `tempDir` `writable/mpdf`, `SetTitle/SetAuthor/SetCreator('Task Tracker inv-1')`, všetky hodnoty v šablóne cez `esc()`, dátumy len zo snapshotu (nie `date()`), sumy formátovať vlastnou funkciou nad reťazcami, nie `NumberFormatter` (ICU zmena po rebuilde obrazu). Rozhranie `PdfRenderer` nutné pre `FakePdfRenderer` v testoch súbehu.

---

### `IssueRejected.php` (exception)

**Analóg:** `Work/AllocationLocked.php`
```php
final class AllocationLocked extends RuntimeException
{
    public function __construct(public readonly int $allocationId)
    {
        parent::__construct('Allocation ' . $allocationId . ' is locked by an issued invoice');
    }
}
```
Rovnaká štruktúra: `final`, `RuntimeException`, `public readonly string $reason` (kódy `already_issued`, `preview_stale`, `currency_mismatch`, `backdate_unconfirmed`, `sequence_overflow`, `legal_text_missing`, `no_bank_account`). Doménové výnimky Master vrstvy (`LegalTextMissing`, `NoBankAccount`, `SeriesRejected`) majú rovnaký tvar.

---

### `ExternalInvoiceLocker.php` + `app/Commands/InvoicesLockHistorical.php` (WR-07)

**Analóg:** `app/Commands/ImportExcel.php` a `Work/Import/ImportRunner.php`

**Príkaz** (ImportExcel riadky 15-80):
```php
final class ImportExcel extends BaseCommand
{
    protected $group       = 'Import';
    protected $name        = 'import:excel';
    protected $description = '...(predvolene suchý beh, zápis s --commit).';
    protected $usage       = 'import:excel <path> --client <id> [--commit]';
    protected $options     = ['--client' => '...', '--commit' => '...'];

    public function run(array $params): int
    {
        $clientId = $params['client'] ?? CLI::getOption('client');
        $commit   = array_key_exists('commit', $params) || CLI::getOption('commit') !== null;
        if (! is_string($clientId) || preg_match('/^\d{1,18}$/D', $clientId) !== 1) {
            CLI::error('Použitie: ' . $this->usage);
            return EXIT_ERROR;
        }
        ...
        if (! $commit) {
            CLI::write('Suchý beh, nič sa nezapísalo. Spustite znova s --commit.', 'yellow');
            return EXIT_SUCCESS;
        }
        try { ... } catch (DomainException|InvalidArgumentException|RuntimeException $e) {
            CLI::error($e->getMessage());
            return EXIT_ERROR;
        }
```
Nový príkaz `invoices:lock-historical --client --month [--ref] [--expect-parts --expect-hours --expect-amount] [--commit]` (skupina napr. `Invoices`). **Rozdiel:** nesmie mať guard `class_exists(IOFactory::class)` (PhpSpreadsheet je dev-only; príkaz musí bežať aj v produkčnom obraze). Validácia vstupu regexmi s `/D` príznakom. Pozor: v ImportExcel sa `--commit` musí zadať až za ostatné argumenty (poznámka v `$options`); pre nový príkaz používať iba pomenované voľby.

**Runner:** `ImportRunner::run` (riadok 40): `return Transaction::run($this->db, function () ...)` s `ClientService($this->db, $this->audit)` vstrekovaným na rovnaké spojenie. Použiť rovnaký vzor + rovnaké poradie zámkov ako `InvoiceIssuer`, `lock_allocations()` a `AuditLogger::record('invoice', $id, 'external_lock', ...)`. V CLI `AuditLogger` zapíše `ip = 'cli'` a `user_id = null` automaticky.

---

### `InvoiceQueries.php` (service, read)

**Analóg:** `Work/TaskQueries.php` (list/detail bez zápisu, hydratácia reťazcov; `LastClient` pre vzor jednoduchého `Database::connect()` + `getRow()`). Zoznam: vymenovať stĺpce, **nikdy `SELECT *` z `invoice_documents`** ani `snapshot` v zozname. Typy: `trim((string) $row['currency'])` pre `char(n)` (vzor `AllocationService::hydrate`, riadky 207-221), `numeric` ostáva ako `string`.

---

### `SeriesService::isFormatLocked` (úprava)

**Analóg:** sám súbor, riadky 159-165 (stub `return false;`). Nová implementácia podľa RESEARCH: `EXISTS (SELECT 1 FROM invoices WHERE series_id = :s)`. Volanie je už v `update()` (riadok 127) pod zámkom radu `FOR UPDATE` (riadok 104), takže je konzistentné s `NumberAllocator`. Aktualizovať docblock (odstrániť "V Phase 3 este neexistuju vystavene doklady").

---

### `app/Controllers/Invoicing.php` (controller, request-response + htmx)

**Analóg:** `app/Controllers/Allocations.php`

**Imports + `final class ... extends BaseController`** (riadky 1-20). Reuse `BillingMonth`, `LastClient`, `AllocationService`, `ClientService`, `PageNotFoundException`, `IncomingRequest`.

**Čítanie vstupu a validácia → 422 s fragmentom** (riadky 33-49):
```php
$request = $this->request;
assert($request instanceof IncomingRequest);
$raw    = AllocationInput::fromPost($request);
$errors = AllocationInput::validate($raw);
if ($errors !== []) {
    return $this->panel(422, $task, null, 'add', $this->formValues($raw), $errors, false);
}
```
Vytvoriť `IssueInput::fromPost/validate/normalize` podľa `AllocationInput` (statické metódy, chyby ako `array<string,string>`). Texty chýb iba cez `lang()`.

**Zachytenie domény na stavové kódy** (riadky 72-84):
```php
try {
    (new AllocationService())->update($id, AllocationInput::normalize($raw));
} catch (AllocationLocked) {
    return $this->panel(409, ...);
} catch (InvalidArgumentException) {
    throw PageNotFoundException::forPageNotFound(lang('Allocations.notFound'));
}
```
Mapovanie `IssueRejected`: `already_issued`/`preview_stale` → 409, `backdate_unconfirmed` a validácie → 422, ostatné (mena, zákonný text, sekvencia) → 422 `alert-danger`. Presun časti z náhľadu volá `AllocationService::update` a zachytáva `AllocationLocked` → 409.

**Úspech: HX-Location, nie 302** (riadky 109-122):
```php
session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang($flashKey)]);
if (is_htmx()) {
    return $this->response->setStatusCode(200)
        ->setHeader('HX-Location', '/tasks/' . $taskId)
        ->setHeader('HX-Trigger', 'allocationChanged')
        ->setBody('');
}
return redirect()->to('/tasks/' . $taskId);
```
Pre vystavenie: `HX-Location` na `/invoices/{id}`, flash „Faktúra {číslo} bola vystavená.“; pre presun časti `HX-Trigger: allocationChanged`.

**Fragment vs celá stránka** (riadky 150-157):
```php
$fragment = is_htmx_fragment_request() && service('request')->getHeaderLine('HX-Target') === 'sheet-body'
    ? $data['inner'] : 'tasks/_sheet';
return $this->response->setStatusCode($status)
    ->setHeader('Vary', 'HX-Request')
    ->setBody(htmx_view($fragment, $data, 'tasks/sheet_page'));
```
`htmx_view($fragment, $data, $page)` z `app/Helpers/htmx_helper.php`; `Vary: HX-Request` povinné pri všetkých odpovediach, ktoré sa líšia podľa htmx (UI-SPEC: `Vary: HX-Request`). Náhľad `/invoices/new` je GET formulár (funguje bez JS), fragment `#inv-preview` rovnako cez `htmx_view`.

**IDOR/404:** `load()` (riadky 190-198): neexistujúce ID = `throw PageNotFoundException::forPageNotFound(lang('...notFound'))`.

**Formátovanie čísel:** `numberFormat()` (riadky 203-217) a `MoneyFormat::format($amount, $currency)` v views, `Hours::format($hours, $locale)`.

---

### `app/Controllers/Invoices.php` (controller, detail + PDF)

**Analóg:** `app/Controllers/Tasks.php` (`index`: `htmx_view('tasks/_list', [...], 'tasks/index')` + `Vary`, riadky 24-43; `show` s `PageNotFoundException`). PDF endpoint nemá analóg: použiť RESEARCH Pattern 7 (`Content-Type: application/pdf`, `Content-Disposition` so sanitizovaným názvom `[^A-Za-z0-9._-]` → `_`, `Cache-Control: private, no-store`, `ETag` = sha256, `X-Content-Type-Options: nosniff`; pri nezhode hash 500 + log, nikdy regenerovať). Odpoveď vždy cez `$this->response->setHeader(...)->setBody($bytes)`.

---

### `app/Config/Routes/invoices.php`

**Analóg:** `app/Config/Routes/tasks.php` (statické cesty pred `(:num)`; súbory načítava `glob(APPPATH . 'Config/Routes/*.php')` v `Routes.php`, takže netreba registrovať):
```php
use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// Statické cesty pred (:num).
$routes->get('tasks', 'Tasks::index');
$routes->get('tasks/new', 'Tasks::new');
$routes->get('tasks/(:num)', 'Tasks::show/$1');
```
Trasy: `GET invoices`, `GET invoices/new` (pred `(:num)`), `GET fragments/invoice-preview`, `POST invoices/issue`, `POST invoices/preview-move`, `GET invoices/(:num)`, `GET invoices/(:num)/pdf`. Všetky zápisy POST + CSRF (`HtmxCsrf`), za `authgate` (existujúce filtre v `Config/Filters.php`, overiť že nové cesty ich dedia ako `tasks/*`).

---

### Views `invoicing/*`, `invoices/*` (view, htmx fragment)

**Analógy:** `app/Views/tasks/_sheet.php` (spodný panel), `tasks/_list.php` (fragment zoznamu), `tasks/_allocations.php` (riadky `.work-alloc`, zámok), `layouts/_nav_items.php`.

**Spodný panel** (`_sheet.php` riadky 15-27): `offcanvas offcanvas-bottom work-sheet`, `aria-labelledby`, `btn-close`, obsah v `#sheet-body` cez `$this->include($inner)`. Potvrdenie vystavenia a presun časti použiť rovnakú kostru (UI-SPEC: `.work-sheet`).

**Zamknutá časť** (`_allocations.php` riadky 25-28): inline SVG zámok `aria-hidden="true"` + `badge text-bg-secondary`; v Phase 5 nahradiť text `lang('Allocations.lockedBadge')` odkazom „Zamknuté · FA {číslo}“ (`/invoices/{id}`), pri externej „Zamknuté · externá faktúra“. Riadok nemá žiadny ovládací prvok. `TaskQueries::detail` musí vrátiť `invoice_id`, `invoice_number`, `invoice_origin` pre každú časť (LEFT JOIN `invoices`).

**Prázdny stav + meta riadok** (`_list.php` riadky 28-40): `p.small.text-body-secondary` + `section.app-card` s `fw-semibold` nadpisom a `text-muted` telom z `lang()`.

**Pravidlá (testy `ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`):** žiadne inline `style=""`/`onclick`/`<script>`, všetky texty `esc(lang('...'))`, kľúče v `sk` aj `en`; `esc($x, 'attr')` v atribútoch. Všetky sumy `MoneyFormat::format($amount, $currency)`.

**Navigácia** (`layouts/_nav_items.php` riadok 10): nahradiť
```php
<span aria-disabled="true"><?= esc(lang('App.navInvoices')) ?></span>
```
za odkaz v štýle riadku `/tasks`:
```php
<a href="/invoices"<?= $path === '/invoices' || str_starts_with($path, '/invoices/') ? ' aria-current="page"' : '' ?>><?= esc(lang('App.navInvoices')) ?></a>
```
(`$path` je už v súbore definované). Kľúč `App.navInvoices` už existuje.

---

### `app/Views/pdf/invoice_inv1.php` — bez analógu

Žiadna existujúca šablóna pre mPDF. Použiť RESEARCH (Pitfall 4, Security Domain): iba snapshot ako vstup, `esc()` všetkých hodnôt, žiadne externé zdroje, dátumy zo snapshotu, `labels` zo snapshotu (nie `lang()` v čase renderu). Overovanie: `pdftotext` v teste (vzor `MpdfSpikeTest::testPdfContainsDiacritics`, preskočí sa, ak `command -v pdftotext` je prázdne).

---

### `app/Language/{sk,en}/Invoicing.php`, `Invoices.php`

**Analóg:** `app/Language/sk/Allocations.php`
```php
<?php

declare(strict_types=1);

return [
    'errorMonth'  => 'Zvoľte mesiac v rozsahu od {0} do {1}.',
    'errorLocked' => 'Časť je na vystavenej faktúre a nedá sa meniť ani presunúť. Zmena je možná až po dobropise.',
    'notFound'    => 'Časť sa nenašla. Obnovte stránku.',
```
Číselné placeholdery `{0}`, `{1}`; kľúče camelCase; texty podľa tabuľky Copywriting v 05-UI-SPEC.md. Pri každom kľúči rovnaký v `en/`.

---

### Testy: integrácia pod `app_rw`

**Analóg:** `tests/integration/Work/AllocationGuardTest.php`, `tests/_support/PgTestCase.php`, `tests/_support/MasterFixtures.php`

**Kostra** (AllocationGuardTest riadky 1-37):
```php
#[Group('integration')]
final class AllocationGuardTest extends PgTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }
```
Hodiny vždy `FrozenClock` cez `Services::injectMock('clock', ...)`. `owner()` = `app_owner` (DDL, fixtures cez raw SQL), `rw()` = `app_rw` (testované služby a oprávnenia). **Testy číslovania a nemennosti musia bežať cez `rw()`** (RESEARCH Pitfall 7).

**Assert DB chyby** (riadky 39-49) a `PgTestCase::assertPermissionDenied` (regex `/permission denied|must be owner/i`):
```php
$this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE invoices SET total = 1 WHERE id = ?', [$id]));
```
Pre `invoices` (owner UPDATE) očakávať text triggeru `immutable_guard`, pre `alloc_guard` `/is locked/`.

**Fixtures** (`MasterFixtures`): `createEntity($country, $overrides)` cez `EntityService` pod `rw()` (SK: `sk_non_vat`, `due_days` `'14'`; PL: `pl_exempt_113_1`), `createClient`, `createTask`, `createAllocation($taskId, $hours, $month, $overrides)` (raw INSERT pod ownerom, vracia id), `lockAllocation($id, $invoiceId = 1)` (priamy UPDATE ownerom).

**Povinné úpravy existujúcich testov (Pitfall 1):**
- `resetMasterTables()` (riadky 22-36): do poľa `['audit_log', 'entity_bank_accounts', ...]` pridať **pred** `billing_allocations`: `invoice_documents`, `invoice_lines`, `invoices`, `invoice_counters`. Zachovať `to_regclass` kontrolu a `TRUNCATE ... RESTART IDENTITY CASCADE`. Nikdy netruncovať `legal_profiles`/`legal_texts` (seed migrácií).
- `lockAllocation()` (`UPDATE billing_allocations SET invoice_id = 1`) po FK padne: nahradiť `IssueFixtures::createLockedAllocation()`, ktorá vloží skutočnú alebo externú faktúru pod ownerom a nastaví `invoice_id` (a zachová `AllocationGuardTest`, `AllocationServiceTest`, `TaskBillingViewTest`, ktoré ju používajú).
- `AllocationGuardTest` test „app_rw nemôže `UPDATE billing_allocations SET invoice_id = 1`“ zostáva (permission denied), lebo sa používa `lock_allocations()`.
- `SeriesServiceTest`: pridať test `isFormatLocked` po faktúre.

**Test súbehu (nový vzor, `proc_open`):** `MpdfSpikeTest::runSpike` (riadok ~80 a ďalej, prečítať pred písaním) spúšťa PHP podproces; rozšíriť na dva workery (`tests/_support/issue_worker.php`) s vlastným spojením `tests` (app_rw), `FakePdfRenderer`, štartom cez `pg_advisory_lock(777)`. Bootstrap CI4 bez PHPUnit cez `system/Test/bootstrap.php` (nie `Boot::bootSpark`: `Undefined constant SUPPORTPATH`). Doplnkový deterministický test cez `pg_send_query` (RESEARCH Code Examples).

---

### Testy: feature (htmx obrazovka)

**Analóg:** `tests/feature/Work/AllocationPanelTest.php`

```php
final class AllocationPanelTest extends AuthTestCase
{
    use MasterFixtures;
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    private function submit(string $url, array $data = [], bool $htmx = true): TestResponse
    {
        $this->withSession();
        $headers = $htmx ? ['HX-Request' => 'true'] : [];
        return $this->withHeaders($headers)->call('post', $url, $data + [csrf_token() => csrf_hash()]);
    }

    private function fragment(string $url): TestResponse
    {
        return $this->withHeaders(['HX-Request' => 'true'])->get($url);
    }
```
Kopírovať `submit()` a `fragment()`; `body(TestResponse)` číta `(string) $result->response()->getBody()`. Kontrola `HX-Location` hlavičky pri úspechu, status 409/422 pri chybách.

---

### `tests/unit/Scripts/BackupScriptTest.php`

**Analóg:** `tests/unit/Scripts/DbResetGuardTest.php`: skript sa kopíruje do dočasného adresára, na `PATH` falošný `docker` (`FAKE_DOCKER` heredoc zapisuje volania do `$FAKE_LOG`), `setUp` vytvára `scripts/` + `bin/` (`mkdir(..., 0o700, true)`), `copy(ROOTPATH . 'scripts/db-reset.sh', ...)`, `chmod 0o700`. Skutočná DB sa nedotkne. Pre backup: falošný `docker` vypíše fixný dump, test kontroluje `pg_restore --list` krok, nenulový exit pri chybe, vytvorenie `.sha256`.

---

### `scripts/backup.sh`

**Analóg:** `scripts/test-db.sh` (`#!/bin/sh`, `set -eu`, `cd "$(dirname "$0")/.."`, `docker compose exec -T db ...`) a `scripts/db-reset.sh` + `scripts/lib-env-guard.sh` (ochrany prostredia). POSIX sh, výstup do `backups/` (pridať do `.gitignore`), `pg_dump --format=custom --no-owner`, `pg_restore --list` ako kontrola čitateľnosti, `sha256sum`. Dokumentácia v `docs/ops/backup.md` vzorovo podľa `docs/ops/import-excel.md`; uviesť, že PDF sú v `bytea`, takže samostatná záloha súborov netreba.

---

## Shared Patterns

### Transakčná hranica a zámky
**Zdroj:** `app/Libraries/Platform/Transaction.php` (riadky 30-70), `AllocationService::lockRow` (181-196)
**Použiť v:** `InvoiceIssuer`, `ExternalInvoiceLocker`, `NumberAllocator` (volaný iba z issuera)
```php
return Transaction::run($this->db, function () use (...) { ... });
```
- `Transaction::run` je vnoriteľná, sama robí `transException(true)->transStart()` a rollback.
- Poradie zámkov: tasks → clients (SHARE) → billing_allocations → entities (SHARE) → invoice_series → čítač. Nikdy opačne (deadlock s `AllocationService`, `ClientService`, `SeriesService`).
- Nikdy nechytať `DatabaseException` vo vnútri transakcie.

### Peniaze a hodiny
**Zdroj:** `app/Libraries/Platform/Money.php`, `Hours.php`, `MoneyFormat.php`
**Použiť v:** `InvoiceCalculator`, `SnapshotBuilder`, views, PDF šablóna
Všetko ako desatinné reťazce, bcmath cez `Money::of/timesHours/sum`; pri `json_decode` bez prevodu na float; formát len cez `MoneyFormat::format`/`Hours::format`. CI4 vracia `numeric` ako string, ponechať.

### Čas
**Zdroj:** `service('clock')->now()->format('Y-m-d H:i:sP')`, `Clock::today()`, `Clock::parseDate($iso)`, `BillingMonth::current()`
**Použiť v:** všade. Žiadne `new DateTime()`/`date()`. Testy cez `Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'))`.

### Audit
**Zdroj:** `app/Libraries/Audit/AuditLogger.php` (`record`, `recordChange`; názov entity/akcie `^[a-z_]{3,40}$`, scrub citlivých kľúčov)
**Použiť v:** `InvoiceIssuer`, `ExternalInvoiceLocker`; zdieľať spojenie volajúceho (`new AuditLogger($this->db)`), aby bol zápis v tej istej transakcii.

### Chyby a mapovanie na HTTP
**Zdroj:** `Allocations.php` riadky 72-84, `AllocationService::translate` riadok 199
Doménová výnimka (`final class ... extends RuntimeException`) → 409 (stav sa zmenil/zamknuté), 422 (vstup), 404 cez `PageNotFoundException::forPageNotFound(lang(...))`. Preklad `DatabaseException` na doménovú výnimku mimo transakcie a podľa textu (`str_contains($e->getMessage(), 'is locked')`).

### htmx odpovede
**Zdroj:** `app/Helpers/htmx_helper.php` (`is_htmx`, `is_htmx_fragment_request`, `htmx_view`), `Allocations::done`
Po POST `HX-Location` (nie 302) + voliteľne `HX-Trigger`; `Vary: HX-Request`; 409 a 422 swapuje existujúci `responseHandling` v `layouts/app.php`; flash cez `session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang(...)])`. CSRF cez `HtmxCsrf` filter.

### Autentifikácia a prístup
Nové routy v `app/Config/Routes/*.php` za `authgate`/`HtmxCsrf` (existujúce globálne filtre, overiť v `app/Config/Filters.php`). Všetky dotazy nesú `client_id`/`entity_id` (IDOR vzor Phase 3: `EntityVersionResolver::find($entityId, $versionId)`, `SeriesService` "kazdy dotaz nesie entity_id").

### Insert-only a nemennosť v DB
**Zdroj:** `docker/postgres/provision.sql` (default privileges `SELECT, INSERT`), `GrantsPrivileges` trait, `CreateAuditLog` (komentár "zámerne bez grantMutable()"), `alloc_guard`
Tabuľky `invoices*` bez akéhokoľvek rozširujúceho grantu; trigger `immutable_guard` ako posledná línia; `COMMENT ON TABLE` vždy.

### Validácia vstupov
**Zdroj:** `Work/AllocationInput.php` (`fromPost`, `validate`, `normalize`), `BillingMonth::parse/inRange`, `Master/PortalUrlTemplate::validate/render`
Mesiac `BillingMonth::parse`, dátumy `Clock::parseDate`, id častí ako `int` (pole pre `= ANY()` zostaviť z `int` castov, nikdy z reťazcov), uuid `request_key` regexom, `external_ref` max 300 znakov a http(s) schéma.

### Texty a i18n
Všetky texty `lang()`, nové súbory `Invoicing.php`, `Invoices.php` v `sk` aj `en`; testy `LangKeysTest`, `ViewsHardcodedTextTest`, `ViewsCspTest` stráži zhodu a CSP.

---

## No Analog Found

| Súbor | Role | Data Flow | Dôvod |
|-------|------|-----------|-------|
| `app/Views/pdf/invoice_inv1.php` | view (mPDF HTML) | transform | Žiadna existujúca PDF šablóna, len spike `scripts/spikes/mpdf.php`; riadiť sa RESEARCH Pitfall 4 a Security Domain |
| Upsert čítača (`NumberAllocator`) | service | upsert + `RETURNING` | V kóde nie je žiadny `ON CONFLICT`; použiť overený SQL z RESEARCH Pattern 1 |
| `lock_allocations()` SECURITY DEFINER, deferred constraint trigger | DB funkcia | guard | Žiadna SECURITY DEFINER funkcia ani constraint trigger ešte nie je; RESEARCH Pattern 4 a Pattern 3 (overené v scratch DB) |
| `tests/_support/FakePdfRenderer.php`, `FaultInjectingRenderer.php`, `issue_worker.php` | test support | — | Žiadne test doubles pre renderer; `proc_open` vzor len v `MpdfSpikeTest` |
| PDF endpoint v `Invoices::pdf` | controller | file download | Žiadny existujúci controller nevracia binárnu odpoveď; RESEARCH Pattern 7 |

## Metadata

**Analog search scope:** `app/Libraries/**`, `app/Controllers/**`, `app/Database/**`, `app/Commands/`, `app/Views/**`, `app/Config/Routes/`, `app/Language/sk/`, `tests/_support`, `tests/integration/Work`, `tests/feature/Work`, `tests/unit/Scripts`, `scripts/`, `docker/postgres/provision.sql`
**Files scanned:** ~35 prečítaných, ~230 v zozname repozitára
**Pattern extraction date:** 2026-10-26
**Poznámka plánovačovi:** `scripts/spikes/mpdf.php` od riadku 60 (konfigurácia mPDF) a `TaskQueries.php` neboli kvôli rozsahu excerptované; pri implementácii `InvoicePdfRenderer` a `TaskQueries::detail` ich prečítať celé.
