# Phase 10: Limity obratu - Research

**Researched:** 2026-11-02
**Domain:** vlastná doménová logika nad existujúcou PHP/CI4/PostgreSQL kódovou základňou (bez nových knižníc)
**Confidence:** HIGH (kódová základňa overená čítaním), MEDIUM (právne hodnoty limitov, riešené konfiguráciou)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Nová tabuľka `turnover_limits` upraviteľná v Nastavenia → Limity: krajina (`SK|PL|CZ`), rok platnosti, názov, suma `numeric(12,2)`, mena (FK na `currencies`), spôsob výpočtu, prahy upozornenia v percentách (predvolené `{80,100}`). Žiadna hodnota limitu v kóde ani v `.env`.
- **D-02:** Na jednu krajinu a rok **viac riadkov**; každý sa vyhodnocuje samostatne, upozornenia pre všetky; zvýraznený je riadok s najvyšším percentom („prísnejšia hodnota“).
- **D-03:** `calc_method`: `calendar_year`, `rolling_12m` (posledných 12 kalendárnych mesiacov končiacich aktuálnym mesiacom, resp. mesiacom dátumu dodania pri predkontrole), `pro_rata_first_year` (v roku `entities.active_from`: `suma × dni_pôsobenia / 365`, dni vrátane dňa `active_from`; mimo prvého roka ako `calendar_year`). Základ 365 predvolený, označený [overiť].
- **D-04:** Seed (len návrh, v UI „neoverené účtovníčkou“): SK 2026 „Registrácia DPH (kalendárny rok)“ 50 000 € `calendar_year`; SK 2026 „Registrácia DPH (12 mesiacov)“ 49 790 € `rolling_12m`; SK 2026 „Okamžitá registrácia“ 62 500 € `calendar_year`, prah `{100}`; PL 2026 „Zwolnienie podmiotowe (art. 113)“ 240 000 zł `pro_rata_first_year`. Seed len ak je tabuľka prázdna.
- **D-05:** Výber riadku pre rok: riadky s `year = rok`; ak pre krajinu a rok neexistuje žiadny, riadky posledného známeho roka + nepotlačiteľné upozornenie „limit pre rok X chýba, použitý rok Y“.
- **D-06:** Zmeny `turnover_limits` aj počiatočného obratu cez `AuditLogger` (pred/po). Mazanie riadku limitu povolené a auditované.
- **D-07:** Tabuľka `entity_opening_turnover` (`entity_id`, `year`, `amount numeric(12,2)` v mene subjektu, voliteľná poznámka), unikát `(entity_id, year)`. Pri `calendar_year` a `pro_rata_first_year` sa pripočíta za daný rok. Pri `rolling_12m` sa pripočíta celý, ak okno zasahuje do daného roka, vo vzorci vyznačené (konzervatívne).
- **D-08:** Jedna služba `TurnoverCalculator` (Reporting kontext) = jediný zdroj čísla pre dashboard, nastavenia, náhľad faktúry aj Phase 11. SQL SUM `invoices.total_entity_ccy` `WHERE entity_id AND counts_toward_limit AND origin='issued' AND turnover_date` v okne + počiatočný obrat. Nikdy JSONB `snapshot`, tabuľka kurzov ani živý príznak klienta.
- **D-09:** Rozhodujúci dátum = `turnover_date`. Dobropis nesie `turnover_date` a `counts_toward_limit` originálu a záporné sumy; netreba filtrovať `cancelled`.
- **D-10:** Externé faktúry sa nepočítajú (`counts_toward_limit=false`); predošlé príjmy patria do počiatočného obratu.
- **D-11:** Obrat za subjekt; limit podľa `entities.country`; ak mena limitu ≠ mena subjektu, chyba konfigurácie (žiadny prepočet aktuálnym kurzom).
- **D-12:** Peniaze cez `Money` a bcmath, percento z reťazcov (bez float), zaokrúhlenie len pre zobrazenie.
- **D-13:** Zobrazenie: názov, metóda, obdobie od–do, obrat z faktúr, počiatočný obrat, spolu, limit (pri pomernom aj vzorec), percento, zostatok, počet dokladov.
- **D-14:** Prehľad čerpania na Nastavenia → Limity; detailné prehľady Phase 11.
- **D-15:** Dashboard upozornenie len ak je dôvod (>= najnižší prah, >= 100 %, chýbajúci riadok roka); žlté/červené/info; inak nič.
- **D-16:** Náhľad faktúry spočíta obrat v okne podľa dátumu dodania návrhu + suma návrhu v mene subjektu; ak by prekročil prah, varovanie pred/po. Len ak klient `counts_toward_limit=true`. **Neblokuje.** Pri vystavení sa nič nové neukladá.
- **D-17:** PHPUnit pokrýva `TurnoverCalculator` a výber limitu (hraničné dni 1.1./31.12., `rolling_12m` cez prelom roka, začiatok činnosti 15.7., prestupný rok, dobropis v inom roku, cudzia mena, vylúčený klient, externá faktúra, počiatočný obrat, chýbajúci rok, viac riadkov, hrana prahu).

### Claude's Discretion
- Tvar UI nastavení, poradie stĺpcov, texty v `lang()`.
- Či `TurnoverCalculator` vracia hodnotový objekt (napr. `LimitStatus`) a ako sa volá z htmx fragmentu dashboardu.
- Spôsob započítania počiatočného obratu pri `rolling_12m` a presná definícia okna pri predkontrole.
- Indexy pre SUM, ak meranie ukáže potrebu.
- Zadávanie prahov vo formulári pri zachovaní `int[]` v DB.

### Deferred Ideas (OUT OF SCOPE)
- Ročné a mesačné prehľady, exporty (Phase 11).
- Strategy pre rozhodujúci dátum `delivery_date|issue_date|payment_date`.
- Otvorené otázky pre účtovníčku (neblokujú implementáciu).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| LIM-01 | Upraviteľná tabuľka limitov (krajina, rok, názov, suma, mena, metóda, prahy) | Migrácia `turnover_limits` + `LimitService` CRUD podľa vzoru `FxSettingsService`/`BankAccountService`; granty cez `GrantsPrivileges`; seed len ak prázdna |
| LIM-02 | Obrat za subjekt a rok z vystavených faktúr podľa dátumu dodania mínus dobropisy, len `counts_toward_limit`; počiatočný obrat | `TurnoverCalculator` SUM nad `invoices.total_entity_ccy` s filtrom (overené: dobropis má záporné `total_entity_ccy`, `turnover_date` originálu); tabuľka `entity_opening_turnover` |
| LIM-03 | Dashboard upozorní pri 80 % / 100 % aj pri chýbajúcom riadku roka | `LimitStatus` + `LimitSelector` (fallback rok), blok `home/_limits.php` v `Home::data()` |
| LIM-04 | Náhľad faktúry upozorní pri prekročení prahu | `TurnoverCalculator::preview()` volaný z `Invoicing::renderPreview()` z `Draft->snapshot` |
| LIM-05 | Metódy: kalendárny rok, kĺzavých 12 mesiacov, pomerný prvý rok | `PeriodResolver` (čistá funkcia nad dátumami) + vzorec vo `LimitStatus` |
| CUR-04 | Prehľady a limity iba z uložených prepočítaných súm | SUM iba `total_entity_ccy`; test, že kurzy (`exchange_rate_cache`) a `snapshot` sa nečítajú |
</phase_requirements>

