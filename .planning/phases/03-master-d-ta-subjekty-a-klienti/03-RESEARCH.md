# Phase 3: Master dáta (subjekty a klienti) - Research

**Researched:** 2026-10-25
**Domain:** relačný model s verziami „platné od“, insert-only oprávnenia v PostgreSQL 18, CRUD obrazovky nastavení v CI4 + htmx, audit v tej istej transakcii
**Confidence:** HIGH (návrh sa opiera o existujúci kód Phase 1-2 a o overené správanie PG 18.6 v bežiacom dev kontajneri; právne texty sú LOW a predbežné podľa D-17)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Viac subjektov. Každý má obdobie pôsobenia `active_from` až `active_to` (do je voliteľné), krajinu (SK, PL, CZ), menu, jazyk dokladov, DPH režim, vlastný číselný rad a predvolenú splatnosť (ENT-01, spec 6.1).
- **D-02:** **Krajina a mena sú na subjekte a po vytvorení sa nedajú zmeniť** (ENT-07, rozhodnutie zapísané v REQUIREMENTS.md). Iná krajina alebo mena znamená nový subjekt. Kontroluje to server (nie len skrytie poľa v UI) a pokrýva to test.
- **D-03:** Verzované údaje (ENT-02, ENT-05): obchodné meno, sídlo, IČO alebo NIP/REGON, DIČ, voliteľné IČ DPH / VAT-UE (NIP UE), údaj o zápise v registri (SK: živnostenský register, okresný úrad, číslo; PL: CEIDG), e-mail, telefón, jazyk dokladov, DPH režim, predvolená splatnosť.
- **D-04:** **Každá zmena údajov vytvorí novú verziu s dátumom „platné od“** (ENT-03). Verzie sa nemenia ani nemažú. `entity_versions` je **insert-only** (prvé použitie vzoru „GRANT + insert-only“: `app_rw` iba SELECT a INSERT, bez `grantMutable()`), `valid_from date NOT NULL`, `UNIQUE (entity_id, valid_from)`. Verzia platná k dátumu: `valid_from <= :d ORDER BY valid_from DESC LIMIT 1` (výskum, Pasca 7). Nová faktúra použije verziu platnú k **dátumu vystavenia**. Služba na výber verzie k dátumu vznikne už tu a otestuje sa.
- **D-05:** História verzií je viditeľná v nastaveniach subjektu (spec 6.1, ROADMAP kritérium 2). Zmena nemení staršie platné obdobie.
- **D-06:** Viac bankových účtov (IBAN, SWIFT/BIC, mena) na subjekte. Faktúra neskôr použije účet v mene faktúry, inak predvolený (ENT-06). Jeden účet je predvolený.
- **D-07:** Ukončený subjekt (`active_to` v minulosti) sa naďalej zobrazuje a je vybrateľný pre storno a opätovné odoslanie svojich dokladov (ENT-04). Pre nové faktúry sa predvyplní subjekt aktívny k dátumu vystavenia. Na prelome môžu byť aktívne oba subjekty naraz (spec 6.1). Prekryv období nie je chyba.
- **D-08:** Slovenský subjekt: EUR, neplatiteľ DPH. Poľský subjekt: PLN, zwolnienie podmiotowe (art. 113 ust. 1). Obidva musia ísť vytvoriť cez UI (ROADMAP kritérium 1).
- **D-09:** Číselný rad patrí subjektu (spec 6.5 Číslovanie). Formát sa nastavuje tokenmi `RRRR`, `RR`, `MM`, `#…` (počet `#` je počet číslic) a pevným textom. Predvolený je `RRRRMM###`. Ak formát obsahuje `MM`, poradie sa resetuje mesačne, inak ročne. Počiatočné číslo radu sa dá nastaviť (predvolene 1).
- **D-10:** Formát čísla sa neverzuje so subjektom, patrí do `invoice_series` (výskum ARCHITECTURE). Dobropis má vlastný rad (`kind = invoice | credit_note`), aby sa schéma neskôr neprerábala. Zmena formátu je dovolená, len kým rad nemá vystavené doklady (výskum). V tejto fáze ešte žiadne neexistujú, kontrola sa však pripraví.
- **D-11:** Samotné pridelenie čísla (čítač, zámok, `ON CONFLICT … RETURNING`) je Phase 5. Tu vznikne len konfigurácia radu a validácia formátu (parser tokenov s testom).
- **D-12:** Údaje klienta (CLI-02 až CLI-04): názov, adresa, krajina, IČO, DIČ, IČ DPH/VAT ID, e-mail na faktúry, e-maily v kópii (viac), hodinová sadzba `numeric` a mena (EUR, PLN, CZK), jazyk dokladov (voliteľný, prebije jazyk subjektu), splatnosť (voliteľná, prebije predvolenú), názov portálu úloh a vzor odkazu na portál.
- **D-13:** Typ identifikátora klienta (CLI-07): IČO/NIP, EÚ VAT ID s prefixom krajiny, iné ID, žiadne. Ukladá sa štruktúrovane kvôli neskoršiemu exportu FA(3).
- **D-14:** Režim fakturácie `domestic | eu_b2b | non_eu` (CLI-05) určuje text zákonnej poznámky. Pri `eu_b2b` sa na faktúru tlačí IČ DPH klienta (spec 6.5). Príznak „započítavať do limitu obratu“ je predvolene áno (CLI-06).
- **D-15:** Klient sa pridá, upraví a archivuje, nikdy sa nemaže (CLI-01). Klient sa neverzuje (spec 6.2: samostatná história klienta nie je potrebná). Nemennosť dokladov rieši kópia údajov na faktúre. Zmeny klienta idú do denníka.
- **D-16:** Právny dôvod oslobodenia od DPH je štruktúrované pole s kódom (napr. `ART_113_1`, `ART_113_9`, reverse charge `ART_28B_RC`) v právnom profile (SET-02). Profil je určený krajinou, DPH režimom a režimom fakturácie (spec 6.5 Zákonné náležitosti). Kód sa neskôr uloží do kópie údajov.
- **D-17:** `legal_texts` (krajina, DPH režim, režim fakturácie, jazyk, kľúč, text) sa seeduje **predbežne** pre SK a PL. Znenie potvrdí účtovníčka (ROADMAP, riziko R1). Úprava textov v UI je až Phase 6 (SET-01). Pri hľadaní textu platí poradie: presná zhoda, potom `billing_mode NULL`, potom jazyk `en`, inak chyba, nikdy ticho prázdny text (výskum).
- **D-18:** `audit_log` je append-only (insert-only cez GRANT): používateľ, čas, typ entity, ID, akcia, hodnoty pred a po v `jsonb`, IP (AUD-01). Zapisuje ho explicitná služba `AuditLogger` volaná zo služieb **v tej istej transakcii** ako zmena, nie DB triggery ani model callbacky (výskum ARCHITECTURE). Citlivé polia sa nikdy nezapisujú. V tejto fáze sa zapisujú zmeny subjektov, verzií, bankových účtov, radov, klientov a nastavení. Služba bude pripravená aj pre ďalšie entity.

### Claude's Discretion
- UX vzniku verzie: či formulár subjektu pri uložení vždy pýta dátum „platné od“ (predvolene dnes), a ako sa zobrazí, že sa zmena týka budúceho obdobia. Pevná požiadavka: žiadna úprava verzie na mieste a žiadne dve verzie s rovnakým `valid_from`. Oprava preklepu je tiež nová verzia (s auditom), plánovač určí, ako ju UI uľahčí (napr. dátum „platné od“ rovný dátumu pôvodnej verzie nie je možný kvôli unikátu, takže treba iný postup).
- Či sú bankové účty súčasťou verzie (kópia pri každej verzii) alebo samostatná tabuľka s archiváciou. Pevná požiadavka: zmena účtu nezmení vystavený doklad a je v denníku.
- Syntax vzoru odkazu na portál (napr. zástupný symbol `{id}`), validácia e-mailov v kópii, povinné a voliteľné polia, zobrazenie archivovaných klientov (filter alebo samostatný zoznam).
- Rozloženie obrazoviek nastavení (Viac → Subjekty, Viac → Klienti) na mobile aj PC, v súlade s maketami a vzorom `settings/security`.
- Dev seeder: ukážkový SK a PL subjekt a klient All4Net (sadzba 27 € bez DPH, Taskman), ktorý nikdy nebeží v `production` (Phase 1, D-09).
- Schéma `turnover_limits` sa môže pripraviť podľa výskumu, ale UI a logika limitov je Phase 10.

