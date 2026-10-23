# Architecture Research

**Domain:** fakturácia s nemennými dokladmi + evidencia úloh (self-hosted PWA pre jedného živnostníka), CodeIgniter 4.7 + PostgreSQL + htmx/Alpine
**Researched:** 2026-10-23
**Confidence:** MEDIUM-HIGH (doménové vzory a PostgreSQL techniky HIGH; detaily Shield a CI4 API MEDIUM, označené nižšie; pri rozpore so špecifikáciou platí špecifikácia)

## Základná myšlienka (jedna veta)

Fakturácia je **jediná transakčná hranica**: `InvoiceIssuer` v jednej DB transakcii zamkne časti, postaví kópiu údajov (snapshot), pridelí číslo a vloží nemenné riadky. Všetko ostatné (PDF, e-mail, prehľady, limity) je **odvodené** z výsledku tejto transakcie a dá sa zopakovať alebo prepočítať. Nemennosť sa vynucuje na troch úrovniach naraz: služba, oprávnenia/triggery v DB a testy.

## Standard Architecture

### System Overview

```
┌──────────────────────────────────────────────────────────────────────┐
│ Prehliadač / iPhone PWA   Bootstrap 5.3 + htmx 2 + Alpine (CSP build)│
│ service worker (len statika + offline.html), žiadne dáta offline     │
└───────────────┬──────────────────────────────────────────────────────┘
                │ HTTPS, HTTP/2 (nginx: statika s verziou, X-Accel pre PDF)
┌───────────────▼──────────────────────────────────────────────────────┐
│ HTTP vrstva (app/Controllers, app/Filters, app/Views)                │
│  tenké kontroléry · filtre: auth, 2FA-complete, role, CSRF, throttle │
│  view: plná stránka alebo fragment (htmx) podľa HX-Request           │
└───────────────┬──────────────────────────────────────────────────────┘
                │ volá len služby (nikdy modely priamo pri zápise)
┌───────────────▼──────────────────────────────────────────────────────┐
│ Doménová vrstva (app/Domain/<Kontext>/…)                             │
│                                                                      │
│  Identity     MasterData      Work          Invoicing                │
│  (Shield+TOTP) (Entity,Client) (Task,        (Issuer, Numbering,     │
│  TrustedDevice  LegalText,      Allocation)   Calculator, Snapshot,  │
│  LoginLog       Currency/FX                   CreditNote)            │
│                                                                      │
│  Documents    Delivery       Receivables    Reporting                │
│  (PdfRenderer, (Mailer,      (Payment,      (Turnover/Limits,        │
│   DocumentStore) Templates)   Overdue)       Reports, Export)        │
│                                                                      │
│  Audit (AuditLogger)   Platform (Settings, i18n, Import, Money)      │
└───────────────┬──────────────────────────────────────────────────────┘
                │ Modely (len čítanie/zápis tabuliek) + raw SQL pre zámky
┌───────────────▼──────────────────────────────────────────────────────┐
│ PostgreSQL: numeric, jsonb, FK, CHECK, EXCLUDE, triggery, GRANT      │
│ Súborový sklad: writable/documents (PDF cache) · writable/cache      │
└──────────────────────────────────────────────────────────────────────┘
        Externé: NBP / ECB / ČNB (kurzy, HTTP s timeoutom) · SMTP
```

### Zodpovednosti komponentov

| Komponent | Vlastní | Typická implementácia |
|---|---|---|
| **Identity** | prihlásenie, TOTP, záložné kódy, dôveryhodné zariadenia, denník prihlásení, roly | Shield (session authenticator) + vlastná akcia `Totp2FA` (`ActionInterface`, registrovaná v `Config\Auth::$actions['login']`), tabuľky `trusted_devices`, `backup_codes`, `login_log` |
| **MasterData** | subjekty a ich verzie, klienti, právne profily a zákonné texty, meny, kurzy (cache) | modely + `EntityVersionResolver`, `LegalTextResolver`, `RateProvider` (NBP/ECB/ČNB/Manual) |
| **Work** | úlohy, fakturačné časti, odvodený stav úlohy | `TaskService`, `AllocationService` (zápis len pre nezamknuté časti), SQL pohľad `task_billing_v` |
| **Invoicing** | **jediný zapisovateľ** faktúr a dobropisov: výpočet súm, číslovanie, snapshot, zamknutie častí | `InvoiceIssuer`, `CreditNoteIssuer`, `InvoiceCalculator`, `SnapshotBuilder`, `NumberAllocator`, `LegalProfile` |
| **Documents** | PDF faktúry a výkazu zo snapshotu, disková cache | `InvoicePdf`, `WorkReportPdf` (mPDF), `DocumentStore` (atomický zápis, sha256) |
| **Delivery** | e-mail s PDF, šablóny, log odoslaní, upomienky | `InvoiceMailer` mimo DB transakcie, `email_templates`, `invoice_sends` |
| **Receivables** | úhrady, po splatnosti | `PaymentService`, odvodený stav faktúry |
| **Reporting** | obrat voči limitom, mesačné/ročné prehľady, CSV/XLSX | `TurnoverCalculator`, `ReportQuery`, `Exporter` (iba z denormalizovaných stĺpcov) |
| **Audit** | denník zmien (append-only) | `AuditLogger::record()` volaný zo služieb v rovnakej transakcii |
| **Platform** | nastavenia, i18n, formátovanie, `Money`, import z Excelu, PWA, asset verzie | helpery, `Config`, spark príkazy |

## Odporúčaná štruktúra projektu

