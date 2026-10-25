# Phase 4: Úlohy, fakturačné časti a dashboard - Research

**Researched:** 2026-10-25
**Domain:** CodeIgniter 4.7 + PostgreSQL 18 doménová vrstva (úlohy, fakturačné časti, odvodené stavy), htmx/Alpine CSP mobilné UI, jednorazový import z XLSX (PhpSpreadsheet v `require-dev`)
**Confidence:** HIGH (kód fázy 1 až 3 prečítaný priamo, zdrojový Excel rozbalený a analyzovaný, PhpSpreadsheet správanie hyperlinkov overené v zdrojáku)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Úloha**
- **D-01:** Polia úlohy: klient, URL na portáli, názov, nacenené hodiny `numeric(7,2)`, sadzba `numeric` (kópia z klienta pri vytvorení, prepisovateľná), nacenená suma (odvodená cez `Money`, half-up na 2 desatinné miesta), poznámka, dátum vytvorenia, stav `in_progress | done` a `done_at` (TSK-01, TSK-03, spec 6.3). Mena úlohy je mena klienta.
- **D-02:** Klient sa predvolí podľa **posledného použitého** klienta (TSK-01). Ponúkajú sa len nearchivovaní klienti. Nacenená suma sa prepočítava už pri písaní (Alpine, CSP build). Server sumu vždy prepočíta sám a hodnote z klienta neverí.
- **D-03:** Zmazať sa dá len úloha bez zamknutých častí (TSK-02). Nezamknuté časti sa zmažú spolu s úlohou v jednej transakcii a s auditom. Kontrolu robí server: atómovo v SQL a cez FK alebo trigger. Skrytie tlačidla v UI nestačí.
- **D-04:** Fakturačný stav sa **neukladá**, odvodzuje sa (výskum Vzor 4, pohľad typu `task_billing_v`). **Vyfakturovaná** = zapracovaná, bez nezamknutej časti a aspoň jedna časť zamknutá. **Čiastočne** = aspoň jedna zamknutá časť, ale nie vyfakturovaná. Inak **nevyfakturovaná** (TSK-03, rozhodnutie vlastníka v REQUIREMENTS.md). Kým neexistujú faktúry, budú všetky úlohy nevyfakturované. Logika sa preto testuje nad časťami s nastaveným `invoice_id` v testovacích dátach.
- **D-05:** Úloha zobrazuje rozdiel nacenených a fakturovaných hodín (napr. „+6 h“ alebo „−2 h“). Nič sa neblokuje: časti môžu presiahnuť nacenené hodiny (TSK-05, spec 6.3). Pri všetkých hodinách a sumách je vždy uvedená mena a popisok, ktorý odlišuje nacenené a fakturované (výskum, UX pasce).
- **D-06:** Zoznam „Aktuálne úlohy“ obsahuje úlohy, ktoré nie sú zapracované **alebo** majú nezamknuté časti (TSK-04, spec 6.3). Filtre: klient, stav, fakturačný mesiac a text v názve, URL a poznámke (TSK-06). Filtre sa aktualizujú cez htmx fragment a stav filtra je v URL. Zoznam sa načíta jedným agregačným dotazom bez N+1 (výskum, Pasce výkonu).

**Fakturačná časť**
- **D-07:** Tabuľka `billing_allocations`: `task_id`, `hours numeric(7,2) CHECK (hours > 0)`, `billing_month` (RRRR-MM), `rate`, `amount` (uložená, `= round(hours*rate, 2)` cez `CHECK`), `currency` (kópia z klienta pri vzniku) a `invoice_id bigint NULL` (NULL = nezamknutá). Partial index `(…, billing_month) WHERE invoice_id IS NULL` (výskum ARCHITECTURE, ALC-01).
- **D-08:** Sadzba časti sa predvolí sadzbou úlohy a dá sa prepísať (spec 6.4). Suma sa vždy počíta cez `Money`, nikdy cez float.
- **D-09:** Úprava, presun do iného mesiaca a zmazanie sú dovolené len pre nezamknutú časť (ALC-02). Server to kontroluje **v tom istom SQL príkaze** (`UPDATE/DELETE … WHERE id = :id AND invoice_id IS NULL` s kontrolou počtu riadkov), nie postupom „načítaj, skontroluj, ulož“. Pri neúspechu vráti 409/422 so správou. Ako posledná línia slúži `BEFORE UPDATE OR DELETE` trigger, ktorý odmietne zmenu zamknutej časti (výskum: obrana v troch vrstvách). Výnimka pre uvoľnenie dobropisom sa doplní až v Phase 9, keď existujú dobropisy. Tu trigger zamknutú časť odmieta vždy.
- **D-10:** Zmazanie časti na mobile vyžaduje potvrdenie (panel zospodu) (výskum, UX pasce).
- **D-11:** Panel „Označiť ako zapracovanú“ (ALC-04, spec 6.4, maketa `task-flows.html`): mesiac Aktuálny / Ďalší / Iný… (predvolený je aktuálny), hodiny (predvolené na zostatok nacenených hodín mínus všetky existujúce časti, dajú sa upraviť), živý prepočet sumy. Po potvrdení sa v jednej transakcii nastaví stav `done` s `done_at` (dnes podľa `Clock`), vytvorí sa časť a zapíše sa audit.
- **D-12:** Časť sa dá pridať aj k nezapracovanej úlohe tlačidlom „+ Pridať časť do mesiaca“ (ALC-05). Detail úlohy ukazuje **vyfakturované** (zamknuté), **naplánované** (nezamknuté) a **zostávajúce** (nacenené − všetky časti, nikdy pod 0 v ukazovateli) hodiny, zoznam častí (zamknuté s číslom faktúry, ostatné upraviteľné) a odkaz na portál (spec 7 Detail úlohy).
- **D-13:** Všetky zmeny úloh a častí sa zapisujú cez existujúci `AuditLogger` v tej istej transakcii (AUD-01, vzor z Phase 3).

**Dashboard (variant B)**
- **D-14:** Obsah podľa DSH-01 a makety `mobile-home.html`: hore aktuálny fakturačný mesiac a klient, dlaždice *Na fakturáciu tento mesiac* (suma a hodiny nezamknutých častí s fakturačným mesiacom = vybraný mesiac) a *V práci* (suma a hodiny nezapracovaných úloh). Pod nimi úlohy zoskupené podľa stavu: **Zapracované, do faktúry** / **Čiastočne vyfakturované** (s ukazovateľom priebehu) / **V práci**. Ďalej plávajúce tlačidlo „+“ a spodná lišta Prehľad / Úlohy / Faktúry / Viac. Položka „Úlohy“ v `_nav_items.php` sa stane aktívnym odkazom.
- **D-15:** Dlaždice sa po zmene časti obnovia cez `HX-Trigger` (napr. `allocationChanged`). Po úspešnom POST cez htmx sa používa `HX-Location` alebo `HX-Redirect`, nie 302 (výskum Vzor 5).

**Import z Excelu**
- **D-16:** Import je `spark` príkaz (napr. `import:excel`), ktorý beží len v dev/CLI. `phpoffice/phpspreadsheet` je iba v `require-dev` a produkčný obraz ho nemá (ROADMAP, STACK). Súbor ostáva mimo gitu a cesta k nemu je argument príkazu.
- **D-17:** Najprv beží **suchý beh**: po mesiacoch ukáže rozdiel „súčet v Exceli“ oproti „prepočítaný súčet“ a vyznačí riadky, kde `hodiny × 27 ≠ suma`. Chybné súčty z Excelu (E25/F25) sa nepreberajú, sumy sa vždy prepočítajú. Ostrý beh prebehne v jednej transakcii (výskum Pasca 15).
- **D-18:** URL na Taskman sa číta z **hyperlinku bunky** (`getHyperlink()->getUrl()`), nie zo zobrazeného textu. Hodnoty sa normalizujú: desatinná čiarka, NBSP, „ÁNO/NIE“ s Unicode normalizáciou a vzorce cez vypočítanú hodnotu.
- **D-19:** „Zapracované = ÁNO“ sa importuje ako zapracovaná úloha s jednou nezamknutou časťou v mesiaci daného bloku. Klient je All4Net so sadzbou 27 € (IMP-01, spec 9). Všetky riadky majú „Vyfakturované = NIE“, import zamknutých častí sa preto nerieši.
- **D-20:** Import je **idempotentný**: kľúč je hash z mesiaca, URL, názvu a hodín, uložený na úlohe. Opakovaný beh nevytvorí duplikáty. Import ide cez tie isté doménové služby ako UI (`TaskService`, `AllocationService`), nie cez surové INSERT. Výsledok overí PHPUnit test nad anonymizovaným vzorkovým súborom vytvoreným v teste. Reálny súbor do gitu nepatrí.