### Deferred Ideas (OUT OF SCOPE)
None. Diskusia sa nekonala, rozsah ostáva podľa ROADMAP. (Z hranice fázy: prehliadanie denníka s filtrami AUD-02, úprava zákonných textov v UI SET-01 Phase 6, tabuľka limitov a UI LIM-* Phase 10, kurzy Phase 8, pridelenie čísla faktúry Phase 5.)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| ENT-01 | Viac subjektov: obdobie pôsobenia, krajina, mena, jazyk dokladov, DPH režim, číselný rad, splatnosť | tabuľky `entities` + `entity_versions` + `invoice_series`; `EntityService::create` v jednej transakcii; sekcia Architecture Patterns 1 |
| ENT-02 | Obchodné meno, sídlo, IČO/NIP/REGON, DIČ, údaj o zápise, e-mail, telefón | stĺpce verzie; validácie (sekcia Validácie); registry_note ako text |
| ENT-03 | Zmena = nová verzia „platné od“, história viditeľná, bez mazania; výber verzie k dátumu | insert-only `entity_versions`, `EntityVersionResolver`, UX pravidlá verzie (Pattern 2) |
| ENT-04 | Ukončený subjekt ostáva zobraziteľný a vybrateľný | `active_to` je jediné, čo sa mení; `EntityDirectory::forSelect()` nevylučuje ukončené (Pattern 5) |
| ENT-05 | Voliteľné IČ DPH / VAT-UE | stĺpec `vat_id` vo verzii, formátová validácia |
| ENT-06 | Viac bankových účtov, výber podľa meny | `entity_bank_accounts`, `BankAccountSelector::forCurrency` (Pattern 3) |
| ENT-07 | Krajina a mena nemenné | stĺpcový GRANT UPDATE bez `country`/`currency` + servis bez týchto parametrov + test (Pattern 1) |
| CLI-01 | Pridať, upraviť, archivovať klienta, bez mazania | `clients` s `archived_at`; stĺpcový UPDATE grant, bez DELETE (Pattern 6) |
| CLI-02 | Názov, adresa, krajina, IČO, DIČ, IČ DPH, e-mail na faktúry a v kópii | štruktúrovaná adresa, `cc_emails jsonb` |
| CLI-03 | Sadzba, mena, jazyk, splatnosť (prebíjajú) | `hourly_rate numeric(10,2)`, nullable `doc_language`, `due_days` |
| CLI-04 | Názov a vzor odkazu na portál | `portal_name`, `portal_url_template` s `{id}`, `PortalUrlTemplate` |
| CLI-05 | Režim fakturácie domestic/eu_b2b/non_eu | CHECK + enum `BillingMode`; väzba na právny profil |
| CLI-06 | Príznak „započítavať do limitu“ | `counts_toward_limit boolean NOT NULL DEFAULT true` |
| CLI-07 | Typ identifikátora klienta | `identifier_type` + `identifier_value`, validácia podľa typu |
| SET-02 | Právny dôvod oslobodenia ako kód v právnom profile | `legal_profiles.exemption_code` + seed v migrácii + `LegalProfileResolver` (Pattern 7) |
| AUD-01 | Denník zmien pred/po | `audit_log` insert-only + `AuditLogger` v transakcii (Pattern 4) |
</phase_requirements>

## Summary

Fáza nepotrebuje žiadnu novú knižnicu. Všetko sa dá postaviť na tom, čo je už v repozitári z Phase 1-2: CI4 Query Builder / `$db->query()` v službách (vzor `AuditLog`, `LockoutService`), migrácie so surovým SQL a `GrantsPrivileges`, `Clock`/`FrozenClock`, `Money`, `htmx_view`, `PgTestCase` (rozlišuje `owner()` a `rw()`), `AuthTestCase`, testy `ViewsCspTest` a `ViewsHardcodedTextTest`. Hlavné riziko nie je technológia, ale návrh: presne rozlíšiť, čo je insert-only (`entity_versions`, `audit_log`), čo je mutovateľné len v určených stĺpcoch (`entities`, `clients`, `entity_bank_accounts`, `invoice_series`) a udržať audit v tej istej transakcii.

Kľúčový nález pre integritu: existujúci `grantMutable()` dáva `UPDATE, DELETE` na celú tabuľku, čo je pre `entities` a `clients` príliš široké (umožnilo by zmeniť `country`/`currency` a mazať). PostgreSQL stĺpcový `GRANT UPDATE (stĺpce)` to rieši: overil som v PG 18.6, že `UPDATE ... SET active_to` prejde, `SET country`/`currency` (aj v kombinácii s povoleným stĺpcom) a `DELETE` dostanú `permission denied`. Odporúčanie: pridať do `GrantsPrivileges` metódu `grantUpdateColumns(table, columns[])` a použiť ju na všetky mutovateľné tabuľky tejto fázy. ENT-07 tak má obranu v DB, nielen v službe a UI.

Druhý nález: aplikačná brána `AuthGate` už pustí do aplikácie iba skupinu `admin` (ostatní dostanú 403), takže na nové routy netreba Shield `permission` filter. Seed `legal_texts` a `legal_profiles` musí ísť **do migrácie** (nie do `DevSeeder`, ktorý v produkcii odmietne bežať, a tabuľky `legal_*` pre `app_rw` nie sú zapisovateľné).

**Primary recommendation:** Jedna migračná dávka (entities, entity_versions, entity_bank_accounts, invoice_series, clients, legal_profiles, legal_texts + seed, audit_log) so stĺpcovými grantmi; doménové služby (`EntityService`, `EntityVersionResolver`, `BankAccountSelector`, `SeriesFormat`, `ClientService`, `LegalProfileResolver`) volajúce `AuditLogger` v rovnakej transakcii; klasické formuláre s PRG a 422 podľa vzoru `Settings\Security`/`Demo`, htmx len na fragmenty (výber krajiny, bankové účty).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Nemennosť verzií, auditu, krajiny a meny | Database / Storage (GRANT, stĺpcové oprávnenia) | API / Backend (služby) | posledná obrana pri chybe v kóde (ARCHITECTURE, vrstva 2) |
| Výber verzie k dátumu, výber účtu podľa meny, parser formátu čísla | API / Backend (doménové služby) | Database (index UNIQUE) | čistá logika, testovateľná bez HTTP |
| Zápis auditu v tej istej transakcii | API / Backend | Database (insert-only tabuľka) | potrebuje používateľa a obchodný význam akcie |
| Validácia vstupov (IBAN, e-maily, sadzba, dátumy, URL šablóna) | API / Backend | Database (CHECK ako sieť) | server je zdroj pravdy, klient môže byť obídený |
| Formuláre a história verzií | Frontend Server (SSR views CI4) | Browser (htmx fragmenty) | žiadna SPA; render na serveri |
| Autorizácia (len admin) | API / Backend (`AuthGate`) | — | už existuje globálne |
| Seed právnych profilov a textov | Database (migrácia) | — | musí existovať aj v produkcii |

## Standard Stack

