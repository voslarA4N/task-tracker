# Phase 9: Dobropisy - Research

**Researched:** 2026-10-31
**Domain:** insert-only doklady, transakcne vystavenie dobropisu v PHP 8.5 / CI4 / PostgreSQL 18 (ziadne nove balicky)
**Confidence:** HIGH (vsetko vychadza z existujuceho kodu a zivej testovacej DB; jedine PL/CZ nazvoslovie a obdobie obratu su otvorene pre uctovnicku)

## Summary

Faza nepridava ziadnu externu zavislost. Cele je to rozsirenie existujuceho vzoru `InvoiceIssuer`: nova sluzba `CreditNoteIssuer` zapisuje riadok `invoices` s `type='credit_note'`, riadky, PDF (`cn-1`), uvolnenie casti a audit v jednej transakcii. Schema (stlpce a CHECK-y) je pripravena z Phase 5; zostava jedna nova migracia (ciastocny unikatny index, vynimka v `alloc_guard`, `SECURITY DEFINER release_allocations()`, uprava `invoice_complete_check`, rozsirenie `invoice_status_v` o stlpce na konci, rozsirenie CHECK-u `email_templates.key` + seed 4 sablon).

Najdolezitejsie zistenie: `app_rw` nema `UPDATE` na `invoices`, takze `SELECT ... FOR UPDATE` na povodnej faktúre konci `permission denied` (overene na `task_tracker_test`). Subeh dvoch dobropisov na tu istu faktúru sa preto riesi `pg_advisory_xact_lock` + ciastocnym unikatnym indexom ako poistkou. Druhe: schema riadkov (`hours <> 0`, `rate >= 0`, `amount = round(hours*rate,2)`) **nutne** vyzaduje zaporne **hodiny** pri kladnej sadzbe (zaporna suma pri kladnych hodinach CHECK nepusti). Tretie: mnozstvo existujucich miest zafarbuje `status` (zoznam, po splatnosti, platby, upomienky, odoslanie, e-mail) a kazde z nich treba prejst, nielen view.

**Primary recommendation:** Jedna migracia `2026-10-31-000001_AddCreditNotes.php` (vsetky DB zmeny naraz, migracny adresar uz ma 37 suborov), `CreditNoteIssuer` skladajuci snapshot dobropisu **z ulozeneho snapshotu a riadkov povodnej faktury** (nikdy zo zivych tabuliek), zamok `pg_advisory_xact_lock` na povodnu faktúru, a stav `cancelled` vyrabat vyhradne v `invoice_status_v`.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
D-01 dobropis = riadok `invoices` (`type='credit_note'`, `origin='issued'`, `corrected_invoice_id`, `correction_reason`, `correction_type`); tabulka ostava insert-only.
D-02 vzdy cele storno, jeden dobropis na povodnu faktúru: ciastocny unikatny index na `corrected_invoice_id WHERE type='credit_note'` + zamok povodnej faktury v transakcii (`pg_advisory_xact_lock` alebo `FOR UPDATE`); dobropis sa nedá stornovat ani vystavit na dobropis.
D-03 jediny zapisovatel `CreditNoteIssuer` (prikaz, nahlad s preview tokenom, `request_key uuid`, jedna transakcia: cislo, riadky, snapshot, PDF, uvolnenie casti, audit).
D-04 cislo z radu `kind='credit_note'` subjektu povodnej faktury, obdobie podla datumu vystavenia dobropisu, cez `NumberAllocator`; nikdy `SEQUENCE`.
D-05 kopia vsetkych riadkov povodnej faktury zo zapornou sumou (tvar rozhodne plan, musi platit `amount = round(hours*rate,2)`), `total = -total`, `line_count` rovnaky; pocita sa z ulozenych riadkov v DB (nie JSONB); sucet = 0,00.
D-06 udaje stran, jazyk, rezim, kod oslobodenia, mena zo snapshotu povodnej faktury; novy snapshot dobropisu s blokom `correction` (cislo a datum povodnej, dovod, typ, `scope='full'`).
D-07 datum vystavenia = dnes (Clock), menitelny, spatny datum s varovanim, nie skorsi ako datum povodnej; datum dodania a `turnover_date` z povodnej, `billing_month` z povodnej, splatnost = datum vystavenia.
D-08 obrat: len ulozit stlpce (`turnover_date`, `counts_toward_limit` z povodnej); vypocet je Phase 10.
D-09 dovod povinny volny text 1 az 500 znakov.
D-10 typ opravy z pevneho zoznamu: `data_error`, `amount_error`, `fx_error`, `order_cancelled`, `other` (enum v kode, preklady v `lang()`); `correction.scope='full'` v snapshote.
D-11 cudzia mena: kurz povodnej faktury (`fx_rate`, `fx_source`, `fx_date`, `fx_table_no`, `fx_date_basis`, blok `fx`), nikdy novy kurz; `total_entity_ccy = -povodna` (kopirovane, nie prepocitane).
D-12 uvolnenie vsetkych casti v tej istej transakcii cez novu `SECURITY DEFINER` funkciu; `alloc_guard` jedina vynimka: `invoice_id: X -> NULL` bez zmeny ostatnych stlpcov a len ak existuje dobropis s `corrected_invoice_id = X`; `app_rw` nedostane `UPDATE (invoice_id)`; uvolnene casti ostavaju v povodnom mesiaci, presun robi existujuca funkcia Phase 4.
D-13 `invoice_lines.source_allocation_id` na povodnej faktúre ostava; stav ulohy a "vyfakturovane hodiny" sa pocitaju len z nestornovanych faktur (overit `task_billing_v`, dashboard).
D-14 externe faktúry (`origin='external'`) sa nedaju stornovat; tlacidlo sa nezobrazi, sluzba odmietne.
D-15 stav sa neuklada; `invoice_status_v` cez `CREATE OR REPLACE VIEW` len pripojenim stlpcov na koniec; `status='cancelled'` ma prednost pred `paid/sent/issued`; stornovana nikdy po splatnosti; dobropis nikdy po splatnosti, bez uhrad a upomienok.
D-16 detail povodnej: "Stornovana dobropisom c. ... zo dna ..." s odkazom; detail dobropisu: "Opravuje fakturu c. ..." s odkazom; PDF povodnej sa nemeni (ziadna pecatka).
D-17 dobropis je povoleny aj pre ciastocne/uplne uhradenu faktúru; nahlad ukaze varovanie so sumou uhrad; uhrady ostavaju, nove sa nedaju pridavat.
D-18 vlastne PDF (`kind='invoice'` na id dobropisu), `sha256`, verzia sablony napr. `cn-1`, renderer, nikdy sa neregeneruje; vykaz sa negeneruje; `invoice_complete_check` sa upravi tak, aby `report` nevyzadoval pre `credit_note`; nadpis podla jazyka dokladu (SK Dobropis, PL Faktura korygujaca, CZ Opravny danovy doklad, EN Credit note); bez QR platby.
D-19 odoslanie rovnakym `InvoiceMailer` / `invoice_sends` na id dobropisu; nova sablona `email_templates.key='credit_note'` v 4 jazykoch, CHECK sa rozsiri migraciou, seed; priloha len PDF dobropisu; adresati `invoice_email` + `cc_emails` klienta.
D-20 audit do `audit_log`; POST s CSRF, dvojkrokovy tok nahlad -> potvrdenie; server overi preview token, `request_key`, `origin='issued'`, typ `invoice`, nestornovana.