CI4 neprikazuje štruktúru pod `App\`. Odporúčam **zoskupenie podľa doménového kontextu** (nie plochý `Models/`, `Services/`), pretože pravidlo „kto smie zapisovať kam“ sa tak dá čitateľne strážiť.

```
app/
├── Config/                    # Routes (skupiny), Filters, Auth (actions), Security, Services
├── Controllers/               # tenké: validácia vstupu -> služba -> view/fragment
│   ├── Auth/ (Totp, Devices)
│   ├── Tasks.php  Allocations.php  Invoices.php  Clients.php  Entities.php
│   └── Reports.php  Settings/  Documents.php  Dashboard.php
├── Filters/                   # TwoFactorComplete, RoleGuard, HtmxVary, SecurityHeaders
├── Domain/
│   ├── Identity/              # Actions/Totp2FA, TrustedDeviceService, LoginLogger
│   ├── MasterData/            # Entity*, Client*, LegalText*, Currency*, Rates/{Nbp,Ecb,Cnb,Manual}
│   ├── Work/                  # Task*, Allocation*, TaskBillingStatus
│   ├── Invoicing/
│   │   ├── InvoiceIssuer.php  CreditNoteIssuer.php  InvoiceCalculator.php
│   │   ├── SnapshotBuilder.php  NumberAllocator.php  NumberFormat.php
│   │   ├── LegalProfile/      # SkNonVat, PlArt113, EuB2b… (kód: aké polia a sekcie PDF; texty sú v DB)
│   │   └── Models/ Entities/  # InvoiceModel (insert-only), InvoiceLineModel…
│   ├── Documents/             # InvoicePdf, WorkReportPdf, DocumentStore, templates/ (views pre mPDF)
│   ├── Delivery/  Receivables/  Reporting/  Audit/
│   └── Platform/              # Money, Settings, ExcelImporter, AssetVersion
├── Database/
│   ├── Migrations/            # + raw SQL: triggery, EXCLUDE, GRANT
│   └── Seeds/                 # limity SK/PL, právne texty, e-mailové šablóny, roly
├── Language/{sk,pl,cs,en}/    # UI texty + Doc.php (statické texty dokladov)
├── Views/                     # layouts/, pages/, fragments/ (htmx), emails/
public/
├── index.php  sw.js  manifest.webmanifest  offline.html  icons/
└── assets/<verzia>/…          # lokálne htmx, alpine, bootstrap, app.css/js
tests/                         # PHPUnit proti PostgreSQL (nie SQLite), vrátane súbehu
docker/                        # Dockerfile (targets dev/prod), nginx/, postgres/, backup/
```

### Odôvodnenie

- **Domain/<Kontext>:** hranice vidno v importoch. Pravidlo smeru závislostí: `Invoicing` číta `MasterData` a `Work`; **nikto okrem `Invoicing` nezapisuje do tabuliek `invoices*`**; `Work` pozná faktúry len cez stĺpec `billing_allocations.invoice_id` (zámok), nie cez triedy z `Invoicing`.
- **Kontroléry tenké:** jediná logika v kontroléri je HTTP (validácia vstupu, výber fragmentu vs. plnej stránky, redirect/`HX-*` hlavičky).
- **Modely nie sú doménová vrstva:** CI4 Model je gateway k tabuľke. Pravidlá (zamykanie, číslovanie, snapshot) patria do služieb, kvôli testovateľnosti a aby `Model::save()` v kóde nikdy neobišiel pravidlo.
- **Entity (CI4 `Entity`)** používať s `casts` na `string` pre `numeric` (PostgreSQL ovládač vracia numeric ako string, **nikdy `float`**) a `json-array` pre `jsonb`.

## Dátový model

Konvencie: PK `bigint generated always as identity`; peniaze `numeric(12,2)`, hodiny `numeric(7,2)`, sadzby `numeric(10,2)`, kurzy `numeric(18,8)`; časy `timestamptz`, dátumy `date`; kódy mien ako `char(3)`.

### Identity

| Tabuľka | Poznámka |
|---|---|
| `users`, `auth_identities`, `auth_groups_users`, `auth_logins`, `auth_remember_tokens` | štandardné tabuľky Shield. Roly = Shield **groups** `admin`, `accountant` (AUTH-08) |
| `user_totp` | `user_id`, `secret_enc` (CI4 Encryption, kľúč v `.env`), `confirmed_at` |
| `backup_codes` | `user_id`, `code_hash`, `used_at`. Kódy len ako hash (Argon2id alebo SHA-256 s vysokou entropiou kódu), 10 ks |
| `trusted_devices` | `user_id`, `token_hash` (cookie nesie náhodný token, v DB len hash), `label` (UA), `last_ip`, `expires_at` (+30 d), `revoked_at` |
| `login_log` | vlastná tabuľka (AUTH-07): `at`, `email_tried`, `user_id?`, `result` (`password_fail`/`totp_fail`/`ok`/`device_ok`/`backup_code`), `ip`, `user_agent`. Plní sa z udalostí Shield + vlastnej akcie. `auth_logins` od Shield nestačí (nemá výsledok kroku 2FA ani zariadenie) |

### Subjekty (dodávateľ) s verziami

```
entities            id, name_internal, country(SK|PL|CZ), currency(char3), active_from, active_to,
                    (country a currency sú NEMENNÉ na úrovni subjektu, nie verzie)
entity_versions     id, entity_id FK, valid_from date, trade_name, address…, tax_id, vat_id,
                    registry_note, iban, swift, email, phone, doc_language, vat_regime,
                    default_due_days, created_at, created_by
                    UNIQUE (entity_id, valid_from)      -- insert-only
invoice_series      id, entity_id FK, kind (invoice|credit_note), format (napr. 'RRRRMM###'),
                    initial_number int default 1
number_sequences    entity_id, series_id, period_key text ('202610' | '2026'), last_value int,
                    PRIMARY KEY (series_id, period_key)
```

- **Návrh odchýlky (na potvrdenie):** špecifikácia vymenúva menu a formát číslovania medzi verziovanými poľami. Odporúčam menu a krajinu držať **na subjekte** (nemenné), lebo zmena meny subjektu by rozbila obrat, limity aj kurzy. Poľská živnosť je tak či tak samostatný subjekt (rozhodnuté). Formát čísla patrí do `invoice_series`; zmena formátu uprostred obdobia je rizikový prípad (pozri Pitfalls), preto ju UI povoliť len pre obdobie bez vystavených dokladov.
- Verzia platná k dátumu: `SELECT … WHERE entity_id=:e AND valid_from <= :date ORDER BY valid_from DESC LIMIT 1`. Pri vystavení sa verzia **pripne** (`entity_version_id` na faktúre + celá kópia v snapshote).
- `entity_versions` = **insert-only**: `GRANT SELECT, INSERT` pre aplikačného používateľa (žiadne UPDATE/DELETE), čím sa história nedá prepísať ani chybou v kóde.

### Klienti, úlohy, časti

```
clients             id, name, address…, country, tax_id, vat_id, invoice_email, cc_emails text[],
                    hourly_rate numeric(10,2), currency, doc_language?, due_days?,
                    billing_mode (domestic|eu_b2b|non_eu), counts_toward_limit bool default true,
                    portal_name, portal_url_template, archived_at
tasks               id, client_id FK, portal_url, title, estimated_hours, rate (kópia z klienta,
                    prepisateľná), note, status (in_progress|done), done_at, created_at
billing_allocations id, task_id FK, hours numeric(7,2) CHECK (hours > 0), billing_month char(7)
                    ('2026-10'), rate, amount (= round(hours*rate,2), ukladá sa),
                    currency (kópia z klienta pri vzniku),
                    invoice_id bigint NULL FK invoices   -- NULL = nezamknutá, NOT NULL = zamknutá