Žiadne nové produkčné ani dev balíky. Všetko z CLAUDE.md a existujúceho `composer.json`.

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `codeigniter4/framework` | 4.7.5 | Query Builder, validácia (`validateData`), views, `lang()`, transakcie (`transException(true)->transStart()`) | rozhodnutý stack [CITED: CLAUDE.md] |
| PostgreSQL | 18.6 | `NULLS NOT DISTINCT`, partial unique index, stĺpcové GRANT | overené v dev kontajneri [VERIFIED: docker compose exec db, SELECT version()] |
| PHP bcmath / intl | súčasť obrazu | sadzba ako string (`Money`), `IntlDateFormatter` pre dátumy v UI | existujúci vzor `Money`, `Security` controller |
| Bootstrap 5.3.8, htmx 2.0.11, Alpine CSP 3.17.5 | lokálne v `public/assets/vendor` | UI | existujúce layouty |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `App\Libraries\Platform\Clock` / `FrozenClock` | repo | jediný zdroj „dnes“ (Pasca 7) | všade, kde sa porovnáva `valid_from`/`active_to` |
| `App\Libraries\Platform\Money` | repo | sadzba klienta ako string | validácia a porovnanie sadzby |
| `Tests\Support\PgTestCase`, `AuthTestCase` | repo | integračné a feature testy pod `app_rw` | všetky nové testy |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| vlastný `Iban` (mod 97, ~30 riadkov) | `globalcitizen/php-iban` | dodatočná závislosť pre triviálny algoritmus; neodporúča sa (nové balíky = riziko, nulový prínos) |
| `jsonb` pre `cc_emails` | `text[]` | CI4 nekonvertuje PG polia, parsovanie literálu `{a,b}` je krehké; `jsonb` s `json_decode` je jednoduchšie (mierna odchýlka od ARCHITECTURE) |
| stĺpcový `GRANT UPDATE (...)` | `grantMutable()` + trigger | stĺpcový grant je jednoduchší a overiteľný testom oprávnení |

**Installation:** žiadna. (`composer.json` sa nemení.)

## Package Legitimacy Audit

Fáza neinštaluje žiadne externé balíky, slopcheck nebolo treba spúšťať.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| (žiadne) | — | — | — | — | — | — |

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
Prehliadač (iPhone/PC)
   | GET/POST (CSRF token, session cookie)
   v
nginx -> php-fpm -> filtre: devicelogin -> csrf (HtmxCsrf) -> authgate (len admin) -> locale
   v
Controllers\Settings\{Entities, EntityVersions, BankAccounts, Series, Clients}
   | validácia (validateData + doménové validátory: Iban, SeriesFormat, PortalUrlTemplate)
   |   chyba -> 422 + re-render formulára (htmx_view fragment alebo celá stránka)
   v
Doménové služby (App\Libraries\Master\*)  --- Clock::now() ---> dátumy
   |  $db->transException(true)->transStart()
   |    1. zápis (INSERT entity/verzia/účet/rad/klient alebo UPDATE povolených stĺpcov)
   |    2. AuditLogger::record(typ, id, akcia, before, after)    <- ta istá transakcia
   |  $db->transComplete()   (výnimka = rollback zmeny aj auditu)
   v
PostgreSQL (app_rw):
   entities (UPDATE len active_to, name_internal, active_from)   entity_versions (INSERT only)
   entity_bank_accounts (UPDATE is_default, archived_at, údaje)  invoice_series (UPDATE format, initial_number)
   clients (UPDATE vybrané stĺpce, bez DELETE)                   audit_log (INSERT only)
   legal_profiles, legal_texts (SELECT only; seed z migrácie, app_owner)
   ^
   | čítanie neskôr: Phase 4 (klient), Phase 5 (verzia k dátumu vystavenia, rad, účet, legal_*), Phase 10 (limit flag)