### Claude's Discretion
- Tvar zapornych riadkov (zaporne hodiny vs. zaporna suma), pokial platí CHECK riadku a sucet = -povodny.
- Ci `CreditNoteIssuer` zdiela cast `InvoiceIssuer` (trieda/trait) alebo je samostatny.
- Nazvy novych stlpcov pohladu, noveho rendereru a verzie sablony.
- Umiestnenie tlacidla "Vystavit dobropis" a vzhlad nahladu (UI-SPEC, 44 px, jedna ruka).
- Ci po vystaveni ponuknut "Prejst na uvolnene casti" / "Presunut do mesiaca".
- Rozsirenie staging seedu o 1 az 2 dobropisy.
- PHPUnit pokrytie (sucty, cislovanie, subeh, uvolnenie casti, `alloc_guard` vynimka, kopia udajov, kurz, ukonceny subjekt).

### Deferred Ideas (OUT OF SCOPE)
- Ciastkove dobropisy (v2+).
- Evidencia vratenia penazi klientovi (mimo V1).
- KSeF cislo povodnej faktury a FA(3) `DaneFaKorygowanej` / `PrzyczynaKorekty` export (mimo V1, udaje sa ukladaju).
- Otvorene otazky pre uctovnicku (neblokuju): obdobie odpoctu dobropisu od obratu (default obdobie povodnej), nalezitosti PL faktury korygujacej pre neplatitela, presne znenie nadpisov a textov.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| INV-07 | Dobropis je cele storno povodnej faktury so zapornymi polozkami, s vlastnym radom/prefixom; uvolni zamknute casti, aby sa dali fakturovat znova. | `CreditNoteIssuer` (zaporne hodiny, kopia riadkov z `invoice_lines`), `NumberAllocator` na rad `credit_note`, `release_allocations()` + vynimka v `alloc_guard`, partial unique index, `invoice_status_v.cancelled`, PDF `cn-1`, e-mail |
| INV-10 | Dobropis ma povinny dovod, odkaz na cislo a datum povodnej faktury, vlastny datum vystavenia a typ opravy. | stlpce `correction_reason`/`correction_type`/`corrected_invoice_id` (uz v schéme), enum typov, validacia `CreditNoteCommand`, blok `correction` v snapshote, PDF label-y `cn*`, kontrola datumu >= povodny |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Vystavenie dobropisu (zapis, cislo, PDF, uvolnenie) | API / Backend (`CreditNoteIssuer`) | Database (triggery, CHECK, partial unique) | jediny zapisovatel, DB je posledna poistka nemennosti |
| Nemennost a "len jeden dobropis" | Database | API | unikatny index + `alloc_guard` + immutable_guard |
| Stav `cancelled` | Database (view) | API (queries) | stav sa neuklada, odvodzuje sa |
| Nahlad (preview token) | API | Browser (htmx) | rovnaky kod pre nahlad aj vystavenie |
| PDF dobropisu | API (mPDF renderer) | Database (bytea + sha256 CHECK) | ulozene raz, nikdy sa neregeneruje |
| E-mail dobropisu | API (`InvoiceMailer`) | — | SMTP mimo transakcie, existujuci vzor |
| Zoznam / detail / banner | Frontend Server (CI4 views) | — | iba citanie zo snapshotu a view |

## Standard Stack

Ziadna nova kniznica. Pouzije sa vyhradne to, co v repozitari je. [VERIFIED: codebase, composer.json]

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| codeigniter4/framework | 4.7.x (existujuce) | kontroler, view, DB | stack projektu |
| mpdf/mpdf | 8.3.x (existujuce) | PDF `cn-1` | rovnaky `MpdfFactory` ako `inv-2` |
| bcmath (`Money`, `Hours`) | PHP ext | zaporne sumy a hodiny | `Money::of` a `negate()` zaporne hodnoty podporuju; `timesHours` pouziva `HalfAwayFromZero`, rovnako ako PG `round(numeric)`, takze `round(-h*r,2) = -round(h*r,2)` [VERIFIED: Money.php] |
| PHPUnit | ^11.5 (`composer.json`; CLAUDE.md spomina 10.5 pin, repo ma 11.5) | testy | existujuci `PgTestCase` (owner + app_rw spojenie) |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `pg_advisory_xact_lock` na povodnu faktúru | `SELECT ... FOR UPDATE` | **nefunguje**: `app_rw` nema UPDATE na `invoices` -> `permission denied for table invoices` [VERIFIED: zive volanie na `task_tracker_test`, 2026-10-31] |
| zaporne hodiny | zaporna suma pri kladnych hodinach | zaporna suma poruší `CHECK (amount = round(hours*rate,2))` pri `rate >= 0`; nemozne bez zmeny schemy |

**Installation:** nic. **Package Legitimacy Audit:** faza neinstaluje ziadne externe balicky, slopcheck nie je potrebny. Packages removed: none. Packages flagged: none.

## Architecture Patterns

### System Architecture Diagram

```
GET /invoices/{id}/credit-note --> CreditNotes::new
   |  nacita original (invoices + invoice_lines + status view + uhrady)
   |  guard: origin=issued, type=invoice, nie cancelled  --> inak 404/409
   v
CreditNoteDraftService::preview(origin, issueDate, reason, type)
   |  snapshot = CreditNoteSnapshotBuilder::build(original row, original lines, original snapshot, input)
   |  previewToken = InvoiceCalculator::previewToken(snapshot)
   v
GET .../confirm (sheet)  --> POST /invoices/{id}/credit-note (CSRF, request_key, token, reason, type, issue_date)
   v
CreditNoteIssuer::issue(CreditNoteCommand)   [Transaction::run]
   1 assertNotIssued(request_key)
   2 pg_advisory_xact_lock(hashtext('credit_note'), original_id)       <- poradie zamkov
   3 SELECT original (bez FOR UPDATE), over: origin, type, nie uz dobropis (EXISTS corrected_invoice_id)
   4 SELECT task ids of original lines -> tasks FOR UPDATE ORDER BY id   (len ak existuju)
   5 invoice_series (entity povodnej, kind=credit_note) FOR UPDATE ; backdate guard
   6 snapshot = build(...); hash_equals(previewToken)
   7 NumberAllocator::next(series, issueDate) ; withNumber (bez QR)
   8 render cn-1 PDF
   9 INSERT invoices (credit_note) ; INSERT invoice_lines (negovane) ; DocumentStore::store(kind='invoice')
  10 SELECT release_allocations(credit_note_id)   -> n  (SECURITY DEFINER, alloc_guard vynimka)
  11 audit_log 'credit_note_issue'
  12 SET CONSTRAINTS ALL IMMEDIATE  (invoice_complete_check, bez report pre credit_note)
   v
PRG -> GET /invoices/{credit_note_id} (flash + pocet uvolnenych casti)
   v
existujuce /invoices/{id}/send  --> InvoiceMailer (template 'credit_note', len PDF dobropisu)
```