```

- **Zámok = stĺpec `invoice_id`**, nie boolean (nemôže sa rozísť s faktúrou). Index `(task_id)`, `(client…, billing_month) WHERE invoice_id IS NULL` (partial index pre „časti na fakturáciu“).
- Fakturačný stav úlohy sa **neukladá**, odvodzuje sa (`task_billing_v`: súčet zamknutých, naplánovaných a nacenených hodín). Dashboardové dlaždice sú 2–3 agregačné dotazy, pri jednom používateľovi hlboko pod 100 ms.
- **Mena na časti:** pri vzniku sa skopíruje z klienta. Zmenu meny/sadzby klienta s otvorenými nezamknutými časťami treba v UI buď zablokovať, alebo explicitne prepočítať (pozri Pitfalls).

### Faktúry (nemenné jadro) a odvodené záznamy

```
invoices            id, entity_id, entity_version_id, series_id, type (invoice|credit_note),
                    number text, UNIQUE (entity_id, number),
                    corrected_invoice_id NULL FK invoices,      -- len pre dobropis
                    client_id FK (pre reporty), billing_month,
                    issue_date, delivery_date, due_date, turnover_date (pre dobropis = delivery_date originálu),
                    currency, total numeric(12,2),
                    fx_rate numeric(18,8) NULL, fx_source, fx_date,
                    total_entity_ccy numeric(12,2),             -- prepočítané pri vystavení
                    counts_toward_limit bool,                   -- zmrazené z klienta pri vystavení
                    snapshot jsonb NOT NULL, snapshot_schema int NOT NULL,
                    created_at, created_by
invoice_lines       id, invoice_id FK, position, source_allocation_id (bez FK), source_task_id,
                    title, hours, rate, amount, vat_rate numeric(5,2) default 0, vat_note_code
invoice_documents   id, invoice_id FK, kind (invoice|report), path, sha256, template_version, created_at
invoice_sends       id, invoice_id FK, kind (invoice|resend|reminder), sent_at, recipients text[], message_id
payments            id, invoice_id FK, paid_on date, amount numeric(12,2), note, created_at
```

- **Stav faktúry sa neukladá.** Odvodzuje sa: `stornovaná` ⇐ existuje dobropis s `corrected_invoice_id`; `uhradená` ⇐ `SUM(payments) >= total`; `odoslaná` ⇐ existuje `invoice_sends`; `po splatnosti` ⇐ `due_date < today` a nie uhradená. Preto je tabuľka `invoices` **plne insert-only** a nikdy sa neaktualizuje (žiadny stav na prepisovanie = žiadna výnimka v ochrane nemennosti). Pre zoznamy slúži SQL pohľad `invoice_status_v`.
- **Dobropis = riadok v `invoices`** s `type='credit_note'`, zápornými riadkami a vlastným radom (`invoice_series.kind='credit_note'`).
- **Denormalizované stĺpce** (`total_entity_ccy`, `counts_toward_limit`, `turnover_date`, `client_id`) sa zapíšu raz pri vystavení z toho istého výpočtu ako snapshot. Prehľady a limity ich sčítavajú SQL-om, **nikdy nečítajú JSONB** a nepoužívajú živé nastavenia klienta (príznak sa mohol zmeniť neskôr).
- `invoice_lines.source_allocation_id` je **bez FK**: po dobropise sa časť uvoľní a môže sa upraviť či zmazať, história na faktúre musí ostať.
- `invoice_documents` je oddelená tabuľka, lebo cache cesta/hash PDF je mutabilná vec (regenerácia), zatiaľ čo `invoices` je nemenná.

### Obsah snapshotu (`snapshot jsonb`)

Verzovaný (`snapshot_schema`), **samostatne postačujúci** na vykreslenie faktúry aj výkazu bez čítania iných tabuliek:

```json
{
  "schema": 1,
  "number": "202610001", "type": "invoice",
  "dates": {"issue": "...", "delivery": "...", "due": "..."},
  "entity": { "id": 1, "version_id": 7, "country": "SK", "currency": "EUR", "...všetky údaje verzie...": "" },
  "client": { "id": 3, "name": "...", "address": {}, "vat_id": "...", "billing_mode": "domestic", "portal_name": "Taskman" },
  "currency": "EUR", "doc_language": "sk",
  "fx": null,
  "vat_regime": "sk_non_vat",
  "legal_texts": [{"key": "vat_note", "text": "Nie som platiteľ DPH."}, {"key": "registry", "text": "..."}],
  "lines": [{"task_id": 1, "title": "...", "portal_url": "...", "estimated_hours": "40.00",
             "hours": "16.00", "rate": "27.00", "amount": "432.00", "vat_rate": "0.00"}],
  "totals": {"net": "432.00", "vat": "0.00", "gross": "432.00", "entity_ccy": "432.00"},
  "payment": {"iban": "...", "swift": "...", "variable_symbol": "202610001"},
  "template_version": "inv-1"
}
```

Zákonné texty sa do snapshotu **kopírujú ako vyriešený text** v jazyku dokladu (nie ako odkaz na `legal_texts`), inak by neskoršia úprava textu v nastaveniach zmenila starý doklad. Reťazce štruktúry PDF (hlavičky stĺpcov „Počet hodín“, „Spolu“…) sú vo verzovaných jazykových súboroch `Doc.php` + `template_version` (viac v sekcii Documents).

### Ostatné tabuľky

```
legal_texts          country, vat_regime, billing_mode, language, key, body, updated_at  -- UNIQUE na prvých 5
                     (fallback pri hľadaní: presná zhoda -> billing_mode NULL -> language 'en' -> chyba pri vystavení, nie ticho)