### Claude's Discretion

Vlastník schválil tieto predvoľby. Plánovač ich môže spresniť, nie obrátiť:
- **Dashboard, mesiac a klient:** aktuálny fakturačný mesiac je kalendárny mesiac podľa `Clock` a dá sa prepnúť na iný. Klient je posledný použitý, s voľbou „Všetci“. Pri viacerých menách dlaždice zobrazia súčty zvlášť pre každú menu, nikdy ich nesčítajú.
- **„Zapracovaná“ bez zostatku:** ak sú nacenené hodiny už celé rozdelené (zostatok ≤ 0), úloha sa dá označiť ako zapracovaná aj bez novej časti (hodiny 0 = nevytvorí sa žiadna časť). Návrat do stavu „V práci“ je dovolený (vymaže `done_at`, existujúce časti ostanú) a zapíše sa do auditu.
- **Zmena sadzby, klienta a meny:** časť si pri vzniku uloží sadzbu a menu. Neskoršia zmena sadzby úlohy ani klienta existujúce časti neprepočíta. Klient úlohy sa nedá zmeniť, kým má úloha nejakú časť. Zmenu meny klienta server odmietne, kým má klient nezamknuté časti (doplnenie kontroly do `ClientService` z Phase 3, výskum ARCHITECTURE „Mena na časti“).
- **Import, riadky „Zapracované = NIE“:** importujú sa ako úlohy v stave „V práci“ bez časti.
- Tvar `billing_month` (`char(7)` alebo `date` s 1. dňom v mesiaci s `CHECK`), mechanizmus „posledného použitého klienta“ (nastavenie používateľa alebo odvodenie z poslednej úlohy), rozloženie PC zoznamu úloh, presný text tlačidiel a toastov a forma ukazovateľa priebehu.
- Či sa pre `billing_allocations.invoice_id` pridá FK hneď (tabuľka `invoices` ešte neexistuje) alebo až v Phase 5. Pevná požiadavka: stĺpec existuje a zámková podmienka je v SQL aj v triggeri.

### Deferred Ideas (OUT OF SCOPE)

Žiadne. Diskusia sa nekonala, rozsah ostáva podľa ROADMAP. (Mimo fázy podľa Phase Boundary: faktúry a skutočné zamknutie časti faktúrou, ALC-03 úplne, PAY-02, LIM-03, AUD-02, PC tabuľkové zoznamy faktúr, preklady PL/CZ/EN.)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TSK-01 | Vytvorenie úlohy, predvolený posledný klient, sadzba z klienta, živý prepočet sumy | `tasks` tabuľka, `TaskInput`/`TaskService`, posledný klient odvodený z poslednej úlohy, Alpine CSP komponent s BigInt aritmetikou + serverový prepočet `Money::timesHours` |
| TSK-02 | Úprava a zmazanie úlohy len bez zamknutých častí | atómový `DELETE ... WHERE NOT EXISTS (zamknutá časť)`, FK `RESTRICT`, trigger na časti, grant DELETE na `tasks` |
| TSK-03 | Stav V práci/Zapracovaná + odvodený fakturačný stav | pohľad `task_billing_v` (CASE nad agregátmi častí), CHECK `(status='done') = (done_at IS NOT NULL)` |
| TSK-04 | Zoznam „Aktuálne úlohy“ | dotaz nad pohľadom: `status <> 'done' OR unlocked_count > 0` |
| TSK-05 | Rozdiel nacenených a fakturovaných hodín | `allocated_hours - hours` zo stringov cez bcmath (`Hours` helper) |
| TSK-06 | Filtre klient, stav, mesiac, text | jeden agregačný dotaz, `EXISTS` na mesiac, `ILIKE` s escapovaním, htmx fragment + `hx-push-url` |
| ALC-01 | Časť: hodiny, mesiac, sadzba, suma, mena z klienta | tabuľka `billing_allocations` s CHECK, partial index |
| ALC-02 | Úprava/presun/zmazanie nezamknutej časti | `UPDATE/DELETE ... AND invoice_id IS NULL` + kontrola `affectedRows()`, trigger `alloc_guard` |
| ALC-04 | Panel „Označiť ako zapracovanú“ | transakcia: `SELECT task FOR UPDATE` + status + časť + audit, Bootstrap offcanvas (bottom) cez htmx fragment |
| ALC-05 | Časť aj k nezapracovanej úlohe, detail s vyfakturované/naplánované/zostáva | `AllocationService::add`, detail úlohy s agregátmi, `<progress>`/triedy namiesto inline `style` |
| DSH-01 | Dashboard variant B | 3 agregačné dotazy (dlaždice po menách, skupiny úloh), htmx obnova dlaždíc cez `HX-Trigger`, aktívna položka „Úlohy“ v navigácii |
| IMP-01 | Import 09 a 10/2026 | `spark import:excel`, PhpSpreadsheet `require-dev` (BEZ `setReadDataOnly`), suchý beh, idempotentný `import_key`, prechod cez doménové služby |
</phase_requirements>

## Summary

Fáza 4 pridáva prvé dve doménové tabuľky (`tasks`, `billing_allocations`), jeden odvodený pohľad (`task_billing_v`), tri vrstvy ochrany nezamknutej časti (atómový SQL, trigger, stĺpcové granty) a tri používateľské plochy: formulár/detail/zoznam úloh, panel „Zapracované“ a dashboard. Všetky stavebné bloky existujú z fázy 3: `Money` (bcmath, half-up), `Transaction::run`, `AuditLogger`, `Clock`/`FrozenClock`, vzor služby s `FOR UPDATE` a auditom (`ClientService`), vzor kontroléra s 422 a flashdata, `htmx_view()`, Alpine CSP komponenty v `public/assets/js/app.js`, testovací základ `PgTestCase` (owner a `app_rw` spojenie) a `MasterFixtures`. Autorizácia je globálna (`AuthGate`: iba skupina admin), nové routy ju dostanú automaticky cez glob `app/Config/Routes/*.php`.

Najdôležitejšie zistenia, ktoré menia plán: (1) **v zdrojovom Exceli majú špecifický hyperlink iba 5 z 16 úloh** (bunky A4 až A8, všetko september); ostatným riadkom je v bunke len generický text `https://taskman.all4net.sk/?section=mana`, takže import musí mať záložný zdroj URL a idempotentný kľúč nesmie počítať s unikátnou URL. (2) PhpSpreadsheet **nečíta hyperlinky, ak je zapnuté `setReadDataOnly(true)`** (overené v zdrojáku `Reader/Xlsx.php`), čo je v rozpore s radou v `PITFALLS.md` Pasca 15. (3) Chybný súčet E25/F25 má dnes **správnu hodnotu** (1 h, 27 €, lebo ÁNO je len riadok 21), chybný je rozsah vzorca (`19:22` namiesto `20:24`), takže suchý beh musí porovnávať aj rozsah/formulu a overenie kritéria č. 5 musí používať fixture s vedome vloženým rozdielom. (4) Živý prepočet v prehliadači je jediné miesto, kde by mohol vzniknúť float, preto v JS použiť `BigInt` a mať paritné testy s PHP. (5) Bootstrap `.progress-bar` sa nastavuje inline `style="width:..%"`, čo prísna CSP (a `ViewsCspTest`) zakazuje; ukazovateľ priebehu treba riešiť natívnym `<progress>` alebo triedami s krokmi.