### Recommended Project Structure
```
app/Libraries/Invoicing/
├── CreditNoteIssuer.php           # jediny zapisovatel dobropisov
├── CreditNoteCommand.php          # validovany vstup (readonly, vzor IssueCommand)
├── CreditNoteRejected.php         # dovody: not_found, not_creditable, already_credited, preview_stale, date_before_original, backdate_unconfirmed, invalid_series_format, ...
├── CreditNoteSnapshotBuilder.php  # origin snapshot + riadky -> snapshot dobropisu (schema 4)
├── CorrectionType.php             # PHP enum (string backed) 5 typov
├── MpdfCreditNoteCn1Renderer.php  # sablona cn-1, registrovat v RendererRegistry
app/Views/pdf/credit_note_cn1.php, resources/pdf/credit-note-cn1.css
app/Controllers/CreditNotes.php    # new / preview fragment / confirm / issue
app/Config/Routes/credit_notes.php # (alebo doplnit invoices.php)
app/Language/{sk,pl,cs,en}/CreditNotes.php, + doplnky InvoicePdf.php, Invoices.php, Deliveries.php, EmailTemplates.php
app/Database/Migrations/2026-10-31-000001_AddCreditNotes.php   # JEDEN subor
```

### Pattern 1: Zaporne riadky z ulozenych riadkov povodnej
**What:** citat `invoice_lines` povodnej (nie JSONB, D-05), `hours` -> `-hours`, `amount` -> `Money::of(amount)->negate()`, `rate` bez zmeny, `position`, `title`, `task_ref`, `portal_url`, `estimated_hours`, `vat_code` kopirovane. `source_allocation_id`/`source_task_id`: **`source_allocation_id = NULL`** na riadkoch dobropisu (stlpec je nullable), `source_task_id` kopirovat. Dovod: `InvoiceDraftService` (riadok ~286) a `_preview.php` pracuju so `source_allocation_id` ako identifikatorom "ktoru cast riadok pokryva"; druhy riadok s tym istym id by mohol povodnu cast ukazat ako patriacu dvom dokladom. (Pozn.: nebolo overene, ze to nieco rozbije; ide o preventivnu volbu, `lock_allocations` ju nepotrebuje, kedze dobropis nezamyka.) Ak planner preferuje kopiu, musi test overit, ze zoznam/detail neukaze cast dvakrat.
**Example:**
```php
// Source: existujuci vzor InvoiceCalculator + Money (app/Libraries/Platform/Money.php)
foreach ($originalLines as $l) {
    $hours  = '-' . ltrim($l['hours'], '-');                     // hours <> 0 je garantovane CHECK-om
    $amount = Money::of($l['amount'])->negate()->toDecimalString();
    // kontrola: Money::of($l['rate'])->timesHours($hours)->equals(Money::of($amount))  (inak throw)
}
$total = Money::sum(...array_map(fn($a) => Money::of($a), $amounts))->toDecimalString(); // == '-' . original.total
```
PHP `Hours::of`/`Hours::plus` **nepodporuju zaporne hodnoty** (regex `^\d{1,5}...`): sucet hodin pre `totals.hours` pocitat z povodnych kladnych hodin a znegovat retazcom, nie cez `Hours::plus` so zapornymi vstupmi. `PdfFormat::hours/amount` a `MoneyFormat::format` zaporne hodnoty zvladaju. [VERIFIED: Hours.php, PdfFormat.php, MoneyFormat.php]

### Pattern 2: Snapshot dobropisu zo snapshotu povodnej
**What:** `CreditNoteSnapshotBuilder` zoberie dekodovany `invoices.snapshot` povodnej a meni len: `schema` (4), `type='credit_note'`, `template_version='cn-1'`, `unset(report_template_version, report)`, `number`/`variable_symbol` (dobropis ma VS z cisla, ale `payment.qr = null` a `payment.method` ostava stlpcom NOT NULL, QR sa negeneruje, `withNumber` z `SnapshotBuilder` sa NEvola lebo vola QR builder), `issue_date`, `due_date = issue_date`, `delivery_date` povodna, `lines`, `totals` (zaporne, `line_count` rovnaky), `fx.total` = `-original fx.total` a `fx.text.total_value` prerendrovat `PdfFormat::amount(zaporna, entityCcy, lang)`, `labels` doplnit o kluce `cn*` v jazyku dokladu z `lang('InvoicePdf.cn...', [], $lang)` (**labely sa ukladaju do snapshotu**, renderer nikdy nevola `lang()`), a novy blok `correction`: `{original_id, original_number, original_issue_date, reason, type, scope:'full'}`.
**Prečo zo snapshotu:** entity/klient/banka/pravne texty/`vat_code` ostanu bitovo rovnake ako na povodnej a funguje pre ukonceny subjekt a zmeneneho klienta (D-06, ENT-04). `SnapshotBuilder::build()` sa **nevolat** (vola `versions->atDate`, `legal->profile`, banku zo zivych tabuliek).
**Preview token:** `InvoiceCalculator::previewToken($snapshot)` je generický (sha256 nad snapshotom bez `number`, `variable_symbol`, `payment`); mozno ho pouzit nezmeneny. Pozor: snapshot musi byt vo vstupe deterministicky (poradie klucov, riadky `ORDER BY position`).

### Pattern 3: Poradie zamkov
Existujuce poradie: tasks -> clients -> casti -> subjekt -> rad -> citac. Pre dobropis: advisory zamok povodnej (novy, na zaciatku, nikto iny ho neberie, takze deadlock s `InvoiceIssuer` nevznikne) -> `tasks FOR UPDATE ORDER BY id` (ak ich riadky existuju; ulohy sa nemazu, ale `source_task_id` moze byt NULL) -> `invoice_series FOR UPDATE` subjektu (ako `InvoiceIssuer`, ORDER BY id, vsetky rady subjektu) -> `invoice_counters` cez `NumberAllocator`. Uvolnenie casti robi UPDATE na `billing_allocations` riadkoch az po vlozeni dobropisu. [VERIFIED: InvoiceIssuer.php]