## Summary

Fáza nepridáva žiadnu externú knižnicu. Celé je to nová doménová vrstva nad už existujúcimi denormalizovanými stĺpcami `invoices` (`turnover_date`, `total_entity_ccy`, `counts_toward_limit`, `origin`), ktoré zapisujú `InvoiceIssuer` a `CreditNoteIssuer`. Overil som v kóde: `InvoiceIssuer` zapisuje `total_entity_ccy = snapshot.fx.total ?? snapshot.totals.total`, `CreditNoteIssuer` zapisuje `total_entity_ccy` negované a `turnover_date`/`counts_toward_limit` z originálu. Externé faktúry majú podľa CHECK `invoices_origin_shape` `entity_id NULL`, `turnover_date NULL` a `counts_toward_limit=false`, takže filter `entity_id = :e` ich vylúči sám, aj bez `origin='issued'`.

Práca je štvorica: (1) migrácia dvoch tabuliek + seed + granty + index, (2) čisté PHP triedy (`PeriodResolver`, `LimitSelector`, `TurnoverCalculator`, `LimitStatus`) s bcmath, (3) nastavenia (controller, service, views, routes, lang), (4) dve integrácie (`Home::data()` a `Invoicing::renderPreview()`). Najväčšie riziko nie je technické, ale presnosť dátumovej aritmetiky a porovnávania prahov bez float.

**Primary recommendation:** Postaviť `TurnoverCalculator` ako čistú, ľahko testovateľnú jadrovú triedu s injektovaným `Clock` (dátum dodávať parametrom), prahy porovnávať celočíselne (`total*100 >= prah*limit` cez `bccomp`), a UI/dashboard/predkontrolu len nechať formátovať jej výstup.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Výpočet obratu a prahov | API / Backend (PHP služba) | Database (SUM) | Jediný zdroj čísla (D-08); DB len sčíta |
| Uloženie limitov a počiatočného obratu | Database | API / Backend | Nastavenia ako dáta, granty a CHECK-y v DB |
| Validácia formulárov, audit, revízie | API / Backend | — | Server nikdy nedôveruje percentu zo vstupu |
| Zobrazenie čerpania, vzorca | Frontend Server (SSR PHP views) | — | Server-rendered, bez JS logiky |
| Dashboard upozornenie | Frontend Server (fragment `body`) | API / Backend | Rovnaký fragment ako `_overdue` |
| Predkontrola faktúry | API / Backend | Frontend Server | Počíta sa pri vykreslení náhľadu, nič sa neukladá |

## Standard Stack

Žiadne nové balíky. Použiť existujúce časti projektu.

### Core (existujúce, overené v kóde)
| Komponent | Kde | Účel |
|-----------|-----|------|
| `App\Libraries\Platform\Money` | `app/Libraries/Platform/Money.php` | Immutable bcmath nad reťazcami, `of/plus/minus/negate/compare`; rozsah `numeric(12,2)` (max 9 999 999 999,99) |
| `bcround` + `RoundingMode::HalfAwayFromZero` | PHP 8.4+ vstavané (použité v `Money::timesHours`) | zaokrúhlenie na zobrazenie pomerného limitu a percenta [VERIFIED: codebase grep] |
| `App\Libraries\Platform\Clock` / `FrozenClock` | `app/Libraries/Platform/` | `service('clock')->today()`, `parseDate()` (UTC polnoc, prísny) |
| `App\Libraries\Platform\Transaction::run` | `Transaction.php` | transakcia bezpečná pre vnorenie |
| `App\Libraries\Audit\AuditLogger` | `record()` a `recordChange()` | audit pred/po; `entity_type` musí vyhovovať `^[a-z_]{3,40}$` (použiť `turnover_limit`, `entity_opening_turnover`) |
| `App\Database\Support\GrantsPrivileges` | trait v migrácii | `grantUpdateColumns`, `grantDelete` |
| `MoneyFormat::format($amount, $ccy)` | `Platform/MoneyFormat.php` | formátovanie `1 080,00 €` pre UI |
| `EntityDirectory::listAll($today)` | `Master/EntityDirectory.php` | zoznam subjektov so `status` (`active`/`prepared`/`ended`), `country`, `currency`, `active_from` |

### Alternatives Considered
| Namiesto | Alternatíva | Verdikt |
|----------|-------------|---------|
| bcmath `Money` | `brick/math` | nepoužiť, `Money` stačí |
| Materializovaná tabuľka obratu | SUM za behu | SUM za behu (1 používateľ, index), materializácia je zbytočná komplexita a rozchádza sa s „jedným zdrojom čísla“ |

**Installation:** žiadna (`composer.json` sa nemení).

## Package Legitimacy Audit

Fáza neinštaluje žiadne externé balíky, audit nie je potrebný.

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
              Nastavenia → Limity (Settings\Limits controller)
                  │ CRUD limitov, počiatočný obrat (422/409, revision, audit)
                  ▼
        ┌────────────────────────┐        ┌────────────────────────────┐
        │ LimitService           │───────▶│ turnover_limits            │
        │ OpeningTurnoverService │───────▶│ entity_opening_turnover    │
        └────────────────────────┘        └────────────────────────────┘
                                                   │ riadky limitov
 Clock/today ──┐                                   ▼
 (alebo dátum  ├──▶ LimitSelector (krajina, rok → riadky | fallback rok | žiadne)
  dodania)     │                                   │
               │                                   ▼
               └──▶ PeriodResolver (metóda, ref. dátum, active_from → od, do, dni, vzorec)
                                                   │
                                                   ▼
   invoices (SUM total_entity_ccy, COUNT) ──▶ TurnoverCalculator ──▶ LimitStatus[]
   + entity_opening_turnover                       │  (spolu, limit, %, zostatok, prahy,
                                                   │   currency_mismatch, fallback_year)
        ┌──────────────────┬───────────────────────┴──────────────┐
        ▼                  ▼                                      ▼
  Settings\Limits     Home::data() → home/_limits.php     Invoicing::renderPreview()
  (karty + vzorec)    (len ak je dôvod, D-15)             → invoicing/_limit_warning.php
                                                          (preview(): pred/po, neblokuje)