**Primary recommendation:** Postaviť to ako sedem vertikálnych rezov (schéma+stráže → CRUD úloh → časti a detail → panel „Zapracované“ → zoznam a filtre → dashboard → import), kde rez 1 (migrácia, trigger, granty, pohľad, `Hours`, DB testy) je základ a každý ďalší rez ide UI → route → služba → DB a končí PHPUnit testom. Nezamknutosť vynucovať vždy v SQL (`AND invoice_id IS NULL` + kontrola počtu riadkov) a v triggeri; nikdy „načítaj, skontroluj, ulož“.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Výpočet sumy časti/úlohy (autoritatívny) | API / Backend (`Money`) | Database (CHECK `amount = round(hours*rate,2)`) | Peniaze len bcmath/numeric; DB CHECK je záchranná sieť |
| Živý prepočet pri písaní | Browser (Alpine CSP + BigInt) | API (serverový prepočet pri uložení) | UX bez sieťového volania; server hodnote z klienta neverí |
| Zámok/nemennosť časti | Database (trigger + stĺpcové granty) | API (atómový `WHERE invoice_id IS NULL`) | Obrana v troch vrstvách, UI len skrýva tlačidlá |
| Odvodený fakturačný stav | Database (pohľad `task_billing_v`) | API (čítanie) | Nič sa neukladá, jedno miesto pravdy |
| Filtre a zoznam | API / Backend (SQL agregácia) | Browser (htmx fragment + `hx-push-url`) | Jeden dotaz bez N+1, stav v URL |
| Dashboard dlaždice a skupiny | API / Backend | Browser (htmx obnova cez `HX-Trigger`) | Súčty po menách musí robiť server |
| Panel „Zapracované“ (bottom sheet) | Frontend Server (HTML fragment cez htmx) | Browser (Bootstrap offcanvas, Alpine) | Zostatok hodín počíta server pod zámkom |
| Posledný použitý klient | API / Backend (odvodené z poslednej úlohy) | — | Bez novej tabuľky nastavení |
| Import z Excelu | CLI (`spark`, dev) | API služby (`TaskService`, `AllocationService`) | Rovnaká doménová logika ako UI, PhpSpreadsheet len dev |
| Audit zmien | API / Backend (`AuditLogger` v transakcii) | Database (`audit_log` insert-only) | Zápis zlyhá, zlyhá celá zmena |

## Standard Stack

### Core (všetko už v projekte, žiadne nové produkčné balíky)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| CodeIgniter 4 | 4.7.5 | routing, DB, view, validácia | rozhodnutý stack [VERIFIED: composer.json] |
| PostgreSQL | 18-alpine | `numeric`, CHECK, partial index, view, plpgsql trigger | rozhodnutý stack [VERIFIED: compose.yaml] |
| bcmath + `App\Libraries\Platform\Money` | PHP ext | peniaze a hodiny | existujúca trieda, `timesHours()` prijíma `^-?\d{1,5}(\.\d{1,2})?$` [VERIFIED: čítané `Money.php`] |
| htmx | 2.0.11 | fragmenty, filtre, panel | `app.php` layout; `responseHandling` už povoľuje swap pre 422 [VERIFIED: layouts/app.php] |
| Alpine.js CSP build | 3.17.5 | živý prepočet, drobná interaktivita | komponenty sa registrujú v `public/assets/js/app.js` cez `Alpine.data` [VERIFIED: app.js] |
| Bootstrap | 5.3.8 (+ bundle JS) | offcanvas (panel zospodu), layout | `bootstrap.bundle-5.3.8.min.js` je v layoute [VERIFIED] |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `phpoffice/phpspreadsheet` | 5.10.0 (2026-09-17, `php ^8.2`) | import XLSX vrátane hyperlinkov | **iba `require-dev`**, iba trieda `ExcelReader` [VERIFIED: packagist p2 JSON]; nepotrebuje nič nové: ext-gd, zip, xmlreader, simplexml sú v obraze (`docker exec ... php -m` ukázalo zip, xmlreader, bcmath) |
| PHPUnit | `^11.5` (composer.json; CLAUDE.md spomína pin 10.5, skutočný `composer.json` má 11.5) | testy | existujúce |
| node (`tests/js`) | — | testy Alpine komponentov | existujúci vzor `client-form.test.js` |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `char(7)` `billing_month` | `date` (1. deň) + CHECK | **Odporúčam `char(7)` s CHECK `~ '^\d{4}-(0[1-9]|1[0-2])$'`**: zhoduje sa so špecifikáciou „RRRR-MM“, zoradiť/porovnať sa dá lexikograficky, nevznikajú časové pásma ani konverzie. Phase 5 ho použije ako kľúč dávky |
| Alpine BigInt (živý prepočet) | htmx `hx-get` na server pri každom `input` | Server je presnejší (jeden kód), ale sieť pri písaní na mobile; D-02 predpisuje Alpine. Použiť Alpine s `BigInt`, server prepočíta pri uložení |
| Vlastná tabuľka „posledný klient“ | odvodenie z `SELECT client_id FROM tasks ORDER BY id DESC LIMIT 1` | Odvodenie netreba migrovať a je vždy konzistentné (jediný používateľ) |

**Installation:**
```bash
docker compose exec php composer require --dev phpoffice/phpspreadsheet:^5.10
```
Produkčný build (`composer install --no-dev`, Dockerfile r. 24) ho vynechá. Triedu `ExcelReader` nikdy nenačítať mimo príkazu `import:excel` (pozri Pitfall 8).

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| phpoffice/phpspreadsheet | Packagist | >10 rokov (nástupca PHPExcel) | veľmi vysoké (stovky miliónov) | github.com/PHPOffice/PhpSpreadsheet | nespustené (pip nie je dostupný v prostredí) | Approved: názov uvedený v `CLAUDE.md`/`STACK.md` (rozhodnutie vlastníka) a existenciu a verziu 5.10.0 potvrdil Packagist p2 JSON. Tag `[ASSUMED]` pre slopcheck, ale pri zhode s dokumentovaným rozhodnutím nepotrebuje samostatný checkpoint |

**Packages removed due to slopcheck [SLOP] verdict:** none (slopcheck nebolo možné spustiť)
**Packages flagged as suspicious [SUS]:** none

*Fáza nepridáva žiadny iný externý balík.*

## Architecture Patterns

### System Architecture Diagram

```
 iPhone/PC (htmx + Alpine CSP)                      CLI (dev)
 ┌──────────────────────────┐                  ┌───────────────────┐
 │ /, /tasks, /tasks/{id}   │                  │ spark import:excel│
 │ Alpine: živý prepočet    │                  │  --file --client  │
 │ htmx: filtre, panel,     │                  │  [--dry-run]      │
 │ tlačidlá, HX-Trigger     │                  └─────────┬─────────┘
 └───────────┬──────────────┘                            │ ExcelReader (PhpSpreadsheet,
             │ HTTPS + CSRF (hx-headers)                 │ hyperlinky, cached values)
             ▼                                           ▼
   AuthGate (admin) → Controllers\{Home,Tasks,Allocations}      ImportPlanner (čistá logika)
             │  validácia: TaskInput / AllocationInput              │
             ▼                                                      ▼
   ┌───────────────────────── Libraries\Work ───────────────────────────┐
   │ TaskService   AllocationService   TaskQueries (list, dashboard)    │
   │ Hours (bcmath)   MonthPicker (Clock)   AllocationLocked (výnimka)  │
   └───────┬───────────────────┬───────────────────────┬───────────────┘
           │ Transaction::run  │ Money::timesHours     │ AuditLogger (tá istá tx)
           ▼                   ▼                       ▼
   ┌────────────────────── PostgreSQL ──────────────────────┐
   │ tasks ─┐  billing_allocations (invoice_id NULL=voľná)  │
   │        └─ FK RESTRICT  CHECK amount=round(h*rate,2)    │
   │ trigger alloc_guard (BEFORE UPDATE OR DELETE)          │
   │ view task_billing_v (billed/planned/state)  audit_log  │
   └────────────────────────────────────────────────────────┘
   Phase 5 neskôr: UPDATE ... SET invoice_id = :id WHERE id = ANY(:ids) AND invoice_id IS NULL
```

### Recommended Project Structure