### Pattern 4: DB vynimka a uvolnenie casti (migracia)
```sql
-- Source: rozsirenie 2026-10-27-000003_LockAllocationsToInvoices.php
CREATE OR REPLACE FUNCTION alloc_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.invoice_id IS NOT NULL THEN
    -- jedina vynimka (Phase 9, D-12): uvolnenie casti stornovanej faktury
    IF TG_OP = 'UPDATE' AND NEW.invoice_id IS NULL
       AND NEW.task_id = OLD.task_id AND NEW.hours = OLD.hours AND NEW.billing_month = OLD.billing_month
       AND NEW.rate = OLD.rate AND NEW.amount = OLD.amount AND NEW.currency = OLD.currency
       AND NEW.created_at = OLD.created_at AND NEW.updated_at = OLD.updated_at
       AND EXISTS (SELECT 1 FROM invoices cn WHERE cn.type = 'credit_note' AND cn.corrected_invoice_id = OLD.invoice_id) THEN
      RETURN NEW;
    END IF;
    RAISE EXCEPTION 'allocation % is locked', OLD.id USING ERRCODE = 'integrity_constraint_violation';
  END IF;
  ... (zvysok bez zmeny, vratane NEW.invoice_id IS NOT NULL kontroly)
END $$;

CREATE FUNCTION release_allocations(p_credit_note_id bigint) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_count integer;
BEGIN
  UPDATE billing_allocations a SET invoice_id = NULL
  FROM invoices cn
  WHERE cn.id = p_credit_note_id AND cn.type = 'credit_note' AND a.invoice_id = cn.corrected_invoice_id;
  GET DIAGNOSTICS v_count = ROW_COUNT;
  RETURN v_count;
END $$;
REVOKE ALL ON FUNCTION release_allocations(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION release_allocations(bigint) TO app_rw;
```
`down()` obnovi povodne telo `alloc_guard` a zahodi funkciu. Nedavat `GRANT UPDATE (invoice_id)` (existujuci `AllocationGuardTest` to strazi). Poznamka: porovnanie `NEW.x = OLD.x` pouziva NOT NULL stlpce, `IS DISTINCT FROM` nie je potrebne, ale konzistentnost so stylom suboru je lepsia: pouzit `IS NOT DISTINCT FROM`.

### Pattern 5: Ostatne DB zmeny v tej istej migracii
- `CREATE UNIQUE INDEX invoices_one_credit_note_uq ON invoices (corrected_invoice_id) WHERE type = 'credit_note'`.
- `invoice_complete_check()`: `CREATE OR REPLACE` s podmienkou `(v_inv.type = 'invoice' AND v_inv.snapshot_schema >= 2 AND NOT EXISTS report)`. Dobropis stale vyzaduje `kind='invoice'` dokument a `vat_code` na riadkoch.
- Dalsie CHECK-y (odporucane, lacne): `CHECK (type <> 'credit_note' OR (origin = 'issued' AND correction_reason IS NOT NULL AND char_length(btrim(correction_reason)) >= 1 AND correction_type IS NOT NULL))` a `CHECK (type <> 'credit_note' OR total <= 0)`; trigger/funkcia pre "povodna musi byt `type='invoice'`, `origin='issued'`" (CHECK nevie dotaz; bud deferred constraint trigger, alebo len servisna kontrola + test). Planner rozhodne; minimum je servisna kontrola + test.
- `email_templates`: `ALTER TABLE email_templates DROP CONSTRAINT email_templates_key_check; ADD CONSTRAINT ... CHECK (key IN ('invoice','reminder','credit_note'))` (nazov overeny v zivej DB) + seed 4 riadkov (owner migracia, `now()`). `down()` musi najprv `DELETE FROM email_templates WHERE key='credit_note'`.
- `invoice_status_v`: `CREATE OR REPLACE VIEW` zachova prvych 22 stlpcov a pridava na koniec napr. `credit_note_id`, `credit_note_number`, `credit_note_issue_date`, `corrected_invoice_id`, `corrected_number`, `corrected_issue_date` (LEFT JOIN `invoices cn ON cn.corrected_invoice_id = i.id AND cn.type='credit_note'` a `invoices o ON o.id = i.corrected_invoice_id`). `status` CASE: `external` > `cancelled` (cn.id IS NOT NULL a i.type='invoice') > `paid` > `partially_paid` > `sent` > `issued`. Odporucanie: `balance` pre `cancelled` vratit `0.00` (zachova typ `numeric(12,2)`, nemeni poradie stlpcov), aby kazda existujuca podmienka `balance > 0` (upomienky, po splatnosti, dashboard) automaticky vynechala stornovane; `paid_amount` ostane skutocny. [ASSUMED: tuto zmenu semantiky `balance` treba potvrdit s vlastnikom, viz Assumptions A2.] Aj tak pridat explicitne `status <> 'cancelled'` do dotazov (obrana do hlbky).
- `GRANT SELECT ON invoice_status_v TO app_rw` po `CREATE OR REPLACE` ostava (grant sa zachova pri REPLACE).

### Anti-Patterns to Avoid
- **`SELECT ... FOR UPDATE` na `invoices`** z `app_rw`: permission denied. Pouzit advisory lock.
- **`SnapshotBuilder::build()` pre dobropis**: cita zive tabulky (`entity_versions at date`, legal profil, banku) a zlyha pre ukonceny subjekt (`entity_inactive`) a zmeni texty. Pouzit kopiu snapshotu povodnej.
- **`InvoiceGuards::assertEntityActive`** nevolat (storno aj pre ukonceny subjekt, ENT-04). `backdate()` naopak volat nad radom `credit_note`.
- **Stav `cancelled` ulozeny v stlpci / UPDATE povodnej:** `invoices` je insert-only a trigger to zablokuje.
- **Nove `FxService` volanie / `fxBlock` s novym kurzom:** kurz sa bere z povodnej; `SnapshotBuilder::fxBlock` volat nemozno (pocita `FxConverter::convert`, co by pri zapornom totale mohlo zaokruhlit inak). Hodnoty `total_entity_ccy` negovat, nie prepocitat.
- **Regeneracia PDF povodnej / pecatka STORNO:** uloženy bytea sa nemeni.
- **Zdielanie `IssueRejected` bez rozsirenia:** `REASONS` je konstantna zoznam; bud novy `CreditNoteRejected`, alebo doplnit dovody a upravit pouzivatelov (`Invoicing` controller mapuje dovody na texty).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Cislovanie | vlastny counter | `NumberAllocator::next` s riadkom `credit_note` radu | bez medzier, riadkovy zamok, existujuce testy |
| Ulozenie PDF + sha256 | vlastny insert | `DocumentStore::store/read/meta` | CHECK sha256, verify pri citani |
| Odoslanie e-mailu | vlastny mailer | `InvoiceMailer` + `invoice_sends` | idempotencia `request_key`, audit, history |
| Idempotencia | vlastny flag | `request_key uuid UNIQUE` + `InvoiceIssuer::isUniqueViolation` | vzor z Phase 5 |
| Formatovanie zapornych sum | vlastne formatovanie | `PdfFormat::amount` (PDF), `MoneyFormat::format` (UI) | zvladaju znamienko |
| Transakcia | vlastny BEGIN/COMMIT | `Transaction::run` | rollback vrati aj citac |
| Stav faktury | stlpec/flag | odvodenie v `invoice_status_v` | Pitfall 20, insert-only |
| Preview token | vlastny hash | `InvoiceCalculator::previewToken` | rovnake pravidla vynechanych klucov |

