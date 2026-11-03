# Phase 11: Prehľady, exporty a audit - Research

**Researched:** 2026-11-04
**Domain:** reporting nad nemennými dokladmi (PHP 8.5 / CI4 4.7.5 / PostgreSQL 18), export CSV/XLSX (OpenSpout), ZIP balík, read-only audit viewer, PC tabuľky v htmx fragmentoch
**Confidence:** HIGH (kód a DB overené priamo v repozitári a v bežiacom kontajneri; OpenSpout API overené zo zdrojov tagu v5.13.0)

## Summary

Fáza je takmer celá read-only nadstavba nad existujúcimi tabuľkami (`invoices`, `payments`, `invoice_documents`, `audit_log`, Shield `users`). Jediná nová produkčná závislosť je `openspout/openspout`. Dá sa postaviť bez novej migrácie (voliteľne dva indexy). Všetky súčty musia ísť z jednej triedy (`Reporting\ReportCalculator` navrhnutá nižšie) postavenej na rovnakých stĺpcoch ako `TurnoverCalculator` (`total_entity_ccy`, `turnover_date`).

Výskum odhalil **štyri veci, ktoré odporujú predpokladom v CONTEXT.md / UI-SPEC a plánovač ich musí zohľadniť** (detaily v sekciách nižšie, na potvrdenie vlastníkom v Open Questions):
1. **Externé faktúry sa nedajú zaradiť do prehľadu ani do balíka po subjekte.** `origin='external'` má `entity_id`, `turnover_date`, `total_entity_ccy`, `number` = NULL (CHECK `invoices_origin_shape`). Voľnosť v CONTEXT „externé sa rátajú ako príjem rovnako ako v obrate limitov“ je faktický omyl: `TurnoverCalculator` ich vylučuje (`origin='issued'`).
2. **`counts_toward_limit=false` (klient mimo limitu) rozbíja rovnosť „prehľad = obrat“.** Prehľad podľa CONTEXT nemá filter `counts_toward_limit`, `TurnoverCalculator` ho má. Test D-05 musí túto medzeru modelovať explicitne.
3. **Každá nová trasa je pre inú rolu než `admin` zakázaná už na úrovni `AuthGate`** (`inGroup('admin')` na všetko mimo whitelistu). Oprávnenie `reports.read` sa dnes nikde nepoužíva. Preto netreba samostatný role-filter na `/settings/audit` a `/settings/users`; stačí ich nezaradiť do whitelistu.
4. **Pravidlo PHPStan `ForbiddenCallsRule` zakazuje `(float)` všade** a OpenSpout `NumericCell` prijíma iba `int|float`. Bez úpravy pravidla sa číselná XLSX bunka nedá napísať.

**Primary recommendation:** Jedna servisná trieda `App\Libraries\Reporting\ReportCalculator` (SQL agregácia + bcmath v `Money`) je jediný zdroj čísel pre obrazovku, CSV, XLSX, ZIP-CSV aj test zhody; `openspout/openspout ^5.13` pre XLSX s explicitnými `StringCell`/`NumericCell` (nikdy `Cell::fromValue`), CSV natívne s `fputcsv(..., ';', '"', '')`; odpovede ako `setBody()` s pevnými hlavičkami (rovnaký vzor ako `Invoices::serve`), ZIP cez `ZipArchive` do súboru v `writable/`.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
(skopírované doslovne z 11-CONTEXT.md `## Implementation Decisions`)

**Prehľady (RPT-01)**
- **D-01 [vlastník]:** „Vyfakturované“ sa priraďuje k mesiacu podľa **dátumu dodania** (`turnover_date`), rovnako ako výpočet limitov (spec 6.8). Dobropisy sa odpočítavajú v mesiaci svojho dátumu dodania. Takto sa súčty prehľadu zhodujú so sledovaním obratu na dashboarde.
- **D-02:** „Uhradené“ sa priraďuje podľa **dátumu úhrady** (zrušené úhrady sa nerátajú). Suma úhrady v mene subjektu sa odvodí z uložených hodnôt faktúry (pomerom k `total_entity_ccy`, bcmath, zaokrúhlenie raz na konci). Nikdy nie aktuálnym kurzom (CUR-04, spec 6.10).
- **D-03:** Prehľad je **za jeden subjekt** (sumy v jeho mene). Ak je subjektov viac, je na stránke výber subjektu, predvolený je aktívny subjekt. Sumy rôznych mien sa nikdy nesčítajú.
- **D-04:** Mesačný pohľad: riadky = klienti, stĺpce = vyfakturované / uhradené, súčtový riadok. Ročný pohľad: riadky = mesiace 1 až 12 (plus rozpad po klientoch), súčty za rok. Navigácia predchádzajúci/ďalší mesiac alebo rok.
- **D-05:** Všetky súčty idú z jednej triedy (NFR-04). Test zhody súčtov sa rozšíri: prehľad = súčet faktúr = obrat z `TurnoverCalculator` za rovnaké obdobie = hodnoty v CSV aj XLSX.

**Exporty (RPT-02)**
- **D-06:** XLSX cez `openspout/openspout` (pridať do `require`, produkčná závislosť). CSV natívne `fputcsv`, oddeľovač `;`, UTF-8 BOM. Sumy v CSV majú **desatinnú čiarku** a sú bez oddeľovača tisícov (Excel v SK/PL/CZ lokalite ich načíta ako čísla). XLSX zapisuje sumy ako číselné bunky s formátom `#,##0.00` (nie text).
- **D-07:** Ochrana pred vzorcami: textová bunka začínajúca `=`, `+`, `-`, `@`, tabulátorom alebo CR dostane prefix `'` (CSV) a v XLSX sa zapisuje vždy ako textová bunka (nikdy ako vzorec). Číselné stĺpce (sumy, hodiny) sa neprefixujú. Pokryť testom.
- **D-08:** Exportovať sa dá: mesačný prehľad, ročný prehľad a zoznam faktúr (s aktuálnymi filtrami zoznamu). Tlačidlá CSV / XLSX sú nad tabuľkou. Názov súboru obsahuje subjekt a obdobie (napr. `prehlad-2026-10-<subjekt>.xlsx`).

**Balík pre účtovníčku (RPT-03)**
- **D-09 [vlastník]:** ZIP obsahuje **PDF faktúr a dobropisov** (bez výkazov práce) a CSV zoznam týchto dokladov. Obdobie = mesiac (voliteľne rozsah mesiacov), za jeden subjekt.
- **D-10:** Doklady do balíka sa vyberajú podľa **dátumu dodania** (zhodne s D-01), aby balík sedel s prehľadom. Zrušené (stornované) doklady sa zahrnú a v CSV majú stav. Externé faktúry (`origin='external'`) bez PDF sa uvedú v CSV so stĺpcom „bez PDF“.
- **D-11:** PDF sa berú **z uloženého `bytea` (`DocumentStore`)**, nikdy sa negenerujú znova zo živých nastavení. Názvy súborov cez existujúci `DocumentFilename`. ZIP sa stavia streamovo/do dočasného súboru v `writable/` (512 MB RAM), nie celý v pamäti.
- **D-12:** CSV zoznam v balíku: číslo, typ (faktúra/dobropis), klient, dátum vystavenia, dátum dodania, splatnosť, mena, suma v mene dokladu, kurz, suma v mene subjektu, stav úhrady, dátum úhrady. Rovnaká ochrana pred vzorcami ako D-07.

**Denník zmien (AUD-02)**
- **D-13:** Obrazovka len na čítanie nad existujúcou tabuľkou `audit_log` (append-only). Filtre: entita (typ), používateľ, časový rozsah, akcia. Stránkovanie (najnovšie hore). Detail záznamu zobrazí polia pred a po, zmenené polia zvýraznené. Dostupné len pre `admin` (citlivé údaje sú už v `AuditLogger` scrubbed).

**UI-03 (PC tabuľky a nastavenia)**
- **D-14 [vlastník]:** Zoznamy úloh a faktúr: **rovnaký htmx fragment** vykreslí na šírke ≥ `lg` tabuľku (triediteľné stĺpce, filtre nad tabuľkou) a na mobile ostávajú dnešné karty. Žiadne samostatné „desktopové“ URL ani duplicitné dotazy.
- **D-15:** Obrazovka „Fakturácia mesiaca“ (`/invoicing`) a väčšina nastavení už existujú (subjekty a história, klienti, číslovanie radov, limity, šablóny, zákonné texty, 2FA a zariadenia). Fáza ich iba overí na PC rozložení a doplní chýbajúce položky do menu „Viac“ (Prehľady, Balík pre účtovníčku, Denník zmien, Používatelia).
- **D-16 [vlastník]:** Nastavenia → Používatelia: **zoznam len na čítanie** (e-mail/meno, rola, stav 2FA, posledné prihlásenie). Vytvorenie účtu `accountant` ostáva `spark` príkaz do v2.