```
app/
├── Libraries/Work/                 # nový kontext (CONTEXT: "Work/")
│   ├── TaskService.php             # create/update/delete/markDone/reopen + audit
│   ├── TaskInput.php               # whitelist + normalizácia + validácia (vzor ClientInput)
│   ├── AllocationService.php       # add/update/move/delete, atómové WHERE invoice_id IS NULL
│   ├── AllocationInput.php
│   ├── AllocationLocked.php        # výnimka -> 409
│   ├── TaskQueries.php             # zoznam s filtrami, detail agregáty, dashboard súčty
│   ├── BillingMonth.php            # parse/validate 'YYYY-MM', current/next z Clock
│   ├── LastClient.php              # odvodenie predvoleného klienta
│   └── Import/{ExcelReader,ImportPlanner,ImportRunner,ImportRow}.php
├── Libraries/Platform/Hours.php    # bcmath nad numeric(7,2) (plus, minus, compare, format)
├── Libraries/Platform/MoneyFormat.php  # vytiahnuté z Settings\Clients::formatRate (zdieľané)
├── Controllers/{Tasks,Allocations}.php  (Home.php = dashboard)
├── Commands/ImportExcel.php
├── Config/Routes/tasks.php
├── Database/Migrations/2026-10-25-00000N_CreateTasks.php, _CreateBillingAllocations.php, _CreateTaskBillingView.php
├── Language/{sk,en}/Tasks.php      # kľúče v oboch (LangKeysTest)
└── Views/{tasks,home}/...          # fragmenty `_list`, `_parts`, `_done_panel`, `_tiles`
public/assets/js/app.js             # + Alpine.data('taskForm'), 'donePanel' ; css/app.css (+ progress)
```

### Pattern 1: Atómový zámok v SQL + kontrola počtu riadkov (ALC-02, D-09)

**What:** Zámok sa kontroluje v tom istom príkaze, ktorý mení dáta. `Transaction::run` obaľuje, `affectedRows()` rozhoduje.
**When to use:** každý UPDATE/DELETE časti, zmazanie úlohy.
**Example:**
```php
// Source: vzor z app/Libraries/Master/ClientService.php (FOR UPDATE + audit v tx) + CONTEXT D-09
public function update(int $id, array $v): void
{
    Transaction::run($this->db, function () use ($id, $v): void {
        $before = $this->db->query('SELECT * FROM billing_allocations WHERE id = :id: FOR UPDATE', ['id' => $id])->getRowArray();
        if ($before === null) { throw new InvalidArgumentException('Allocation not found'); }

        $amount = Money::of($v['rate'])->timesHours($v['hours'])->toDecimalString();
        $this->db->query(
            'UPDATE billing_allocations SET hours = :hours:, billing_month = :month:, rate = :rate:, amount = :amount:, updated_at = :now:
             WHERE id = :id: AND invoice_id IS NULL',
            [...],
        );
        if ($this->db->affectedRows() !== 1) { throw new AllocationLocked($id); } // -> 409
        $this->audit->recordChange('billing_allocation', $id, 'update', $this->auditValues($before), $this->auditValues($after));
    });
}
```
Pozn.: trigger v DB je posledná línia; PG výnimku z triggera prekladá CI4 na `DatabaseException` (v `Transaction::run` s `transException(true)`). Kontroler zachytáva `AllocationLocked` aj `DatabaseException` s textom `locked` a vracia 409.

### Pattern 2: Odvodený stav cez pohľad (TSK-03, D-04)

**Example:**
```sql
-- Source: ARCHITECTURE.md Vzor 4 + REQUIREMENTS.md rozhodnutie vlastníka (TSK-03)
CREATE VIEW task_billing_v AS
SELECT s.*,
       CASE WHEN s.locked_count = 0 THEN 'unbilled'
            WHEN s.status = 'done' AND s.unlocked_count = 0 THEN 'billed'
            ELSE 'partial' END AS billing_state
FROM (
  SELECT t.id AS task_id, t.client_id, t.status,
         COUNT(a.id) FILTER (WHERE a.invoice_id IS NOT NULL) AS locked_count,
         COUNT(a.id) FILTER (WHERE a.invoice_id IS NULL)     AS unlocked_count,
         COALESCE(SUM(a.hours) FILTER (WHERE a.invoice_id IS NOT NULL), 0) AS billed_hours,
         COALESCE(SUM(a.hours) FILTER (WHERE a.invoice_id IS NULL), 0)     AS planned_hours,
         COALESCE(SUM(a.hours), 0) AS allocated_hours
  FROM tasks t LEFT JOIN billing_allocations a ON a.task_id = t.id
  GROUP BY t.id
) s;
```
`app_rw` dostane SELECT na pohľad automaticky (default privileges `ON TABLES` pokrývajú aj views); overiť integračným testom pod `app_rw`. Zoznam a dashboard čítajú pohľad (jeden dotaz, bez N+1). „Zostáva“ v PHP: `max(0, tasks.hours - allocated_hours)` cez `Hours`.

### Pattern 3: Trigger `alloc_guard` (D-09, posledná línia)

V tejto fáze zamknutú časť odmietne vždy (dobropisová výnimka až Phase 9). Migrácia ako `app_owner`, trigger `BEFORE UPDATE OR DELETE ... FOR EACH ROW`:
```sql
CREATE FUNCTION alloc_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.invoice_id IS NOT NULL THEN
    RAISE EXCEPTION 'allocation % is locked', OLD.id USING ERRCODE = 'integrity_constraint_violation';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;  -- NULL -> hodnota (Phase 5 zamyká) je povolené
END $$;
```
Dôležité: prechod `NULL → hodnota` (zamykanie vo Phase 5) musí prejsť; `hodnota → čokoľvek` nie. Granty: `app_rw` v tejto fáze **nedostane `UPDATE (invoice_id)`**; Phase 5 pridá `GRANT UPDATE (invoice_id)`. Testy nastavujú `invoice_id` cez `owner()` spojenie.

### Pattern 4: htmx fragment + stav filtra v URL (TSK-06)

Existujúce `htmx_view($fragment, $data, $page)` + filter `HtmxVary`. Filtračný formulár `hx-get="/tasks" hx-target="#task-list" hx-push-url="true" hx-trigger="change, input delay:300ms from:#f-q"`; kontroler číta `GET` parametre (whitelist, validácia mesiaca regexom a klienta ako int). Po úspešnom POST cez htmx: `HX-Redirect`/`HX-Location` (nie 302). Formuláre vytvorenia/úpravy úlohy nechať ako obyčajný POST + `redirect()->to()` s flashdata (rovnako ako `Settings\Clients`), htmx použiť pre fragmenty (panel, zoznam častí, dlaždice).

### Pattern 5: Panel „Zapracované“ ako offcanvas (ALC-04)

GET `/tasks/{id}/done` vráti fragment (Bootstrap `offcanvas offcanvas-bottom`), ktorý sa vloží do `#sheet`; JS v `app.js` ho po `htmx:afterSwap` otvorí cez `bootstrap.Offcanvas.getOrCreateInstance(el).show()` (CSP-bezpečné, žiadny inline skript). Tlačidlá mesiaca Aktuálny/Ďalší/Iný: radio inputy (Iný = `<input type="month">` alebo select), predvolený zostatok vypočíta server pri generovaní fragmentu aj pri POST pod `FOR UPDATE` (zdroj pravdy je POST, nie hodnota z formulára pre zostatok). Dvojklik: `data-submit-once` (existuje) + serverová idempotencia (ak už `status='done'`, nevytvárať ďalšiu časť, vrátiť 409 so správou).

