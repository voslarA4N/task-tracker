# Task Tracker (evidencia úloh a fakturácia)

## What This Is

Webová aplikácia (PWA) pre jedného živnostníka-programátora, ktorá nahrádza ručne vedený Excel. Eviduje úlohy pre klientov a ich nacenenie, fakturuje ich po mesiacoch aj po častiach, vystavuje nemenné faktúry a výkazy práce v PDF, posiela ich e-mailom a sleduje úhrady a daňové limity obratu. Používa sa na PC aj na iPhone (inštalovaná PWA).

## Core Value

Vystavená faktúra a výkaz sú **správne a nemenné**: súčty sa vždy zhodujú na obrazovke, vo výkaze, na faktúre aj v exportoch a žiadna neskoršia zmena (subjekt, klient, sadzba, mena) už vystavený doklad nezmení. Ak zlyhá všetko ostatné, toto musí fungovať.

## Requirements

### Validated

- [x] Prihlásenie heslom (Argon2id) s povinnou TOTP 2FA, záložnými kódmi, „zapamätať zariadenie“ na 30 dní a denníkom prihlásení (E1). Validated in Phase 2: Identita a skoré nasadenie (staging https://work-tracker.vostack.dev, UAT na iPhone 2026-10-25; 7- a 30-dňová kontrola sledovaná v STATE.md)

- [x] Viac vlastných subjektov (SK, PL, CZ) s verziami „platné od“, vlastným číselným radom, menou, jazykom a DPH režimom (E2). Validated in Phase 3: Master dáta (subjekty a klienti), nasadené na https://work-tracker.vostack.dev 2026-10-25

- [x] Klienti so sadzbou, menou, jazykom, splatnosťou, režimom fakturácie a príznakom započítania do limitu (E3). Validated in Phase 3: Master dáta (subjekty a klienti)

- [x] Úlohy s nacenením, stavmi V práci / Zapracovaná a odvodeným fakturačným stavom (E4). Validated in Phase 4: Úlohy, fakturačné časti a dashboard (nasadené na https://work-tracker.vostack.dev 2026-10-26, UAT na iPhone)

- [x] Fakturačné časti po mesiacoch, zamykanie po vystavení kontrolované na serveri aj v DB (E5). Validated in Phase 5: Fakturačné jadro

- [x] Jadro faktúr (E6): atómové vystavenie za klienta a mesiac, kópia údajov v JSONB, číslovanie `RRRRMM###` bez medzier a bezpečné voči súbehu, uložené PDF so sha256, náhľad chránený tokenom z celej kópie údajov, historické externé faktúry (FA 208-2026 za 09/2026 na produkcii). Validated in Phase 5: Fakturačné jadro (nasadené 2026-10-27, UAT na iPhone)

### Active

Úplný zoznam s ID je v `.planning/REQUIREMENTS.md` (prevzatý z `docs/PRD.md`, epiky E1–E12, priority P0 + P1 + P2).

- [ ] Faktúry (E6): zvyšok – PDF podľa právneho profilu (Phase 6) a dobropis (Phase 9). Jadro hotové vo Phase 5
- [ ] PDF výkaz práce ku každej faktúre, rovnako nemenný (E7)
- [ ] Odoslanie e-mailom, opätovné odoslanie, ručné úhrady, faktúry po splatnosti, upomienky (E8)
- [ ] Meny EUR, PLN, CZK, kurzy z NBP / ECB / ČNB s ručným prepisom a uložením do kópie údajov (E9)
- [ ] Dashboard (mobil, variant B), mesačné a ročné prehľady, export CSV a XLSX, limity obratu nastaviteľné v aplikácii (E10)
- [ ] Denník zmien s prehliadaním a filtrami (E11)
- [ ] Responzívne UI (Bootstrap 5.3), jazyky SK, PL, CZ, EN, PWA, import z Excelu, editovateľné zákonné texty (E12)

### Out of Scope

- Odosielanie do KSeF: rieši účtovníčka. Dáta faktúry sa však ukladajú štruktúrovane (INV-08), aby bol neskorší export FA(3) možný.
- Napojenie na banku a automatické párovanie platieb: úhrady sa označujú ručne.
- Meranie času (stopky, timesheety): fakturujú sa zadané hodiny.
- Režim platiteľa DPH: netreba ho implementovať, ale model ho nesmie znemožniť (sadzba DPH na položke, predvolene 0 / „bez DPH“).
- Offline práca s dátami: PWA cachuje len statické súbory a offline stránku.
- Klientsky portál a multi-tenant SaaS: jediný používateľ a jedna inštalácia.
- Synchronizácia s Taskmanom a inými portálmi: odkaz na portál je len URL.

## Context

- **Dnešný stav:** Excel `All4net-fakturovanie-prace.xlsx` s mesačnými blokmi (odkaz do Taskmanu, názov, nacenené hodiny, suma, Zapracované/Vyfakturované ÁNO/NIE), jediná sadzba 27 € pre klienta All4Net. Súčty sú chybné (októbrový súčet E25/F25 počíta len riadky 19–22). PDF výkaz sa skladá ručne, platby sa sledujú ručne v banke.
- **Vlastné faktúry zatiaľ neexistujú.** Doteraz sa fakturovalo cez tretiu osobu, číselný rad teda začína od 001 (počiatočné číslo sa dá nastaviť).
- **Zmena právnej formy:** najbližšie mesiace fakturuje slovenská živnosť (EUR, neplatiteľ DPH). Potom ju nahradí poľská (PLN, zwolnienie podmiotowe, art. 113). Ide o samostatný subjekt s vlastným radom a obratom. Na prelome môžu existovať oba subjekty naraz a staré doklady musia ostať na pôvodnom subjekte.
- **Cezhraničná fakturácia** (PL subjekt → SK B2B klient) nie je právne overená (reverse charge, VAT-UE, započítanie do limitu). Preto je všetko nastaviteľné: režim fakturácie a príznak limitu na klientovi, editovateľné zákonné texty.
- **Používatelia:** V1 len vlastník (admin). Do budúcna účtovníčka s prístupom len na čítanie a exporty (model a roly sa pripravia vo V1). Klient do aplikácie nevstupuje, dostáva e-maily s PDF.
- **Zdrojové dokumenty:** `docs/PRD.md` (požiadavky s ID a kritériami prijatia), `docs/superpowers/specs/2026-10-23-task-tracker-design.md` (špecifikácia, **pri rozpore platí špecifikácia**), makety `docs/mockups/mobile-home.html` a `docs/mockups/task-flows.html`.
- **Import:** zdrojový súbor je v `docs/All4net-fakturovanie-prace.xlsx` (gitignorovaný cez `*.xlsx`, do gitu nepatrí). Importujú sa úlohy za 09 a 10/2026.
- **Vývojové prostredie:** Windows + WSL2, projekt v `/mnt/c/personal-projects/task-tracker`, Docker Compose.

## Constraints

- **Tech stack**: PHP 8.5 (FPM, OPcache, preloading), CodeIgniter 4.7.x, CodeIgniter Shield + TOTP knižnica (`spomky-labs/otphp` alebo `robthree/twofactorauth`), PostgreSQL (najnovšia stabilná), Bootstrap 5.3, htmx 2, Alpine.js, mPDF, CI4 Email (SMTP), PhpSpreadsheet alebo ľahšia alternatíva. Všetko v najnovších stabilných verziách. Dohodnuté s používateľom.
- **Žiadna SPA ani build pipeline**: JS a CSS hostované lokálne, minimálne, s dlhou cache a verziou v názve. Dôvod: rýchlosť na mobile, PWA a nízke nároky na server.
- **Výkon**: odozva servera p95 pod 100 ms na bežných stránkach, generovanie PDF pod 2 s, HTTP/2 a kompresia.
- **Zdroje**: najmenší VPS Hetzner Cloud (Docker Compose: nginx, php-fpm, PostgreSQL), cieľovo do 512 MB RAM v pokoji. PostgreSQL nastavený na nízku spotrebu pamäte.
- **Integrita dát**: peniaze `numeric(12,2)`, hodiny `numeric(7,2)`, žiadny float. Vystavené doklady sú nemenné a kontroluje to serverová logika. Číslovanie je transakčné, bez medzier a bezpečné voči súbehu (zámok v DB).
- **Bezpečnosť**: HTTPS (Let's Encrypt), CSP, HSTS a ďalšie hlavičky, CSRF, secure a httpOnly cookies, tajomstvá v `.env` mimo gitu, šifrované TOTP tajomstvá.
- **Prenositeľnosť**: rovnaké Docker obrazy pre vývoj (WSL2) aj produkciu. Vo vývoji Mailpit.
- **Zálohy**: denný šifrovaný `pg_dump` a PDF dokladov mimo servera (napr. Hetzner Storage Box), rotácia 14 / 8 / 12, zdokumentovaná a otestovaná obnova.
- **Testy**: PHPUnit povinne pre výpočty súm, číslovanie, zamykanie častí, kópiu údajov, kurzy a limity.
- **UX**: ovládateľné jednou rukou na iPhone, dotykové ciele aspoň 44 px, bez horizontálneho posúvania.
- **Závislosti**: produkčný SMTP poskytovateľ ešte nie je zvolený (konfigurácia cez `.env`, vyberie vlastník pred nasadením). API kurzov (NBP, ECB, ČNB) môže byť nedostupné, preto je k dispozícii ručné zadanie.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| PostgreSQL namiesto MariaDB | `jsonb` pre kópie údajov, `numeric`, spoľahlivé transakčné zámky pre číslovanie | — Pending |
| Kópia údajov (snapshot) v JSONB pri vystavení, PDF sa generuje z kópie | Záruka nemennosti dokladov po zmene subjektu, klienta, sadzby či meny | — Pending |
| Poľská živnosť je samostatný subjekt, nie verzia slovenského | Vlastný číselný rad, obrat, mena a obdobie pôsobenia. Staré doklady ostanú na starom subjekte | — Pending |
| Jedna položka faktúry za každú fakturačnú časť | Faktúra slúži zároveň ako súhrn, výkaz pridáva odkazy na portál | — Pending |
| Číslovanie `RRRRMM###`, rad patrí subjektu, mesiac a rok z dátumu vystavenia | Požiadavka používateľa, reset poradia mesačne pri tokene `MM`, inak ročne | — Pending |
| Dobropis je vo V1 vždy celé storno a uvoľní zamknuté časti | Jednoduchosť, časti sa dajú fakturovať znova | — Pending |
| Limity obratu v tabuľke v nastaveniach (nie v kóde), obrat z vystavených faktúr podľa dátumu dodania | Limity sa menia každý rok, spôsob výpočtu sa dá nastaviť pre krajinu | — Pending |
| Režim fakturácie a príznak limitu na klientovi, editovateľné zákonné texty | Cezhraničné pravidlá nie sú overené (riziko R1), rozhodne účtovníčka | — Pending |
| Kurz podľa krajiny subjektu (PL: NBP, SK: ECB, CZ: ČNB) s ručným prepisom, uložený do kópie údajov | Prehľady a limity počítajú len z uložených prepočítaných súm, nikdy aktuálnym kurzom | — Pending |
| Shield + vlastná akcia TOTP. Ak Shield prekáža, vlastné prihlasovanie nad sessions CI4 | Shield nemá natívnu TOTP (riziko R4) | — Pending |
| Bootstrap 5.3 + htmx 2 + Alpine.js, bez SPA a build pipeline | Výkon, malý JS, jednoduchá PWA | — Pending |
| Plánovacie dokumenty v slovenčine. V1 = P0 + P1 + P2 (všetky epiky E1–E12) | Rozhodnutie používateľa pri inicializácii | — Pending |
| Peniaze cez bcmath (`bcmul` scale 4 + `bcround` HalfAwayFromZero), nie `brick/math` | MoneyTest (32 testov, kladné aj záporné hodnoty, bez `-0.00`) a paritný test s PostgreSQL `round(numeric, 2)` na 2000 kombináciách sú zelené, `brick/math` netreba | ✓ Phase 1 (2026-10-23) |
| mPDF 8.3.1 pod PHP 8.5: DejaVu Sans pripnutý v `resources/fonts/`, bez substitúcií (`useSubstitutions`, `autoLangToFont`, `autoScriptToLang` vypnuté), `tempDir` `writable/mpdf` | Vzorová faktúra 40 riadkov so SK/PL/CZ diakritikou: CLI cold 299 ms, CLI warm 264 ms (peak 22 MB), FPM cold 218 ms, FPM warm 185 ms, 0 deprecations pri `E_ALL`; `memory_limit` 256M stačí aj pre PDF požiadavky, limit 2 s s veľkou rezervou | ✓ Phase 1 (2026-10-23) |
| Kolácia DB: databáza vytvorená s `LOCALE_PROVIDER icu ICU_LOCALE 'sk-SK' LOCALE 'C.UTF-8'` | Spike na PG 18 prešiel (`'Č'` medzi `C` a `D`, `'ch'` za `'h'`), usporiadanie diakritiky funguje bez `COLLATE` v dotazoch, `COLLATE "sk-x-icu"` netreba | ✓ Phase 1 (2026-10-23) |
| CSRF pre htmx: vlastný filter `HtmxCsrf`, `csrfProtection=session`, `regenerate=false`, `redirect=false`, token v `hx-headers` | htmx 2.0.11 neposiela `X-Requested-With`, token sa preto posiela hlavičkou z `hx-headers` v layoute, stabilný počas session, chyba vracia 403 bez presmerovania | ✓ Phase 1 (2026-10-23) |
| CSP a bezpečnostné hlavičky iba v nginx, CI4 CSP a Debug Toolbar vypnuté | Jedno miesto pravdy, žiadne inline skripty ani štýly vo views (strážené testami `ViewsCspTest`), Alpine CSP build bez `unsafe-eval` | ✓ Phase 1 (2026-10-23) |
| PHPUnit 11.5 namiesto pinu 10.5 z appstarter-a | Beží 11.5.57 na PHP 8.5 s `CIUnitTestCase` bez problémov, 92 testov zelených | ✓ Phase 1 (2026-10-23) |
| DB roly: default privileges `app_rw` = SELECT, INSERT, UPDATE/DELETE len cez `grantMutable()` | Vystavené doklady sú nemenné aj na úrovni DB, ochranu dokazujú testy rolí pod pripojením `app_rw`. Migrácie vždy `spark migrate -g owner|tests_owner` (`Config\Database` prepne `defaultGroup`, inak by tabuľku `migrations` vytvoril `app_rw`) | ✓ Phase 1 (2026-10-23) |
| Preklady: `codeigniter4/translations` s auto-discovery, bez zásahu do `Autoload` | Predpoklad potvrdený v 01-04, texty vo views iba cez `lang()` (test `ViewsHardcodedTextTest`) | ✓ Phase 1 (2026-10-23) |
| Vývoj na Windows: `mkcert.exe` sa hľadá cez env `MKCERT`, `PATH`, potom WinGet Packages (`scripts/lib-mkcert.sh`); CI na mkcert ani `hosts` nezávisí | Certifikát dôveryhodný v prehliadači vo Windows bez ručných krokov, CI beží s `--network host` a vlastnou efemérnou DB | ✓ Phase 1 (2026-10-23) |
| PHPStan `phpstan-codeigniter` schema cache v `build/phpstan` (nie `tmp/` v koreni) | Konfigurovateľné cez `parameters.codeigniter.schemaCacheDirectory`, `build/` je v `.gitignore` | ✓ Phase 1 (2026-10-23) |


## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-10-27 after Phase 5 (Fakturačné jadro)*