```

### Recommended Project Structure
```
app/Libraries/Reporting/            # nový kontext (D-08 "Reporting kontext")
├── TurnoverCalculator.php          # status(entity, refDate), preview(entity, refDate, addAmount)
├── LimitStatus.php                 # readonly hodnotový objekt (reťazce, bez float)
├── LimitSelector.php               # výber riadkov pre krajinu a rok + fallback
├── PeriodResolver.php              # čistá logika okien a pomerného limitu
├── LimitService.php                # CRUD turnover_limits (+ audit, revision, validácia)
├── LimitInput.php                  # validácia a normalizácia vstupu (prahy "80, 100" → int[])
├── LimitRejected.php               # výnimka (reason, fields) ako FxSettingsRejected
└── OpeningTurnoverService.php
app/Controllers/Settings/Limits.php
app/Views/settings/limits/{index,form,delete,opening}.php  + partial _card.php
app/Views/home/_limits.php
app/Views/invoicing/_limit_warning.php
app/Language/{sk,en}/Limits.php  (+ doplnky Dashboard.php, Invoicing.php)
app/Database/Migrations/2026-11-02-000001_CreateTurnoverLimits.php
tests/unit/Reporting/PeriodResolverTest.php, LimitInputTest.php
tests/integration/Reporting/{TurnoverCalculatorTest,LimitServiceTest,LimitSchemaTest,LimitSelectorTest}.php
tests/feature/Settings/LimitsControllerTest.php, tests/feature/Invoicing/InvoicingLimitWarningTest.php, tests/feature/DashboardLimitsTest.php
```
Adresár `Reporting` je nový (existujú `Fx`, `Master`, `Receivables`, `Work`...). Namespace `App\Libraries\Reporting`.

### Pattern 1: Migrácia nastavení s grantmi (vzor `CreateFxCountrySettings`)
**What:** tabuľka s `revision`, CHECK-y, `COMMENT ON TABLE`, granty cez trait. `app_rw` má predvolene iba SELECT, INSERT (provision.sql default privileges), UPDATE/DELETE treba pridať.
**Example:**
```php
// Source: app/Database/Migrations/2026-10-30-000003_CreateFxCountrySettings.php (vzor)
final class CreateTurnoverLimits extends Migration
{
    use GrantsPrivileges;
    public function up(): void
    {
        $this->db->query("CREATE TABLE turnover_limits (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            country char(2) NOT NULL CHECK (country IN ('SK','PL','CZ')),
            year smallint NOT NULL CHECK (year BETWEEN 2000 AND 2100),
            name text NOT NULL CHECK (char_length(btrim(name)) BETWEEN 1 AND 120),
            amount numeric(12,2) NOT NULL CHECK (amount > 0),
            currency char(3) NOT NULL REFERENCES currencies(code),
            calc_method text NOT NULL CHECK (calc_method IN ('calendar_year','rolling_12m','pro_rata_first_year')),
            thresholds integer[] NOT NULL DEFAULT '{80,100}'
                CHECK (cardinality(thresholds) BETWEEN 1 AND 3 AND array_position(thresholds, NULL) IS NULL),
            is_seed boolean NOT NULL DEFAULT false,
            revision integer NOT NULL DEFAULT 1 CHECK (revision >= 1),
            created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
            UNIQUE (country, year, name)
        )");
        // seed len ak je prázdna (D-04): INSERT ... SELECT ... WHERE NOT EXISTS (SELECT 1 FROM turnover_limits)
        $this->grantUpdateColumns('turnover_limits', ['country','year','name','amount','currency','calc_method','thresholds','is_seed','revision','updated_at']);
        $this->grantDelete('turnover_limits');   // D-06: mazanie povolené (nastavenia, nie doklad)
    }
}
```
CHECK pre rozsah prahov 1..200 po prvku: `thresholds <@ ARRAY(SELECT generate_series(1,200))` nejde v CHECK (subquery); použiť `(SELECT bool_and(t BETWEEN 1 AND 200) FROM unnest(thresholds) t)` tiež nejde v CHECK. Preto validáciu rozsahu prvkov robiť v `LimitInput` (PHP) a v DB nechať `cardinality` CHECK; alebo vlastná IMMUTABLE SQL funkcia `thresholds_valid(int[])`. [ASSUMED] že subquery v CHECK nie je podporovaná (PostgreSQL to naozaj neumožňuje; potvrdiť pri písaní migrácie).

`entity_opening_turnover`: `entity_id bigint REFERENCES entities(id)`, `year smallint`, `amount numeric(12,2) CHECK (amount >= 0)`, `note text CHECK (char_length(note) <= 255)`, `revision`, `updated_at`, `UNIQUE (entity_id, year)`; granty `grantUpdateColumns(['amount','note','revision','updated_at'])` + `grantDelete` (prázdna suma = zmazanie, UI-SPEC D).

**Seed v migrácii** (platné aj pre staging/prod: migrácia beží ako owner): hodnoty `is_seed = true`, mena `EUR`/`PLN` priamo v INSERT. Seed rok 2026 je pevný v migrácii (nie z `Clock`) – to je v poriadku, je to len predvyplnený návrh. Ak sa po 2026 nepridá riadok pre nový rok, zadanie D-05 (fallback + upozornenie) to presne rieši.

**Index pre SUM** (invoices je insert-only, ale index vytvoriť smie owner):
```sql
CREATE INDEX invoices_turnover_idx ON invoices (entity_id, turnover_date)
  WHERE counts_toward_limit AND origin = 'issued';