### Anti-Patterns to Avoid
- **Čítaj-skontroluj-ulož pre zámok** (D-09): vždy `AND invoice_id IS NULL` v príkaze a `affectedRows()`.
- **`float` kdekoľvek**, aj `round()`/`number_format()` na sumách; v JS nepoužiť `Number` násobenie sumy, ale `BigInt`.
- **`(float)` rozdiel hodín**: použiť `Hours::minus()` (bcsub scale 2), formátovať znamienko ručne (`+`, U+2212).
- **Inline `style="width: 40%"`** pre priebeh (porušuje `ViewsCspTest`).
- **Ukladať fakturačný stav** (rozíde sa s časťami).
- **Zapisovať do budúcich `invoices*`** alebo na nich dať FK (Phase 5).
- **Surové INSERT v importe** namiesto služieb (obíde audit a výpočty).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Násobenie hodín × sadzba, zaokrúhlenie | vlastné `round()`/float | `Money::timesHours()` + DB `CHECK amount = round(hours*rate,2)` | PG `round(numeric)` zaokrúhľuje half-away-from-zero ako `bcround(..., HalfAwayFromZero)`; paritu už pokrýva `MoneyDbParityTest` |
| Transakcie a vnorenie | `transStart` ručne | `Transaction::run` | vyriešené WR-01 (vnorenie, reset stavu) |
| Audit | vlastný INSERT do `audit_log` | `AuditLogger::record/recordChange` | scrub citlivých polí, IP, CLI režim (`ip='cli'`) |
| Formátovanie meny podľa jazyka | `number_format` | extrahovať `formatRate` z `Settings\Clients` do `Platform\MoneyFormat` (NumberFormatter + string, bez float) | už overené, SK `1 080,00 €` |
| Čítanie XLSX | ručné rozbalenie ZIP + `.rels` | PhpSpreadsheet `getHyperlink()->getUrl()` | STACK.md: ručné je krehké |
| Odvodený stav | ukladaný stĺpec + triggery | pohľad `task_billing_v` | jedno miesto pravdy |
| Posledný klient | nová nastavovacia tabuľka | odvodenie z poslednej úlohy | menej stavu |
| CSRF pre htmx | vlastné tokeny | existujúci `hx-headers` na `<body>` + `HtmxCsrf` | hotové vo fáze 2 |
| Priebeh | Bootstrap progress s inline štýlom | natívny `<progress>` alebo triedy | CSP |

**Key insight:** Doména je malá, ale chyby sú finančné. Všetko, čo počíta, prechádza cez `Money`/`Hours` a DB CHECK; všetko, čo chráni nemennosť, je v SQL, nie v UI.

## Runtime State Inventory

Nejde o rename/refactor; nová tabuľková doména. Jediná zmena existujúceho správania: `ClientService::update` získa kontrolu zmeny meny (Discretion). Žiadne uložené reťazce sa nepremenúvajú.

| Category | Items Found | Action Required |
|----------|-------------|-----------------|
| Stored data | None: tabuľky `tasks`/`billing_allocations` sú nové, overené `ls app/Database/Migrations` (posledná je `000006_WidenClientsHourlyRate`) | migrácie |
| Live service config | None — verified (žiadne externé služby) | — |
| OS-registered state | None — verified | — |
| Secrets/env vars | None — import berie cestu k súboru z argumentu | — |
| Build artifacts | `composer.lock` sa zmení (nový dev balík); dev obraz musí mať dev závislosti | `composer require --dev`, overiť že `docker compose exec php` ich vidí |

## Common Pitfalls

### Pitfall 1: PhpSpreadsheet nevráti hyperlinky pri `setReadDataOnly(true)`
**What goes wrong:** Všetky URL by sa stratili (alebo ostal len zobrazený text).
**Why it happens:** `Reader/Xlsx.php` načíta `hyperlinks` iba v bloku `if (!$this->readDataOnly)` [VERIFIED: github.com/PHPOffice/PhpSpreadsheet Reader/Xlsx.php].
**How to avoid:** Reader bez `setReadDataOnly`. Pamäť nie je problém (súbor má 25 riadkov). Použiť `IReadFilter` len ak treba, nie readDataOnly. `PITFALLS.md` Pasca 15 radí opak, táto fáza ju prepisuje.
**Warning signs:** všetky `url` v dry-run výpise sú generické.

### Pitfall 2: Iba 5 z 16 riadkov má reálny hyperlink
**What goes wrong:** Pri import sa očakáva unikátna URL na riadok. V skutočnosti [VERIFIED: rozbalený `sheet1.xml` a `sheet1.xml.rels`] sú hyperlinky len v A4 až A8 (`...?section=management&project=443&todo=81252` atď.). Ostatné bunky majú len text `https://taskman.all4net.sk/?section=mana`. `&amp;` v `.rels` je XML escaping, PhpSpreadsheet vráti `&`.
**How to avoid:** URL = hyperlink, ak existuje, inak text bunky (ak je to platná http(s) URL); dry-run vypíše počet riadkov „bez špecifického odkazu“ a vlastník ich doplní v UI. Idempotentný kľúč nesmie predpokladať unikátnu URL.

### Pitfall 3: Kolízia idempotentného kľúča
**What goes wrong:** Dva riadky s rovnakým mesiacom, rovnakou generickou URL, názvom a hodinami dostanú rovnaký hash a druhý sa zahodí. V aktuálnom súbore sa to nestane (názvy sú rôzne, jeden je „… 2“), ale kľúč je krehký.
**How to avoid:** `import_key = sha256(month|url|title|hours|ordinal)`, kde `ordinal` je poradie medzi rovnakými štvoricami v súbore. Stĺpec `import_key text NULL` + `UNIQUE INDEX ... WHERE import_key IS NOT NULL`. Normalizovať vstup pred hashom (trim, NFC, `mb_strtolower` názvu, hodiny `bcadd(x,'0',2)`). Zmena názvu v Exceli = nová úloha (zdokumentovať).

### Pitfall 4: „Chybný súčet E25/F25“ nemá dnes chybnú hodnotu
**What goes wrong:** CONTEXT hovorí, že suchý beh musí ukázať rozdiel. Skutočnosť: F25 = `SUM(F19:F22)` = 27 a správna hodnota (ÁNO je iba riadok 21: 1 h × 27) je tiež 27; E25 = 1 = správne. Chybný je **rozsah vzorca** (19 až 22 namiesto 20 až 24). D25 = 2214 = 82 h × 27 je správne; D15 = 2403 = 89 h × 27 správne.
**How to avoid:** Dry-run nech porovnáva (a) uloženú cache hodnotu bunky `getOldCalculatedValue()` s prepočtom z riadkov, (b) pre vzorec súčtu overí, či jeho rozsah pokrýva všetky riadky bloku (parsovať `SUM(X:Y)`). Hlásiť aj „rozdiel 0, ale rozsah vzorca neúplný“. Kritérium č. 5 testovať fixture s vedome pokazeným súčtom (hodnota aj rozsah), nie oproti reálnemu súboru. Reálny súbor len ručné overenie (suchý beh: 09 = 89 h/2 403 €, 10 = 82 h/2 214 €, zapracované v 10 = 1 h/27 €).

### Pitfall 5: Pevná sadzba 27 v kontrole
Excel má sadzbu v `J40` (`Rate na hodinu:`). Dry-run kontrola „hodiny × sadzba ≠ suma“ má používať sadzbu z `J40` (a porovnať ju so sadzbou klienta, rozdiel zahlásiť), nie literál 27. Importované sumy vždy z klientovej sadzby cez `Money`.

### Pitfall 6: Rozbitie CSP živým prepočtom a ukazovateľom
Alpine CSP build nepodporuje ľubovoľné výrazy v atribútoch (len vlastnosti, volania metód, základné operátory podľa dokumentácie CSP buildu [ASSUMED: konkrétna množina výrazov, overiť pri implementácii]); logika patrí do `Alpine.data` v `app.js` ako gettery (vzor `clientForm`). `x-text="sumText"`, nie `x-text="a * b"`. Inline `style=""` a `onclick` zakázané (`ViewsCspTest`). Priebeh: `<progress max="40" value="16">` alebo `.seg-NN` triedy v `app.css`.

### Pitfall 7: Float v JS pri živom prepočte
`27 * 8.1` v JS dá `218.70000000000002`. Implementovať v `taskForm`: parsovať text (čiarka aj bodka) na celé čísla `BigInt` v jednotkách 1e-2, súčin v 1e-4, half-up zaokrúhlenie celým delením na 1e-2, formátovanie reťazcom. Rovnaké testovacie vektory v `tests/js/task-form.test.js` a v PHP `MoneyTest` (paritné: `8.1×27`, `0.01×0.5` → `0.01` half-up, `0.01×0.49`). Mena sa zobrazí z `data-currency` (nie z JS formátovania locale).