**Key insight:** dobropis je 90 % kopia existujucich mechanizmov; riziko je v okrajoch (stav, platby, e-mail, testy asertujuce pocet stlpcov/sablon), nie v jadre.

## Common Pitfalls

### Pitfall 1: `FOR UPDATE` na insert-only tabulke
**What goes wrong:** `permission denied for table invoices`. **Why:** `app_rw` nema UPDATE (ani stlpcovy). **How to avoid:** `pg_advisory_xact_lock(hashtext('credit_note'), (int) $originalId)` + unikatny index ako poistka; zachytit `unique_violation` na `invoices_one_credit_note_uq` a mapovat na `already_credited` (vzor `isUniqueViolation`; nazov constraintu pouzit presne). **Warning signs:** 500 pri prvom vystaveni.

### Pitfall 2: Druhy dobropis pri subehu / dobropis na dobropis
**What goes wrong:** dva dobropisy na tu istu faktúru, alebo na `type='credit_note'`. **How to avoid:** servisna kontrola `type='invoice' AND origin='issued'` + `NOT EXISTS credit_note` pod advisory lockom + partial unique index. Test s dvoma procesmi (vzor `issue_worker.php` + `IssueConcurrencyTest`): jeden uspeje, druhy `already_credited`, cislo radu bez medzery (rollback vrati counter).

### Pitfall 3: `invoice_complete_check` vyzaduje report
**What goes wrong:** vystavenie dobropisu padne `invoice N is incomplete`, lebo `snapshot_schema >= 2` -> vyzaduje `report`. **How to avoid:** migracia upravi funkciu (podla `type`), nie hodnotou `snapshot_schema`. Aktualny snapshot dobropisu ma schema 4; test na `invoice_complete_check` pre dobropis bez reportu aj pre faktúru bez reportu (stale chyba).

### Pitfall 4: View `invoice_status_v` - nie len pripojit
**What goes wrong:** `CREATE OR REPLACE VIEW` zlyha, ak sa zmeni nazov/typ/poradie existujucich stlpcov. **How to avoid:** zachovat vsetkych 22 stlpcov v rovnakom poradi a type, nove na koniec. Existujuci `InvoiceStatusViewColumnsTest` asertuje zoznam stlpcov -> aktualizovat. Poznamka z komentara: SPL iterator migracii sa pri >30 suboroch na 9p rozbije; repo je teraz na ext4, ale **jeden migracny subor** je stale lacnejsie a bezpecnejsie.

### Pitfall 5: Nezmenene spotrebitelia stavu
Miesta, ktore treba upravit (vsetky overene grepom): 
- `InvoiceStatusQueries::forInvoice/overdue/countOverdue` (overdue podmienka: pridat `status <> 'cancelled'`; dobropis uz je vylucený `type='invoice'`).
- `InvoiceQueries::list` (`$cond` + `in_array($status, [...])` -> pridat `cancelled`, `credit_note` filter; `Invoices::index` whitelist), `InvoiceQueries::detail` (doplnit `corrected_invoice_id`, `correction_reason`, `correction_type`, stlpce z view: kto stornoval/co opravuje; `report_document` je pre dobropis `null`).
- `Invoices::serve`: `report` pre `credit_note` -> 404 (nie 500 "critical"); `DocumentFilename::for` kluce `invoice/report` -> pre dobropis doplnit prefixy podla jazyka (`Dobropis_`, `Faktura_korygujaca_`, `Opravny_doklad_`, `Credit_note_`; UI-SPEC G) cez novy `kind` (napr. `credit_note`) alebo parameter typu. `DocumentFilenameTest` rozsirit.
- `Payments::form/record/impact` a `PaymentService` (assert `not_payable` uz pokryva `type !== 'invoice'`; **doplnit `status = 'cancelled'`** -> `not_payable`/novy dovod; takisto **storno uhrady** `PaymentService::void` pre cancelled faktúru -> odmietnut, D-17 + UI-SPEC D).
- `InvoiceMailer::loadInvoice` (zamieta `type !== 'invoice'` -> povolit `credit_note` pre `invoice`/`resend`, zamietnut `reminder` aj pre `cancelled`), `draft()` (priloha len `invoice` pre dobropis; sablona `credit_note`; adresati zo snapshotu povodnej: pre dobropis klient je rovnaky `client_id`, takze existujuci SELECT z `clients` funguje; ak ma vlastnik zmenu e-mailu klienta, pouzit zivy e-mail je v stave sa Phase 7 chova rovnako), `loadAttachments` (skip `report` pre `credit_note`), `ManualSendService` (riadok 178), `InvoiceDelivery` (riadok 145: `type !== 'invoice'`; `NOT_FOUND_REASONS`).
- `TemplateContext::values` + `TemplateRenderer::PLACEHOLDERS` + `sampleValues`: pridat `original_number`, `original_date` (UI-SPEC H; pre sender pouzit existujuce `entity_name`, nie nove `sender_name`), `EmailTemplateService::KEYS` a `Controllers/Settings/EmailTemplates` + view, `Language/*/EmailTemplates.php`.
- Existujuce testy, ktore pocitaju zaznamy: `EmailTemplatesSeedTest` (8 -> 12 riadkov, riadky 21 a 34), `InvoiceStatusViewColumnsTest`.
- Dashboard (`Home.php` -> `overdue()`) sa opravi uz cez `InvoiceStatusQueries`.

### Pitfall 6: Cislo dobropisu koliduje s cislom faktury
`UNIQUE (entity_id, number)` je pre cely subjekt. Ak vlastnik nastavi rad `credit_note` na rovnaky format ako `invoice` (napr. `RRRRMM###`), vznikne `unique_violation` az pri vystaveni dobropisu (po tom, co sa cislo uz alokovalo, transakcia sa vrati). **How to avoid:** predvoleny prefix `DB-` je v poriadku; doplnit do `Series` nastaveni varovanie/validaciu, alebo zachytit `invoices_entity_id_number_key` a vratit pochopitelnu chybu `number_collision`. Minimum: test + mapovanie chyby. [ASSUMED: konstrukcia kolizie nebola spustena, vyplyva z `UNIQUE(entity_id, number)` v `CreateInvoices`.]