### Claude's Discretion
- Presné rozloženie tabuliek, triedenie a stránkovanie (server-side cez query string, bez JS knižníc).
- Či prehľad a balík používajú jednu servisnú triedu na výber dokladov za obdobie (odporúčané, kvôli D-05).
- Zahrnutie externých faktúr do súčtov prehľadu: rátajú sa ako príjem rovnako ako v obrate limitov (`counts_toward_limit` sa na prehľad neaplikuje, prehľad ukazuje celý príjem). **(pozri Critical Finding 1 a 2: tvrdenie je v rozpore s kódom, plán má použiť odporúčanie z tohto výskumu)**
- Limit veľkosti obdobia pre ZIP (napr. max 12 mesiacov) a časový limit generovania.
- Formátovanie a preklady nových textov (SK hotovo v tejto fáze, PL/CZ/EN kľúče pripraviť, preklad je fáza 12).

### Deferred Ideas (OUT OF SCOPE)
- Plné UI pre rolu `accountant` (prihlásenie účtovníčky, obmedzené menu) — v2.
- Vytváranie a deaktivácia používateľov v UI — v2 (teraz `spark`).
- Výkazy práce v balíku pre účtovníčku — zatiaľ nie (D-09), dá sa doplniť neskôr.
- Export KSeF FA(3) — mimo V1 (INV-08, P2).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| RPT-01 | Mesačný a ročný prehľad: vyfakturované a uhradené, podľa klienta, v mene subjektu. | `ReportCalculator` (SQL agregácia nad `invoices.total_entity_ccy` + `turnover_date`; úhrady cez kumulatívne prepočítanie z `payments`), sekcie Architecture Patterns 1 a 2, Pitfalls 1 až 4 |
| RPT-02 | Export prehľadov a zoznamu faktúr do CSV a XLSX (s ochranou pred vložením vzorcov). | OpenSpout 5.13.0 API, `CsvWriter`/`XlsxWriter` obaly, `FormulaGuard`, Pitfalls 5 až 8, rozšírenie `ForbiddenCallsRule` |
| RPT-03 | Export balíka pre účtovníčku za obdobie (ZIP: PDF dokladov a CSV zoznam). | `ZipArchive` (ext-zip v obraze), `DocumentStore::read`, `DocumentFilename::for`, rovnaká selekcia ako prehľad, Pitfalls 9 až 10 |
| AUD-02 | Používateľ prehliada denník len na čítanie, s filtrami. | `AuditQueries` nad `audit_log`, `LIMIT 51`, pre-zoznam filtrov z `DISTINCT`, detail so `jsonb` diffom |
| UI-03 | Na PC sú tabuľkové zoznamy úloh a faktúr, „Fakturácia mesiaca“ a nastavenia (…, používatelia, 2FA, zariadenia). | jeden fragment s `.dt-only-lg`/`.dt-only-sm`, whitelisty triedenia v `InvoiceQueries`/`TaskQueries`, `Settings\Users`, menu „Viac“ |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

- Stack zamknutý: PHP 8.5 FPM, CI4 4.7.x, PostgreSQL, Bootstrap 5.3.8, htmx 2.0.11, Alpine CSP build (v tejto fáze sa Alpine nepoužíva). **Žiadna SPA ani build pipeline.**
- Peniaze `numeric(12,2)`, hodiny `numeric(7,2)`, **žiadny float** (v PHP string + bcmath); bcmath len v `Money`/`Hours`/Fx/Reporting (PHPStan `ForbiddenCallsRule`).
- CSP: žiadne inline `style=""`, `<style>`, inline `<script>`, `on*=` atribúty (stráži `ViewsCspTest`); texty iba cez `lang()` (`ViewsHardcodedTextTest`, `LangKeysTest`).
- XLSX = OpenSpout (nie PhpSpreadsheet v produkcii); CSV natívne `fputcsv`, `;`, BOM.
- Výkon: p95 < 100 ms bežné stránky, 512 MB RAM VPS (ZIP/XLSX nesmú držať veľké dáta v pamäti zbytočne), PDF z uloženého `bytea`, nikdy z živých nastavení.
- Testy: PHPUnit povinne pre výpočty súm; `failOnDeprecation=true`, `failOnWarning=true`, `error_reporting=-1` (`phpunit.dist.xml`).
- GSD workflow: zmeny súborov len cez GSD príkazy (týka sa vykonávania, nie tohto výskumu).
- Pamäť: dev repo je `~/projects/task-tracker` (ext4), `/mnt/c` kópia je zastaralá; staging = `work-tracker-stage.vostack.dev` (develop), test len na staging.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Agregácia súm (vyfakturované/uhradené) | Database / Storage (SQL `SUM`/`GROUP BY`) | API / Backend (`ReportCalculator`, bcmath cez `Money`) | Jeden dotaz na obdobie (UI-SPEC výkon); zaokrúhľovanie a prepočet úhrad v PHP cez `Money` |
| Generovanie CSV/XLSX/ZIP | API / Backend | — | Streamovanie, formula-injection ochrana a názvy súborov sú serverová logika |
| Výber PDF do ZIP | API / Backend (`DocumentStore`) | Database (`invoice_documents.bytea`) | Nemennosť: iba uložené bajty + kontrola sha256 |
| Filtre/triedenie/stránkovanie zoznamov a denníka | API / Backend (whitelist, SQL) | Frontend Server (htmx fragment) | Whitelist stĺpcov musí byť na serveri; URL je reprodukovateľný stav |
| Tabuľka vs. karty (≥992px) | Browser / Client (CSS `.dt-only-*`) | Frontend Server (jeden fragment s oboma reprezentáciami) | D-14: jeden dotaz, CSS rozhoduje o zobrazení |
| Prístup admin-only | API / Backend (`AuthGate`) | — | Už dnes všetko mimo whitelistu vyžaduje `admin` |
| Prehľad používateľov | API / Backend | Database (`users`, `auth_identities`, `auth_groups_users`, `user_security`, `auth_events`) | Čisté čítanie, bez zápisu |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `openspout/openspout` | **^5.13** (v5.13.0, 2026-10-27; CLAUDE.md uvádza 5.12.2, aktuálna je 5.13.0) | streamovaný zápis XLSX | rozhodnuté v CLAUDE.md; `require`: `php ~8.4.0 \|\| ~8.5.0 \|\| ~8.6.0`, ext-dom/filter/libxml/xmlreader/zip (všetko v obraze) |
| PHP `ext-zip` (`ZipArchive`) | súčasť obrazu (`install-php-extensions ... zip`, overené `php -m`) | ZIP balík | už nainštalované v `Dockerfile` |
| `fputcsv` (natívne) | PHP 8.5 | CSV | rozhodnuté D-06 |
| `App\Libraries\Platform\Money` | repo | všetka aritmetika súm | NFR-04 |
| `App\Libraries\Platform\MoneyFormat` | repo | formát súm na obrazovke | existuje |
| `IntlDateFormatter` | ext-intl | názvy mesiacov a dátumy | vzor v `Invoices::dateFormatter()` |

[VERIFIED: packagist p2 openspout/openspout (v5.13.0), `php -m` v kontajneri `task-tracker-php-1` (zip, dom, xmlreader, libxml, filter, bcmath); CLAUDE.md pre rozhodnutie o výbere]

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| PHPUnit | `^11.5` (v `composer.json`; CLAUDE.md píše 10.5 ale repo má 11.5) | testy | všetky nové testy |
| `Tests\Support\{PgTestCase,AuthTestCase,TurnoverFixtures,IssueFixtures,InvoiceFixtures,CreditNoteFixtures,MasterFixtures}` | repo | fixtures | integrácia a feature testy |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `openToFile` + `setBody` | `openToBrowser` | `openToBrowser` posiela hlavičky cez `header()` mimo CI4 `Response`, rozbije HTTP testy a filtre. Nepoužiť. |
| `setBody(file_get_contents)` pre ZIP | `DownloadResponse` (`$this->response->download($path, null, true)`) | DownloadResponse číta po 1 MB (dobré), ale dočasný súbor sa nedá bezpečne zmazať po odoslaní a feature test `getBody()` vráti `null`. Pri limite 12 mesiacov je ZIP rádovo jednotky MB; použiť `setBody` + `unlink`. |
| bcmath v ReportCalculator | SQL `round(sum*tec/total, 2)` | SQL numeric je presný, ale `ForbiddenCallsRule` a konvencia chcú aritmetiku v `Money`/Reporting; plánovač môže pridať `ReportCalculator.php` do `BC_ALLOWED_FILES` (pozri Pitfall 4) |