```

### Recommended Project Structure
```
app/
├── Controllers/Settings/{Entities.php, Clients.php}        # + podstránky verzií a účtov
├── Libraries/Master/                                        # doménové služby (bez HTTP)
│   ├── EntityService.php          # create, newVersion, end (active_to), rename
│   ├── EntityVersionResolver.php  # atDate(entityId, date), history(entityId)
│   ├── EntityDirectory.php        # forSelect(), activeAt(date)
│   ├── BankAccountService.php     # add, update, archive, setDefault
│   ├── BankAccountSelector.php    # forCurrency(entityId, ccy)
│   ├── SeriesService.php          # updateFormat (+ isFormatLocked seam)
│   ├── SeriesFormat.php           # parse, validate, render preview, periodKey
│   ├── ClientService.php          # create, update, archive, unarchive
│   ├── LegalProfileResolver.php   # profile(country, regime, billingMode), text(...)
│   ├── Iban.php, PortalUrlTemplate.php, CcEmails.php        # hodnotové validátory
│   └── Enum: VatRegime, BillingMode, IdentifierType, Country, DocLanguage
├── Libraries/Audit/AuditLogger.php      # NIE "AuditLog": ten názov už patrí auth_events (Libraries/Auth/AuditLog.php)
├── Database/Migrations/2026-10-25-00000N_*.php
├── Database/Support/GrantsPrivileges.php   # + grantUpdateColumns()
├── Language/{sk,en}/{Entities,Clients,Audit}.php
└── Views/settings/{entities,clients}/...
tests/{unit,integration,feature}/Master/*
```

### Pattern 1: Tabuľky a oprávnenia (migrácie)
**What:** `entities` (id, name_internal, country char(2) CHECK IN SK/PL/CZ, currency char(3) CHECK IN EUR/PLN/CZK, active_from date NOT NULL, active_to date NULL CHECK `active_to >= active_from`, created_at/created_by) s `GRANT UPDATE (name_internal, active_from, active_to)`; `entity_versions` (insert-only, `UNIQUE (entity_id, valid_from)`, `created_by`, `created_at`); `entity_bank_accounts`; `invoice_series` (`UNIQUE (entity_id, kind)`, `kind` CHECK invoice/credit_note, `format`, `initial_number int CHECK >= 1`, `GRANT UPDATE (format, initial_number)`); `clients`; `legal_profiles`; `legal_texts`; `audit_log`.
**When to use:** celá fáza; migrácie sa spúšťajú `spark migrate -g owner` (testy `-g tests_owner`, `composer test:migrate`).
**Example:**
```php
// Source: vzor app/Database/Support/GrantsPrivileges.php + overené v PG 18.6 (scratch DB)
trait GrantsPrivileges
{
    protected function grantUpdateColumns(string $table, array $columns): void
    {
        // validovať identifikátory rovnakým regexom ako grantMutable()
        $cols = implode(', ', array_map(fn ($c) => $this->db->escapeIdentifiers($c), $columns));
        $this->db->query('GRANT UPDATE (' . $cols . ') ON TABLE ' . $this->db->escapeIdentifiers($table) . ' TO app_rw');
    }
}
// entities: SELECT, INSERT sú z default privileges; country a currency nie sú v zozname.
// DELETE nikde (entity, klienti, účty, rady sa nemažú).
```
**Overené správanie (PG 18.6):** `UPDATE ent SET active_to=...` OK; `SET country='PL'` a `SET active_to=..., currency='PLN'` a `DELETE` -> `permission denied for table ent` [VERIFIED: psql v dev kontajneri `db`].

DB-level doplnky (CHECK ako sieť): `legal_texts`/`legal_profiles` unikát `UNIQUE NULLS NOT DISTINCT (country, vat_regime, billing_mode, language, key)` (bežný `UNIQUE` pri `billing_mode NULL` dovolí duplikáty, overené: `NULLS NOT DISTINCT` duplikát odmietne) [VERIFIED: PG 18.6]. Partial unique `entity_bank_accounts (entity_id) WHERE is_default AND archived_at IS NULL`.

### Pattern 2: Verzie „platné od“
**What:** `EntityVersionResolver::atDate(int $entityId, string $date): array` = `SELECT * FROM entity_versions WHERE entity_id=:e AND valid_from <= :d ORDER BY valid_from DESC LIMIT 1`; výnimka, ak nič (nemá nastať, pozri invariant). `EntityService::newVersion(entityId, validFrom, fields)` vloží verziu a audit `entity_version / create`.
**Invarianty a pravidlá (odporúčané, plánovač ich len zapíše):**
1. Pri vytvorení subjektu sa v tej istej transakcii vloží prvá verzia s `valid_from = active_from`. Ďalšie verzie vyžadujú `valid_from >= entities.active_from`, takže pre každý dátum v období pôsobenia existuje verzia (žiadna diera). `active_from` sa dá meniť len na dátum >= `MIN(valid_from)` (rovnaký dôvod). [ASSUMED: pravidlo je návrh, nie z PRD]
2. `valid_from` je povinné pole formulára, predvolené `Clock::now()` dnes; povolený rozsah 2000-01-01 až dnes + 366 dní (ochrana pred preklepom v roku); strict parse `DateTimeImmutable::createFromFormat('!Y-m-d')` s kontrolou round-trip.
3. Duplicitný `valid_from` -> unikát; správa „Verzia k tomuto dátumu už existuje“ (422). Pre súbeh nezachytávať výnimku vnútri transakcie (PG po chybe transakciu označí za zlyhanú): použiť `transException(true)`, zachytiť `DatabaseException` **mimo** `transStart/transComplete`, alebo predtým `SELECT 1` kontrolu a unikát ako záchranu.
4. Oprava preklepu = nová verzia s dátumom dnes (alebo neskôr). UI: v zozname histórie tlačidlo „Nová verzia z tejto“ predvyplní formulár hodnotami vybranej verzie a dátum dnes; hint pod poľom dátumu vysvetlí, že rovnaký dátum ako existujúca verzia nejde. Dôsledok: doklad s dátumom vystavenia v medzere medzi pôvodnou verziou a opravou by použil starú (chybnú) verziu, čo je akceptované, lebo vystavené doklady nesú kópiu (Phase 5) a vystavuje sa spravidla dnešným dátumom.
5. Odmietnuť verziu bez zmeny voči verzii platnej k `valid_from` („Žiadna zmena“, 422).
6. Ak existujú neskoršie verzie, formulár pri uložení ukáže upozornenie, že nová verzia platí len do dátumu najbližšej neskoršej verzie; vkladanie medzi verzie je dovolené (časová os ostáva konzistentná).
7. História (D-05): zoznam od najnovšej, značky „platí dnes“ (max `valid_from <= dnes`) a „budúca“ (`valid_from > dnes`), zvýraznenie zmenených polí voči predchádzajúcej verzii (`VersionDiff`, čistá funkcia, unit test).
8. Formulár nikdy neprijíma `country`/`currency`: používať explicitný whitelist `getPost([...])`; na stránke sa zobrazujú ako text (nie input). Test: POST s podvrhnutými `country`/`currency` nezmení DB.

### Pattern 3: Bankové účty (samostatná tabuľka, nie súčasť verzie)
**What/why:** `entity_bank_accounts (id, entity_id, label, iban text, swift text NULL, currency char(3), is_default bool, archived_at timestamptz NULL, created_at)`. Rozhodnutie podľa Claude's Discretion: samostatná tabuľka s archiváciou, lebo (a) účet nie je „adresa platná od“, (b) neprenáša sa ako kópia pri každej verzii, (c) nemennosť dokladov zaručí kópia účtu v snapshote (Phase 5), nie história účtu. Zmeny sú v `audit_log` (`bank_account`: create/update/archive/set_default).
**Pravidlá:** IBAN uložiť normalizovaný (bez medzier, veľké písmená), validácia mod-97 a dĺžok SK 24, PL 28, CZ 24 (ostatné 15-34 + mod-97). Overil som mod-97 na vzorových IBAN `SK3112000000198742637541`, `PL61109010140000071219812874`, `CZ6508000000192000145399` (všetky platné) [VERIFIED: python výpočet]. SWIFT/BIC: `^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$`, voliteľné. Meno účtu mení (`currency`) len nový účet (stĺpec `currency` nie je v UPDATE grante); `iban` je editovateľný (oprava preklepu, snapshot chráni doklady, zmena je v audite).
**Predvolený účet:** zmena predvoleného **dvoma príkazmi v transakcii** (najprv `SET is_default=false` pre subjekt, potom `true` pre vybraný). Jeden príkaz `SET is_default = (id = :id)` mi v teste prešiel, ale PG kontroluje nededikovaný unikátny index po riadkoch, takže výsledok závisí od poradia riadkov; nespoliehať sa naň [VERIFIED: PG 18.6 test, správanie závislé od poradia]. Archivácia predvoleného účtu povýši najstarší aktívny (alebo nechá bez predvoleného, ak už nie je žiadny); prvý aktívny účet je automaticky predvolený.
**Výber:** `BankAccountSelector::forCurrency(entityId, ccy)`: aktívne účty s menou `ccy` zoradené `is_default DESC, id ASC` -> prvý; ak žiadny, predvolený účet subjektu; ak ani ten, chyba (Phase 5 ju zobrazí). Unit/integračný test všetkých troch vetiev.

### Pattern 4: AuditLogger v tej istej transakcii
**What:** `App\Libraries\Audit\AuditLogger::record(string $entityType, int $entityId, string $action, ?array $before, ?array $after): void` zapíše riadok do `audit_log` (`id`, `occurred_at timestamptz`, `user_id integer NULL REFERENCES users`, `entity_type` a `action` s CHECK `^[a-z_]{3,40}$` ako `auth_events`, `entity_id bigint`, `before_data jsonb`, `after_data jsonb`, `ip text`). Stĺpce `before_data`/`after_data` (nie `before`/`after`) kvôli čitateľnosti SQL. Používa `Database::connect()` (zdieľané spojenie s volajúcou službou), preto sa účastní jej transakcie.
**Pravidlá obsahu:** `create`: before NULL, after = celý riadok; `update`: len zmenené kľúče v oboch (`AuditLogger::diff(before, after)`; ak je diff prázdny, nezapisovať); `archive`: `archived_at` null -> hodnota. Hodnoty ako reťazce (numeric z CI4 je string, bez `float`). Denylist kľúčov (`password`, `secret*`, `token*`, `code_hash`, `hash`) sa vyhodí pred zápisom a unit test to pinuje. `json_encode(..., JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)`. `user_id` = `auth()->id()` (v CLI NULL), `ip` = `service('request')->getIPAddress()` alebo `'cli'` (vzor `AuditLog`, ktorý už rozlišuje `CLIRequest`). Čas z `service('clock')`.
**Test (povinný):** služba, ktorá po zápise zahodí výnimku -> v DB ostane 0 riadkov zmeny aj auditu; úspech -> presne 1 audit riadok s očakávaným before/after.
**Transakčný vzor (CI4):** `$db->transException(true)->transStart(); ...; $db->transComplete();` pri výnimke sa vykoná rollback; `transException` existuje v `BaseConnection` [VERIFIED: vendor/codeigniter4/framework BaseConnection::transException v dev kontajneri]. `transStart` je vnoriteľný (počítadlo hĺbky), takže `AuditLogger` môže byť volaný aj mimo transakcie aj v nej.

### Pattern 5: Dostupnosť subjektov (ENT-04, D-07)
`EntityDirectory::forSelect()` vráti **všetky** subjekty (aj ukončené) s príznakom `ended` (podľa `active_to < dnes`) pre výber pri storne a opätovnom odoslaní; `activeAt(date)` vráti subjekty s `active_from <= d AND (active_to IS NULL OR active_to >= d)` zoradené `active_from DESC` (prekryv nie je chyba, prvý je predvyplnený, ostatné ponúknuté). Ukončenie subjektu = nastavenie `active_to` (UPDATE povoleného stĺpca + audit `entity / end`). Žiadny kód nesmie ukončený subjekt skrývať v zoznamoch nastavení; v zozname len badge „Ukončený“.

### Pattern 6: Klient
`clients`: `name`, adresa štruktúrovane (`address_line1`, `address_line2 NULL`, `postal_code`, `city`, `country char(2)`), `identifier_type` CHECK (`local_id`,`eu_vat`,`other`,`none`) + `identifier_value NULL`, `tax_id` (DIČ) NULL, `vat_id` NULL, `invoice_email`, `cc_emails jsonb NOT NULL DEFAULT '[]'` CHECK `jsonb_typeof = 'array'`, `hourly_rate numeric(10,2) CHECK >= 0`, `currency` CHECK IN EUR/PLN/CZK, `doc_language NULL` (sk/pl/cs/en), `due_days smallint NULL CHECK 0..365`, `portal_name NULL`, `portal_url_template NULL`, `billing_mode` CHECK, `counts_toward_limit boolean NOT NULL DEFAULT true`, `archived_at timestamptz NULL`, `created_at`, `updated_at`. `GRANT UPDATE (všetky okrem id, created_at)`, bez DELETE. Archivácia = `UPDATE archived_at` + audit `client / archive`, obnova `unarchive`.
**Pravidlá validácie:** `identifier_type=none` -> value musí byť prázdna; `eu_vat` -> `^[A-Z]{2}[A-Z0-9]{2,12}$` po normalizácii (veľké písmená, bez medzier); `local_id` -> 1-20 alfanumerických znakov (SK IČO 8 číslic, PL NIP 10 číslic ako formátová kontrola pre `country`, **bez kontrolnej sumy**, aby sa neodmietali platné cudzie ID); `billing_mode=eu_b2b` vyžaduje `vat_id`. Sadzba: `normalize` (čiarka -> bodka) a regex `^\d{1,8}(\.\d{1,2})?$` (vzor `Demo::validates`), uložiť string, porovnania cez `Money`. `cc_emails`: oddeľovač čiarka/bodkočiarka/nový riadok, `trim`, malé písmená, deduplikácia, max 5, každý `valid_email` (CI4), žiadne CR/LF (ochrana pred hlavičkovým injektom pri Phase 7). Zoznam: predvolene aktívni, prepínač „Archivované“ (filter v query stringu); triedenie bez `COLLATE` (DB je `sk-SK` ICU).
**Poznámka na Phase 4:** zmena sadzby/meny klienta pri otvorených úlohách je až problém Phase 4 (úlohy ešte neexistujú); sem len zapísať do integračných bodov, že guard treba doplniť tam.

### Pattern 7: Právny profil a seed (SET-02, D-16, D-17)
`legal_profiles (id, country, vat_regime, billing_mode, exemption_code NULL, UNIQUE NULLS NOT DISTINCT (country, vat_regime, billing_mode))`, `CHECK (exemption_code ~ '^[A-Z0-9_]{3,30}$')`; `legal_texts (id, country, vat_regime, billing_mode NULL, language, key, body, updated_at, UNIQUE NULLS NOT DISTINCT (country, vat_regime, billing_mode, language, key))`. Oba `SELECT` pre `app_rw` (Phase 6 pridá UPDATE stĺpce pre SET-01). **Seed ide v migrácii** (vykoná ho `app_owner` aj v produkcii), nie v `DevSeeder` [CITED: Phase 1 D-09 + kód `DevSeeder::assertAllowed`].
Hodnoty `vat_regime` (CHECK + enum `VatRegime` s mapovaním na krajinu): `sk_non_vat` (SK), `pl_exempt_113_1`, `pl_exempt_113_9` (PL), `cz_non_vat` (CZ, len pripravené). Predbežný seed kódov: `pl_exempt_113_1` + `domestic` -> `ART_113_1`; `pl_exempt_113_9` + `domestic` -> `ART_113_9`; `pl_*` + `eu_b2b` -> `ART_28B_RC`; SK `sk_non_vat` -> bez kódu (NULL). Texty: SK `Nie som platiteľ DPH.` (zo spec 6.5) v `sk`+`en`; PL podklad oslobodenia v `pl`+`en`+`sk`, napr. `Sprzedawca zwolniony podmiotowo z podatku VAT na podstawie art. 113 ust. 1 ustawy o VAT.` Všetky texty sú **predbežné**, v komentári migrácie a v `legal_texts.body` neoznačovať ako konečné; účtovníčka potvrdí (R1). [ASSUMED: konkrétne poľské znenia a mapovanie kódov na režimy, spec ich neuvádza doslovne]
`LegalProfileResolver::text(country, regime, billingMode, language, key)`: poradie presná zhoda -> `billing_mode IS NULL` -> `language='en'` (s rovnakým poradím pre billing_mode) -> `LegalTextMissing` výnimka (nikdy prázdny reťazec). Test všetkých vetiev vrátane chyby. Entita zobrazuje svoj právny profil (read-only: kód + text) na detaile; úprava kódu v UI je mimo rozsahu (SET-01 Phase 6), ale `LegalProfileService::updateExemptionCode` s auditom nie je potrebná, ak tabuľka ostáva SELECT-only; AUD-01 pre „nastavenia“ v tejto fáze pokrýva audit radov (`invoice_series`) a subjektov. (Ak plánovač chce zmenu kódu v UI už teraz, pridať stĺpcový grant `UPDATE (exemption_code, updated_at)` a audit `legal_profile / update`; je to voliteľné.)

### Pattern 8: Číselný rad (D-09 až D-11)
`SeriesFormat` (čistá trieda, bez DB): tokenizácia zľava dlhším-vyhráva (`RRRR` pred `RR`, `MM`, `#+`), zvyšok je pevný text. Pravidlá validácie: práve **jeden** blok `#` (1-9 číslic), práve jeden z `RRRR`/`RR` je povinný (bez roka by sa čísla po ročnom resete opakovali), `MM` len spolu s rokom, pevný text z množiny `[A-Za-z0-9/_.-]` (obmedzená, kvôli neskoršej sanitizácii názvu súboru podľa Pasce o číslovaní), dĺžka celého výstupu <= 30. Metódy: `parse(string): self` (výnimka `InvalidSeriesFormat`), `render(date, seq): string`, `periodKey(date): string` (`202610` ak formát obsahuje `MM`, inak `2026`), `resetsMonthly(): bool`. Upozornenie v dokumentácii: pevný text nesmie obsahovať sekvencie `RR`/`MM`/`#` (budú interpretované ako tokeny). Predvolené formáty pri vytvorení subjektu: faktúra `RRRRMM###`, dobropis `DB-RRRRMM###`. Pre jeden subjekt musia byť formáty `invoice` a `credit_note` **rôzne** (inak `UNIQUE (entity_id, number)` z Phase 5 zrazí číslovanie), validuje služba. `initial_number >= 1`. `SeriesService::isFormatLocked(seriesId): bool` je zámerne zatiaľ `false` (žiadne doklady neexistujú); je to jediné miesto, ktoré Phase 5 prepíše na kontrolu `number_sequences`/`invoices` (zapísať ako integračný bod). Zmena formátu aj počiatočného čísla ide cez službu s auditom `invoice_series / update`; ak `isFormatLocked`, služba zmenu odmietne (test s mockom/podtriedou).

### Pattern 9: Routing, UI, i18n
- Routy v novom súbore `app/Config/Routes/master.php` (glob už načíta všetky súbory z `Config/Routes/`): `settings/entities` (zoznam, detail, nový), `settings/entities/(:num)/versions` (POST nová verzia), `.../bank-accounts` (+ `/(:num)/archive`, `/(:num)/default`), `.../series`, `.../end`, `settings/clients` (+ `/(:num)/archive`, `/unarchive`). Všetky zmeny len POST + CSRF. Odkazy Subjekty a Klienti pridať do `app/Views/more/index.php` (zachovať vzor `list-group` a 44 px cieľ) a prípadne do `_nav_items.php`.
- Formuláre: klasický POST + PRG, pri chybe 422 a re-render (`htmx_view` fragment / stránka, vzor `Demo`/`Security`); formulár musí fungovať aj bez JS. Výber krajiny pri vytvorení subjektu obnoví možnosti meny a DPH režimu cez `hx-get` fragment (bez inline JS; Alpine CSP len ak treba). Hodnoty krajiny a meny sú pri vytvorení povinné, **po vytvorení len text**.
- Všetky texty `lang('Entities.*')`, `lang('Clients.*')` v `sk` aj `en` (`LangKeysTest`), názvy súborov nesmú kolidovať s Shield/CI4 (`Auth`, `Security`, `Validation` už existujú). Žiadne inline `style`/`on*`/`<script>` (`ViewsCspTest`). Dátumy formátovať `IntlDateFormatter` s locale požiadavky, ukladať `Y-m-d`.
- Dev seeder: `DevSeeder::run()` rozšíriť o SK subjekt (EUR, `sk_non_vat`), PL subjekt (PLN, `pl_exempt_113_1`), účty a klienta All4Net (27.00 EUR, Taskman); idempotentne (`ON CONFLICT DO NOTHING`/existencia) a cez `Database::connect('owner')`, aby audit/oprávnenia nezavadzali; `assertAllowed` ostáva. Seed vypisovať cez `service()` služby len ak by tým neobišiel audit; jednoduchšie priamy SQL pre dev.

### Anti-Patterns to Avoid
- **`grantMutable()` na `entities`/`clients`:** dovolí zmeniť krajinu/menu a mazať. Použiť stĺpcový grant.
- **Audit cez model callbacky alebo DB triggery:** D-18 to zakazuje; hromadné dotazy by sa minuli.
- **`CURRENT_DATE` alebo `date()` v biznis dotazoch:** dátum len z `Clock` (Pasca 7). Grep/PHPStan pravidlo už existuje, nezavádzať obchádzku.
- **Úprava verzie `UPDATE entity_versions`:** DB to odmietne; nehľadať obchádzku (žiadny `grantMutable`).
- **`float` pre sadzbu:** string + `Money`/regex.
- **Seed právnych textov v `DevSeeder`:** v produkcii by chýbali.
- **Mazanie klienta/subjektu/účtu:** len archivácia/`active_to`; DELETE nie je v grante.
- **Ukončené subjekty skryté vo výbere:** porušuje ENT-04.
- **Pomenovanie `AuditLog` pre nový logger:** kolízia s existujúcou `Auth\AuditLog` (auth_events). Použiť `Audit\AuditLogger`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Výber verzie k dátumu | PHP slučka cez verzie | SQL `valid_from <= :d ORDER BY valid_from DESC LIMIT 1` + unikátny index | jeden dotaz, indexované, bez chyby na hranici |
| Číselník kód->text, fallback | ad-hoc `if` v šablóne | `LegalProfileResolver` s definovaným poradím a výnimkou | nikdy ticho prázdny text |
| Nemennosť | kontroly „if issued“ v kóde | PG `GRANT` (stĺpcové/insert-only) | chyba v kóde nič nezmení |
| Jedinečnosť predvoleného účtu | kontrola v PHP | partial unique index + dvojkrokové prepnutie | súbeh |
| Súbeh verzií s rovnakým dátumom | zámok v PHP | `UNIQUE (entity_id, valid_from)` | DB je rozhodca |
| Formátovanie dátumu/čísel | `date('d.m.Y')` | `IntlDateFormatter`/`NumberFormatter` (už použité v `Security`) | locale, `Clock` |
| Validácia e-mailu | vlastný regex | CI4 pravidlo `valid_email` | spoľahlivé |
| IBAN | knižnica | vlastných ~30 riadkov mod-97 (štandardný algoritmus ISO 13616) s unit testom | žiadna závislosť, triviálne |

**Key insight:** v tejto doméne chybu nespôsobuje chýbajúca knižnica, ale chýbajúca obrana na úrovni DB; každé „nesmie sa zmeniť“ má byť vynútené oprávnením, nie disciplínou.

## Runtime State Inventory

Nie je rename/refactor fáza, sekcia sa vynecháva. Poznámka: pridáva sa len nová schéma; existujúce tabuľky Phase 1-2 sa nemenia.

## Common Pitfalls

### Pitfall 1: Stĺpcový grant sa „rozbije“ ORM-om
**What goes wrong:** CI4 `Model::update()` alebo `Entity` pošle do UPDATE aj stĺpce mimo grantu (`updated_at`, `country`), chyba `permission denied`.
**How to avoid:** v službách písať UPDATE s explicitným zoznamom stĺpcov (Query Builder `set([...])`), nepoužívať `Entity::save()` na tieto tabuľky; `updated_at` zahrnúť do grantu pre tabuľky, kde sa používa.
**Warning signs:** test oprávnení padá na legitímnom update.

### Pitfall 2: Chyba v transakcii zhltne audit
**What goes wrong:** PG po chybe (napr. unikát) ukončí transakciu; ďalší príkaz (audit) zlyhá alebo sa tiché `transStatus=false` ignoruje.
**How to avoid:** `transException(true)`, výnimku zachytiť mimo transakcie, test rollbacku (zmena aj audit zmiznú).

### Pitfall 3: Boolean z PG v CI4
**What goes wrong:** `is_default`/`counts_toward_limit` sa vrátia ako `'t'/'f'` reťazce (Phase 2 to rieši `in_array($row['success'], [true,'t','true','1',1])`). Zápis ako `'true'/'false'` reťazec (vzor `AuditLog`).
**How to avoid:** jeden helper na prevod (`DbBool::of()`), použiť vo všetkých novej službách; alebo `SELECT col::int`.

### Pitfall 4: Nesprávne „dnes“ pri `valid_from` a `active_to`
**What goes wrong:** dátumy z `date()`/UTC o polnoci. **How to avoid:** `service('clock')->now()->format('Y-m-d')`; testy s `FrozenClock` na 31. 10. 23:30 a 1. 11. 00:30 (vzor `ClockTest`).

### Pitfall 5: Duplicitné číslovanie medzi radom faktúr a dobropisov
**What goes wrong:** rovnaký formát pre oba rady -> kolízia čísel v Phase 5. **How to avoid:** validácia „formáty musia byť rôzne“, predvolený `DB-` prefix.

### Pitfall 6: Formát bez roka
**What goes wrong:** `###` s ročným resetom opakuje čísla. **How to avoid:** rok je povinný token.

### Pitfall 7: Odkaz na portál ako vektor XSS
**What goes wrong:** `javascript:` alebo `data:` v `portal_url_template` sa vypíše do `href` v Phase 4. **How to avoid:** povoliť len `http`/`https` (`parse_url`, host povinný), práve jedno `{id}`, dĺžka <= 500, pri vykreslení `rawurlencode($id)` a `esc(..., 'attr')`.

### Pitfall 8: `char(n)` a bodkočiarky v e-mailoch
**What goes wrong:** `char(3)` je v PG presné, ale `bpchar` pri porovnaní/ trim; e-maily s CR/LF umožnia injektovať hlavičky. **How to avoid:** `char(3)` len pre ISO kódy veľkými písmenami (CHECK), e-maily validovať a odmietnuť riadiace znaky.

### Pitfall 9: Seed v `DevSeeder`
Uvedené v Anti-Patterns; plus pozor, že `app_rw` nemá na `legal_*` zápis, teda aj chyba v seede sa opravuje len novou migráciou (nie editom starej, ktorá už bežala v produkcii).

### Pitfall 10: Čas migrácií a poradie
Migrácie mena `YYYY-MM-DD-NNNNNN_Name.php` triedené abecedne; tabuľky s FK (`entity_versions` -> `entities`, `audit_log` -> `users`) musia mať vyššie poradové číslo; `down()` v opačnom poradí. Test migrácií beží `composer test:migrate` pod `tests_owner`.

## Code Examples

### Verzia platná k dátumu
```php
// Source: CONTEXT D-04 + ARCHITECTURE.md (Subjekty s verziami); overiť EXPLAIN pri implementácii
$row = $this->db->query(
    'SELECT * FROM entity_versions WHERE entity_id = :e: AND valid_from <= :d: ORDER BY valid_from DESC LIMIT 1',
    ['e' => $entityId, 'd' => $date],   // $date = Clock::now()->format('Y-m-d') alebo dátum vystavenia
)->getRowArray();
```

### Zápis zmeny + audit v jednej transakcii
```php
// Source: CI4 BaseConnection::transException/transStart (overené v dev kontajneri)
$db = Database::connect();
$db->transException(true)->transStart();
$db->table('entity_versions')->insert($values);
$versionId = (int) $db->insertID();
$this->audit->record('entity_version', $versionId, 'create', null, $values);
$db->transComplete();   // výnimka vyššie = rollback zmeny aj auditu
```

### Test oprávnení (rozšírenie vzoru DbRolesTest)
```php
// Source: tests/integration/DbRolesTest.php (assertPermissionDenied)
$this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE entities SET country = 'PL'"));
$this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE entities SET currency = 'PLN'"));
$this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM entities'));
$this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE entity_versions SET trade_name = 'x'"));
$this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM audit_log'));
$this->rw()->query("UPDATE entities SET active_to = '2026-12-31' WHERE id = :id", [...]);   // musí prejsť
```

### Mod-97 IBAN
```php
// Source: ISO 13616 / algoritmus overený na SK, PL, CZ vzorových IBAN (python)
$s = substr($iban, 4) . substr($iban, 0, 4);
$n = '';
foreach (str_split($s) as $c) { $n .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c; }
$rem = 0;
foreach (str_split($n, 7) as $chunk) { $rem = (int) ($rem . $chunk) % 97; }   // bez bcmath/GMP, bez pretečenia
$valid = $rem === 1;
```
(Pozor pri chunkovaní: `$rem` sa pripája pred blok, `(int)($rem.$chunk)` zostáva v rozsahu 64-bit int.)

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `UNIQUE` s NULL = duplikáty povolené | `UNIQUE NULLS NOT DISTINCT` | PostgreSQL 15 | čistý unikát pre `billing_mode NULL` bez sentinelu |
| tabuľkový `GRANT UPDATE` | stĺpcový `GRANT UPDATE (cols)` | dlhodobo dostupné | nemenné stĺpce (country, currency) v DB |
| `text[]` pre zoznamy v CI4 | `jsonb` pole | — | bez parsovania PG literálu |

**Deprecated/outdated:** DB triggery na audit (zamietnuté D-18); PG `SEQUENCE` na čísla faktúr (Phase 5, zakázané).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Konkrétne poľské znenie podkladu oslobodenia a mapovanie `vat_regime`/`billing_mode` -> kód (`ART_113_1`, `ART_113_9`, `ART_28B_RC`) je predbežné | Pattern 7 | zlý text na faktúre; mitigované tým, že seed je označený ako predbežný a účtovníčka ho potvrdí pred Phase 6 (R1) |
| A2 | Hodnoty `vat_regime` (`sk_non_vat`, `pl_exempt_113_1`, `pl_exempt_113_9`, `cz_non_vat`) | Pattern 7 | zmena názvov = migrácia dát; názvy sa uložia do snapshotu v Phase 5 |
| A3 | `active_from` sa dá meniť len na dátum >= najstaršej verzie; rozsah `valid_from` 2000-01-01 až dnes + 366 dní | Pattern 2 | príliš prísne pre vlastníka; dá sa uvoľniť bez migrácie |
| A4 | Oprava preklepu = nová verzia s dátumom dnes (medzera medzi pôvodnou verziou a opravou ostáva s pôvodnými údajmi) | Pattern 2 | back-dated faktúra v medzere by vzala chybnú verziu; akceptované kvôli insert-only |
| A5 | `iban` na účte je editovateľný (s auditom), `currency` nie | Pattern 3 | ak má byť účet po vytvorení nemenný, odobrať `iban` z grantu |
| A6 | Klientske ID bez kontrolnej sumy (len formát) | Pattern 6 | preklep v ID prejde; kontrola by mohla odmietnuť platné zahraničné ID |
| A7 | `isFormatLocked` je v tejto fáze konštantne `false` a Phase 5 ho nahradí | Pattern 8 | ak sa Phase 5 zabudne, formát sa dá meniť aj po vystavení |
| A8 | UI-SPEC pre Phase 3 neexistuje; použiť `02-UI-SPEC.md` a existujúce views | Pattern 9 | rozloženie obrazoviek sa môže líšiť od očakávaní |

## Open Questions

1. **Má Phase 3 obsahovať UI na úpravu `exemption_code`?**
   - Čo vieme: SET-02 vyžaduje štruktúrované pole v právnom profile; UI editácie textov je Phase 6 (SET-01).
   - Čo je nejasné: či sa kód edituje už tu.
   - Odporúčanie: nie. Seed + zobrazenie read-only + `LegalProfileResolver`; úprava až v Phase 6 spolu s textami (voliteľne stĺpcový grant, pozri Pattern 7).
2. **Otázky pre účtovníčku (SUMMARY.md body 1-10)** sú akcia pri štarte fázy, neblokujú implementáciu; seed zostáva predbežný.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker Compose (php, nginx, db, mailpit) | vývoj a testy | ✓ | služby `Up (healthy)` | — |
| PostgreSQL | migrácie, testy | ✓ | 18.6 (`postgres:18-alpine`) | — |
| PHP | aplikácia | ✓ | 8.5.12 v kontajneri `php` | — |
| `vendor/` na hostiteľskom WSL FS | spúšťanie z hostiteľa | ✗ (vo vývoji je vo volume) | — | spúšťať cez `make`/`docker compose exec php` |
| `psql` na hostiteľovi | ručné overenie | ✗ | — | `docker compose exec db psql` |

**Missing dependencies with no fallback:** žiadne.
**Missing dependencies with fallback:** psql/vendor na hostiteľovi (použiť kontajnery).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit `^10.5.16` s CI4 `CIUnitTestCase`, skupiny `unit`, `integration`, `feature` |
| Config file | `phpunit.xml.dist` (existujúci) |
| Quick run command | `make test-unit` (alebo `docker compose exec -T -u www-data php composer test:unit`) |
| Full suite command | `make check` (PHPStan + CS + PHPUnit); `make test` len testy |

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ENT-01 | vytvorenie SK (EUR, `sk_non_vat`) a PL (PLN, `pl_exempt_113_1`) subjektu vytvorí entitu, prvú verziu, 2 rady, audit v 1 transakcii | integration | `phpunit tests/integration/Master/EntityServiceTest.php` | ❌ Wave 0 |
| ENT-01/D-08 | obe vytvorenia cez UI (POST) | feature | `phpunit tests/feature/Master/EntitiesTest.php` | ❌ Wave 0 |
| ENT-02 | validácie polí (IČO/NIP/e-mail/register) | unit | `phpunit tests/unit/Master/EntityValidationTest.php` | ❌ Wave 0 |
| ENT-03 | nová verzia, duplicitný `valid_from` odmietnutý, výber k dátumu (hranica, medzi verziami, budúca), `UPDATE/DELETE entity_versions` denied | integration | `phpunit tests/integration/Master/EntityVersionTest.php` | ❌ Wave 0 |
| ENT-03/D-05 | história viditeľná, `VersionDiff` | unit+feature | `phpunit tests/unit/Master/VersionDiffTest.php` | ❌ Wave 0 |
| ENT-04 | ukončený subjekt v `forSelect()`; `activeAt` s prekryvom | integration | `phpunit tests/integration/Master/EntityDirectoryTest.php` | ❌ Wave 0 |
| ENT-05 | VAT-UE formát a uloženie vo verzii | unit | `EntityValidationTest` | ❌ Wave 0 |
| ENT-06 | viac účtov, `forCurrency` (3 vetvy), jedno predvolené, IBAN mod-97 | integration+unit | `phpunit tests/integration/Master/BankAccountTest.php tests/unit/Master/IbanTest.php` | ❌ Wave 0 |
| ENT-07 | server odmietne zmenu country/currency (podvrhnutý POST), `UPDATE entities SET country/currency` je `permission denied` | feature+integration | `phpunit tests/feature/Master/EntityImmutableTest.php tests/integration/Master/MasterRolesTest.php` | ❌ Wave 0 |
| D-09..D-11 | parser formátu, `periodKey`, rok povinný, rady invoice/credit_note rôzne, `isFormatLocked` | unit | `phpunit tests/unit/Master/SeriesFormatTest.php` | ❌ Wave 0 |
| CLI-01 | pridať, upraviť, archivovať, obnoviť; DELETE denied | integration+feature | `phpunit tests/integration/Master/ClientServiceTest.php tests/feature/Master/ClientsTest.php` | ❌ Wave 0 |
| CLI-02/03/07 | validácie (identifikátor podľa typu, e-maily v kópii, sadzba string, prebíjanie jazyka/splatnosti nullable) | unit | `phpunit tests/unit/Master/ClientValidationTest.php` | ❌ Wave 0 |
| CLI-04 | `PortalUrlTemplate` (schéma, `{id}` práve raz, `javascript:` odmietnuté) | unit | `phpunit tests/unit/Master/PortalUrlTemplateTest.php` | ❌ Wave 0 |
| CLI-05/06 | režim fakturácie, `eu_b2b` vyžaduje `vat_id`, príznak default true | unit+integration | `ClientValidationTest`, `ClientServiceTest` | ❌ Wave 0 |
| SET-02 | seed profilov a textov prítomný po migrácii, `exemption_code` formát, fallback poradie a výnimka | integration | `phpunit tests/integration/Master/LegalProfileTest.php` | ❌ Wave 0 |
| AUD-01 | audit v tej istej transakcii (rollback), diff len zmenených polí, denylist citlivých kľúčov, insert-only `audit_log`, IP a user | integration+unit | `phpunit tests/integration/Master/AuditLoggerTest.php tests/unit/Master/AuditDiffTest.php` | ❌ Wave 0 |
| UI | bez inline skriptov/štýlov a bez natvrdo textov, kľúče sk/en | unit (existujúce) | `phpunit tests/unit/Security/ViewsCspTest.php tests/unit/I18n` | ✅ |

### Sampling Rate
- **Per task commit:** `make test-unit` a cielený `phpunit --filter <trieda>`
- **Per wave merge:** `make test` (celá sada vrátane integračných pod `app_rw`)
- **Phase gate:** `make check` zelené pred `/gsd-verify-work`

### Wave 0 Gaps
- [ ] migrácie (`composer test:migrate`) a `tests/_support/MasterFixtures.php` (pomocníci na vytvorenie SK/PL subjektu a klienta, čistenie tabuliek pod `owner()`, keďže `app_rw` nemôže `DELETE`)
- [ ] `tests/{unit,integration,feature}/Master/*` podľa tabuľky vyššie
- [ ] rozšírenie `ViewsHardcodedTextTest`/`LangKeysTest` nie je potrebné (pokrývajú nové views automaticky)
- Framework install: netreba.

## Security Domain

### Applicable ASVS Categories (úroveň 1)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (rieši Phase 2) | — |
| V3 Session Management | no (Phase 2); nové routy za `authgate` | existujúce |
| V4 Access Control | yes | `AuthGate` len `admin`; všetky zápisy POST; IDOR: ID z URL vždy overiť, že existuje (404), jediný vlastník dát, ale kontrola `entity_id` príslušnosti účtu/rady k subjektu v URL (účet patrí subjektu) |
| V5 Input Validation | yes | `validateData` + doménové validátory (Iban, SeriesFormat, PortalUrlTemplate, CcEmails), whitelist `getPost([...])` proti mass assignment, DB CHECK ako sieť |
| V6 Cryptography | no | nové tajomstvá nevznikajú |
| V7 Error/Logging | yes | `audit_log` insert-only, žiadne citlivé polia, `IP` |
| V8 Data Protection | yes | osobné údaje klientov (e-maily); neposielať do logov, `esc()` pri výstupe |
| V12/V13 Files, API | no | bez nahrávania súborov |

### Known Threat Patterns for CI4 + PostgreSQL CRUD

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Zmena krajiny/meny cez podvrhnutý POST (mass assignment) | Tampering | whitelist polí v kontroléri, služba bez týchto parametrov, stĺpcový GRANT |
| Prepis histórie verzií alebo auditu (chyba kódu, SQLi) | Tampering/Repudiation | insert-only GRANT, test oprávnení |
| SQL injection | Tampering | Query Builder/viazané parametre `:name:`; `GrantsPrivileges` validuje identifikátory |
| XSS cez názov klienta, poznámku, `portal_url_template` | Tampering/Elevation | `esc()` všade, `esc(..., 'attr')` v `href`, povolené len `http/https`, CSP bez `unsafe-inline` |
| CSRF na mutujúce akcie | Tampering | `HtmxCsrf` globálne, `csrf_field()`, žiadne GET zmeny |
| IDOR (cudzí `account_id` pod iným subjektom) | Elevation | pri každej akcii overiť `WHERE id = :id AND entity_id = :e` |
| Hlavičkový injekt cez e-maily v kópii | Tampering | `valid_email`, odmietnuť CR/LF, limit počtu |
| Neúmyselné zničenie dát (DELETE) | DoS/Tampering | žiadny DELETE grant na entity, klientov, účty, rady |
| Audit obsahuje tajomstvá | Information disclosure | denylist kľúčov + test |
| Strata auditu pri chybe | Repudiation | zápis v tej istej transakcii |

## Sources

### Primary (HIGH confidence)
- Repozitár: `03-CONTEXT.md`, `REQUIREMENTS.md`, `.planning/research/ARCHITECTURE.md` (§ Subjekty s verziami, Vynucovanie nemennosti, AuditLogger), `PITFALLS.md` (Pasca 7), spec `docs/superpowers/specs/2026-10-23-task-tracker-design.md` § 6.1, 6.2, 6.5, 6.9, 7
- Existujúci kód: `app/Database/Support/GrantsPrivileges.php`, migrácie Phase 2 (`CreateAuthEvents`), `app/Libraries/Auth/AuditLog.php`, `app/Controllers/Settings/Security.php`, `app/Controllers/Demo.php`, `app/Filters/AuthGate.php`, `app/Config/AuthGroups.php`, `app/Config/Routes/*`, `tests/integration/DbRolesTest.php`, `tests/_support/PgTestCase.php`, `AuthTestCase.php`, `docker/postgres/provision.sql`
- Živé overenie v dev kontajneri: PG 18.6 (`NULLS NOT DISTINCT`, partial unique index, stĺpcový GRANT pod rolou, DELETE denied), CI4 `BaseConnection::transException`, Shield `PermissionFilter`/`GroupFilter` registrované, IBAN mod-97 na SK/PL/CZ vzorkách

### Secondary (MEDIUM confidence)
- `CLAUDE.md` (stack, konvencie, požiadavky na testy a UX)

### Tertiary (LOW confidence)
- Predbežné právne znenia a mapovanie kódov (A1, A2): bez konzultácie s účtovníčkou, podľa spec 6.5

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, nič nové, všetko existuje v repozitári
- Architecture: HIGH, opiera sa o locked rozhodnutia a overené správanie PG
- Pitfalls: HIGH pre DB/transakcie/oprávnenia; MEDIUM pre UX verzií (návrh)
- Právne texty: LOW (predbežné podľa D-17)

**Research date:** 2026-10-25
**Valid until:** 2026-11-24 (stabilné; prehodnotiť, ak sa zmení CI4/Shield minor)

## Project Constraints (from CLAUDE.md)

- Stack je pevný: PHP 8.5 FPM, CI4 4.7.x, Shield, PostgreSQL 18, Bootstrap 5.3, htmx 2, Alpine **CSP build**; žiadna SPA ani build pipeline, JS/CSS lokálne.
- **Peniaze `numeric(12,2)`, hodiny `numeric(7,2)`, sadzby `numeric(10,2)`, žiadny float**; v PHP string + bcmath (`Money`).
- Vystavené doklady sú nemenné a kontroluje to serverová logika; číslovanie transakčné, bez medzier, zámok v DB (pridelenie je Phase 5; tu len konfigurácia).
- Prísna CSP: žiadne inline `style=""`, inline `onclick`, inline skripty; htmx `allowEval=false`.
- Bezpečnosť: CSRF, secure/httpOnly cookies, tajomstvá v `.env`; šifrovanie tajomstiev (v tejto fáze nevznikajú).
- Testy: PHPUnit povinne pre výpočty súm, číslovanie, zamykanie častí, kópiu údajov, kurzy a limity (tu: formát čísla, výber verzie, oprávnenia).
- UX: ovládateľné jednou rukou na iPhone, dotykové ciele >= 44 px, bez horizontálneho posúvania; texty cez `lang()`.
- Výkon: p95 < 100 ms; zoznamy sú malé, žiadne N+1 v zozname subjektov a klientov (jeden dotaz s agregáciou).
- GSD: zmeny súborov cez GSD workflow (`/gsd-execute-phase`), nie priame edity mimo neho.