exchange_rates       source(nbp|ecb|cnb|manual), base, quote, rate_date, rate  -- LEN cache, pravda je v snapshote
currencies           code, name, symbol, decimals                                -- rozšíriteľné bez zmeny modelu
turnover_limits      country, year, name, amount, currency, calc_method, warn_thresholds int[]
entity_opening_turnover  entity_id, year, amount
email_templates      key (invoice|reminder), language, subject, body (s {{placeholdermi}}), updated_at
audit_log            id, at, user_id, entity_type, entity_id, action, before jsonb, after jsonb, ip   -- append-only
settings             key/value (jazyk UI, predvolby)
user_prefs           user_id, ui_locale
```

## Vynucovanie nemennosti (obrana v troch vrstvách)

**Odporúčanie: áno, aj DB, nielen služby.** Požiadavka G4 a „core value“ znamenajú, že chyba v kóde nesmie dokument tíško zmeniť. Služby sú prvá línia, DB posledná.

1. **Služba (primárna logika).** Iba `InvoiceIssuer`/`CreditNoteIssuer` zapisujú do `invoices*`. `AllocationService` pri úprave/presune/zmazaní kontroluje `invoice_id IS NULL` v **tom istom SQL príkaze** (`UPDATE … WHERE id=:id AND invoice_id IS NULL` + kontrola počtu riadkov), nie „načítaj, skontroluj, ulož“ (race). Chyba sa vráti ako 409/422 a UI ju zobrazí. Toto splní ALC-03/E5 „odmietne sa na serveri“.
2. **Oprávnenia PostgreSQL (najlacnejšia a najsilnejšia ochrana).** Dva DB role:
   - `app_owner`: vlastní tabuľky, používa ho iba `spark migrate` (a testy).
   - `app_rw`: používa ho php-fpm. `GRANT SELECT, INSERT` na `invoices`, `invoice_lines`, `entity_versions`, `audit_log`, `login_log`, `payments`(+ UPDATE/DELETE len ak sa pripustí oprava úhrady), **bez UPDATE/DELETE/TRUNCATE**. Na `billing_allocations`, `tasks` plné DML (chránia ich triggery). Bug alebo SQL injection tak nezmení doklad.
3. **Triggery tam, kde je výnimka podmienená** (`billing_allocations`):
   ```sql
   CREATE FUNCTION alloc_guard() RETURNS trigger LANGUAGE plpgsql AS $$
   BEGIN
     IF TG_OP = 'DELETE' THEN
       IF OLD.invoice_id IS NOT NULL THEN RAISE EXCEPTION 'allocation % is locked', OLD.id; END IF;
       RETURN OLD;
     END IF;
     IF OLD.invoice_id IS NOT NULL THEN
       -- jediná povolená zmena: uvoľnenie po dobropise, ostatné stĺpce nemenné
       IF NEW.invoice_id IS NULL
          AND (to_jsonb(NEW) - 'invoice_id' - 'updated_at') = (to_jsonb(OLD) - 'invoice_id' - 'updated_at')
          AND EXISTS (SELECT 1 FROM invoices WHERE type='credit_note' AND corrected_invoice_id = OLD.invoice_id)
       THEN RETURN NEW; END IF;
       RAISE EXCEPTION 'allocation % is locked', OLD.id;
     END IF;
     RETURN NEW;
   END $$;
   ```
   Podmienka „existuje dobropis“ umožňuje uvoľnenie bez session premenných a bez vypínania triggerov. Pridať `BEFORE UPDATE OR DELETE` trigger. Ďalšie jemné obmedzenia: `CHECK (hours > 0)`, `CHECK (amount = round(hours*rate, 2))`.
4. **Testy:** PHPUnit proti reálnemu PostgreSQL (migrácie sa spúšťajú ako `app_owner`, testované pod `app_rw`): pokus o UPDATE/DELETE faktúry, riadku, zamknutej časti musí zlyhať na úrovni DB.

Pozn.: CI4 `Model::$allowedFields`/`protect` a callbacky sú len pohodlie. Ako obrana sa nepočítajú (`Query Builder`, `db->query()` ich obchádzajú).

## Architektonické vzory

### Vzor 1: Vystavenie faktúry ako jedna transakcia

**Čo:** jediná verejná metóda `InvoiceIssuer::issue(IssueCommand $cmd): Invoice`. **Poradie je záväzné**, aby sa zámok na číselný rad držal čo najkratšie a nevznikali deadlocky.

```
BEGIN
 1. načítaj vstup (klient, subjekt+verzia podľa issue_date, kurz z príkazu)           [bez zámkov]
 2. zamkni časti ATOMICKY:
      UPDATE billing_allocations SET invoice_id = :reserved   -- pozri poznámku
      WHERE id = ANY(:ids) AND invoice_id IS NULL AND billing_month = :m
        AND task.client_id = :c
      -> počet zmenených riadkov MUSÍ == počet očakávaných, inak ROLLBACK (súbeh / zmena medzitým)
 3. InvoiceCalculator::compute(časti)  -> riadky, súčet (jediné miesto, kde sa počíta)
 4. SnapshotBuilder::build(...)        -> JSON (legal texty vyriešené, kurz, entita, klient)
 5. NumberAllocator::next(entity, series, issue_date)   <- POSLEDNÝ zámok:
      INSERT INTO number_sequences (...) VALUES (..., :start-1) ON CONFLICT DO NOTHING;
      UPDATE number_sequences SET last_value = last_value + 1
        WHERE series_id=:s AND period_key=:p RETURNING last_value;
 6. INSERT invoices (+ snapshot) RETURNING id; INSERT invoice_lines
 7. UPDATE billing_allocations SET invoice_id = :id  (doplnenie skutočného ID, alebo vlož faktúru
      skôr a zamykaj priamo s jej ID, pozri poznámku)
 8. AuditLogger::record(...)
COMMIT
-- po commite, mimo transakcie:
 9. Documents::generate(invoice)  (zlyhanie nie je chyba vystavenia, PDF sa dá kedykoľvek vytvoriť zo snapshotu)