**Installation:**
```bash
make composer ARGS="require openspout/openspout:^5.13"
```

**Version verification:** `curl repo.packagist.org/p2/openspout/openspout.json` → v5.13.0, 2026-10-27, `php: ~8.4.0 || ~8.5.0 || ~8.6.0` [VERIFIED: packagist]. Prvý prvok p2 poľa je najnovší.

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| openspout/openspout | Packagist | roky (nástupca box/spout, v5 línia) | nezisťované (nie je potrebné: balík je explicitne zvolený používateľom v CLAUDE.md) | github.com/openspout/openspout (zdroj tagu v5.13.0 stiahnutý a prečítaný) | not run | Approved (user-locked v CLAUDE.md `Zhrnutie rozhodnutí`) |

**Packages removed due to slopcheck [SLOP] verdict:** none (slopcheck nespustený; balík je používateľom zamknutý a overený cez Packagist + čítanie zdrojov v5.13.0)
**Packages flagged as suspicious [SUS]:** none
Žiadne `scripts`/postinstall v composer.json balíka nepozorované pri čítaní zdrojov [VERIFIED: tarball v5.13.0 `composer.json` sa nepoužíva na spúšťanie skriptov; ďalšie závislosti nepribúdajú, všetky `ext-*` sú už v obraze].

## Architecture Patterns

### System Architecture Diagram

```
 GET /reports/month|year ───────────► Reports controller ──► ReportCalculator ──► PostgreSQL
 GET /reports/*.csv|.xlsx ──────────►        │                    │  (1 SQL na vyfakturované,
 GET /invoices(.csv|.xlsx) ─────────►        │                    │   1 SQL na úhrady)
 GET /reports/accountant/download ──►        │                    ▼
                                              │             ReportResult (stringy v mene subjektu)
                                              │                    │
                       ┌──────────────────────┼────────────────────┤
                       ▼                      ▼                    ▼
                 HTML fragment          CsvExport/XlsxExport    BundleBuilder
                 (#report-body)         (FormulaGuard, BOM,     (ZipArchive v writable/,
                 .dt-only-lg/sm         OpenSpout StringCell/    DocumentStore::read ->
                                        NumericCell)             DocumentFilename::for,
                                                │                zoznam-dokladov.csv)
                                                ▼                    │
                                        Response::setBody ◄──────────┘
                                        (Content-Disposition attachment, no-store)

 GET /settings/audit ──► Settings\Audit ──► AuditQueries (LIMIT 51, filtre) ──► audit_log
 GET /settings/audit/{id} ► detail sheet (before/after diff z jsonb)
 GET /settings/users ───► Settings\Users ──► UserDirectory (users+auth_identities+groups+user_security+auth_events)
 GET /invoices, /tasks ─► Invoices/Tasks::index ► *Queries::list(filter, sort whitelist) ► jeden fragment (karty + tabuľka)

 Všetko za AuthGate (admin). Zdroj čísla: ReportCalculator == TurnoverCalculator (rovnaké stĺpce).
```

### Recommended Project Structure
```
app/Libraries/Reporting/
├── ReportCalculator.php      # jediný zdroj súm (mesiac/rok/po klientoch + úhrady)
├── ReportPeriod.php          # parsovanie a validácia RRRR-MM / RRRR (VO), predchádzajúce/ďalšie
├── ReportResult.php          # readonly VO: riadky + totals (stringy)
├── BundleSelector.php        # doklady za obdobie pre ZIP aj CSV (rovnaká podmienka ako prehľad)
app/Libraries/Export/
├── FormulaGuard.php          # ochrana textu pred vzorcom (CSV prefix ')
├── CsvWriter.php             # BOM + fputcsv(';', '"', '') + desatinná čiarka z Money stringu
├── XlsxWriter.php            # OpenSpout obal (StringCell/NumericCell, formát #,##0.00, šírky, zmrazený riadok)
├── XlsxNumber.php            # JEDINÉ miesto s (float) castom, povolené v ForbiddenCallsRule
├── ExportFilename.php        # slug + sanitizácia (štýl DocumentFilename)
└── AccountantBundle.php      # ZipArchive stavba, kontrola chýbajúcich PDF
app/Libraries/Audit/AuditQueries.php   # filtre + LIMIT+1 + detail + zoznamy pre selecty
app/Libraries/Auth/UserDirectory.php   # zoznam používateľov (read-only)
app/Controllers/Reports.php            # month, year, exporty, accountant, preview, download
app/Controllers/Settings/Audit.php, Settings/Users.php
app/Config/Routes/reports.php, doplnok app/Config/Routes/settings.php
app/Views/reports/*, settings/audit/*, settings/users/*, invoices/_list.php + tasks/_list.php (úprava)
app/Language/{sk,en}/{Reports,Export,AuditLog,Users}.php  (+ doplnky Invoices.php, Tasks.php, App/Account pre menu)
```
Pozn.: `app/Libraries/Reporting` už existuje (Phase 10); `AuditLog.php` v `Libraries/Auth` je **iná vec** (zapisuje `auth_events`), nezamieňať s `Libraries/Audit/AuditLogger` + `audit_log`.

### Pattern 1: ReportCalculator (vyfakturované) — jeden dotaz, rovnaké stĺpce ako TurnoverCalculator
**What:** `SELECT client_id, SUM(total_entity_ccy) ... WHERE entity_id=:e AND origin='issued' AND turnover_date BETWEEN :from AND :to GROUP BY client_id` (mesiac) resp. `GROUP BY date_trunc('month', turnover_date), client_id` (rok). Súčet za obdobie = súčet cez riadky v PHP cez `Money::sum`, **nie** druhý dotaz, aby tabuľka a „Spolu“ nemohli divergovať.
**When to use:** mesačný, ročný prehľad, všetky exporty, CSV v balíku.
**Example:**
```php
// Source: vzor z app/Libraries/Reporting/TurnoverCalculator.php::turnover() (repo)
$rows = $db->query(
    "SELECT i.client_id, c.name AS client_name,
            COALESCE(SUM(i.total_entity_ccy), 0) AS invoiced,
            COALESCE(SUM(i.total_entity_ccy) FILTER (WHERE i.counts_toward_limit), 0) AS counted
     FROM invoices i JOIN clients c ON c.id = i.client_id
     WHERE i.entity_id = :e: AND i.origin = 'issued' AND i.turnover_date BETWEEN :from: AND :to:
     GROUP BY i.client_id, c.name",
    ['e' => $entityId, 'from' => $from, 'to' => $to],
)->getResultArray();
// zoradenie podľa invoiced zostupne v PHP cez Money::compare, súčet cez Money::sum
```
Stĺpec `counted` (filter `counts_toward_limit`) existuje práve kvôli testu D-05 (viď Pitfall 2) a pre informačný riadok „z toho do limitu“ (voľnosť).