```
Existujúci `invoices_entity_issue_idx (entity_id, issue_date)` je na `issue_date`, nepomôže (dobropis má iný `issue_date` než `turnover_date`). Pre 1 používateľa a stovky faktúr by stačil aj bez indexu; index je lacný, odporúčam ho pridať (je v Claude's Discretion).

### Pattern 2: SUM dotaz (jediný zdroj čísla, D-08)
```php
// Reťazce z numeric: sum() vracia numeric ako string, nikdy (float)
$row = $db->query(
    "SELECT COALESCE(SUM(total_entity_ccy), 0) AS total,
            COUNT(*) FILTER (WHERE type = 'invoice')     AS invoices,
            COUNT(*) FILTER (WHERE type = 'credit_note') AS credit_notes
     FROM invoices
     WHERE entity_id = :e: AND counts_toward_limit AND origin = 'issued'
       AND turnover_date BETWEEN :from: AND :to:",
    ['e' => $entityId, 'from' => $from, 'to' => $to],
)->getRowArray();
$total = Money::of((string) $row['total']);   // 'numeric' → string cez pdo_pgsql; overiť presne v teste
```
`counts_toward_limit` je v pdo_pgsql boolean; v WHERE sa používa priamo ako SQL boolean (bez parametra), preto netreba `DbBool`. Pozor: ak by sa niekde čítala hodnota stĺpca do PHP, projekt na to používa `DbBool::of()`.

### Pattern 3: Okno a pomerný limit (`PeriodResolver`) – čistá funkcia
Všetky dátumy ako `Y-m-d` reťazce alebo UTC `DateTimeImmutable` (vzor `Clock::parseDate`), žiadne `time()`/`CURRENT_DATE`.

- `calendar_year(Y)`: od `Y-01-01`, do `Y-12-31`.
- `rolling_12m(ref)`: `do = posledný deň mesiaca ref`, `od = prvý deň mesiaca (ref − 11 mesiacov)`. Bezpečný výpočet: `first = ref->modify('first day of this month')`, `od = first->modify('-11 months')` (`first day of` predtým, aby nevznikol overflow 31. dňa), `do = first->modify('last day of this month')`. [CITED: php.net DateTime relative formats; overiť testom 31.3.→ február]. Pozor: `modify('-11 months')` na 31. dni mesiaca by pretiekol, preto vždy až po `first day of this month`.
- `pro_rata_first_year(Y, active_from)`: ak `year(active_from) == Y`: `dni = daysBetween(active_from, Y-12-31) + 1`; `limit = round_half_up(amount × dni / 365, 2)` – výpočet `bcdiv(bcmul(amount, dni, 2), '365', 6)` potom `bcround(.., 2, HalfAwayFromZero)`. Kontrola príkladu: `240000 × 170 / 365 = 111 780,82` (111780,8219…) [VERIFIED: aritmetika, 15. 7. → 31. 12. = 170 dní]. Inak ako `calendar_year` s plným limitom a hintom (UI-SPEC).
  - Obdobie pri pomernom roku: od `max(active_from, Y-01-01)` do `Y-12-31`. Obrat sa počíta v kalendárnom roku.
  - Prestupný rok: základ ostáva 365 (D-03, [overiť] s účtovníčkou); dni sa počítajú reálne (rok 2028 má 366 dní, no základ 365). Hraničný test: `active_from = 2028-01-01` → 366 dní → limit by bol 100,3 % sumy; toto je pravdepodobne nechcené: **cap `dni` na 365** alebo zdokumentovať. [ASSUMED] odporúčam `min(dni, 365)`; potvrdiť s plánovačom, je to súčasť testu D-17 (prestupný rok).
- Rolling + pomerný prvý rok sa nekombinujú (metóda je na riadok limitu).

### Pattern 4: Výber limitov (`LimitSelector`, D-05)
```
rows = SELECT * FROM turnover_limits WHERE country = :c AND year = :Y  → fallbackYear = null
ak prázdne: Y' = MAX(year) pre krajinu s year < Y; ak žiadny, MIN(year) s year > Y;
            rows = riadky roka Y'; fallbackYear = Y'