### Pitfall 8: `ImportExcel` v produkčnom classmape
`composer dump-autoload --no-dev --classmap-authoritative` zaradí aj `app/Commands/ImportExcel.php` a spark ho pri výpise zoznamu načíta. Ak príkaz importuje PhpSpreadsheet triedy, spadne `spark list` v produkcii. **Riešenie:** príkaz používa len `ExcelReader` cez `class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)` guard s chybovou správou „len dev“; `use` importy sú neškodné, ale typehinty/`new` na tieto triedy len vnútri `ExcelReader`. Overiť testom, že `ImportRunner`/`ImportPlanner` nemajú závislosť na PhpSpreadsheet (plánovač je čistá logika testovateľná bez knižnice). PHPStan beží nad dev, takže `ExcelReader` prejde.

### Pitfall 9: Zmena meny klienta a zmena klienta úlohy
Časť má vlastnú `currency` a `rate` (neprepočítavajú sa). `ClientService::update` musí v tej istej transakcii (po `FOR UPDATE` na klientovi) odmietnuť zmenu `currency`, ak existuje nezamknutá časť úloh klienta (`SELECT 1 FROM billing_allocations a JOIN tasks t ON t.id=a.task_id WHERE t.client_id=:c AND a.invoice_id IS NULL LIMIT 1`). Odporúčanie nad rámec Discretion: odmietnuť aj pri zamknutých (po Phase 5 by klient mal doklady v inej mene). Zmena klienta úlohy sa odmietne, ak má úloha akúkoľvek časť (UPDATE grant na `client_id` ostáva, kontrola v SQL: `... AND NOT EXISTS (SELECT 1 FROM billing_allocations WHERE task_id = tasks.id)`).

### Pitfall 10: Súbeh „zapracované“ a zmazanie/úprava
Dva rýchle ťuky vytvoria dve časti. Vždy `SELECT ... FROM tasks WHERE id = :id: FOR UPDATE` ako prvý krok akejkoľvek operácie nad úlohou alebo jej časťami (add/update/delete/markDone/reopen/delete task) → serializácia. Phase 5 pri zamykaní častí musí brať rovnaký poriadok zámkov (task potom časť), spísať do komentára migrácie.

### Pitfall 11: Zobrazenie rozdielu hodín (TSK-05) a súčty bez menovky
`+6 h` = súčet všetkých častí − nacenené (zdokumentovať). Pri úlohe v práci s 16/40 h by ukazoval −24 h ako šum; ukázať rozdiel iba pre zapracované úlohy alebo keď časti presiahnu nacenené. Tiles a zoznamy: vždy `hodiny + mena`, nacenené vs. fakturované s popiskami (D-05). Súčty rôznych mien nikdy nesčítať (`GROUP BY currency`).

### Pitfall 12: ILIKE a wildcard injekcia
`q` s `%`/`_`/`\` mení význam hľadania. Escapovať: `str_replace(['\\','%','_'], ['\\\\','\%','\_'], $q)` a `ILIKE :q: ESCAPE '\'`. Bind parameter, nikdy konkatenácia.

### Pitfall 13: URL úlohy a XSS
Používateľské URL sa renderujú ako `href`. Povoliť iba `http`/`https` s hostom (existujúci vzor `PortalUrlTemplate::validate` robí rovnakú kontrolu pre šablónu), max 2000 znakov, `esc($url,'attr')`, `target="_blank" rel="noopener noreferrer"`. Import berie URL z hyperlinku (nedôveryhodný vstup súboru) a prechádza tou istou validáciou.

## Code Examples

### Hours (bcmath, žiadne float)
```php
// Source: vzor Money.php (explicitný scale, regex vstup)
final class Hours
{
    public static function of(string $v): string // '8,5' -> '8.50'
    {
        $v = str_replace(',', '.', trim($v));
        if (preg_match('/^\d{1,5}(\.\d{1,2})?$/D', $v) !== 1) { throw new InvalidArgumentException('Invalid hours'); }
        return bcadd($v, '0', 2);
    }
    public static function minus(string $a, string $b): string { return bcsub($a, $b, 2); }
    public static function remaining(string $quoted, string $allocated): string
    { return bccomp($quoted, $allocated, 2) > 0 ? bcsub($quoted, $allocated, 2) : '0.00'; }
}
```

### Migrácia tabuliek (skratka)
```sql
CREATE TABLE tasks (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  client_id bigint NOT NULL REFERENCES clients(id),
  url text NULL CHECK (char_length(url) <= 2000),
  title text NOT NULL CHECK (char_length(title) BETWEEN 1 AND 300),
  hours numeric(7,2) NOT NULL CHECK (hours > 0),
  rate numeric(12,2) NOT NULL CHECK (rate >= 0),
  note text NULL,
  status text NOT NULL DEFAULT 'in_progress' CHECK (status IN ('in_progress','done')),
  done_at timestamptz NULL,
  import_key text NULL,
  created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
  CHECK ((status = 'done') = (done_at IS NOT NULL))
);
CREATE UNIQUE INDEX tasks_import_key_uq ON tasks (import_key) WHERE import_key IS NOT NULL;
CREATE INDEX tasks_client_status_idx ON tasks (client_id, status);

CREATE TABLE billing_allocations (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  task_id bigint NOT NULL REFERENCES tasks(id) ON DELETE RESTRICT,
  hours numeric(7,2) NOT NULL CHECK (hours > 0),
  billing_month char(7) NOT NULL CHECK (billing_month ~ '^\d{4}-(0[1-9]|1[0-2])$'),
  rate numeric(12,2) NOT NULL CHECK (rate >= 0),
  amount numeric(12,2) NOT NULL,
  currency char(3) NOT NULL CHECK (currency IN ('EUR','PLN','CZK')),
  invoice_id bigint NULL,                    -- FK na invoices až v Phase 5
  created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
  CHECK (amount = round(hours * rate, 2))
);
CREATE INDEX alloc_task_idx ON billing_allocations (task_id);
CREATE INDEX alloc_unlocked_month_idx ON billing_allocations (billing_month, task_id) WHERE invoice_id IS NULL;
```
Granty (migrácia vlastníka): `tasks` stĺpcový UPDATE (`client_id, url, title, hours, rate, note, status, done_at, updated_at`) + `DELETE` (trait `GrantsPrivileges` má len `grantMutable` a `grantUpdateColumns`; doplniť `grantDelete()` do traitu, `grantMutable` komentár hovorí „nie dokladové“, ale ARCHITECTURE.md výslovne dáva `tasks` a `billing_allocations` plné DML chránené triggermi). `billing_allocations`: UPDATE na `hours, billing_month, rate, amount, updated_at` + DELETE (nie `invoice_id`, `task_id`, `currency`). Default privileges dajú SELECT, INSERT. Pozn. `rate` na úlohe má byť `numeric(12,2)`; sadzba klienta je `numeric(12,2)` (migrácia 000006). `Money::of` povoľuje max 2 desatinné miesta, preto sadzba s viac ako 2 des. nie je podporovaná (netreba meniť).

### Živý prepočet (Alpine CSP, BigInt)
```js
// public/assets/js/app.js (vzor clientForm: gettery, žiadne výrazy v atribútoch)
window.Alpine.data('taskForm', function () {
  return {
    hours: '', rate: '', currency: '',
    init: function () { this.currency = this.$el.dataset.currency || ''; this.hours = this.$el.dataset.hours || ''; this.rate = this.$el.dataset.rate || ''; },
    get sumText() { return calcAmount(this.hours, this.rate); } // BigInt, half-up; '' pri neplatnom vstupe
  };
});
// calcAmount: parse '8,5' -> 850n (1e-2), rate '27' -> 2700n; product 1e-4; (p + 50n) / 100n half-up; format 'X.YY'
```
Textová hodnota sa pre zobrazenie formátuje podľa jazyka rozhrania zo `data-` atribútov (oddeľovač), nie z `Intl` na floate.