### Pitfall 7: Presun a zmena uvolnenych casti
Po uvolneni ostanu casti v povodnom mesiaci. `AllocationService::moveMonth` aj `lock_allocations` funguju bez zmeny (`invoice_id IS NULL`). **Past:** `lock_allocations` vyzaduje `a.billing_month = i.billing_month` a `a.currency = i.currency` a existenciu `invoice_lines` s `source_allocation_id = a.id` na NOVEJ faktúre; povodne (stornovane) riadky tym nic nenaruszaju, nema unikatny index. Test "faktúra -> dobropis -> presun do iného mesiaca -> nová faktúra tých istých casti" je kluczovy (specifics v CONTEXT).
`TaskQueries` a `task_billing_v` pocitaju z `billing_allocations.invoice_id`, uvolnenie to vyrieši samo (D-13 overene citanim `CreateTaskBillingView`). Detail ulohy `LEFT JOIN invoices i ON i.id = a.invoice_id` tiez bez zmeny.

### Pitfall 8: Datum vystavenia a rad
`NumberAllocator::next` odvodzuje obdobie z `issue_date`; `hasInvoices` pouziva `series_id`. `initial_number` rad `credit_note` plati pre prvy dobropis. `InvoiceGuards::lastIssue/backdate` sa filtruju podla `series_id`, takze backdate varovanie sa vztahuje na rad dobropisov (spravne podla D-07). Navyse nova kontrola: `issue_date >= original.issue_date` (inak `date_before_original`, 422). Dobropis nesmie mat datum v buducnosti? CONTEXT to nespresnuje; `IssueInput`/faktúra to riesi rovnako (odporucit rovnaky pristup ako faktúra; overit pri implementacii).

### Pitfall 9: Mena a kurz - nepocitat
`total_entity_ccy` povodnej je `NOT NULL` pre `issued`. Pre dobropis negovat retazec (`'-' . ltrim($v,'-')`, pozor na `0.00` -> `-0.00`; povodna faktúra s total_entity 0 je nemozna, lebo `line_count >= 1` a `hours <> 0`, ale `rate` moze byt `0` -> total `0.00`: negacia cez `Money::negate()` normalizuje `-0.00` na `0.00` - overene v `Money::norm`; pouzit `Money`, nie retazec). CHECK `invoices_fx_total_check` (bez kurzu `total_entity_ccy = total`) a `invoices_fx_pair_check` platia automaticky.

### Pitfall 10: PDF a mPDF
Renderer `cn-1` musi byt v `RendererRegistry::for()` (inak `InvalidArgumentException` pri prvom vystaveni; `RendererRegistryTest` doplnit). Neukladat `report`. PDF nesmie volat `lang()` ani zive tabulky; vsetky texty zo snapshotu. Zachovat limit 2 s (jeden dokument namiesto dvoch = rychlejsie ako faktúra). `LegacyInv1UnchangedTest`/`InvoiceDocumentTest` ako vzor "stare PDF sa nemeni".

## Code Examples

### Zamok a kontroly na zaciatku transakcie
```php
// Source: vzor InvoiceIssuer::issueInTransaction + zive overenie FOR UPDATE permission (2026-10-31)
$this->assertNotIssued($cmd->requestKey);
$this->db->query("SELECT pg_advisory_xact_lock(hashtext('credit_note'), :id:)", ['id' => $cmd->originalId]);

$orig = $this->db->query('SELECT * FROM invoices WHERE id = :id:', ['id' => $cmd->originalId])->getRowArray();
if ($orig === null) { throw new CreditNoteRejected('not_found'); }
if ($orig['origin'] !== 'issued' || $orig['type'] !== 'invoice') { throw new CreditNoteRejected('not_creditable'); }
$existing = $this->db->query("SELECT id, number FROM invoices WHERE corrected_invoice_id = :id: AND type = 'credit_note'", ['id' => $cmd->originalId])->getRowArray();
if ($existing !== null) { throw new CreditNoteRejected('already_credited', [], (int) $existing['id'], (string) $existing['number']); }
```

### INSERT dobropisu (stlpce)
Rovnaky zoznam stlpcov ako `InvoiceIssuer::insertInvoice`, ale: `type='credit_note'`, `corrected_invoice_id`, `correction_reason`, `correction_type`, hodnoty stran/jazyka/rezimu/banky z **riadka povodnej faktury** (`entity_id`, `entity_version_id`, `client_id`, `currency`, `doc_language`, `vat_regime`, `billing_mode`, `exemption_code`, `entity_vat_id`, `client_*`, `bank_*`, `payment_method`, `counts_toward_limit`, `billing_month`, `delivery_date`, `turnover_date`, `fx_*`), `due_date = issue_date`, `total = -orig.total`, `total_entity_ccy = -orig.total_entity_ccy`, `line_count = orig.line_count`, `series_id` = rad dobropisov, `template_version='cn-1'`, `snapshot_schema = 4`. `counts_toward_limit` boolean cez `DbBool::toDb(DbBool::of(...))` ako vo vzore.

### Audit
`$this->audit->record('invoice', $creditNoteId, 'credit_note_issue', null, ['number', 'corrected_invoice_id', 'corrected_number', 'reason', 'correction_type', 'total', 'currency', 'released_parts', 'sha256', 'fx_*'])`; (vzor `AuditLogger::record`, bez snapshotu a bajtov).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| ciastkove dobropisy | celé storno | rozhodnutie V1 | jednoduchsia schema, jeden dobropis na faktúru |
| stav ako stlpec | odvodeny stav vo view | Phase 5/7 | `cancelled` len vo view |

**Deprecated/outdated:** nie je co.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `source_allocation_id = NULL` na riadkoch dobropisu je bezpecnejsie nez kopia (ziadny konzument nebol overeny ako rozbity kopiou) | Pattern 1 | Ak ma UI zobrazovat vazbu dobropis -> cast, treba kopiu; nizke riziko, stlpec je nullable |
| A2 | `balance` stornovanej faktury vo view = 0.00 (zmena semantiky oproti `total - paid`) | Pattern 5 | Ak vlastnik chce videt "nezaplaten zostatok" aj na stornovanej, treba nechat povodny vyraz a spoliehat sa na explicitny filter `status` vsade |
| A3 | Kolizia cisel `UNIQUE(entity_id, number)` medzi radmi `invoice`/`credit_note` pri rovnakom formate | Pitfall 6 | Mala; ak sa uz validuje v `SeriesService`, odpada |
| A4 | Datum vystavenia dobropisu v buducnosti sa spravá ako pri faktúre (odmietne sa) | Pitfall 8 | Treba overit v `IssueInput` a zjednotit |
| A5 | PL/CS/EN nazvy a texty (`PrzyczynaKorekty`, nadpisy) su predbezne do potvrdenia uctovnickou | UI-SPEC G | Obsahova, nie technicka; texty su v `lang()` |
| A6 | Schema dobropisu snapshotu = 4 (kluc `schema` nema ziadny konzument, ktory by pre >3 padol) | Pattern 2 | Treba grep `snapshot_schema` konzumentov pri implementacii (`Invoices::serve` a mailer pouzivaju `< 2`) |