### Pattern 2: ReportCalculator (uhradené) — kumulatívne zaokrúhlenie
**What:** Platba je v mene faktúry; v mene subjektu = podiel na `total_entity_ccy`. Aby súčet plne uhradenej faktúry v mene subjektu bol presne `total_entity_ccy` (nie ±0,01), počítať **kumulatívne**: pre platby faktúry zoradené `paid_on, id`: `entity_k = round(cum_k * tec / total) - round(cum_{k-1} * tec / total)`. Zaokrúhlenie raz na hodnotu kumulácie (D-02 „zaokrúhlenie raz na konci“), `RoundingMode::HalfAwayFromZero` ako v `Money::timesHours`.
**Dotaz:** všetky nezrušené platby faktúr, ktoré majú aspoň jednu platbu v období (kumulácia potrebuje aj platby pred obdobím):
```sql
SELECT p.id, p.invoice_id, p.paid_on, p.amount, i.client_id, i.total, i.total_entity_ccy,
       SUM(p.amount) OVER (PARTITION BY p.invoice_id ORDER BY p.paid_on, p.id) AS cum_amount
FROM payments p JOIN invoices i ON i.id = p.invoice_id
WHERE i.entity_id = :e: AND i.origin = 'issued'
  AND NOT EXISTS (SELECT 1 FROM payment_voids v WHERE v.payment_id = p.id)
  AND i.id IN (SELECT p2.invoice_id FROM payments p2 JOIN invoices i2 ON i2.id=p2.invoice_id
               WHERE i2.entity_id=:e: AND p2.paid_on BETWEEN :from: AND :to:)
```
Potom v PHP: pre každú faktúru prejsť platby vo vzostupnom poradí, držať `prevEntityCum`, a sumovať do obdobia len tie s `paid_on` v rozsahu. **Guard:** `total = 0` → entitná suma 0 (žiadne delenie nulou; faktúra s `total 0` sa nikdy neuhrádza automaticky). Delenie a násobenie len v `Money`/`Reporting` súbore povolenom v pravidle (pozri Pitfall 4).
**Pozn.:** Preplatok (platba > total) je možný? `invoice_status_v` povoľuje `paid >= total`; ak by `cum > total`, kumulácia by prekročila `tec`. [ASSUMED: PaymentService preplatok odmieta; overiť čítaním `PaymentService` pri plánovaní] — v každom prípade clampnúť `cum` na `total` pre výpočet entitnej sumy alebo test s preplatkom.

### Pattern 3: Jeden fragment, dve reprezentácie (D-14)
**What:** `_list.php` obsahuje `<div class="dt-only-sm">` (existujúce karty) a `<div class="dt-only-lg">` (`<table class="dt-table">`) nad tým istým `$rows`. Triedenie: odkazy `.dt-sort` s `hx-get` + `hx-push-url` + rovnaký `hx-target` ako formulár; **whitelist** v `InvoiceQueries::list()`/`TaskQueries::list()` (mapa `kľúč => bezpečný SQL výraz`, nikdy interpolácia vstupu).
**Existujúci kód:** `Invoices::index` volá `htmx_view('invoices/_list', ..., 'invoices/index')`; `InvoiceQueries::list(int $limit, ?string $status, ?string $today)`; `Tasks::index` používa `TaskFilter::fromQuery()` + `TaskQueries::list(TaskFilter, 200)` s pevným `ORDER BY (...), t.created_at DESC`. Rozšíriť podpisy o voliteľný `sort`/`dir` (a pre faktúry `client`, `q`, `from`, `to`) bez zmeny predvolených hodnôt (existujúce testy `InvoicesListTest`/`InvoicesListStatusTest` musia ostať zelené).
**Export zoznamu faktúr:** nový parameter `limit` = `null`/veľký pre export (D-08/UI-SPEC: export je úplný napriek `truncated`); použiť rovnakú metódu, aby filter a triedenie boli identické.

### Pattern 4: Audit viewer
`AuditQueries::list(AuditFilter, page)`: `WHERE` zo whitelistu (entity_type, action, user_id, occurred_at >= from, occurred_at < to + 1 day), `ORDER BY occurred_at DESC, id DESC LIMIT 51 OFFSET (page-1)*50`. Existujúci index je `(entity_type, entity_id, occurred_at DESC)`: filtre bez `entity_type` ho nepoužijú, pri jednom používateľovi (~700 riadkov na dev) to je zanedbateľné, ale pri raste odporúčam **migráciu s indexom `audit_log (occurred_at DESC)`** (jednoduchá, owner migrácia; `audit_log` zostáva insert-only, index nemení oprávnenia). Zoznamy pre `select` (entity_type, action): `SELECT DISTINCT entity_type FROM audit_log ORDER BY 1` a `SELECT DISTINCT action ...`; popisky cez `lang('AuditLog.entity.<kľúč>')` s fallbackom na surový kľúč (UI-SPEC). **Reálne hodnoty v dev DB:** entity_type `invoice_send, payment_void, billing_allocation, invoice, invoice_series, bank_account, entity_version, entity, task, payment, client` (+ v kóde `legal_text, turnover_limit, entity_opening_turnover, invoice_send_void`), action `create, update, delete, issue, mark_done, credit_note_issue, external_lock, archive, set_default`. Zoznam akcií v UI-SPEC („Odoslané, Uhradené, Stornované“) **nezodpovedá** skutočným hodnotám; akcie musia byť dynamické z `DISTINCT action`.
**Detail:** `before_data`/`after_data` sú `jsonb` (stringy po `getResultArray`) → `json_decode(..., true, 512, JSON_THROW_ON_ERROR)`; union kľúčov; „zmenené“ ak `before[k] !== after[k]`; hodnoty ne-skalárne zobraziť ako `json_encode(..., JSON_UNESCAPED_UNICODE)`; všetko cez `esc()`. `AuditLogger::scrub()` už odstránil citlivé kľúče (regex `password|secret|token|code_hash|hash`), takže „skryté“ znamená **kľúč chýba v zázname**: stĺpec „skryté“ zobraziť len ak by existoval marker; inak sa nezobrazuje (nie je čo skrývať). Pri vytvorení je `before_data` NULL a pri `recordChange` obsahujú oba objekty len zmenené polia (`AuditLogger::diff`), preto nezmenené polia v detaile nemusia existovať; rendering musí zvládnuť kľúč prítomný len na jednej strane.
**Meno používateľa:** `users` nemá e-mail; e-mail je v `auth_identities.secret` pre `type='email_password'` [VERIFIED: psql]. `LEFT JOIN auth_identities ai ON ai.user_id = u.id AND ai.type = 'email_password'`. `audit_log.user_id` je `ON DELETE SET NULL` → zobraziť „systém/neznámy“ pre NULL (CLI akcie majú `ip='cli'`).

### Pattern 5: Users (read-only)
Jeden dotaz: `users` (aktívne, `deleted_at IS NULL`) + e-mail z `auth_identities` + skupina z `auth_groups_users` (`"group"` je rezervované slovo, v SQL cituj `"group"`) + `user_security.totp_enabled_at IS NOT NULL` (2FA zapnuté) + posledné prihlásenie `MAX(auth_events.occurred_at) FILTER (WHERE success AND event IN ('login_password_ok','login_totp_ok','login_no_totp','device_login'))`. `users.last_active` je `timestamp` bez zóny a aktualizuje sa pri aktivite, nie pri prihlásení; **použiť `auth_events`** (`timestamptz`, indexované `user_id, occurred_at DESC`). Rola: `Config\AuthGroups::$groups[...]['title']` alebo `lang()` kľúče (UI-SPEC: „Administrátor“/„Účtovníčka“).

### Anti-Patterns to Avoid
- **`Cell::fromValue()` / `Row::fromValues()` na užívateľský text** — vytvára `FormulaCell` pre reťazec začínajúci `=` (overené v zdroji). Vždy explicitne `new StringCell($text, $style)`.
- **`openToBrowser()`** — mimo CI4 response.
- **`fputcsv` bez 5. parametra** — PHP 8.5 vyhodí `Deprecated`, `failOnDeprecation=true` zhodí test (overené v kontajneri). Vždy `fputcsv($f, $row, ';', '"', '')`.
- **Prefixovanie číselných stĺpcov apostrofom** — „-123,45“ začína `-`; typované hodnoty (`ExportMoney`/`ExportText`) nie reťazce s heuristikou, inak sa záporné dobropisy rozbijú.
- **Druhý SQL dotaz na „Spolu“** — súčet z už načítaných riadkov.
- **Interpolácia `sort`/`dir` do SQL** — iba whitelist mapa.
- **Dvojitá aplikácia `htmx_view` + samostatná „desktop“ trasa** — D-14 to zakazuje.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| XLSX zápis | vlastný XML/zip writer | `openspout/openspout` | streamovanie, escaping, štýly, šírky stĺpcov, freeze panes |
| CSV escaping | `implode(';')` | `fputcsv(..., ';', '"', '')` | úvodzovky, `;` a nové riadky v názvoch klientov |
| ZIP | ručný zip kontajner | `ZipArchive` | už v obraze |
| Peniaze | `float`, `number_format`, `round` | `Money` + `MoneyFormat` | pravidlo PHPStan, NFR-04 |
| Názov mesiaca | pole názvov | `IntlDateFormatter` (pattern `LLLL yyyy`) | lokalizácia |
| Názvy PDF | vlastné pravidlo | `DocumentFilename::for($kind, $docLanguage, $number)` | D-07 z Phase 5; pre dobropis `kind='credit_note'` |
| Čítanie PDF | `Query Builder` na `bytea` | `DocumentStore::read()` (`pg_unescape_bytea` + `verify` sha256) | binárne dáta |
| Fragment vs. stránka | vlastná detekcia | `htmx_view()` + `HtmxVary` filter | existuje |
| Role kontrola | vlastný filter | `AuthGate` (už vyžaduje `admin`) | nepridávať druhý mechanizmus |