10. UI ponúkne odoslanie (Delivery), e-mail sa nikdy neposiela vnútri DB transakcie
```

Poznámka k krokom 2/7: praktickejšie je vložiť `invoices` riadok až po pridelení čísla (číslo je `NOT NULL`), preto sa v kroku 2 časti zamknú `SELECT … FOR UPDATE` (poradie `ORDER BY id`) a `invoice_id` sa nastaví v kroku 7. Zámky riadkov zabraňujú súbehu rovnako a rozhodujúce je overenie počtu.

**Prečo číslo v tabuľke a nie PostgreSQL `SEQUENCE`:** sekvencia nie je transakčná (rollback vytvorí medzeru), čo porušuje požiadavku „bez medzier“. `UPDATE … RETURNING` na riadku vykoná zámok a druhá súbežná transakcia čaká na commit prvej, takže číslo sa pridelí v poradí commitov a pri rollbacku sa vráti. Cena: vystavovanie jedného radu je serializované, čo pri jednom používateľovi nevadí.

**Obdobie (`period_key`):** formát obsahuje `MM` → `'202610'`, obsahuje len rok → `'2026'` (odvodiť z parsovania `NumberFormat`). Prvé obdobie radu začína z `initial_number`, ďalšie od 1. Unikátny index `UNIQUE (entity_id, number)` je záverečná poistka.

CI4 poznámky: `$db->transStart()/transComplete()` (alebo `transException(true)` pre výnimky), zámky `FOR UPDATE` cez `$db->query()` (Query Builder ich nepodporuje). Služba sa nevolá z kontroléra „po častiach“, kontrolér predá hotový príkaz.

### Vzor 2: Kópia údajov je jediný zdroj pre doklady

**Čo:** PDF faktúry aj výkazu aj e-mail čítajú **iba** `invoices.snapshot` + `Doc.php` jazyk. Výkaz práce používa tie isté `lines` (s doplnenými poľami `portal_url`, `estimated_hours`), takže súčty faktúry a výkazu sa nedajú rozísť.
**Kedy:** vždy pre vystavené doklady. Náhľad pred vystavením používa ten istý `SnapshotBuilder` v režime `preview` (nič sa nezapíše), takže „čo vidím = čo sa vystaví“.
**Kompromis:** snapshot je väčší než relačné polia, ale dáta sú duplicitné zámerne. Štruktúrované stĺpce v `invoices`/`invoice_lines` zostávajú pre reporty a budúci KSeF FA(3) export (INV-08).

### Vzor 3: Peniaze iba cez jednu triedu

**Čo:** `Money`/`InvoiceCalculator` nad `brick/math` (alebo `bcmath`), všetky hodnoty ako decimal reťazce, jedna pravidlo zaokrúhľovania (riadok = `round(hours × rate, 2)` half-up, súčet = súčet zaokrúhlených riadkov, prepočet kurzom sa zaokrúhľuje na úrovni celkového súčtu — politika sa zapíše do `snapshot.totals.rounding`).
**Prečo:** presne tá chyba z Excelu (súčty sa líšia na rôznych miestach) vznikne, ak sa suma počíta na viacerých miestach (view, PDF, SQL). Obrazovky len zobrazujú `amount` zo záznamu; živý prepočet v UI (Alpine) je len náhľad a server ho vždy prepočíta a ignoruje klientsku hodnotu.

### Vzor 4: Odvodené, nie ukladané (stavy)

Stav úlohy (fakturačný), stav faktúry, „po splatnosti“, obrat: počítajú sa SQL pohľadmi/dotazmi z faktov (časti, platby, odoslania, dobropisy). Znižuje to počet mutabilných miest a tým riziko nekonzistencie.

### Vzor 5: htmx fragmenty

- **Jeden kontrolér, dve odpovede:** `if ($this->request->hasHeader('HX-Request') && !HX-Boosted)` vráť fragment (`view('fragments/…')`), inak plnú stránku s layoutom. Core CI4 nemá `isHTMX()` (overené v dokumentácii `IncomingRequest`). Stačí kontrola hlavičky; voliteľne balík `michalsn/codeigniter-htmx` (pridáva metódy na Request/Response/Redirect a podporu fragmentov, MEDIUM, balík nie je v core).
- **Vždy `Vary: HX-Request`** (filter `HtmxVary`), inak prehliadač/späť-tlačidlo zobrazí fragment ako stránku.
- **Validačné chyby:** vrátiť `422` a fragment formulára. htmx 2 predvolene **neswapuje 4xx/5xx**, preto v konfigurácii `htmx.config.responseHandling` povoliť swap pre 422.
- **CSRF:** CI4 CSRF token dať do `hx-headers` na `<body>` (`X-CSRF-TOKEN`) a po každej odpovedi aktualizovať (alebo vypnúť regeneráciu tokenu pri každej požiadavke); inak druhá htmx požiadavka zlyhá 403. Nezabudnúť na `Debug Toolbar` (v dev ruší AJAX, vypnúť pre `HX-Request`).
- **Presmerovania:** po úspešnom POST pre htmx `HX-Redirect`/`HX-Location` (nie 302, ktorý XHR nasleduje potichu a vloží celú stránku do fragmentu); `HX-Trigger` pre toasty a obnovu dlaždíc dashboardu (`hx-trigger="allocationChanged from:body"`).
- **Kde použiť:** dialóg „Zapracované“ (bottom sheet, fragment), zoznam častí v detaile úlohy, náhľad fakturácie mesiaca (výber časti → prepočet), filtre zoznamov, odoslanie e-mailu. **Kde nie:** prihlásenie/2FA, stiahnutie PDF, exporty (obyčajné odkazy).
- **CSP:** používať `@alpinejs/csp` build (štandardný Alpine vyžaduje `unsafe-eval`), v htmx `allowEval: false`, `includeIndicatorStyles: false` + vlastné štýly indikátora; nonce alebo žiadne inline skripty.

### Vzor 6: PDF z kópie s diskovou cache

- `Documents::generate(invoice, kind)`: `html = view('pdf/invoice', snapshot)`, mPDF → dočasný súbor → `rename()` na `writable/documents/{entity_id}/{rok}/{číslo}-{kind}.pdf` (atomicky), zapíše sa `invoice_documents` (sha256, `template_version`).
- **Vystavenie PDF je eager** (hneď po commite), nie lazy: súbor je potom kanonický artefakt (bit po bite stabilný, G4) a je v zálohách. Ak súbor chýba (obnova, zlyhanie), regeneruje sa zo snapshotu; obsah bude rovnaký, kým sa nezmení `template_version`/jazykový súbor. Zmenu šablóny preto vždy robiť ako **novú verziu šablóny** (`inv-2`) s tým, že staré faktúry si držia `inv-1` kód (alebo ich stačí neregenerovať, kým existuje súbor).
- **Stiahnutie:** `Documents::download($id)` overí prístup a odpovie `X-Accel-Redirect` na internú nginx lokáciu (súbory nie sú vo `public/`); PHP-FPM worker sa neblokuje streamovaním.
- **Výkon (NFR-01, <2 s):** mPDF s vloženým fontom s diakritikou (DejaVu/Noto Sans, mPDF ho dodáva), `tempDir` na `writable/cache/mpdf`, vypnúť zbytočné funkcie (`useSubstitutions=false`, jednoduché CSS bez zložitých tabuliek), generovať faktúru a výkaz ako dva samostatné `Mpdf` objekty. mPDF je pamäťovo náročný: dať generovaniu `memory_limit` ~192 MB a udržať počet FPM workerov nízky (pozri Docker).

### Vzor 7: Kurzy ako vstup príkazu, nie skrytý vedľajší efekt

`RateProvider` (rozhranie `fetch(Currency $from, Currency $to, Date $deliveryDate): Rate`) s implementáciami `NbpRateProvider` (priemerný kurz tabuľky A z posledného pracovného dňa **pred** dátumom dodania; pri 404 sa cúva po dňoch), `EcbRateProvider` (referenčné kurzy ECB, bázou EUR), `CnbRateProvider`, `ManualRate`. Výber podľa `entities.country` (konfigurovateľné v právnom profile). Volanie cez `CURLRequest` s timeoutom 3 s.
Tok: **náhľad fakturácie** (htmx) zavolá provider, zobrazí kurz a dáva možnosť prepísať; **vystavenie** dostane kurz explicitne v príkaze (`fx_rate`, zdroj, dátum) a len ho validuje a uloží do snapshotu. `InvoiceIssuer` tak nerobí sieťové volania vnútri transakcie. Tabuľka `exchange_rates` je len cache pre rýchlosť a pre nasledujúci náhľad; nikdy sa z nej nepočítajú prehľady (CUR-04). Detaily API (endpointy, formáty, dostupnosť v deň zverejnenia) overiť v implementačnej fáze.

### Vzor 8: Limity a obrat

`TurnoverCalculator::forEntity(entity, year)`:
`SUM(total_entity_ccy)` z `invoices` (faktúry kladne, dobropisy záporne) `WHERE entity_id=:e AND counts_toward_limit AND extract(year from turnover_date)=:y` + `entity_opening_turnover`. Limit vyhľadať v `turnover_limits` pre (krajina subjektu, rok), fallback na posledný známy rok so značkou „skontroluj limit“ (LIM-03). Pomerný limit PL v prvom roku (art. 113 ust. 9) = `amount × dni_pôsobenia / dni_roka` z `entities.active_from`; vzorec je dôvod mať `calc_method` v tabuľke. Dobropis má `turnover_date` originálu (inak by storno z novembra znížilo obrat iného roka).

## Tok údajov

### Hlavný tok

```
Úloha ──(Zapracované dialóg / + časť)──► billing_allocations (invoice_id NULL)
                                              │
        náhľad fakturácie mesiaca (klient+mesiac, výber častí, subjekt, kurz)
                                              │  IssueCommand
                                              ▼
                              InvoiceIssuer (1 DB transakcia)
        ┌─────────────────────────────────────────────────────────┐
        │ lock časti → compute → snapshot → číslo → INSERT         │
        │ invoices+lines → allocations.invoice_id → audit          │
        └─────────────────────────────────────────────────────────┘
                                              │ COMMIT
                                              ▼
                     Documents: PDF faktúry + PDF výkazu (zo snapshotu) → disk + sha256
                                              │
                     Delivery: e-mail (šablóna v jazyku klienta, prílohy) → invoice_sends
                                              │
                     Receivables: payments → odvodený stav (uhradená / po splatnosti)
                                              │
                     Reporting: invoices.total_entity_ccy + turnover_date → limity, prehľady, CSV/XLSX