### Import: čítanie hyperlinku (PhpSpreadsheet 5.x)
```php
// Source: PhpSpreadsheet Reader/Xlsx + CLAUDE.md STACK; BEZ setReadDataOnly(true)
$reader = IOFactory::createReader('Xlsx');
$sheet  = $reader->load($path)->getActiveSheet();
$cell   = $sheet->getCell('A' . $row);
$url    = $cell->hasHyperlink() ? $cell->getHyperlink()->getUrl() : (string) $cell->getValue();
$done   = self::isYes((string) $sheet->getCell('G' . $row)->getValue()); // NFC, NBSP, mb_strtoupper, 'ÁNO'|'ANO'
$cached = $sheet->getCell('D25')->getOldCalculatedValue(); // uložená hodnota vzorca
```
Štruktúra súboru (overená): blok = riadok `Ulohy na fakturovanie:` (A), nasledujúci riadok `Mesiac RRRR` (slovenský názov **bez diakritiky**, „September“, „Oktober“), hlavička so `URL (Taskman)`, riadky úloh do `Sumar za mesiac:`; stĺpce A url, B názov, C nacenené hodiny, D suma (vzorec), E/F fakturovateľné, G zapracované, H vyfakturované; `J40` = sadzba. Mapovanie mesiacov robiť po odstránení diakritiky a `mb_strtolower`.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Spoliehať sa na `readDataOnly` pre rýchlosť | Čítať s hyperlinkmi (bez readDataOnly) | PhpSpreadsheet 5.x správanie | Zmena oproti PITFALLS Pasca 15 |
| Ukladaný stav faktúrovania | Odvodený pohľad | ARCHITECTURE Vzor 4 | Jedno miesto pravdy |
| `SEQUENCE` pre čísla | riadkový zámok | (Phase 5) | Irelevantné tu |

**Deprecated/outdated:** `PITFALLS.md` Pasca 15 odporúča `setReadDataOnly(true)`: pre túto fázu neplatí.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Alpine CSP build podporuje len vlastnosti/volania metód (nie ľubovoľné výrazy) v atribútoch | Pitfall 6 | Ak podporuje viac, kód môže byť jednoduchší; ak menej (napr. aj `!x`), treba gettery. Riešenie „všetko cez gettery“ funguje v oboch prípadoch |
| A2 | `ALTER DEFAULT PRIVILEGES ... ON TABLES` pokrýva aj views (SELECT pre `app_rw`) | Pattern 2 | Ak nie, view nebude čitateľný: ošetrí integračný test pod `app_rw` (+ explicitný `GRANT SELECT`) |
| A3 | PHP-FPM prekladá výnimku z trigger-a na `DatabaseException` s textom „locked“ pri `transException(true)` | Pattern 1 | Kontroler musí rozlíšiť 409; riešiť testom |
| A4 | Odporúčanie rozdielu hodín = všetky časti − nacenené, zobrazenie len pre zapracované alebo pri prekročení | Pitfall 11 | Iná interpretácia „fakturované“ (len zamknuté) zmení text, nie schému; potvrdiť v UI-SPEC |
| A5 | slopcheck nespustené, `phpoffice/phpspreadsheet` schválené vlastníkom v STACK.md | Package Legitimacy Audit | Nízke (balík je dokumentované rozhodnutie a overený na Packagist) |
| A6 | `created_at` importovaných úloh = čas importu, `done_at` = čas importu (skutočné dátumy nie sú v Exceli) | Open Questions | Kozmetické, ovplyvní zoradenie a „posledný klient“ |

## Open Questions

1. **Definícia dlaždice „V práci“ voči makete**
   - What we know: maketa `mobile-home.html` ukazuje „V práci 2 214 € · 82 h nacenených“, čo je súčet všetkých úloh októbrového bloku vrátane zapracovanej Listobox (1 h), pričom skupina „V práci“ má 4 úlohy (81 h = 2 187 €). CONTEXT D-14: nezapracované úlohy.
   - What's unclear: či odpočítať hodiny už rozdelené do častí.
   - Recommendation: držať D-14 (nacenené hodiny a suma nezapracovaných úloh, per mena), v UI-SPEC to explicitne popísať; maketa je v tomto čísle nekonzistentná.
2. **Dôkaz „rozdielu proti Excelu“ pri reálnom súbore**: rozdiel hodnôt je 0 (Pitfall 4). Planner nech to zohľadní v akceptácii: „suchý beh vypíše tabuľku porovnania a upozornenie na neúplný rozsah vzorca E25/F25“.
3. **Zmena meny klienta pri zamknutých častiach**: odporúčam rozšíriť odmietnutie aj na zamknuté (Pitfall 9). Potvrdiť, inak implementovať presne podľa Discretion.
4. **`done_at`/`created_at` importu**: A6.
5. **Stránkovanie zoznamu úloh**: zoznam „Aktuálne“ je malý; pre „Všetky“ odporúčam `LIMIT 100` a poznámku, plnohodnotné stránkovanie nie je v rozsahu.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker (php, nginx, db kontajnery) | všetky testy a migrácie | ✓ | php `task-tracker-php:dev` healthy | — |
| PHP ext bcmath, zip, xmlreader | Money, PhpSpreadsheet | ✓ | v kontajneri | — |
| Composer | `require --dev` | ✓ | host aj kontajner | — |
| PostgreSQL 18 | testy, trigger, view | ✓ (compose `db`) | 18 | — |
| `docs/All4net-fakturovanie-prace.xlsx` | ručné overenie importu | ✓ | rozbalený a analyzovaný | testy používajú anonymizovanú fixture |
| slopcheck / pip | audit balíkov | ✗ | — | balík je schválený rozhodnutím (A5) |
| node | `tests/js` | pozri `Makefile`/existujúce `tests/js` testy | — | spúšťať ako doteraz |

**Missing dependencies with no fallback:** none.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5 (CIUnitTestCase, `failOnDeprecation`, `failOnWarning`), JS testy v `tests/js` |
| Config file | `phpunit.dist.xml` (suites `unit`, `integration`, `feature`) |
| Quick run command | `docker compose exec php composer test:unit` |
| Full suite command | `make test` (`perms test-db` + `composer test`), `make stan` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| TSK-01 | vstup úlohy, sadzba z klienta, server prepočíta sumu, posledný klient | unit + feature | `vendor/bin/phpunit tests/unit/Work/TaskInputTest.php`, `tests/feature/Work/TaskCreateTest.php` | ❌ Wave 0 |
| TSK-01 | živý prepočet BigInt = PHP výsledok | js | `node --test tests/js/task-form.test.js` | ❌ Wave 0 |
| TSK-02 | zmazanie len bez zamknutých častí (server aj DB) | integration | `tests/integration/Work/TaskDeleteTest.php` | ❌ Wave 0 |
| TSK-03 | odvodený stav (nevyfakt./čiastočne/vyfakt.) s `invoice_id` nastaveným cez owner | integration | `tests/integration/Work/TaskBillingViewTest.php` | ❌ Wave 0 |
| TSK-04/06 | zoznam a filtre, jeden dotaz (bez N+1), escapovanie ILIKE | feature + integration | `tests/feature/Work/TaskListTest.php` | ❌ Wave 0 |
| TSK-05 | rozdiel hodín `+6 h` / `−2 h` | unit | `tests/unit/Work/HoursTest.php` | ❌ Wave 0 |
| ALC-01 | CHECK amount, CHECK hours>0, mesiac regex, mena z klienta | integration | `tests/integration/Work/AllocationSchemaTest.php` | ❌ Wave 0 |
| ALC-02 | úprava/presun/zmazanie nezamknutej; zamknutá = výnimka aj z triggera, aj priamym SQL pod `app_rw` | integration | `tests/integration/Work/AllocationLockTest.php` | ❌ Wave 0 |
| ALC-02 | e5 kritérium: 200 h, 4 časti v 4 mesiacoch | integration | `tests/integration/Work/AllocationE5Test.php` | ❌ Wave 0 |
| ALC-04 | „Zapracované“: stav+časť+audit atómovo, zostatok, dvojklik, zostatok 0 | feature + integration | `tests/feature/Work/MarkDoneTest.php` | ❌ Wave 0 |
| ALC-05 | časť k nezapracovanej, detail vyfakt./naplánované/zostáva | feature | `tests/feature/Work/TaskDetailTest.php` | ❌ Wave 0 |
| DSH-01 | dlaždice po menách (nesčítať), skupiny, aktívna „Úlohy“, HX-Trigger | feature | `tests/feature/Work/DashboardTest.php`, `LayoutTest` | ❌ Wave 0 / ✅ LayoutTest existuje |
| IMP-01 | suchý beh s rozdielom (fixture), `ÁNO` → done + nezamknutá časť, idempotencia, hyperlink z bunky, fallback URL | integration | `tests/integration/Work/ImportExcelTest.php` | ❌ Wave 0 |
| Všetky | texty cez `lang()`, žiadny inline štýl/skript | unit | `tests/unit/I18n/ViewsHardcodedTextTest.php`, `LangKeysTest`, `ViewsCspTest` | ✅ existujú |
| Všetky | PHPStan float/Clock pravidlá | static | `make stan` | ✅ |