**Key insight:** Najdrahšie chyby tu nie sú v knižniciach, ale v tichých rozdieloch medzi „súčet na obrazovke“ a „súčet v súbore“. Preto jeden `ReportResult` objekt, z ktorého oba výstupy len formátujú.

## Runtime State Inventory

Nie je rename/refactor/migrácia fáza. Sekcia sa vynecháva (greenfield nadstavba, žiadne premenované reťazce).

## Common Pitfalls

### Pitfall 1 (Critical): Externé faktúry nemajú subjekt ani dátum dodania
**What goes wrong:** Plán podľa CONTEXT zahrnie `origin='external'` do súčtov a do balíka, ale tieto riadky majú `entity_id IS NULL`, `turnover_date IS NULL`, `total_entity_ccy IS NULL`, `number IS NULL`, `counts_toward_limit = false` (CHECK `invoices_origin_shape`) [VERIFIED: migrácia CreateInvoices a `\d invoices`]. Dotaz s `entity_id = :e` ich nikdy nevráti; v mene subjektu nemajú sumu; dátum dodania (D-10) nemajú.
**Why it happens:** CONTEXT predpokladal, že externé sa rátajú „ako v obrate limitov“, ale `TurnoverCalculator` ich vylučuje (`origin = 'issued'`).
**How to avoid:** Odporúčanie: **vylúčiť externé z súčtov prehľadu, exportov aj z výberu balíka** (zhoduje sa s `TurnoverCalculator`, D-05 platí). V UI-SPEC sú texty „Zahŕňa externé faktúry“ a „{d} externých faktúr nemá PDF“: tie nahradiť/vynechať (jedna `lang` hodnota „Externé faktúry (vystavené mimo aplikácie) nie sú v prehľade“ je čestnejšia). Alternatíva pre balík, ak vlastník trvá na D-10: pripojiť externé podľa `billing_month` v rozsahu a `client`→(žiadny subjekt), v CSV s prázdnymi stĺpcami subjektu/sumy v mene subjektu. Vyžaduje rozhodnutie vlastníka (Open Question 1).
**Warning signs:** test s externou faktúrou v období, ktorá sa v CSV objaví s `NULL` v číselných stĺpcoch.

### Pitfall 2 (Critical): `counts_toward_limit` vs. „celý príjem“
**What goes wrong:** `TurnoverCalculator` filtruje `counts_toward_limit AND origin='issued'`; prehľad podľa CONTEXT nie. Pre klienta s `clients.counts_toward_limit=false` (faktúry s `counts_toward_limit=false`) sa súčty rozídu a test D-05 „prehľad = obrat“ padne bez vysvetlenia.
**How to avoid:** `ReportCalculator` vráti dva čísla: `invoiced` (celý príjem, tabuľka) a `counted` (rovnaká podmienka ako obrat). Test D-05: `counted == TurnoverCalculator::turnover()['total']` pre rovnaké obdobie (mesiac, rok, vrátane dobropisov) a `invoiced == SUM(všetky issued)`. Na obrazovke riadok „z toho započítané do limitu“ iba ak sa líšia. Dobropis dedí `counts_toward_limit` z originálu (`CreditNoteIssuer` r. 281) → konzistentné.

### Pitfall 3: Dobropis a storno v mesiacoch
**What goes wrong:** Faktúra v mesiaci A (`turnover_date` A), dobropis v mesiaci B → mesiac A je plný, B záporný; ročný súčet 0. To je správne podľa D-01, ale pri `GROUP BY client` môže byť riadok klienta záporný alebo nulový. Neskrývať riadky s nulou/zápornou hodnotou. `total_entity_ccy` dobropisu je záporné (CHECK `total <= 0`) [ASSUMED pre `total_entity_ccy`; potvrdené nepriamo, lebo `TurnoverCalculator` ho sumuje a testy `TurnoverCalculatorTest` pokrývajú dobropisy].

### Pitfall 4: Pravidlo `ForbiddenCallsRule` blokuje bcmath a `(float)` mimo allowlistu
**What goes wrong:** Prvé spustenie `composer stan` na novom kóde zlyhá: `bcmul/bcdiv/bcround` sú povolené len v `Money`, `Hours`, `Fx*`, `LimitMath`, `PeriodResolver` (`BC_ALLOWED_FILES`); `(float)` je zakázané **bezpodmienečne** (aj v povolených súboroch), `round`, `number_format`, `/` operátor tiež [VERIFIED: tests/_support/PhpStan/ForbiddenCallsRule.php].
**How to avoid:** (a) Podiel úhrady (`cum * tec / total`) implementovať ako metódu v `Money` (napr. `Money::proportion(string $part, string $whole)`) alebo vo vlastnom `Reporting/ReportMath.php` pridanom do `BC_ALLOWED_FILES` (vzor `LimitMath`); (b) pre XLSX číslo pridať **nový** zoznam `FLOAT_CAST_ALLOWED_FILES = ['app/Libraries/Export/XlsxNumber.php']` a v `processNode` vrátiť `[]` pre `Double` len v tomto súbore; aktualizovať `phpstan.selftest.neon`/`tests/_support/PhpStan/selftest.php`, aby overil, že cast mimo súboru stále padá. `XlsxNumber::fromMoney(Money $m): float` musí cez `Money::of` overiť formát (max 10 + 2 miesta), potom `(float) $m->toDecimalString()`.
**Overené správanie:** `(string)(float)'1080.50'` → `"1080.5"`, `(string)1234567890.12` → `"1234567890.12"`; `serialize_precision=-1` (shortest round-trip), preto OpenSpout (`'<v>'.$cell->getValue().'</v>'`) zapíše presne max. 2 desatinné miesta bez exponentu (exponent až od 1e15) [VERIFIED: php 8.5.12 v kontajneri; WorksheetManager riadok 185]. Test: pre vzorku hodnôt (vrátane 0.10, 0.07, 9999999999.99, záporných) rozbaliť XLSX a porovnať `<v>` s `Money` stringom.

### Pitfall 5: `Cell::fromValue` mení `=...` na vzorec (XLSX formula injection)
**What goes wrong:** `Cell::fromValue('=HYPERLINK("http://evil")')` → `FormulaCell` → `<f>` v XML → Excel vzorec spustí [VERIFIED: zdroj `src/Common/Entity/Cell.php`: `isset($value[0]) && '=' === $value[0]` → `FormulaCell`]. Aj `Row::fromValues` to volá interne.
**How to avoid:** Obal `XlsxWriter` vždy zostaví `new StringCell($text, $style)` pre text a `new NumericCell($float, $style)` pre čísla, nikdy `fromValue`/`fromValues`. Nemá sa používať ani `$hyperlinkUrl`. Test: názov klienta `=1+1`, `+cmd`, `-2`, `@SUM(A1)`, `\tx`, `\rx` → po rozbalení XLSX v `sheet1.xml` nesmie byť `<f>` a bunka je `t="inlineStr"` (`SHOULD_USE_INLINE_STRINGS=true` je default; [VERIFIED: Options.php riadok 35]).

### Pitfall 6: CSV prefix `'` a číselné stĺpce
**What goes wrong:** Heuristika „začína `-` → prefix“ pokazí záporné sumy dobropisov. D-07 to vyžaduje oddeliť: **typované hodnoty**. `FormulaGuard::text(string): string` aplikovať iba na textové stĺpce; sumy idú cez `CsvWriter::money(string)` (desatinná čiarka). Rozšírenie (voliteľné hardening): kontrolovať prvý znak po `ltrim` bielych znakov a aj `\n`; OWASP uvádza `= + - @ \t \r` (a niekedy `\n`). Poznámka: v CSV je `'` po otvorení v Excel viditeľný ako znak; to je prijatý kompromis D-07.