ak stále nič: status "bez limitu" (info alert „Pre krajinu X nie je zadaný žiadny limit“)
```
Pre dashboard: referenčný rok = rok z `Clock::today()`. Pre predkontrolu: rok z dátumu dodania návrhu (poznámka: dátum dodania môže byť v inom roku než dnes, vtedy sa vyberá limit toho roka, nie aktuálneho). Ak je viac riadkov, vyhodnotia sa všetky.

### Pattern 5: Porovnanie prahov bez float
```php
// percent = total / limit * 100, na zobrazenie 1 desatinné miesto half-up:
$pct = bcround(bcdiv(bcmul($total, '100', 4), $limit, 6), 1, RoundingMode::HalfAwayFromZero);
// dosiahnutie prahu rozhoduj presne (nie zaokrúhleným percentom):
$reached = bccomp(bcmul($total, '100', 4), bcmul((string)$threshold, $limit, 4), 4) >= 0;
```
Rozhodovanie z presného porovnania zabráni, aby 79,96 % (zobrazené „80,0 %“) hlásilo prah. Pri zobrazení 79,96 % → „80,0 %“ vznikne vizuálna nezhoda; riešenie: zobrazovať percento zaokrúhlené **nadol** (`RoundingMode::TowardsZero`) alebo na 2 desatinné; zvoliť a otestovať (UI-SPEC hovorí half-up 1 des. miesto). [ASSUMED] ponechať half-up z UI-SPEC, ale rozhodnutie o prahu robiť presne a pridať test pre hranu 79,96 %.
Limit > 0 je zaručené CHECK-om (delenie nulou nehrozí); bcdiv aj tak hádže `DivisionByZeroError` – nekontrolovať, CHECK stačí.

Stav karty (UI-SPEC): `crit` ak dosiahnutý najvyšší prah, `warn` ak dosiahnutý akýkoľvek nižší, inak neutrálny. Riadok s jediným prahom `{100}` je neutrálny do 100 %.

### Pattern 6: Pomocné stavy (D-11)
`currency_mismatch` ak `limit.currency != entity.currency` – obrat sa nepočíta (`LimitStatus` s `error='currency_mismatch'`, bez čísel). Subjekty: porovnávať `trim($entity['currency'])`; stĺpce sú `char(3)` a `invoices.currency` je tiež `char(3)` (projekt používa `trim(...)` v kóde, napr. `trim((string) $client['country'])`).

### Pattern 7: Predkontrola (LIM-04, D-16)
V `Invoicing::renderPreview()` po `$draft = $service->draft(...)`:
```php
// vstupy: $draft->entityId, $draft->deliveryDate, $draft->snapshot (nullable), $draft->canIssue
// suma v mene subjektu:  $snapshot['fx']['total'] ?? $snapshot['totals']['total']   (rovnaký výraz ako InvoiceIssuer r.280)
// príznak klienta:       $snapshot['client']['counts_toward_limit']   (SnapshotBuilder r.154)
```
`TurnoverCalculator::preview($entity, $deliveryDate, $addAmount)` vráti pre každý limit `before`, `after`, `crossed`, `already_over`. Zobraziť iba ak `counts_toward_limit` je true. Ak `draft->snapshot === null` alebo `draft->fx['state']` je chybový/nerozhodnutý (suma v mene subjektu nie je k dispozícii), upozornenie sa vynechá alebo ukáže „nie je dostupná“ (UI-SPEC F). Celé volanie zabaliť do `try/catch (Throwable)` → „Predkontrolu limitu sa nepodarilo vypočítať…“ (musí nikdy neblokovať vystavenie). Do `$data` pridať `'limitWarning' => ...` a vložiť `view('invoicing/_limit_warning')` v `_preview.php` pod `_fx_card` (riadok 111). Fragment `/fragments/invoice-preview` sa už prekresľuje pri zmene dátumu dodania, takže sa prepočíta spolu s ním.
**Overiť pri plánovaní:** či `Draft->snapshot` je naplnený aj pri `canIssue=false`; ak nie, odvodiť sumu z `Draft->total` a `Draft->fx['quote']`.
`IssueCommand`/vystavenie sa nemení (nič sa neukladá).

Rozhodnutie ≥ prahu pre predkontrolu: UI-SPEC (F) žiada upozornenie aj pri „už prekročené“; pravidlo: upozorniť ak `after >= najnižší prah` (aj keď `before` už bolo nad ním), text sa líši (prekročí vs. už prekročený). Pozor, to znamená, že po dosiahnutí 80 % bude varovanie pri každej ďalšej faktúre; je to zámer UI-SPEC.

### Pattern 8: Dashboard (LIM-03)
V `Home::data()` pridať `'limits' => (new TurnoverCalculator())->alertsForActiveEntities(service('clock')->today())` a vo `home/_body.php` pred `_overdue`: `view('home/_limits', ['limits' => $limits])`. Partial na začiatku `if ($alerts === []) { return; }` presne ako `_overdue.php`. Dashboard filtruje podľa klienta, ale limity sú za subjekt (nezávislé od filtra klienta, nepoužívať `$filter->clientId`). Aktívne subjekty: `EntityDirectory::listAll($today)` a `status === 'active'`. Upozornenia nemajú mať ďalší request (blok je súčasťou fragmentu `body`, ktorý sa obnovuje na `allocationChanged`; vystavenie faktúry mení obrat, ale tento trigger nenastane, a nie je to potrebné – po vystavení redirect načíta stránku znova).

### Pattern 9: Nastavenia – controller/service/view vzory
- **Routy** `app/Config/Routes/settings.php` (pridať; `settings/limits/opening` pred `(:num)` skupinou):
  `GET settings/limits` index, `GET settings/limits/new`, `GET settings/limits/(:num)/edit`, `POST settings/limits`, `POST settings/limits/(:num)`, `GET settings/limits/(:num)/delete`, `POST settings/limits/(:num)/delete`, `GET settings/limits/opening`, `POST settings/limits/opening`.
- **Controller** vzor `Settings\FxSettings` (422 → `setStatusCode(422)->setBody(view(...))`, 409 pri `stale` revízii, úspech `session()->setFlashdata('app_notice', [...])` + `HX-Location` pri htmx / `redirect()->to` inak). Sheet vzor: `hx-target="#sheet"`, `.dl-sheet`, bez JS samostatná stránka (pozri `settings/entities/bank_account_form.php`, `settings/clients/form.php`).
- **Service** vzor `FxSettingsService`: `Transaction::run`, `SELECT ... FOR UPDATE`, porovnanie `revision`, `recordChange` s `entity_type` `turnover_limit`. `AuditLogger::record()` pri create (`before=null`) a delete (`after=null`). `scrub()` odstráni kľúče s `password|secret|token`, tie tu nehrozia.
- **Menu:** `app/Views/more/index.php` pridať odkaz za „Kurzy“: `<a class="list-group-item list-group-item-action more-item" href="/settings/limits"><?= esc(lang('Limits.navTitle')) ?></a>`.
- **Pole `thresholds` v PostgreSQL:** pdo_pgsql vracia `int[]` ako text `{80,100}`; zapisovať ako literál `'{80,100}'` s parametrom a `::integer[]`, čítať parsovaním (`trim($s,'{}')` → `explode`). Nepoužívať `json_decode`. [ASSUMED] pdo_pgsql vracia textovú reprezentáciu poľa; overiť v prvom integračnom teste.
- **Zoznam subjektov:** `EntityDirectory::listAll($today)` (aktívne). Mena subjektu `currency` sa pre formulár počiatočného obratu berie z `entities.currency` (nemenná).
- **Zákaz inline `style`, `onclick`, hardcoded textu:** testy `ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest` vynucujú (kľúče v `sk` aj `en`). `<progress value max>` nesie šírku natívne (bez inline štýlu).
- **CSS:** pridať `.lim-*` do `public/assets/css/app.css` a zvýšiť verziu v `asset_url` (ako Phase 9).

### Anti-Patterns to Avoid
- **Čítať JSONB `snapshot` ani `exchange_rate_cache` pre obrat** (D-08, CUR-04). Výnimka: predkontrola čerpá sumu a príznak z už vytvoreného `Draft->snapshot` (návrh, nie vystavený doklad), nie z `invoices`.
- **`float` kdekoľvek** (percento, pomerný limit, prahy): iba bcmath reťazce a `int` prahy.
- **`CURRENT_DATE`/`new DateTime('now')`/`date('Y')`** v dotazoch a výpočtoch: iba `service('clock')` (test `TimezoneConsistencyTest`, pasca 7).
- **Filtrovať `status = 'cancelled'`** (D-09): dobropis už odpočíta, dvojitý odpočet by obrat podhodnotil.
- **Sumovať naprieč subjektmi** alebo prenášať obrat pri prechode SK→PL (D-11).
- **Zahodiť duplicitné riadky limitu** po jednom na krajinu: každý riadok sa hodnotí zvlášť (D-02).
- **Blokovať vystavenie** na základe limitu (D-16).
- **Zdieľať jedinečnosť `(country, year)`**: unikát je `(country, year, name)`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Peňažná aritmetika | vlastné bc* volania rozptýlené po kóde | `Money` (+ jedna pomocná v `Reporting` pre násobenie/delenie s pevným scale) | `Money` už stráži rozsah a `-0.00` |
| Formát meny | `number_format` | `MoneyFormat::format` | konzistentné s ostatnými UI |
| Audit | vlastná tabuľka histórie | `AuditLogger` | AUD-01, jeden denník |
| Transakcie | `$db->transStart()` ručne | `Transaction::run` | bezpečné pre vnorenie (WR-01) |
| Optimistický zámok | vlastné timestampy | stĺpec `revision` + `FOR UPDATE` ako `FxSettingsService` | overený vzor, 409 UX |
| Granty | priame `GRANT` v migrácii | `GrantsPrivileges` trait | fail-closed konvencia |
| Dátumové prísne parsovanie | `strtotime` | `Clock::parseDate` | 2026-02-30 a polnoc UTC |
| Rozhodovanie o prahu | porovnanie zaokrúhleného percenta | presné `bccomp(total*100, prah*limit)` | 79,96 % nesmie hlásiť 80 % |

**Key insight:** Celá hodnota fázy je v správnosti čísla a v tom, že ho vidí každé miesto rovnaké. Preto jedna trieda a jeden SQL dotaz, a UI iba formátuje.

## Runtime State Inventory

Nejde o rename/refactor/migráciu, vynecháva sa. Poznámka: seed riadkov `turnover_limits` sa vloží migráciou na staging a prod pri `spark migrate`; stav sa nemení bez zásahu vlastníka. Staging seed (`StagingSeed` modul) môže voliteľne pridať počiatočný obrat, nie je nutné.

## Common Pitfalls

### Pitfall 1: Rolling okno s mesačným overflowom
**What goes wrong:** `ref->modify('-11 months')` na 31. dni pretečie do ďalšieho mesiaca (31.3.−1 mes. = 3.3.) a okno sa skráti/posunie.
**How to avoid:** najprv `first day of this month`, až potom odpočet mesiacov; konštruovať dátumy z `(rok, mesiac)` celými číslami.
**Warning signs:** test `rolling_12m` s ref 31. 12. a 29. 2. dáva nesprávne `od`.

### Pitfall 2: Percento pre rozhodnutie vs. zobrazenie
Opísané vyššie (Pattern 5). Rozhodovať presne, zobrazovať zaokrúhlene, mať test hrany.

### Pitfall 3: Počiatočný obrat pri `rolling_12m` dvojitý počet
Okno 12/2025–11/2026 zasahuje do 2025 aj 2026 → sčíta sa opening 2025 + 2026 (D-07, konzervatívne). Používateľ, ktorý zadal opening za rok 2026 ako „príjmy od 1.1. do nasadenia“, môže dostať dvojité započítanie, ak by zadal aj 2025, no to sú dva rôzne roky, nie duplicita. Vzorec to musí vypísať (hint v UI-SPEC). Test: opening zasahuje len ak rok ∈ [rok(od), rok(do)].

### Pitfall 4: Fallback roka a „aktuálny rok“
Pre dashboard sa rok berie z `Clock`, pre predkontrolu z dátumu dodania. Ak sa dátum dodania nachádza v budúcom roku bez riadku, fallback použije posledný známy a ukáže hint; nie je to chyba.

### Pitfall 5: `char(3)` / `char(2)` medzery
`country`/`currency` z `char` stĺpcov vracajú vypchaté hodnoty v niektorých ovládačoch; projekt používa `trim()` pri `entities.currency`/`clients.country`. Pri porovnaní `limit.currency` s `entity.currency` vždy `trim`.

### Pitfall 6: Testovacie čistenie
`MasterFixtures::resetMasterTables()` TRUNCATE-uje zoznam tabuliek; `entity_opening_turnover` treba pridať do zoznamu (FK na `entities`, CASCADE to síce vyrieši, ale tabuľka nesmie zostať s osirelými riadkami pri `TRUNCATE ... CASCADE` – CASCADE ju vyprázdni automaticky). `turnover_limits` nie je viazaná na entity, preto testy limitov musia samy `DELETE FROM turnover_limits` pod ownerom v `setUp`/`tearDown` a **nespoliehať sa na seed** (seed z migrácie v testovacej DB existuje; test ho buď vymaže, alebo zámerne testuje).
Poznámka: ak sa `turnover_limits` pridá do `resetMasterTables`, seed sa pri prvom teste zmaže a ostatné testy (napr. dashboard feature) ho nemajú; preto testy, ktoré chcú limity, si ich vložia samy.

### Pitfall 7: Zmena roka mena limitu vs. mena subjektu
Pri ukladaní limitu NEblokovať nesúlad mien (UI-SPEC: iba varovanie, vyhodnotí sa v čerpaní). Krajina bez subjektu v danej krajine je legitímna (limit pre PL pred založením PL subjektu).

### Pitfall 8: Predkontrola a chýbajúci kurz
Pri cudzej mene klienta bez dostupného kurzu (manual/fx error) je suma v mene subjektu neznáma; predkontrola sa nesmie pokúsiť o kurz z `exchange_rate_cache` sama. Použiť iba hodnotu v návrhu.

### Pitfall 9: Nový `entity_type` v audite
`^[a-z_]{3,40}$`: `turnover_limit` (13) a `entity_opening_turnover` (23) vyhovujú.

## Code Examples

### LimitStatus (tvar výstupu, readonly, reťazce)
```php
final readonly class LimitStatus
{
    public function __construct(
        public int $limitId, public string $name, public string $country, public int $year,
        public ?int $fallbackFromYear,          // požadovaný rok, ak sa použil náhradný
        public string $method,                  // calendar_year|rolling_12m|pro_rata_first_year
        public string $periodFrom, public string $periodTo,
        public string $documentTurnover, public int $invoiceCount, public int $creditNoteCount,
        public string $openingTurnover,         // '0.00' ak nezadaný (a flag hasOpening)
        public bool $hasOpening,
        public string $total,                   // spolu
        public string $limit,                   // efektívny limit (po pomernom prepočte)
        public string $baseLimit, public ?int $proRataDays,
        public string $percent,                 // zaokrúhlené na 1 des. na zobrazenie
        public string $remaining,               // max(0, limit - total)
        public string $exceededBy,              // max(0, total - limit)
        public string $currency,
        public string $level,                   // normal|warn|crit
        public ?string $error,                  // null|currency_mismatch
        public bool $isSeed,
    ) {}
}
```

### Test fixture pre faktúry s obratom (bez vystavovania cez celý stack)
Existujú `IssueFixtures::issueSetup()` + `issueCommand()` pre reálne vystavenie (pomalé, PDF) a `CreditNoteFixtures::insertRawCreditNote()`. Pre testy `TurnoverCalculator` odporúčam **priamy INSERT do `invoices` pod ownerom** s minimálnym platným riadkom (musí splniť `invoices_origin_shape` CHECK a deferred `invoice_complete_check`, ktorý vyžaduje `invoice_lines` a `invoice_documents`). Praktickejšie: nový pomocný trait `TurnoverFixtures` v `tests/_support/`, ktorý vystaví reálnu faktúru cez `InvoiceIssuer` s `FakePdfRenderer` (vzor `IssueFixtures`) a dátum dodania/mena nastaví cez `IssueCommand`, a dobropis cez `CreditNoteFixtures::issueCreditNote`. Skúsenosť z predošlých fáz (`CreditNoteQueriesTest`) ukazuje, že to je bežný a podporovaný spôsob. Pre hraničné dátumy a veľa riadkov je rýchlejšie raw INSERT cez `INSERT ... SELECT` s vypnutým deferred trigger (`SET CONSTRAINTS ALL DEFERRED` a dopísané riadky v tej istej transakcii); rozhodne plánovač podľa nákladov.

### Zobrazenie percenta
```php
$pct = bcround(bcdiv(bcmul($total, '100', 4), $limit, 6), 1, RoundingMode::HalfAwayFromZero);
// formát čísla cez NumberFormatter(locale) pre "88,5 %"
```

## State of the Art

| Old | Current | Impact |
|-----|---------|--------|
| float percentá | bcmath reťazce + `bcround` (PHP 8.4+) | bez driftu; `bcround` je k dispozícii v PHP 8.5 (použité v `Money`) |
| Seed hodnoty v kóde | seed v migrácii len ak je tabuľka prázdna + editovateľné v UI | žiadna „pravda“ v kóde (ROADMAP) |

**Deprecated/outdated:** nič relevantné; fáza nepoužíva externé API.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | PostgreSQL CHECK nepodporuje subquery, preto rozsah prvkov `thresholds` (1..200) validovať v PHP alebo cez IMMUTABLE funkciu | Pattern 1 | migrácia padne pri CREATE; rýchlo opraviteľné |
| A2 | pdo_pgsql vracia `integer[]` ako text `{80,100}` | Pattern 9 | zlé parsovanie prahov; overiť prvým integračným testom |
| A3 | Pomerný limit cap `dni` na 365 (prestupný rok) | Pattern 3 | limit v prvom prestupnom roku by mohol presiahnuť plný limit; potvrdiť s účtovníčkou |
| A4 | Rozhodovať o prahu presným porovnaním, zobrazovať half-up na 1 des. miesto | Pattern 5 | vizuálna nezhoda 79,96 % → „80,0 %“; kozmetické |
| A5 | Fallback roka = najbližší nižší rok, inak najbližší vyšší | Pattern 4 | iný „posledný známy“ rok než čaká vlastník; zdokumentovať |
| A6 | `Draft->snapshot` je naplnený aj pri neúplnom stave potrebnom pre sumu predkontroly | Pattern 7 | treba odvodiť sumu z `Draft->total`/`fx.quote`; overiť pri plánovaní |
| A7 | Právne hodnoty limitov (SK 50 000 / 49 790 / 62 500, PL 240 000, základ 365) sú len návrh | D-04 | riešené konfiguráciou a príznakom `is_seed`; vlastník overí s účtovníčkou |

## Open Questions

1. **`is_seed` stĺpec (UI-SPEC [predpoklad])**
   - Know: UI vyžaduje odznak „Návrh, neoverené“ a jeho zrušenie prvým uložením.
   - Unclear: CONTEXT stĺpec nemenuje. 
   - Recommendation: stĺpec `is_seed boolean` v `turnover_limits`, pri akomkoľvek uložení z UI `false` (zahrnúť do auditu).
2. **Opening turnover výber rokov v sekcii 6 UI** – zjednodušene: aktuálny rok + už zadané záznamy (UI-SPEC predpoklad). Netreba ďalší dotaz.
3. **Počiatočný obrat pri pomernom prvom roku** – pripočíta sa za rok Y; nie je v zadaní zmena, ostáva.
4. **Predkontrola pre cudziu menu a manuálny kurz** – suma sa berie z návrhu; žiadny samostatný kurz.

## Environment Availability

Fáza nemá nové externé závislosti (PHP, PostgreSQL a bcmath už projekt používa). Spúšťanie testov vyžaduje bežiaci PostgreSQL pre integračné testy (rovnako ako predošlé fázy; roly `app_owner`/`app_rw`, konfigurácia `tests`/`tests_owner`). Overenie dostupnosti PG v tejto session som nerobil (`composer test:migrate` vykoná migrácie testovacej DB). Fallback: unit testy (`composer test:unit`) bežia bez DB a pokryjú `PeriodResolver`, `LimitInput`, aritmetiku prahov.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit `^10.5.16` + `CIUnitTestCase` (pin z appstarter), integračné testy cez `Tests\Support\PgTestCase` (owner + rw spojenie) |
| Config file | `phpunit.dist.xml` |
| Quick run command | `composer test:unit` (alebo `vendor/bin/phpunit --testsuite unit`) |
| Full suite command | `composer test` (`phpunit`); migrácie testovacej DB: `composer test:migrate` |
| Skupiny | integračné triedy označené `#[Group('integration')]` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| LIM-01 | CRUD limitov, validácia, duplicita, revision 409, audit, mazanie, seed len pri prázdnej tabuľke | integration + feature | `vendor/bin/phpunit tests/integration/Reporting/LimitServiceTest.php`, `tests/feature/Settings/LimitsControllerTest.php` | ❌ Wave 0 |
| LIM-01 | schéma: CHECK-y, granty `app_rw` (UPDATE stĺpce, DELETE, nie INSERT mimo), seed 4 riadky | integration | `tests/integration/Reporting/LimitSchemaTest.php` | ❌ Wave 0 |
| LIM-02 | SUM: faktúry − dobropisy, len `counts_toward_limit`, `turnover_date` hranice 1.1./31.12., externá faktúra mimo, cudzia mena cez `total_entity_ccy`, zmena príznaku klienta po vystavení nemení výsledok, opening turnover | integration | `tests/integration/Reporting/TurnoverCalculatorTest.php` | ❌ Wave 0 |
| LIM-02 | dobropis v inom roku než faktúra sa odpočíta v roku originálu | integration | `TurnoverCalculatorTest::testCreditNoteNetsInOriginalPeriod` | ❌ Wave 0 |
| LIM-05 | okná: `calendar_year`, `rolling_12m` (cez prelom roka, 31. 12., 29. 2.), `pro_rata_first_year` (15. 7. → 170 dní, 111 780,82), mimo prvého roka = plný limit, prestupný rok | unit | `tests/unit/Reporting/PeriodResolverTest.php` | ❌ Wave 0 |
| LIM-05 | vzorec a použitá hodnota vo `LimitStatus` | unit/integration | `TurnoverCalculatorTest::testFormulaFields` | ❌ Wave 0 |
| LIM-03 | prahy 80/100, hrana 79,99 %/80,00 %/100,00 %, viac riadkov = všetky + prísnejší, fallback roka + príznak, `currency_mismatch`, jediný prah `{100}` | unit + integration | `tests/unit/Reporting/ThresholdTest.php`, `LimitSelectorTest.php` | ❌ Wave 0 |
| LIM-03 | dashboard: blok sa nezobrazí bez dôvodu, zobrazí pri 80 %, 100 %, chýbajúcom roku; neutíšiteľný | feature | `tests/feature/DashboardLimitsTest.php` | ❌ Wave 0 |
| LIM-04 | predkontrola: prekročenie prahu, hrana, klient nezapočítavaný = bez upozornenia, cudzia mena, výnimka = bezpečný stav, neblokuje vystavenie (vystavenie stále OK) | feature | `tests/feature/Invoicing/InvoicingLimitWarningTest.php` | ❌ Wave 0 |
| CUR-04 | obrat nečíta `snapshot` ani `exchange_rate_cache` (zmena/zmazanie kurzu a JSONB nemení výsledok) | integration | `TurnoverCalculatorTest::testIgnoresRatesAndSnapshot` | ❌ Wave 0 |
| Všetky UI | CSP, hardcoded text, lang kľúče sk/en | unit | `tests/unit/I18n/LangKeysTest.php`, `ViewsHardcodedTextTest.php`, `ViewsCspTest` (existujúce, pokryjú nové views automaticky) | ✅ |
| Výkon | SUM p95 pod 100 ms (index) | manuálne/integračné | EXPLAIN v teste alebo manuálne | manuálne |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit tests/unit/Reporting` (< 30 s, bez DB) a cielený integračný súbor.
- **Per wave merge:** `composer test`.
- **Phase gate:** plná sada zelená pred `/gsd-verify-work`; PHPStan (`phpstan.neon.dist`, `phpstan.views.neon`) bez nových chýb.

### Wave 0 Gaps
- [ ] `tests/_support/TurnoverFixtures.php` – pomocník na vloženie/vystavenie faktúr a dobropisov s daným `turnover_date`, cudzou menou, príznakom klienta.
- [ ] Pridať `entity_opening_turnover` do zoznamu v `MasterFixtures::resetMasterTables()` (a rozhodnúť o `turnover_limits`: test ho maže sám).
- [ ] Všetky testovacie súbory z tabuľky vyššie.
- [ ] Framework install: nie je potrebný.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | nie | existujúci Shield |
| V3 Session Management | nie | existujúce |
| V4 Access Control | áno | nové routy za existujúcim auth filtrom (overiť, že `settings/*` skupina je chránená rovnako ako `settings/fx`) |
| V5 Input Validation | áno | server-side validácia v `LimitInput`: whitelist krajiny, metódy, meny (existuje v `currencies`), rozsah roka, suma `> 0` a `numeric(12,2)` regex ako `Money::of`, prahy 1–3 celé čísla 1–200; parametrizované dotazy (`:name:` bindings) |
| V6 Cryptography | nie | — |
| V8 Data Protection / audit | áno | `AuditLogger` pre zmeny limitov |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SQL injection cez filtre | Tampering | parametrizované dotazy CI4 (`:param:`), nikdy konkatenácia; názvy tabuliek konštantné |
| CSRF na POST nastavení | Tampering | CI4 CSRF filter + `HtmxCsrf` (existujúce), `data-submit-once` |
| Podvrhnuté percento/obrat vo formulári | Tampering | server nikdy neprijíma obrat ani percento, všetko počíta `TurnoverCalculator` |
| Súbeh dvoch úprav | Tampering | `revision` + `FOR UPDATE` → 409 |
| XSS v názve limitu | Tampering | `esc()` vo views; CSP bez inline |
| IDOR na `/settings/limits/{id}` | Info disclosure | jediný vlastník; existencia riadka overená, 404 inak |
| Mutácia vystavených dokladov | Tampering | fáza `invoices` nikdy nemení (len SELECT); `app_rw` insert-only ostáva |
| Redukcia grantov | Elevation | `GRANT UPDATE (stĺpce)` + `DELETE` iba na nové tabuľky, nič na `invoices` |

## Project Constraints (from CLAUDE.md)

- PHP 8.5, CI4 4.7.x, PostgreSQL 18, bez SPA/build pipeline, JS a CSS lokálne s verziou v názve.
- Peniaze `numeric(12,2)`, hodiny `numeric(7,2)`, **žiadny float**; v PHP reťazec + bcmath.
- Vystavené doklady nemenné (kontroluje DB aj server) – fáza ich nesmie meniť.
- CSP: žiadny inline štýl, `onclick`, inline skript; htmx `allowEval=false`.
- PHPUnit povinne pre výpočty súm, číslovanie, zamykanie častí, kópiu údajov, kurzy a limity.
- UX: jedna ruka na iPhone, dotykové ciele min. 44 px, bez horizontálneho posúvania (zoznamy riadkov namiesto tabuliek).
- Výkon: p95 < 100 ms bežné stránky (dashboard pridáva jeden SUM dopyt na limit a subjekt; index).
- Zdroje: do 512 MB RAM v pokoji, nepridávať ťažké závislosti.
- GSD workflow: zmeny súborov iba cez GSD príkazy (research je dokument, bez zásahu do kódu).
- Pamäť projektu: vývoj v `~/projects/task-tracker` (nie `/mnt/c`), staging `work-tracker-stage.vostack.dev` pre testy; `app.env` na serveri musí zostať `deploy:82` (neupravovať `sed -i`); produkčné tajomstvá nikdy do chatu.

## Sources

### Primary (HIGH confidence) — priame čítanie kódu
- `app/Database/Migrations/2026-10-27-000002_CreateInvoices.php` (stĺpce, `invoices_origin_shape`, indexy)
- `app/Database/Migrations/2026-10-31-000001_AddCreditNotes.php` (dobropis, trigger cieľa)
- `app/Database/Migrations/2026-10-30-000003_CreateFxCountrySettings.php`, `2026-10-30-000001_CreateCurrencies.php`
- `app/Database/Support/GrantsPrivileges.php`, `docker/postgres/provision.sql` (default privileges)
- `app/Libraries/Invoicing/InvoiceIssuer.php` r. 270–340, `CreditNoteIssuer.php` r. 215–285, `SnapshotBuilder.php` r. 140–230
- `app/Libraries/Platform/{Money,Clock,FrozenClock,Transaction}.php`, `app/Libraries/Audit/AuditLogger.php`
- `app/Libraries/Fx/FxSettingsService.php`, `app/Controllers/Settings/FxSettings.php`
- `app/Controllers/Home.php`, `app/Views/home/_body.php`, `_overdue.php`
- `app/Controllers/Invoicing.php` (`renderPreview`), `app/Libraries/Invoicing/Draft.php`, `app/Views/more/index.php`, `app/Config/Routes/settings.php`
- `tests/_support/{PgTestCase,MasterFixtures,IssueFixtures,CreditNoteFixtures}.php`
- `.planning/phases/10-limity-obratu/10-CONTEXT.md`, `10-UI-SPEC.md`, `.planning/REQUIREMENTS.md`

### Secondary (MEDIUM)
- Aritmetika pomerného limitu overená výpočtom (240 000 × 170 / 365 = 111 780,82; 15. 7.–31. 12. = 170 dní)

### Tertiary (LOW)
- Právne hodnoty SK/PL limitov (nie sú overené, bez web výskumu; riešené konfiguráciou, otvorené otázky pre účtovníčku)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, žiadne nové balíky, všetko existuje v repozitári.
- Architecture: HIGH, vzory a integračné body overené v kóde.
- Pitfalls: MEDIUM-HIGH; A1, A2, A6 treba overiť pri implementácii.
- Právne hodnoty: nepodliehajú výskumu (mimo kódu).

**Research date:** 2026-11-02
**Valid until:** 30 dní (stabilná kódová základňa)