### Sampling Rate
- **Per task commit:** `docker compose exec php vendor/bin/phpunit --filter <test>` (nad dotknutým súborom, < 30 s)
- **Per wave merge:** `composer test` + `make stan`
- **Phase gate:** full suite zelená pred `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/_support/MasterFixtures.php`: pridať `billing_allocations`, `tasks` do `resetMasterTables()` (pred `clients`) a helpery `createTask()`, `createAllocation()`, `lockAllocation($id)` (UPDATE `invoice_id` cez `owner()`)
- [ ] `tests/_support/` fixture generátor anonymizovaného XLSX (PhpSpreadsheet writer + `setHyperlink`, zápis do `WRITEPATH`)
- [ ] `tests/js/task-form.test.js`
- [ ] Framework install: `composer require --dev phpoffice/phpspreadsheet`

## Security Domain

### Applicable ASVS Categories (úroveň 1)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (nezmenené z fázy 2) | — |
| V3 Session Management | no | existujúce Shield sessions |
| V4 Access Control | yes | globálny `AuthGate` (iba admin) pre všetky nové routy; žiadne verejné cesty; test, že anonym dostane redirect/403 |
| V5 Input Validation | yes | whitelist polí (`TaskInput`), regex hodiny/mesiac, int klient, http(s) URL, escapovanie ILIKE |
| V6 Cryptography | no | — |
| V8 Data Protection / V13 API | yes | CSRF cez `hx-headers`, parametrizované dotazy (`:name:`), `esc()` výstup |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SQL injection (filtre, import) | Tampering | CI4 bindovanie `:name:`, žiadna konkatenácia hodnôt |
| Stored XSS (názov, poznámka, URL) | Tampering | `esc()`, URL len http/https, `rel="noopener noreferrer"` |
| `javascript:` URL v `href` | Tampering | validácia schémy pri vstupe aj v importe |
| Úprava zamknutej časti obchádzaním UI | Tampering | atómový SQL + trigger + stĺpcové granty |
| Race (dvojklik, súbeh) | Tampering | `FOR UPDATE` na úlohe, `data-submit-once`, kontrola `status` |
| Mass assignment (`status`, `invoice_id`, `amount` z POST) | Elevation | `fromPost` iba whitelist; `amount` sa nikdy neberie z formulára |
| Audit obchádzanie | Repudiation | audit v tej istej transakcii cez `AuditLogger` |
| CSRF | Spoofing | existujúci filter + `hx-headers` |
| Import: nedôveryhodný XLSX | Tampering | len CLI, dev, súbor od vlastníka, hodnoty prechádzajú tou istou validáciou |

## Odporúčané rezy (MVP, vertikálne)

1. **Schéma a stráže:** migrácie `tasks`, `billing_allocations`, `task_billing_v`, trigger, granty (+ `grantDelete` v traite), `Hours`, `MoneyFormat` extrakcia, rozšírenie `MasterFixtures`, DB testy (CHECK, trigger, granty pod `app_rw`, pohľad). Bez UI.
2. **Úloha CRUD (TSK-01/02):** `TaskInput`, `TaskService`, `Tasks` kontroler, formulár + Alpine `taskForm`, `LastClient`, routy, jazykové kľúče, navigácia „Úlohy“ ako odkaz (aj `aria-current`), zmazanie s atómovou podmienkou.
3. **Časti a detail (ALC-01/02/05, TSK-05):** `AllocationService`, detail úlohy s agregátmi a zoznamom častí, pridať/upraviť/presunúť/zmazať (potvrdenie zospodu), `<progress>`, 409 správy.
4. **Zapracované (ALC-04):** offcanvas panel, mesiac Aktuálny/Ďalší/Iný, zostatok pod zámkom, reopen s auditom, zostatok 0.
5. **Zoznam a filtre (TSK-03/04/06):** `TaskQueries::list`, htmx filter fragment, stav v URL, odvodené štítky.
6. **Dashboard (DSH-01):** `Home` ako variant B, dlaždice po menách, skupiny, prepínač mesiaca a klienta, `HX-Trigger`, plávajúce „+“.
7. **Import (IMP-01):** `ExcelReader`, `ImportPlanner`, `ImportRunner`, `import:excel` (`--file`, `--client`, `--dry-run`), fixture testy, ručný beh nad reálnym súborom.
Rez 1 je predpoklad pre všetky; 2 pred 3 a 4; 5 a 6 môžu ísť paralelne po 3; 7 po 2 a 3.

## Project Constraints (from CLAUDE.md)

- PHP 8.5 / CI 4.7.x, PostgreSQL, Bootstrap 5.3, htmx 2, Alpine.js CSP build; **bez SPA a build pipeline**, JS/CSS lokálne s verziou v názve.
- Peniaze `numeric(12,2)`, hodiny `numeric(7,2)`, **žiadny float**; v PHP string + bcmath; CI4 vracia `numeric` ako string.
- Vystavené doklady nemenné, kontrola na serveri; číslovanie sa týka Phase 5.
- CSP: žiadne inline `style=""`, `onclick`, inline skripty; htmx `allowEval=false`; Alpine `@alpinejs/csp`.
- PHPUnit povinne pre výpočty súm, zamykanie častí a limity; PHPStan pravidlo zakazuje float a čas mimo `Clock`.
- UX: ovládateľné jednou rukou na iPhone, dotykové ciele ≥ 44 px, bez horizontálneho posúvania; UI po slovensky, texty cez `lang()` (sk aj en kľúče).
- PhpSpreadsheet iba `require-dev`; XLSX export (iná fáza) OpenSpout.
- Tajomstvá v `.env`; zálohy a ďalšie fázy mimo rozsahu.
- GSD workflow: zmeny súborov cez GSD príkazy.

## Sources

### Primary (HIGH confidence)
- Priamo prečítaný kód: `app/Libraries/Platform/{Money,Transaction}.php`, `Audit/AuditLogger.php`, `Master/{ClientService,ClientInput,PortalUrlTemplate}.php`, `Controllers/Settings/Clients.php`, `Database/Migrations/*Clients*`, `Support/GrantsPrivileges.php`, `Filters/AuthGate.php`, `Views/layouts/{app,_nav_items}.php`, `public/assets/js/app.js`, `tests/_support/{PgTestCase,MasterFixtures}.php`, `phpunit.dist.xml`, `Dockerfile`, `composer.json`
- `docs/All4net-fakturovanie-prace.xlsx`: rozbalený (`xl/worksheets/sheet1.xml`, `_rels/sheet1.xml.rels`, `sharedStrings.xml`), všetky riadky, vzorce a hyperlinky
- `https://raw.githubusercontent.com/PHPOffice/PhpSpreadsheet/master/src/PhpSpreadsheet/Reader/Xlsx.php`: hyperlinky len pri `!readDataOnly`
- Packagist p2 JSON `phpoffice/phpspreadsheet`: 5.10.0, 2026-09-17, `php ^8.2`
- `.planning/research/ARCHITECTURE.md` (alloc_guard, Vzory 4 a 5), `PITFALLS.md` (Pasca 15), `docs/superpowers/specs/2026-10-23-task-tracker-design.md` 6.3, 6.4, 7, 9, maketa `mobile-home.html`, `task-flows.html`

### Secondary (MEDIUM confidence)
- `docker/postgres/provision.sql` default privileges (view pokrytie: A2)

### Tertiary (LOW confidence)
- Správanie PHPUnit/CI4 pri výnimke z PG triggera (A3), Alpine CSP výrazy (A1): overiť pri implementácii

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, žiadne nové produkčné balíky, jediný dev balík schválený
- Architecture: HIGH, priamo nadväzuje na overený kód Phase 3 a ARCHITECTURE.md
- Pitfalls: HIGH pre import (overené na súbore a zdrojáku), MEDIUM pre Alpine CSP výrazy a trigger výnimky

**Research date:** 2026-10-25
**Valid until:** 2026-11-25 (stabilný stack)