## Open Questions (RESOLVED)

1. **Ma `balance` stornovanej = 0?** (A2) Odporucanie: ano v view + explicitne filtre; potvrdit pri discuss/plan.
   **RESOLVED:** ano. `invoice_status_v.balance` = 0.00 pre stornovanu fakturu aj pre dobropis (migracia v 09-01, Task 2) a dotazy na pohladavky/po splatnosti/zoznam maju explicitne filtre `status <> 'cancelled'` a `type = 'invoice'` (plan 09-07).
2. **Presna sablona `labels` pre dobropis:** vsetky `LABEL_KEYS` z faktúry plus `cn*`; faktúra uz ma `labels` v snapshote povodnej v **jazyku povodnej** (rovnaky `doc_language`), takze staci len doplnit `cn*` kluce. Pridat `cn*` do `LABEL_KEYS`? Nie: LABEL_KEYS plni `SnapshotBuilder::build` pre fakturu (zmenilo by to `previewToken` a snapshot novych faktur). Pouzit samostatny `CreditNoteSnapshotBuilder::LABEL_KEYS`. (UI-SPEC pise "Nový preklad popiskov do SnapshotBuilder::LABEL_KEYS": **konflikt**, doporucujem NEpridavat do faktúrneho zoznamu; planner nech rozhodne a zosuladi `SampleSnapshotShapeTest`.)
   **RESOLVED:** `SnapshotBuilder::LABEL_KEYS` sa NEMENI (snapshot a `previewToken` faktur ostavaju). Dobropis ma samostatny `CreditNoteSnapshotBuilder::LABEL_KEYS` = popisky povodnej faktury + kluce `cn*` v jazyku dokladu (plan 09-02); `SampleSnapshotShapeTest` sa nemeni. Odchylka od UI-SPEC zapisana v 09-02.
3. **Obdobie odpoctu od obratu** (D-08): len ulozene stlpce; Phase 10 to dopocita.
   **RESOLVED:** odlozene do Phase 10 (Limity obratu) a na potvrdenie uctovnickou. Vo Phase 9 sa per D-08 iba ukladaju stlpce dobropisu: `delivery_date`, `turnover_date`, `billing_month` a `counts_toward_limit` kopirovane z povodnej faktury (D-07, D-08) a `total_entity_ccy` = zaporny sucet povodnej (D-11); ziadny vypocet obratu v tejto faze.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker (php, nginx, db, mailpit kontajnery) | testy, migracie | OK (kontajnery bezia) | — | — |
| PostgreSQL 18 (`task_tracker_test`, role `app_owner`/`app_rw`) | integracne testy | OK | 18 | — |
| PHP 8.5 na hoste (`/usr/bin/php`), composer | vyvoj | OK | — | spustat v kontajneri |
| Mailpit | vizualne UAT e-mailu | OK (kontajner) | — | — |