### Pitfall 7: BOM a `;` pre lokalizovaný Excel
UTF-8 BOM `"\xEF\xBB\xBF"` na začiatku; oddeľovač `;`; konce riadkov `\r\n` (`fputcsv` používa `\n`: ponechať, Excel zvláda; ak test vyžaduje CRLF, konkrétne rozhodnúť). Desatinná čiarka a žiadny oddeľovač tisícov z `Money::toDecimalString()` → `str_replace('.', ',', ...)`. Dátumy ako `RRRR-MM-DD` (ISO; Excel v SK/PL/CZ ich pri otvorení dvojklikom rozpozná). Kurz s 8 desatinnými miestami tiež s čiarkou.

### Pitfall 8: `fputcsv` deprecation v PHP 8.5
Pozri Anti-patterns: povinný 5. argument `''`. [VERIFIED: spustené v kontajneri: „Deprecated: fputcsv(): the $escape parameter must be provided“].

### Pitfall 9: ZIP pamäť a časový limit
`ZipArchive::addFromString` drží obsah do `close()`; pri limite 12 mesiacov a PDF ~50 až 150 kB je to rádovo jednotky MB. Zaviesť tvrdý strop (napr. súčet `byte_size` z `invoice_documents` > 64 MB → odmietnuť s chybou „obdobie je príliš veľké“; `byte_size` je v DB, takže sa dá overiť **pred** čítaním bajtov, bez načítania). Dočasný súbor v `writable/` (`tempnam(WRITEPATH . 'exports', 'bundle')`), `try/finally` s `unlink`. `set_time_limit` neni potrebné pri tomto rozsahu; FPM `request_terminate_timeout`/nginx `fastcgi_read_timeout 30s` je horná hranica [CITED: CLAUDE.md nginx]. Pred stavbou **overiť všetky PDF existujú** (`invoice_documents` pre `kind='invoice'`) a pri chýbajúcich vrátiť chybu bez čiastočného ZIP (UI-SPEC). `DocumentStore::read` hodí `DocumentIntegrityError` pri nezhode sha256 → log `critical` ako `Invoices::serve`, balík sa nevytvorí.
**Názvy v ZIP:** `DocumentFilename::for('invoice'|'credit_note', doc_language, number)`; číslo faktúry je unikátne v subjekte, takže kolízie nevznikajú pre jeden subjekt; pridať kontrolu duplicity a pri kolízii pripnúť `_{id}`. `ZipArchive::addFromString('doklady/' . $name, $bytes)`; v CSV rovnaký názov súboru v stĺpci „Súbor“ (voľnosť) pre previazanie.

### Pitfall 10: Obdobie a mesiac → rozsah dátumov
Mesiac `RRRR-MM` → `from = RRRR-MM-01`, `to = posledný deň` — bez `date()`/`strtotime` (zakázané mimo `Clock`). Použiť `BillingMonth` (existuje v `Work`) alebo `cal_days_in_month` nie je v obraze zaručené; bezpečnejšie `DateTimeImmutable` je tiež **zakázané** mimo Clock (`TIME_CLASSES`). Existuje `PeriodResolver` a `Clock::parseDate` (`Clock.php:36`) a `service('clock')->today()`; rozšíriť `Clock`/`Reporting\ReportPeriod` (súbor povolený pre čas len `Clock.php`/`FrozenClock.php`, takže mesačné hranice počítať z reťazcov: posledný deň = `intdiv`-based tabuľka dní + priestupný rok, alebo využiť SQL `(date_trunc('month', CAST(:m: || '-01' AS date)) + interval '1 month - 1 day')::date`). Odporúčané: **SQL** na hranice (`BETWEEN :m-01 AND (:m-01 + interval '1 month - 1 day')`), alebo `turnover_date >= :first AND turnover_date < :first_next_month`. Test s `FrozenClock`.

### Pitfall 11: Netestovateľný export v HTTP teste
`$this->response->download()` + `DownloadResponse` v `FeatureTestTrait`: `getBody()` je null. Používať `setBody($bytes)` + hlavičky (`Content-Type`, `Content-Disposition: attachment; filename="..."`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`, `Content-Length`) ako v `Invoices::serve`. Hodnoty Content-Type: `text/csv; charset=UTF-8`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `application/zip`.

### Pitfall 12: Názov súboru / `Content-Disposition` injection
Názov obsahuje subjekt (`name_internal`); slug do `[a-z0-9-]` (bez diakritiky: `transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s)` (ext-intl) + `preg_replace('/[^a-z0-9]+/','-')`, trim `-`, fallback „subjekt“) — nikdy surové meno v hlavičke.

### Pitfall 13: Úhrady zrušených / dobropisových faktúr
Zrušená úhrada (`payment_voids`) sa nerátať (D-02). Faktúra zrušená dobropisom (`status='cancelled'`) môže mať platby; platba je reálna udalosť v mesiaci `paid_on` a rátať sa má. Dobropis sám nemá platby (`payments.amount > 0` a PaymentService pracuje s `type='invoice'`) [ASSUMED: overiť v `PaymentService` pri plánovaní; ak dobropis platbu môže mať, query musí povoliť aj `type='credit_note'`]. Test D-02: platba v inom mesiaci než faktúra, čiastočná platba, zrušená platba.

### Pitfall 14: Sticky/sheet a CSP v nových pohľadoch
`ViewsCspTest` hľadá `style=`, `on*=`, `<style>`, `javascript:` v každom views súbore. Ľavé okraje, farby, šírky stĺpcov iba triedy v `app.css` (nová verzia súboru sa rieši automaticky `?v=filemtime`). `hx-*` atribúty sú v poriadku (vzor v `invoices/index.php`).

### Pitfall 15: Čas „dnes“ a budúce obdobie
`Ďalší ›` je `disabled` pre budúce obdobie: porovnávať `RRRR-MM` reťazce s `service('clock')->today()` (nie `date()`). V testoch `FrozenClock` (vzor `Services::injectMock('clock', new FrozenClock(...))`).

## Code Examples

### OpenSpout 5.13 — zápis do súboru, štýly, šírky, freeze, formát čísla
```php
// Source: zdroj v5.13.0 (src/Writer/XLSX/Writer.php, Options.php, Common/Entity/Style/Style.php, AbstractWriter.php), prečítané 2026-11-04
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

$options = new Options();           // SHOULD_USE_INLINE_STRINGS = true (default)
$options->setColumnWidth(32, 1);    // 1-based indexy stĺpcov
$options->setColumnWidth(16, 2, 3);

$writer = new Writer($options);
$writer->openToFile($tmpPath);      // nie openToBrowser (posiela header() mimo CI4 Response)

$bold   = (new Style())->withFontBold(true);
$money  = (new Style())->withFormat('#,##0.00');