Storno: CreditNoteIssuer (1 transakcia) → vlastný rad → záporné riadky → allocations.invoice_id = NULL
```

### Požiadavka (HTTP)

```
Prehliadač ─► nginx ─► php-fpm ─► Filters (HTTPS hlavičky, auth, 2FA-complete, rola, CSRF)
        ▲                              │
        │                              ▼
   fragment/stránka ◄── View ◄── Controller ──► Domain Service ──► Model/SQL ──► PostgreSQL
```

### Autentifikácia

```
heslo (Shield session) ─► akcia Totp2FA (show/handle/verify):
   trusted_devices cookie platná? ─ áno ─► prihlásený
                                  └ nie ─► TOTP kód alebo záložný kód ─► (voliteľne "zapamätať 30 d") ─► login_log
Filter TwoFactorComplete: bez dokončenej akcie žiadna chránená stránka (kritérium E1)
```

Shield akcie: trieda implementuje `CodeIgniter\Shield\Authentication\Actions\ActionInterface` (metódy `show`, `handle`, `verify`), registruje sa v `Config\Auth::$actions['login']`, routy `auth/a/show|handle|verify` pridá `auth()->routes()` (MEDIUM: ide o dokumentáciu viacerých verzií Shield, signatúry overiť na nainštalovanej verzii). Rozhodnutie o riziku R4: **spike v prvej fáze s pevným časovým limitom** (napr. 1 deň). Ak Shield pending-state/„zapamätať zariadenie“ vzťahy bránia, prejsť na vlastnú autentifikáciu nad CI4 sessions (users/groups tabuľky ponechať podľa Shield, aby sa nič iné nemenilo).

## Nefunkčné prierezové témy

### Autorizácia (roly)
Shield groups `admin`/`accountant`. Pravidlo jedným filtrom: **všetky metódy okrem GET/HEAD vyžadujú `admin`**, a čítacie routy pre `accountant` sa vymenujú v skupine (faktúry, prehľady, exporty, stiahnutie PDF). Zápisové služby neprijímajú „kontext role“, rola sa strážia na vstupe.

### Audit
Explicitné `AuditLogger::record(entityType, id, action, before, after)` v službách a **v rovnakej transakcii** ako zmena (ak sa zmena vráti, vráti sa aj záznam). Dôvod voči DB triggerom: potrebuje používateľa a obchodný význam akcie; voči model-callbackom: tie neobsiahnu hromadné dotazy a Query Builder. `audit_log` insert-only cez GRANT. Citlivé polia (TOTP tajomstvo, hash hesla) sa do `before/after` nikdy nezapisujú.

### i18n (CI4 jazykové súbory)
- Dve nezávislé osi: **jazyk UI** (`user_prefs.ui_locale`, nastaví sa filtrom `Locale` do `$request->setLocale()`) a **jazyk dokladu** (klient → subjekt → fallback). Statické texty dokladov sú v samostatnom súbore `Language/<l>/Doc.php`, čítané ako `lang('Doc.total', [], $docLocale)` (tretí parameter `lang()` je explicitný locale, MEDIUM, overiť na 4.7), takže generovanie PDF nezávisí od jazyka prihláseného používateľa a e-mailu.
- **Editovateľné** texty (zákonné poznámky, e-mailové šablóny) sú v DB, nie v jazykových súboroch (SET-01, MAIL-04). Seeduje sa SK a PL, EN/CZ doplniť.
- Kód locale pre češtinu je `cs` (UI štítok „CZ“). Formáty: `NumberFormatter`/`IntlDateFormatter` (`ext-intl`) s locale dokladu. PHPUnit test parity kľúčov medzi `sk`/`pl`/`cs`/`en`.
- Vo verzii 1 stačí SK UI úplne (UI-02: SK P0, ostatné P1), ale **všetky reťazce od začiatku cez `lang()`** (spätné vytiahnutie je drahé).

### PWA a service worker
- `public/sw.js` servírovaný z koreňa, **scope `/`**, `Cache-Control: no-cache` pre `sw.js` a manifest. Predcache: verzované súbory `/assets/<verzia>/…` + `/offline.html`. Cache názov obsahuje verziu assetov, `activate` maže staré cache.
- Fetch stratégia: statika z `/assets/*` cache-first; **navigácia: network-only s fallbackom na `offline.html`**; všetko ostatné (htmx fragmenty, POST, `/documents/*`, `/auth/*`) **prejde bez zásahu SW**. Nikdy nekešovať HTML s dátami ani PDF (offline dáta sú mimo rozsahu a hrozia úniky po odhlásení).
- Manifest: `display: standalone`, `start_url: /`, ikony vrátane `apple-touch-icon` (iOS ignoruje časť manifestu, ikony a `theme-color` dať aj do `<head>`).
- Dôležité pre G5: dôveryhodné zariadenie = **dlhodobý cookie** (`Max-Age` 30 dní, `Secure`, `HttpOnly`, `SameSite=Lax`) a session so zodpovedajúcou životnosťou; PWA na iOS má vlastné úložisko cookies oddelené od Safari, takže prihlásenie sa musí urobiť **v nainštalovanej PWA** (testovať na skutočnom iPhone). Inštalácia a testovanie vyžaduje HTTPS s platným certifikátom, preto **skoré nasadenie na staging doménu** (nie až v poslednej fáze).
- Verzie assetov bez build kroku: spark príkaz `assets:version` vygeneruje `assets.json` (hash obsahu), helper `asset_url('css/app.css')` vráti `/assets/<hash>/css/app.css`, nginx `location ~ ^/assets/[^/]+/(.*)$` mapuje na súbor s `Cache-Control: public, immutable, max-age=31536000`.

### Odoslanie e-mailu
CI4 Email (SMTP, `.env`). Odoslanie je **samostatný krok** po vystavení (aj pre „jeden klik“ UI: vystaviť → hneď spustiť odoslanie, ale 2 oddelené volania). Záznam v `invoice_sends` len po úspechu. Bez fronty vo V1 (jeden používateľ, odoslanie ~1–3 s); ak pribudne potreba, `outbox` tabuľka + `spark` worker. Prílohy z `invoice_documents` (nie nové generovanie). Šablóny `email_templates` s whitelistom placeholderov (`{{number}}`, `{{total}}`, `{{due_date}}`…), vykreslené `strtr` (žiadny vykonateľný kód zo šablóny).

## Docker Compose (dev vs. prod)

```
compose.yaml               # spoločné služby
compose.override.yaml      # automaticky pre dev (profile "dev")
compose.prod.yaml          # prod overlay: docker compose -f compose.yaml -f compose.prod.yaml up -d
```

| Služba | Dev | Prod |
|---|---|---|
| `nginx` | HTTP :8080, bez HSTS | HTTP/2, TLS (certbot webroot sidecar alebo certbot na hostiteľovi), gzip/brotli, HSTS, `immutable` assety, interná lokácia pre `X-Accel-Redirect` |
| `php` (FPM, jeden Dockerfile, targety `dev`/`prod`) | Xdebug, composer, `opcache.validate_timestamps=1` | `vendor --no-dev`, `validate_timestamps=0`, preload, `pm=static`/`pm.max_children=3`, `memory_limit≈192M` |
| `postgres` | published port len na loopback | **bez** published portu, ladenie pamäte (`shared_buffers≈64MB`, `work_mem≈4MB`, `max_connections≈20`, `effective_cache_size≈256MB`), pomenovaný volume |
| `mailpit` | áno (SMTP :1025, UI :8025) | **nie** (profil `dev`) |
| `backup` | nie | sidecar s cronom (napr. supercronic): `pg_dump` + tar `writable/documents` → šifrovanie (age/gpg) → Storage Box (rclone/restic/borg), rotácia 14/8/12, skript `restore-test.sh` |

- **WSL2 poznámka (výkon dev):** bind mount z `/mnt/c/...` je pre PHP (tisíce malých súborov, `vendor/`) citeľne pomalý (9p). Mitigácia: `vendor/` a `writable/` ako pomenované volumes, alebo držať pracovnú kópiu v súborovom systéme WSL (ext4) a z `/mnt/c` len odkaz. Neskôr pri hlbšom meraní rozhodnúť.
- Migrácie a zálohy pod `app_owner`, php-fpm beží ako `app_rw` (pozri nemennosť). Premenné cez `.env` / Docker secrets, mimo gitu.
- Rovnaké obrazy pre dev a prod (NFR-07); líši sa len target a overlay. RAM cieľ: nginx ~10 MB, 3 × FPM worker ~ 3×40 MB v pokoji, PostgreSQL ~100 MB, backup sidecar po dobu behu; 512 MB je dosiahnuteľné (MEDIUM, ale mPDF špičky treba zmerať).
- Plánované úlohy: len zálohy a údržba (expirované zariadenia/tokeny). Kurzy sa sťahujú na požiadanie, nie cronom.

## Odporúčané poradie výstavby

Zhoda s PRD míľnikmi M1–M5, s úpravami z dôvodov závislostí a rizík.

| # | Fáza | Obsah | Prečo v tomto poradí |
|---|---|---|---|
| 0 | **Základ** | Docker (dev), CI4 + PostgreSQL migrácie, DB role (`owner`/`rw`), layout + Bootstrap, `lang()` kostra, `Money`, htmx/CSRF/CSP/`Vary` zapojené, testovacia DB v PostgreSQL | Všetko ostatné stojí na tomto. Konvencie (numeric ako string, `lang()` všade) sa neskôr opravujú draho |
| 1 | **Identita + skoré nasadenie** | Shield spike, TOTP akcia, záložné kódy, dôveryhodné zariadenia, login log, throttling, PWA kostra, **staging na Hetzner s HTTPS** | Bez prihlásenia sa nič nesmie vystaviť verejne. PWA/iOS cookies sa dajú overiť len na HTTPS. R4 je najväčšie neznáme zvolené skoro |
| 2 | **Master data + audit + nemenné vzory** | Subjekty s verziami (insert-only), klienti, nastavenia, `AuditLogger`, `legal_texts` seed (obsah overí účtovníčka, R1) | Audit musí existovať skôr než doménové zápisy. Subjekt/klient sú vstupom snapshotu. Tu sa prvýkrát vyskúša vzor „GRANT + insert-only“ |
| 3 | **Úlohy a časti** | CRUD úloh, časti, dialóg Zapracované, odvodený stav, dashboard (variant B), zamykanie na úrovni služby + trigger, **import z Excelu** | Časti sú vstupom fakturácie. Import ide až po existencii častí a dáva reálne dáta na testovanie fakturácie |
| 4 | **Fakturácia (jadro)** | `InvoiceCalculator`, `NumberAllocator`, `SnapshotBuilder`, `InvoiceIssuer`, náhľad, nemennosť invoices (GRANT), test súbehu na 2 DB spojeniach, jedna mena (mena klienta = mena subjektu) | Srdce produktu (core value). Najprv bez FX, aby sa riziko izolovalo |
| 5 | **Dokumenty a doručenie** | PDF faktúra + výkaz zo snapshotu, `DocumentStore`, právne profily SK/PL, e-mail (+ Mailpit), opätovné odoslanie, úhrady, po splatnosti | Závisí od hotového snapshotu. Prvý reálne použiteľný produkt (M3) |
| 6 | **Meny a kurzy** | `RateProvider` (NBP/ECB/ČNB/ručný), kurz v náhľade, uloženie do snapshotu, PDF s dvoma sumami | Samostatná fáza kvôli externým API (R3). Potrebné až pri PL subjekte/cudzej mene |
| 7 | **Dobropis, upomienky, limity, prehľady** | `CreditNoteIssuer` + uvoľnenie častí, `turnover_limits`, `TurnoverCalculator`, upozornenia, reporty, CSV/XLSX, upomienky, audit viewer | Závisí od faktúr a kurzov (obrat v mene subjektu) |
| 8 | **Lokalizácia a produkcia** | PL/CZ/EN preklady, doladenie UX, backup sidecar, **test obnovy**, hardening, runbook | Zálohy a obnova majú mať skúšku s reálnymi dátami pred ostrým používaním |

**Poznámky k závislostiam:** `legal_texts` a `Doc.php` treba začať skôr (fáza 2), lebo PDF vyžaduje obsah overený účtovníčkou (dlhý čas odozvy). Dobropis (`INV-07`, P1) je v dátovom modeli od fázy 4 (`type`, `corrected_invoice_id`, trigger výnimka), aby sa schéma neprerábala.

## Anti-vzory

### Anti-vzor 1: Stav faktúry ako stĺpec, ktorý sa prepisuje
**Čo ľudia robia:** `invoices.status` + `UPDATE` pri odoslaní/úhrade.
**Prečo je to zlé:** tabuľka sa dá meniť, ochrana nemennosti potrebuje výnimky a tie sa rozšíria na viac stĺpcov.
**Namiesto toho:** insert-only `invoices`, udalosti (`invoice_sends`, `payments`, dobropis) a odvodený stav.

### Anti-vzor 2: Číslo faktúry zo `SEQUENCE`/`MAX()+1`
**Prečo zlé:** sekvencia nechá medzery po rollbacku, `MAX()+1` kolabuje pri súbehu.
**Namiesto toho:** riadok v `number_sequences` v transakcii vystavenia, zámok ako posledný krok, `UNIQUE` poistka.

### Anti-vzor 3: PDF z živých dát („render on demand“)
**Prečo zlé:** zmena adresy či sadzby zmení historický doklad.
**Namiesto toho:** render zo snapshotu, eager generovanie a súbor v zálohách.

### Anti-vzor 4: Počítanie súm vo viacerých vrstvách
**Namiesto toho:** `InvoiceCalculator` je jediný kód počítajúci peniaze, ostatné vrstvy len čítajú uložené sumy. PHP `float` nikde (ani v JSON dekódovaní: dekódovať `jsonb` čísla ako reťazce, snapshot ukladať s hodnotami ako reťazcami).

### Anti-vzor 5: Odosielanie e-mailu alebo HTTP volanie vnútri transakcie
**Prečo zlé:** dlhý zámok číselného radu, e-mail odíde aj pri rollbacku.
**Namiesto toho:** commit, potom PDF, potom e-mail; kurz je vstup príkazu.

### Anti-vzor 6: Reporty čítajúce z JSONB alebo z aktuálneho klienta
**Namiesto toho:** denormalizované stĺpce zapísané pri vystavení (`total_entity_ccy`, `counts_toward_limit`, `turnover_date`).

### Anti-vzor 7: Ochrana len v UI a v CI4 Modeli
**Namiesto toho:** WHERE-podmienka v SQL, oprávnenia a triggery, test pod rolou `app_rw`.

### Anti-vzor 8: Cachovanie dát v service worker-i
**Namiesto toho:** len statika a offline stránka (spec), inak hrozí únik cez zdieľané zariadenie a zastarané čísla vo faktúrach.

## Škálovanie

| Rozsah | Úprava |
|---|---|
| 1 používateľ (zadanie) | monolit, 3 FPM workery, 1 PostgreSQL. Serializované číslovanie nevadí |
| Účtovníčka + pár subjektov | rovnaké riešenie, len role. Indexy na `(entity_id, delivery_date)` a `(client_id)` |
| Viac ako 10 tisíc faktúr | stále triviálne. Reporty z indexovaných stĺpcov, snapshot nikdy neskenovať |

**Prvé úzke miesto:** pamäť pri generovaní PDF (mPDF) na 512 MB VPS. Riešenie: nízky počet FPM workerov, `memory_limit` a nastavenie `tempDir`, generovanie len po vystavení (nie pri každom zobrazení). **Druhé:** latencia externého SMTP/kurzových API, riešené timeoutmi a ručným zadaním.

## Integračné body

### Externé služby

| Služba | Spôsob | Poznámky |
|---|---|---|
| NBP | `CURLRequest` GET, tabuľka A, posledný pracovný deň pred dodaním | 404 v dni bez publikácie, cúvať po dňoch. Overiť endpointy pri implementácii (LOW-MEDIUM) |
| ECB | referenčné kurzy (báza EUR) | prepočet cez EUR. Overiť formát (XML/CSV) pri implementácii (LOW-MEDIUM) |
| ČNB | denný kurzový lístok (text) | formát a čas zverejnenia overiť (LOW-MEDIUM) |
| SMTP | CI4 Email | poskytovateľ nezvolený (R6), `.env`, SPF/DKIM u poskytovateľa |
| Hetzner Storage Box | rclone/restic/borg zo sidecaru | šifrovať pred odoslaním, test obnovy |

### Vnútorné hranice

| Hranica | Komunikácia | Poznámky |
|---|---|---|
| Controller → Domain | volanie služby s DTO/príkazom | kontroléry nepoužívajú `db->query` ani modely pri zápise |
| Work ↔ Invoicing | len `billing_allocations.invoice_id` a SQL kontrola | `Work` neimportuje `Invoicing`; `Invoicing` mení zámok |
| Invoicing → Documents | výsledok transakcie (ID) po commite | Documents čítajú len snapshot |
| Documents/Delivery ← Invoicing | read-only | nikdy nezapisujú do `invoices` |
| Reporting ← Invoicing | read-only SQL | denormalizované stĺpce |
| Všetko → Audit | `AuditLogger::record()` v tej istej transakcii | |

## Otvorené otázky

- **Definícia „vyfakturovaná“ úlohy**, keď sa fakturované hodiny líšia od nacenených (spec: nič neblokuje). Návrh: *vyfakturovaná* = zapracovaná + žiadna nezamknutá časť + aspoň jedna zamknutá; *čiastočne* = aspoň jedna zamknutá časť inak; potvrdiť s vlastníkom.
- **Zaokrúhľovanie pri kurze** (riadok vs. súčet) a pri neskoršej DPH na položkách. Potvrdiť s účtovníčkou, zapísať do snapshotu.
- **Mena/krajina na subjekte ako nemenné** (odchýlka od znenia špecifikácie v 6.1). Potvrdiť.
- **Zmena formátu číslovania uprostred obdobia** a spätné dátumovanie (číslo narastá, dátum vystavenia nesmie ísť späť). Návrh: pri vystavení varovanie, ak `issue_date` < dátum posledného vystaveného dokladu radu (na účtovnú kontrolu).
- **Dobropis a obrat** (`turnover_date` originálu vs. dátum dobropisu) podľa pravidiel krajiny. Overiť u účtovníčky.

## Zdroje

- Zadanie projektu: `docs/superpowers/specs/2026-10-23-task-tracker-design.md`, `docs/PRD.md`, `.planning/PROJECT.md` (HIGH, autoritatívne pre požiadavky)
- CodeIgniter 4 IncomingRequest (nemá htmx helper; `is()` od 4.3.0): https://codeigniter.com/user_guide/incoming/incomingrequest.html (MEDIUM)
- CodeIgniter Shield, Authentication Actions (rozhranie `ActionInterface`, `Config\Auth::$actions`, routy `auth/a/*`): https://shield.codeigniter.com/references/authentication/auth_actions/ (MEDIUM, stránka zrkadlí viac verzií)
- Komunitný htmx helper pre CI4: https://packagist.org/packages/michalsn/codeigniter-htmx (MEDIUM)
- htmx hlavička `HX-Request`: https://four.htmx.org/reference/headers/HX-Request (MEDIUM)
- PostgreSQL zámky riadkov, `UPDATE … RETURNING`, `GRANT`, triggery: oficiálna dokumentácia PostgreSQL (HIGH ako znalosť vzoru; verzie nevyžadujú špeciálne funkcie, v tejto session neotvorené)
- Zákonné požiadavky (SK 431/2002, PL art. 106e/113/28b): iba podľa špecifikácie, **neoverené**; implementátor overí podľa aktuálnych predpisov (R1)
- Všeobecné vzory (immutability cez insert-only, gap-free číslovanie riadkom v tabuľke, PWA SW scope): vlastná znalosť a bežná prax (MEDIUM)

---
*Architecture research for: self-hosted fakturácia + evidencia úloh (CodeIgniter 4.7 / PostgreSQL / htmx)*
*Researched: 2026-10-23*