Missing dependencies: none. Posledna aplikovana migracia v test DB: `2026-10-30-000005`. Nova migracia: `2026-10-31-000001`. Test migracie: `composer test:migrate` (`spark migrate -g tests_owner --all`).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5 (`composer.json`), CI4 `CIUnitTestCase`, `Tests\Support\PgTestCase` (owner + app_rw) |
| Config file | `phpunit.dist.xml` |
| Quick run command | `composer test -- --filter CreditNote` (alebo `vendor/bin/phpunit --testsuite unit` pre unit) |
| Full suite command | `composer check` (stan + cs + test) |

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| INV-07 | dobropis = zaporne riadky, `total=-orig`, sucet 0,00 (mena faktury aj subjektu pri kurze) | integration | `phpunit --filter CreditNoteIssuerTest` | Wave 0 |
| INV-07 | vlastny rad `credit_note`, format `DB-RRRRMM###`, obdobie podla datumu dobropisu, bez medzier po rollbacku | integration | `--filter CreditNoteNumberingTest` | Wave 0 |
| INV-07 | subezne vystavenie dvoch dobropisov na tu istu faktúru: 1 uspech, 1 `already_credited`, counter bez medzery (2 procesy) | integration | `--filter CreditNoteConcurrencyTest` | Wave 0 (+ worker script) |
| INV-07 | uvolnenie casti: `release_allocations` len pre existujuci dobropis; `alloc_guard` vynimka len `X -> NULL` bez zmeny ostatnych stlpcov; `app_rw` priamy `UPDATE invoice_id` = permission denied; zamok/DELETE zamknutych casti dalej padaju | integration | `--filter CreditNoteAllocationReleaseTest` | Wave 0 |
| INV-07 | cyklus faktúra -> dobropis -> presun do iného mesiaca -> nová faktúra tých istých casti; `task_billing_v` a "vyfakturovane hodiny" sedia, kazda cast ma jedinu aktivnu faktúru | integration | `--filter CreditNoteReissueTest` | Wave 0 |
| INV-07 | pôvodna faktúra a jej PDF bajty/sha256/snapshot po dobropise nezmenene; `invoices` UPDATE/DELETE dalej blokovane | integration | `--filter CreditNoteImmutabilityTest` | Wave 0 |
| INV-07 | `invoice_status_v`: `cancelled` ma prednost, stlpce na konci, povodnych 22 beze zmeny; stornovana nie je po splatnosti; dobropis nema uhrady | integration | `--filter InvoiceStatusViewColumnsTest` (rozsirit) + `CreditNoteStatusTest` | rozsirit / Wave 0 |
| INV-07 | uhrady a upomienky na stornovanu faktúru odmietnute, aj storno uhrady | integration | `--filter PaymentServiceTest` (rozsirit) | rozsirit |
| INV-07 | externa faktúra a dobropis sa nedaju stornovat; ukonceny subjekt (`active_to` < dnes) dobropis vystavi | integration | `--filter CreditNoteGuardsTest` | Wave 0 |
| INV-07 | zmena subjektu/klienta po vystaveni nezmeni dobropis (kopia udajov) | integration | `--filter CreditNoteSnapshotImmutableTest` | Wave 0 |
| INV-07 | kurz prevzaty z povodnej (ziadne volanie `FxService`/HTTP), `fx_*` stlpce a `total_entity_ccy = -orig` | integration | `--filter CreditNoteFxTest` | Wave 0 |
| INV-07 | `invoice_complete_check`: dobropis bez reportu OK, faktúra bez reportu stale padne; dobropis bez PDF padne | integration | `--filter CreditNoteCompletenessTest` | Wave 0 |
| INV-07 | rollback pri zlyhani (renderer vyhodi vynimku): ziadny riadok, cislo volne, casti ostavaju zamknute | integration | `--filter CreditNoteRollbackTest` (`FaultInjectingRenderer`) | Wave 0 |
| INV-10 | dovod povinny 1-500 znakov, typ z enumu (CHECK `^[a-z_]{3,30}$`), datum >= povodna, spatny datum vyzaduje potvrdenie | unit | `--filter CreditNoteCommandTest` | Wave 0 |
| INV-10 | blok `correction` v snapshote + stlpce `corrected_invoice_id`, `correction_reason`, `correction_type`; nadpis a texty v 4 jazykoch | integration | `--filter CreditNoteSnapshotBuilderTest` | Wave 0 |
| INV-10 | PDF `cn-1`: obsahuje cislo + datum povodnej, dovod, zaporne riadky, kurz; vlastne `sha256` v `invoice_documents`, ziadny `report`; `LangKeysTest` (vsetky `cn*` kluce v 4 jazykoch) | integration/unit | `--filter MpdfCreditNoteCn1RendererTest`, `LangKeysTest` | Wave 0 / rozsirit |
| INV-07/INV-10 | odoslanie dobropisu e-mailom: sablona `credit_note`, priloha iba PDF dobropisu, `invoice_sends` na id dobropisu, reminder odmietnuty | integration | `--filter CreditNoteMailerTest`, `EmailTemplatesSeedTest` (8 -> 12) | Wave 0 / rozsirit |
| INV-07 | CSP/hardcoded text: nove view bez inline skriptov/stylov a bez literalov | unit | `--filter ViewsCspTest`, `ViewsHardcodedTextTest` | rozsirit (existuju) |
| INV-07 | controller feature: GET nahlad, confirm, POST (CSRF, PRG), 404 pre externu/dobropis, 409 uz stornovana | feature | `--filter CreditNotesControllerTest` | Wave 0 |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit --filter "CreditNote|InvoiceStatusView|AllocationGuard"`
- **Per wave merge:** `composer test`
- **Phase gate:** `composer check` zelene pred `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/_support/CreditNoteFixtures.php` (trait: vystavi faktúru cez `IssueFixtures`, potom dobropis; pomocne assercie sucet = 0) 
- [ ] `tests/_support/credit_note_worker.php` (vzor `issue_worker.php`) pre test suhrady
- [ ] vsetky vyssie "Wave 0" test subory
- [ ] aktualizacia: `InvoiceStatusViewColumnsTest`, `EmailTemplatesSeedTest`, `RendererRegistryTest`, `DocumentFilenameTest`, `LangKeysTest`, `SampleSnapshotShapeTest` (ak sa zmeni LABEL_KEYS)

## Security Domain

### Applicable ASVS Categories (L1)
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (existujuci Shield + TOTP) | — |
| V3 Session Management | no (existujuce) | — |
| V4 Access Control | yes | trasy iba za auth filtrom (ako `invoices/*`); jediny pouzivatel, ale server overi `origin/type/status` pri kazdom POST |
| V5 Input Validation | yes | `CreditNoteCommand` konstruktor validuje id, UUID, datum, dovod (1-500, bez riadiacich znakov), typ (enum), preview token (`^[0-9a-f]{64}$`); server NEPRIJIMA sumy/riadky/kurz z POST |
| V6 Cryptography | no | sha256 PDF cez existujuci `DocumentStore` |
| V8 Data Protection / integrita | yes | insert-only, immutable_guard, partial unique index |
| V13 / CSRF | yes | CI4 CSRF na POST, `HtmxCsrf` pre htmx |

### Known Threat Patterns
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Dvojite odoslanie / replay POST | Tampering | `request_key uuid UNIQUE` (`already_issued` vrati ten isty dobropis) |
| Subezny dvojity dobropis | Tampering | advisory xact lock + partial unique index |
| Obchadzanie nemennosti (priamy UPDATE casti / faktury) | Tampering/Elevation | `app_rw` bez UPDATE (invoice_id), `SECURITY DEFINER` funkcia s uzkou vynimkou v `alloc_guard`; test `DbRolesTest` styl |
| Manipulacia sum/kurzu v POST | Tampering | server berie vsetko z DB; preview token nad snapshotom |
| XSS cez `correction_reason` | Tampering | `esc()` vo views, mPDF: HTML escapovat (`esc` v `credit_note_cn1.php`), textarea bez HTML |
| SQL injection | Tampering | parametrizovane dotazy; `release_allocations(bigint)` s fixnym `search_path` |
| Header injection v e-mail predmete (`{{original_number}}`) | Tampering | existujuci `TemplateRenderer` strip CR/LF; whitelist placeholderov rozsirit len o `original_number`, `original_date` |

## Sources

### Primary (HIGH confidence)
- Codebase (`/home/voslar/projects/task-tracker`): `InvoiceIssuer.php`, `NumberAllocator.php`, `SnapshotBuilder.php`, `InvoiceCalculator.php`, `InvoiceGuards.php`, `DocumentStore.php`, `RendererRegistry.php`, `MpdfInvoiceInv2Renderer.php`, `InvoiceQueries.php`, `InvoiceStatusQueries.php`, `InvoiceMailer.php`, `ManualSendService.php`, `PaymentService.php`, `EmailTemplateService.php`, `TemplateRenderer/Context`, `Money/Hours/MoneyFormat/PdfFormat`, `Controllers/Invoices|Invoicing|InvoiceDelivery|Payments`, migracie `CreateInvoices`, `LockAllocationsToInvoices`, `RequireReportDocument`, `CreateBillingAllocations`, `CreateTaskBillingView`, `CreateEmailTemplates`, `CreateInvoiceSends`, `ExtendInvoiceStatusViewPayments`, `AddInvoiceFxChecks`.
- Zive overenie na `task_tracker_test` (Docker, 2026-10-31): `app_rw` `SELECT ... FOR UPDATE` na `invoices` -> `permission denied`; nazvy constraintov `email_templates_key_check`; posledna migracia `2026-10-30-000005`.
- `.planning/phases/09-dobropisy/09-CONTEXT.md`, `09-UI-SPEC.md`, `./CLAUDE.md`.

### Secondary (MEDIUM)
- PostgreSQL dokumentacia (locking clauses vyzaduju UPDATE privilege) - potvrdene zivym volanim, URL nenacitavana v tomto behu.

### Tertiary (LOW)
- Ziadne.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH (ziadna zmena, vsetko v repo)
- Architecture: HIGH (priamo odvodene z existujuceho kodu a overenej DB)
- Pitfalls: HIGH pre zamky, view, complete_check, mailer; MEDIUM pre kolizie cisel a `source_allocation_id` (A1, A3)

**Research date:** 2026-10-31
**Valid until:** 30 dni (stabilne; menia sa len ak sa zmeni schema invoices/billing_allocations v inej faze)