$writer->addRow(new Row([new StringCell('Klient', $bold), new StringCell('Vyfakturované', $bold)]));
$writer->addRow(new Row([
    new StringCell(FormulaGuardXlsx::passThrough($clientName)),   // text vždy StringCell (nikdy Cell::fromValue)
    new NumericCell(XlsxNumber::fromMoney($invoiced), $money),    // jediný (float) v XlsxNumber
]));
$writer->close();
```
Zmrazený prvý riadok: `OpenSpout\Writer\XLSX\Entity\SheetView` (`freezeRow`, `freezeColumn`; konštruktor `SheetView(non-negative-int $freezeRow, non-empty-string $freezeColumn ...)`) nastaviť cez `$writer->getCurrentSheet()->setSheetView(...)` [CITED: zdroj `src/Writer/XLSX/Entity/SheetView.php`; presný podpis overiť pri implementácii, v tomto behu prečítaný len začiatok]. Automatický filter: `Sheet::setAutoFilter(new AutoFilter(...))` (voliteľné).

### CSV s BOM, `;`, desatinnou čiarkou a ochranou
```php
// Source: overené v php 8.5.12 (fputcsv vyžaduje 5. parameter)
final class CsvWriter
{
    /** @param list<list<string|ExportMoney>> $rows */
    public static function render(array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($h, array_map(static fn ($c) => $c instanceof ExportMoney ? str_replace('.', ',', $c->decimal) : FormulaGuard::text((string) $c), $row), ';', '"', '');
        }
        rewind($h);
        return (string) stream_get_contents($h);
    }
}
final class FormulaGuard
{
    public static function text(string $v): string
    {
        return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
    }
}
```

### Kontrola, že export a prehľad majú rovnaké čísla (vzor testu D-05)
```php
// Source: vzor tests/integration/Reporting/TurnoverCalculatorTest.php (repo)
$report = (new ReportCalculator($this->rw()))->month($entity, '2026-10');
$turn   = (new TurnoverCalculator($this->rw()))->turnover($entity, '2026-10-01', '2026-10-31');
$this->assertSame($turn['total'], $report->countedTotal);                    // vrátane dobropisov
$this->assertSame($this->sqlSumAllIssued($entity, '2026-10'), $report->invoicedTotal);
$csv = parseCsv(CsvWriter::render(...)); $this->assertSame(str_replace('.', ',', $report->invoicedTotal), $csv['Spolu']['Vyfakturované']);
$xlsx = readXlsxNumbers($path);  // ZipArchive + SimpleXML nad xl/worksheets/sheet1.xml
```
V testoch XLSX čítať cez `ZipArchive`/`SimpleXML`, **nie** cez PhpSpreadsheet (dev závislosť je k dispozícii v `require-dev`, ale test by nemal byť od nej závislý, ani od `openspout` Reader-a, aby test overoval skutočný výstup).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `fputcsv` s default escape | explicitný 5. parameter `''` | PHP 8.4 (deprecation), platí v 8.5 | bez neho `failOnDeprecation` zhodí testy |
| `box/spout` | `openspout/openspout` v5 (`Cell` hierarchia: `StringCell`, `NumericCell`, `FormulaCell`...) | v4/v5 | `Cell::fromValue` auto-vzorec: nové riziko |
| PhpSpreadsheet export | OpenSpout streaming | rozhodnutie CLAUDE.md | pamäť na 512 MB |

**Deprecated/outdated:**
- `Writer::openToBrowser` v kontexte CI4 (nepoužívať).
- CLAUDE.md uvádza OpenSpout 5.12.2; aktuálna 5.13.0 (rovnaké PHP obmedzenia). PHPUnit v repo je 11.5 (nie 10.5 z CLAUDE.md).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `PaymentService` odmieta preplatok a platby na dobropis (kumulatívny výpočet predpokladá `cum <= total`) | Pattern 2, Pitfall 13 | Kumulácia > `tec` → súčet uhradené v mene subjektu presiahne faktúru; ošetriť clampom + testom |
| A2 | `total_entity_ccy` dobropisu je záporné | Pitfall 3 | Dobropisy by sa neodpočítali; overiť jedným čítaním `CreditNoteIssuer` alebo testom |
| A3 | `SheetView` API (`setSheetView`, freeze parametre) presný podpis | Code Examples | Mierna úprava kódu; overiť pri implementácii |
| A4 | Dopyty `DISTINCT entity_type/action` zostanú lacné aj po raste audit_log | Pattern 4 | Pri veľkom raste pomalé selecty; pridať materializovaný zoznam alebo `lang` whitelist |
| A5 | Nginx/FPM prostredie zvláda ZIP do 12 mesiacov v čase pod 30 s | Pitfall 9 | Timeout; zmerať na staging |
| A6 | Excel v SK/PL/CZ otvorí CSV `;` + BOM + čiarku dvojklikom správne (podľa D-06/CONTEXT) | Pitfall 7 | Overiť manuálne na staging (HV krok) |

## Open Questions

1. **Externé faktúry v prehľade a balíku (Critical)**
   - What we know: nemajú `entity_id`, `turnover_date`, `total_entity_ccy`, `number`; `TurnoverCalculator` ich vylučuje.
   - What's unclear: či vlastník chce externé v balíku napriek tomu (D-10) a ako ich priradiť k subjektu (nemajú).
   - Recommendation: vylúčiť z prehľadov a z balíka (jednoduché, zhoduje sa s D-05); ak vlastník trvá, pridať do CSV balíka osobitnú sekciu „Externé faktúry (bez PDF)“ riadenú `billing_month` a klientom bez sumy v mene subjektu. Rozhodnúť pred plánovaním; inak plán prijme „vylúčiť“ ako default.
2. **`counts_toward_limit=false` v prehľade** — viď Pitfall 2; odporúčanie: zobraziť `invoiced` ako hlavné číslo a len v teste/voliteľne `counted`.
3. **Zoznam akcií v UI-SPEC D1** nezodpovedá dátam: dynamický zoznam z `DISTINCT action` s `lang` popiskami (potvrdiť, že UI-SPEC ide upraviť).
4. **Ordering `uhradené` pre dobropisom zrušenú faktúru** — platba zostáva v mesiaci `paid_on` (reálna udalosť); potvrdiť s účtovníčkou? Default: ponechať.
5. **ZIP dĺžka obdobia a veľkosť** — strop 12 mesiacov (UI-SPEC) + strop súčtu `byte_size` navrhnutý v Pitfall 9.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.5 (kontajner) | všetko | ✓ | 8.5.12 | — (host má 8.3.6, **nepoužívať**, testy len cez kontajner) |
| ext-zip | ZIP + OpenSpout | ✓ | v kontajneri (`php -m`) | — |
| ext-dom, xmlreader, libxml, filter | OpenSpout | ✓ | v kontajneri | — |
| ext-bcmath, intl, gd, mbstring | existujúce | ✓ | v kontajneri | — |
| `transliterator_transliterate` (ext-intl) | slug názvov súborov | ✓ (ext-intl) [ASSUMED: Transliterator dostupný] | — | `preg_replace` len ASCII s náhradou `-` |
| PostgreSQL 18 | dotazy | ✓ (`task-tracker-db-1` healthy) | 18 | — |
| Docker compose (`make test`, `make composer`) | testy, install | ✓ | php, db, nginx, mailpit up | — |
| `vendor/` na hostiteľovi | — | ✗ (vendor žije v kontajneri) | — | spúšťať cez `make`/`docker compose exec` |

**Poznámka:** dev DB v kontajneri nie je migrovaná na najnovšiu migráciu (posledná zapísaná `2026-10-30-000005`), kým test DB sa pripravuje cez `make test-db`. Fáza nezávisí od nových stĺpcov okrem prípadného nového indexu.

**Missing dependencies with no fallback:** žiadne.
**Missing dependencies with fallback:** žiadne blokujúce.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5 (CI4 `CIUnitTestCase`, `PgTestCase`, `AuthTestCase`) |
| Config file | `phpunit.dist.xml` (suites `unit`, `integration`, `feature`; `failOnDeprecation`, `failOnWarning`, `failOnNotice`, `error_reporting=-1`) |
| Quick run command | `make test-unit` (resp. `docker compose exec -T -u www-data php vendor/bin/phpunit tests/unit/Reporting`) |
| Full suite command | `make test` (pred tým `make test-db`); `make stan` + `make cs` pre `ForbiddenCallsRule` a štýl; `make check` všetko |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| RPT-01 | mesačné/ročné súčty po klientoch, dobropisy v mesiaci dodania | integration | `phpunit tests/integration/Reporting/ReportCalculatorTest.php` | ❌ Wave 0 |
| RPT-01 | úhrady podľa `paid_on`, voidy, kumulatívne zaokrúhlenie, preplatok | integration | `phpunit tests/integration/Reporting/ReportPaymentsTest.php` | ❌ Wave 0 |
| RPT-01/D-05 | prehľad = SUM(faktúry) = `TurnoverCalculator` = dashboard = CSV = XLSX | integration | `phpunit tests/integration/Reporting/ReportTotalsMatchTest.php` | ❌ Wave 0 |
| RPT-01 | obrazovky mesiac/rok, prázdny stav, neplatné obdobie, výber subjektu | feature | `phpunit tests/feature/Reports/ReportsPageTest.php` | ❌ Wave 0 |
| RPT-02 | CSV: BOM, `;`, čiarka, úvodzovky, prefix `'` len na texte, záporné sumy bez prefixu | unit | `phpunit tests/unit/Export/CsvWriterTest.php` | ❌ Wave 0 |
| RPT-02 | `FormulaGuard` (`= + - @ \t \r`) | unit | `phpunit tests/unit/Export/FormulaGuardTest.php` | ❌ Wave 0 |
| RPT-02 | XLSX: číselné bunky `#,##0.00`, žiadny `<f>`, `inlineStr`, hodnoty = Money | integration | `phpunit tests/integration/Export/XlsxWriterTest.php` | ❌ Wave 0 |
| RPT-02 | endpointy `.csv/.xlsx` (hlavičky, názov súboru, filtre faktúr, plný export pri `truncated`) | feature | `phpunit tests/feature/Reports/ExportEndpointsTest.php` | ❌ Wave 0 |
| RPT-02 | PHPStan pravidlo: `(float)` povolený len v `XlsxNumber.php` | unit (selftest) | `composer stan` (+ `php tests/_support/PhpStan/selftest.php`) | ✅ (rozšíriť) |
| RPT-03 | ZIP: obsah = PDF z `bytea` (sha256 zhoda), `zoznam-dokladov.csv`, názvy cez `DocumentFilename`, storno zahrnuté, chýbajúce PDF → chyba bez ZIP | integration | `phpunit tests/integration/Export/AccountantBundleTest.php` | ❌ Wave 0 |
| RPT-03 | balík = prehľad (rovnaké doklady a sumy), limit 12 mesiacov, 0 dokladov | feature | `phpunit tests/feature/Reports/AccountantBundleTest.php` | ❌ Wave 0 |
| AUD-02 | filtre (entita, akcia, používateľ, rozsah vrátane celého dňa „do“), stránkovanie 50 + LIMIT+1, detail diff | integration/feature | `phpunit tests/integration/Audit/AuditQueriesTest.php` ; `tests/feature/Settings/AuditViewerTest.php` | ❌ Wave 0 |
| AUD-02 | read-only: žiadny POST, 404 pre neexistujúce ID, 403 pre ne-admin | feature | `phpunit tests/feature/Settings/AuditViewerTest.php` | ❌ Wave 0 |
| UI-03 | fragment obsahuje tabuľku aj karty, whitelist triedenia, `aria-sort`, filtre faktúr | feature | `phpunit tests/feature/Invoicing/InvoicesListTableTest.php`, `tests/feature/Work/TasksListTableTest.php` | ❌ Wave 0 (existujúce `InvoicesListTest`, `InvoicesListStatusTest` musia ostať zelené) |
| UI-03 | `/settings/users` read-only, 2FA stav, posledné prihlásenie, menu „Viac“ | feature | `phpunit tests/feature/Settings/UsersListTest.php`, `tests/feature/LayoutTest.php` (rozšíriť) | ❌ Wave 0 |
| NFR | CSP/texty/lang kľúče nových views | unit | `phpunit tests/unit/Security/ViewsCspTest.php tests/unit/I18n` | ✅ |

### Sampling Rate
- **Per task commit:** `docker compose exec -T -u www-data php vendor/bin/phpunit <dotknutý adresár>` + `composer stan` pri zmene Reporting/Export.
- **Per wave merge:** `make test` (všetky suity) + `make stan` + `make cs`.
- **Phase gate:** `make check` zelené pred `/gsd-verify-work`; ručné: otvoriť CSV a XLSX v Exceli (SK lokalita) na staging, stiahnuť ZIP a porovnať s prehľadom (HV krok, `human_verify_mode: end-of-phase`).

### Wave 0 Gaps
- [ ] `tests/_support/ReportFixtures.php` — faktúry/úhrady/dobropisy v rôznych mesiacoch a klienti (nadväzuje na `TurnoverFixtures`, `IssueFixtures`, `CreditNoteFixtures`)
- [ ] `tests/_support/XlsxReader.php` — helper: rozbaliť XLSX cez `ZipArchive` a vrátiť bunky z `sheet1.xml` (bez PhpSpreadsheet)
- [ ] Rozšírenie `ForbiddenCallsRule` + selftest pre povolený `(float)` v `XlsxNumber.php` a bcmath v `ReportMath.php`
- [ ] `composer require openspout/openspout:^5.13` (+ aktualizovať `composer.lock`)
- [ ] Žiadny nový test framework nie je potrebný

## Security Domain

### Applicable ASVS Categories (úroveň 1)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (žiadna zmena) | existujúci Shield + TOTP |
| V3 Session Management | no | existujúce |
| V4 Access Control | yes | `AuthGate` (admin pre všetko mimo whitelistu); nové trasy sa nepridávajú do whitelistu; test 403 pre iného používateľa a anonym |
| V5 Input Validation | yes | whitelist `sort/dir/status/entity/action`, validácia `period` regexom `^\d{4}-(0[1-9]|1[0-2])$`, rok `^\d{4}$`, numerické ID `^\d{1,18}$`, dátumy cez `Clock::parseDate`, rozsah ≤ 12 mesiacov |
| V6 Cryptography | no | — |
| V12 Files/Resources | yes | dočasné súbory v `writable/` s `tempnam`, mazanie v `finally`, slug názvov súborov, `nosniff`, `Content-Disposition: attachment` |
| V13/CSV injection | yes | `FormulaGuard`, `StringCell`, test |

### Known Threat Patterns for PHP/CI4 export a audit

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| CSV/XLSX formula injection (`=cmd\|...`) z názvov klientov, úloh | Tampering / Elevation | `FormulaGuard` v CSV, explicitný `StringCell` v XLSX (nikdy `Cell::fromValue`) |
| SQL injection cez `sort`/`dir`/filtre | Tampering | whitelist mapa na pevné SQL fragmenty, bind parametre `:p:` |
| Path/header injection cez názov súboru | Tampering | slug `[a-z0-9-]`, pevné prípony |
| Únik citlivých údajov v audit detaile | Information Disclosure | `AuditLogger::scrub` už pri zápise; výstup cez `esc()`; len admin |
| Neoprávnený prístup k exportom | Elevation | `AuthGate`, žiadny verejný odkaz |
| Čiastočný/poškodený ZIP s nemennými dokladmi | Repudiation / Integrity | sha256 verify pri čítaní, pred stavbou overiť, že všetky PDF existujú, žiadna regenerácia |
| Preplnenie pamäte veľkým obdobím | DoS | strop 12 mesiacov a súčtu `byte_size`, `LIMIT` na audit |
| Vytečenie dočasného súboru | Information Disclosure | `writable/` mimo webroot, mazanie v `finally`, prefix nepredvídateľný (`tempnam`) |

## Sources

### Primary (HIGH confidence)
- Repozitár `/home/voslar/projects/task-tracker`: `TurnoverCalculator`, `InvoiceQueries`, `DocumentStore`, `DocumentFilename`, `AuditLogger`, `AuthGate`, `AuthGroups`, `ForbiddenCallsRule`, migrácie (`CreateInvoices`, `CreatePayments`, `ExtendInvoiceStatusViewPayments`, `AddCreditNotes`, `CreateAuditLog`), `Money`, `MoneyFormat`, `Invoices`/`Tasks` kontroléry a views, `phpunit.dist.xml`, `Makefile`, `Dockerfile`
- Živá DB v kontajneri `task-tracker-db-1` (`\d invoices`, `\d users`, `\d auth_logins`, `\d auth_events`, `audit_log` distinct hodnoty, `auth_identities.type`)
- Packagist p2 `openspout/openspout` (v5.13.0, 2026-10-27)
- Zdroje `openspout/openspout` v5.13.0 (`Cell.php`, `NumericCell.php`, `WorksheetManager.php` r. 184 až 192, `Writer/XLSX/Options.php`, `AbstractWriter.php`, `Style.php`)
- PHP 8.5.12 v kontajneri (`fputcsv` deprecation, float→string, `php -m`)
- CI4 4.7.5 `DownloadResponse.php`, `ResponseTrait::download()` v kontajneri

### Secondary (MEDIUM confidence)
- CLAUDE.md (rozhodnutia o stacku), 11-CONTEXT.md, 11-UI-SPEC.md, 10-PATTERNS.md

### Tertiary (LOW confidence)
- Podpis `SheetView` pre zmrazený riadok (A3), správanie Excelu s lokalizovaným CSV (A6) — overiť pri implementácii a na staging.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — OpenSpout overený zo zdrojov, ostatné je existujúce repo
- Architecture: HIGH — postavené na existujúcich vzoroch a skutočnom DB schéme
- Pitfalls: HIGH pre externé faktúry, `counts_toward_limit`, `Cell::fromValue`, `fputcsv`, `ForbiddenCallsRule`; MEDIUM pre úhrady (A1/A2)
- Slopcheck: nespustený (balík je používateľom zamknutý a overený cez Packagist)

**Research date:** 2026-11-04
**Valid until:** 2026-12-04 (stabilné; OpenSpout 5.x a CI4 4.7.x)
