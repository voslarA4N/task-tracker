# Task Tracker (evidencia úloh a fakturácia)

## What This Is

Webová aplikácia (PWA) pre jedného živnostníka-programátora, ktorá nahrádza ručne vedený Excel. Eviduje úlohy pre klientov a ich nacenenie, fakturuje ich po mesiacoch aj po častiach, vystavuje nemenné faktúry a výkazy práce v PDF, posiela ich e-mailom a sleduje úhrady a daňové limity obratu. Používa sa na PC aj na iPhone (inštalovaná PWA).

## Current State

**v1.0 MVP shipped 2026-11-10** (tag `v1.0`). Produkcia https://work-tracker.vostack.dev (vetva `main`, deploy so schválením vlastníka), staging https://work-tracker-stage.vostack.dev (vetva `develop`, reseed pri každom deployi). 14 fáz, 155 plánov, 90/90 v1 požiadaviek, 2701 PHPUnit testov. Súhrn v `.planning/MILESTONES.md`, archív v `.planning/milestones/`.

## Next Milestone Goals

Zatiaľ nedefinované, určí `/gsd-new-milestone`. Kandidáti z prevádzky v1.0:
- Potvrdenie zákonných textov, limitov a cezhraničnej fakturácie PL → SK účtovníčkou a ich zapracovanie
- Import reálnych dát a zamknutie historických „Fakturované = ÁNO“ častí (Phase 5 WR-07 C) pred prvým ostrým vystavením
- Prechod zo SK na PL živnosť (nový subjekt) v ostrej prevádzke
- Prístup účtovníčky len na čítanie a exporty (model a roly pripravené vo V1)
- Neskorší export FA(3) pre KSeF zo štruktúrovaných dát (INV-08)

## Core Value

Vystavená faktúra a výkaz sú **správne a nemenné**: súčty sa vždy zhodujú na obrazovke, vo výkaze, na faktúre aj v exportoch a žiadna neskoršia zmena (subjekt, klient, sadzba, mena) už vystavený doklad nezmení. Ak zlyhá všetko ostatné, toto musí fungovať.

## Requirements

### Validated

- [x] Prihlásenie heslom (Argon2id) s voliteľnou TOTP 2FA (od 2026-10-28, pôvodne povinná), záložnými kódmi, „zapamätať zariadenie“ na 30 dní a denníkom prihlásení (E1). Validated in Phase 2: Identita a skoré nasadenie (staging https://work-tracker.vostack.dev, UAT na iPhone 2026-10-25; 7- a 30-dňová kontrola sledovaná v STATE.md)

- [x] Viac vlastných subjektov (SK, PL, CZ) s verziami „platné od“, vlastným číselným radom, menou, jazykom a DPH režimom (E2). Validated in Phase 3: Master dáta (subjekty a klienti), nasadené na https://work-tracker.vostack.dev 2026-10-25

- [x] Klienti so sadzbou, menou, jazykom, splatnosťou, režimom fakturácie a príznakom započítania do limitu (E3). Validated in Phase 3: Master dáta (subjekty a klienti)

- [x] Úlohy s nacenením, stavmi V práci / Zapracovaná a odvodeným fakturačným stavom (E4). Validated in Phase 4: Úlohy, fakturačné časti a dashboard (nasadené na https://work-tracker.vostack.dev 2026-10-26, UAT na iPhone)

- [x] Fakturačné časti po mesiacoch, zamykanie po vystavení kontrolované na serveri aj v DB (E5). Validated in Phase 5: Fakturačné jadro

- [x] Jadro faktúr (E6): atómové vystavenie za klienta a mesiac, kópia údajov v JSONB, číslovanie `RRRRMM###` bez medzier a bezpečné voči súbehu, uložené PDF so sha256, náhľad chránený tokenom z celej kópie údajov, historické externé faktúry (FA 208-2026 za 09/2026 na produkcii). Validated in Phase 5: Fakturačné jadro (nasadené 2026-10-27, UAT na iPhone)

- [x] PDF faktúra `inv-2` podľa právneho profilu (SK, CZ, PL art. 113, EÚ B2B), forma úhrady a poznámka, QR platba PAY by square 1.0.0 / SPAYD / ZBP, nemenný PDF výkaz `rep-1` vznikajúci v tej istej transakcii ako faktúra (E6, E7), editovateľné zákonné texty s revíziou a auditom (E12). Validated in Phase 6: Dokumenty faktúry a výkazu (UAT na stagingu 2026-10-28; znenia čakajú na potvrdenie účtovníčkou)

- [x] Doručenie a pohľadávky (E8): odoslanie faktúry s výkazom z uložených PDF cez vlastný SMTP (Stalwart, `noreply@vostack.dev`), opätovné odoslanie na ľubovoľnú adresu, ručné označenie odoslania so stornom, odosielateľ / Reply-To / skrytá kópia pri subjekte, editovateľné šablóny e-mailov pre každý jazyk, úhrady (aj čiastočné) so stornom, stavy Čiastočne uhradená / Uhradená, prehľad Po splatnosti na dashboarde, upomienka jedným klepnutím vo vlákne pôvodnej faktúry. Validated in Phase 7: Doručenie, úhrady a upomienky (UAT staging + dev Mailpit + produkcia 2026-10-30, SPF/DKIM/DMARC PASS)

- [x] Meny a kurzy (E9): zoznam mien v tabuľke `currencies` (EUR, PLN, CZK, rozšíriteľný bez zmeny modelu), kurz podľa krajiny subjektu (PL: NBP, SK: ECB, CZ: ČNB) s nastaviteľným dátumom a zdrojom (Nastavenia → Kurz mien, auditované), cache kurzov, ručné zadanie pri výpadku API alebo budúcom dátume dodania, kurz, zdroj, dátum a suma v mene subjektu v kópii údajov, na PDF, v detaile aj zozname faktúr. Vystavenie číta kurz len z cache (bez siete). Validated in Phase 8: Meny a kurzy (2026-10-30; prehľady a limity v mene subjektu, CUR-04, prídu vo Phase 10)

- [x] Limity obratu (E10, CUR-04): tabuľka limitov editovateľná v aplikácii (návrhy SK/PL označené ako návrh), obrat subjektu v jeho mene z uložených súm faktúr a dobropisov plus počiatočný obrat, spôsoby výpočtu kalendárny rok / kĺzavých 12 mesiacov / pomerný prvý rok so vzorcom na karte čerpania, upozornenia na dashboarde (prahy, chýbajúci rok) a neblokujúca predkontrola v náhľade faktúry. Validated in Phase 10: Limity obratu (UAT staging 2026-11-02) — v1.0

- [x] Jazyky rozhrania SK, PL, CZ a EN s voľbou používateľa na Zabezpečení, formát čísel, súm, dátumov a mesiacov podľa jazyka, vystavený doklad sa jazykom UI nemení (UI-02). Validated in Phase 12: Lokalizácia a produkcia (2026-11-09; PL/CS preklady a iPhone schválené vlastníkom v 12-HUMAN-UAT.md 2026-11-10) — v1.0

- [x] Prevádzka (NFR-01, NFR-05, NFR-09): denná šifrovaná restic záloha DB vrátane PDF s rotáciou 14/8/12, upozornenia (healthchecks.io, e-mail), mesačný test obnovy, obnova reálnych dát na čistom prostredí overená s 2FA, kópia mimo servera ručným pullom na PC (D-05 akceptovaný odklon), ročný archív dokladov `archive:year` (cron 2. 1.), doklady nezmazateľné ani DB oprávneniami, odozva servera p95 51 ms, PDF 230 ms, prod stack 97 MiB. Validated in Phase 12: Lokalizácia a produkcia (2026-11-09)

- [x] Prehľady a exporty berú meno klienta z kópie vystaveného dokladu (zoznam a detail faktúr, CSV/XLSX, mesačný a ročný prehľad, dashboard, úhrady, balík pre účtovníčku), obdobia mimo 2000-01..2100-12 vracajú validačnú odpoveď namiesto 500, zlyhaný ZIP pre účtovníčku nenechá súbor vo `writable/exports`, základ dátumu (archív podľa dátumu vystavenia, prehľady a balík podľa dátumu dodania) zdokumentovaný (RPT-01, RPT-02, RPT-03). Validated in Phase 12.1: Close gaps and UI polish (2026-11-09; dizajnová kontrola a premenovanie klienta na staging-u schválené vlastníkom)

- [x] Faktúry (E6): dobropis (celé storno) s povinným dôvodom a typom opravy, vlastným číslom, odkazom na pôvodnú faktúru, zápornými položkami (aj v cudzej mene s pôvodným kurzom), vlastným PDF `cn-1` so sha256 a odoslaním e-mailom; pôvodná faktúra je stornovaná (bez úhrad, upomienok a doručovania), jej PDF sa nemení, časti sa uvoľnia na opätovnú fakturáciu. Jadro vo Phase 5, PDF podľa právneho profilu vo Phase 6. Validated in Phase 9: Dobropisy (UAT staging 2026-11-02; WR-02/WR-04 opravené v quick 261110-kfy 2026-11-10) — v1.0

- [x] Dashboard (mobil, variant B), mesačné a ročné prehľady, export CSV a XLSX, ZIP balík pre účtovníčku (E10, RPT-01..03). Validated in Phase 11 a 12.1 — v1.0

- [x] Denník zmien s prehliadaním a filtrami (E11). Validated in Phase 11 — v1.0

- [x] Responzívne UI (Bootstrap 5.3), jazyky SK, PL, CZ, EN, PWA, import z Excelu, editovateľné zákonné texty (E12). Validated in Phases 1, 2, 4, 6, 12 a 12.1 — v1.0

### Active

(Žiadne. Všetkých 90 v1 požiadaviek je splnených, archív v `.planning/milestones/v1.0-REQUIREMENTS.md`. Nové požiadavky pre ďalší milestone pridá `/gsd-new-milestone`.)

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
- **Vývojové prostredie:** Windows + WSL2, projekt v `~/projects/task-tracker` (ext4; kópia na `/mnt/c` je zastaraná, 9p rozbíjal výpis migrácií), Docker Compose.
- **Stav po v1.0 (2026-11-10):** približne 55 tis. riadkov PHP v `app/` (106 tis. s testami), 2701 PHPUnit testov, CI (PHPUnit, PHPStan level 6, php-cs-fixer) a deploy cez GitHub Actions. Produkcia beží na Hetzner VPS: p95 51 ms, PDF 230 ms, stack 97 MiB v pokoji. E-maily idú cez vlastný Stalwart SMTP (`noreply@vostack.dev`, SPF/DKIM/DMARC PASS). Migrácie bežia iba ako owner (`make migrate` alebo `spark migrate -g owner --all`).
- **Známy technický dlh:** InvoiceMailer drží zámok `payments` počas SMTP (úhrada na tej istej faktúre počká), off-site záloha je ručný pull na PC, znenia zákonných textov čakajú na účtovníčku.

## Constraints

- **Tech stack**: PHP 8.5 (FPM, OPcache, preloading), CodeIgniter 4.7.x, CodeIgniter Shield + TOTP knižnica (`spomky-labs/otphp` alebo `robthree/twofactorauth`), PostgreSQL (najnovšia stabilná), Bootstrap 5.3, htmx 2, Alpine.js, mPDF, CI4 Email (SMTP), PhpSpreadsheet alebo ľahšia alternatíva. Všetko v najnovších stabilných verziách. Dohodnuté s používateľom.
- **Žiadna SPA ani build pipeline**: JS a CSS hostované lokálne, minimálne, s dlhou cache a verziou v názve. Dôvod: rýchlosť na mobile, PWA a nízke nároky na server.
- **Výkon**: odozva servera p95 pod 100 ms na bežných stránkach, generovanie PDF pod 2 s, HTTP/2 a kompresia.
- **Zdroje**: najmenší VPS Hetzner Cloud (Docker Compose: nginx, php-fpm, PostgreSQL), cieľovo do 512 MB RAM v pokoji. PostgreSQL nastavený na nízku spotrebu pamäte.
- **Integrita dát**: peniaze `numeric(12,2)`, hodiny `numeric(7,2)`, žiadny float. Vystavené doklady sú nemenné a kontroluje to serverová logika. Číslovanie je transakčné, bez medzier a bezpečné voči súbehu (zámok v DB).
- **Bezpečnosť**: HTTPS (Let's Encrypt), CSP, HSTS a ďalšie hlavičky, CSRF, secure a httpOnly cookies, tajomstvá v `.env` mimo gitu, šifrované TOTP tajomstvá.
- **Prenositeľnosť**: rovnaké Docker obrazy pre vývoj (WSL2) aj produkciu. Vo vývoji Mailpit.
- **Zálohy**: denný šifrovaný restic snapshot DB a PDF dokladov na serveri, rotácia 14 / 8 / 12, mesačný test obnovy; kópia mimo servera ručným pullom na PC (bez plateného úložiska, rozhodnutie vlastníka D-05). Postup v `docs/ops/backup.md`.
- **Testy**: PHPUnit povinne pre výpočty súm, číslovanie, zamykanie častí, kópiu údajov, kurzy a limity.
- **UX**: ovládateľné jednou rukou na iPhone, dotykové ciele aspoň 44 px, bez horizontálneho posúvania.
- **Závislosti**: produkčný SMTP je vlastný Stalwart (zvolený vo Phase 7, konfigurácia cez `app.env`). API kurzov (NBP, ECB, ČNB) môže byť nedostupné, preto je k dispozícii ručné zadanie.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| PostgreSQL namiesto MariaDB | `jsonb` pre kópie údajov, `numeric`, spoľahlivé transakčné zámky pre číslovanie | ✓ Good — v1.0 (JSONB snapshoty, advisory zámky, DB triggery na nemennosť) |
| Kópia údajov (snapshot) v JSONB pri vystavení, PDF sa generuje z kópie | Záruka nemennosti dokladov po zmene subjektu, klienta, sadzby či meny | ✓ Good — v1.0 (aj pregenerovanie 11.1 a meno klienta v prehľadoch 12.1 idú zo snapshotu) |
| Poľská živnosť je samostatný subjekt, nie verzia slovenského | Vlastný číselný rad, obrat, mena a obdobie pôsobenia. Staré doklady ostanú na starom subjekte | ✓ Good — v1.0 (model hotový, ostrý prechod v ďalšom milestone) |
| Jedna položka faktúry za každú fakturačnú časť | Faktúra slúži zároveň ako súhrn, výkaz pridáva odkazy na portál | ✓ Good — v1.0 |
| Číslovanie `RRRRMM###`, rad patrí subjektu, mesiac a rok z dátumu vystavenia | Požiadavka používateľa, reset poradia mesačne pri tokene `MM`, inak ročne | ✓ Good — v1.0 (riadkový zámok čítača, bez medzier) |
| Dobropis je vo V1 vždy celé storno a uvoľní zamknuté časti | Jednoduchosť, časti sa dajú fakturovať znova | ✓ Good — v1.0 (súbeh s úhradou ošetrený v quick 261110-kfy) |
| Limity obratu v tabuľke v nastaveniach (nie v kóde), obrat z vystavených faktúr podľa dátumu dodania | Limity sa menia každý rok, spôsob výpočtu sa dá nastaviť pre krajinu | ✓ Good — v1.0 (hodnoty čakajú na účtovníčku) |
| Režim fakturácie a príznak limitu na klientovi, editovateľné zákonné texty | Cezhraničné pravidlá nie sú overené (riziko R1), rozhodne účtovníčka | ⚠️ Revisit — technicky hotové, právne overenie účtovníčkou ešte chýba |
| Kurz podľa krajiny subjektu (PL: NBP, SK: ECB, CZ: ČNB) s ručným prepisom, uložený do kópie údajov | Prehľady a limity počítajú len z uložených prepočítaných súm, nikdy aktuálnym kurzom | ✓ Good — v1.0 |
| TOTP 2FA voliteľná pre všetkých, ponuka pri prvom prihlásení, vypnutie v GUI (heslo + kód), reset hesla e-mailom a zapamätanie zariadenia aj bez 2FA | Rozhodnutie vlastníka 2026-10-28, prekonáva povinnú 2FA z Phase 2 | ✓ quick 261028-mf3 |
| Shield + vlastná akcia TOTP. Ak Shield prekáža, vlastné prihlasovanie nad sessions CI4 | Shield nemá natívnu TOTP (riziko R4) | ✓ Good — v1.0 (Shield 1.4.1 s gateway akciou, vlastné prihlasovanie netreba) |
| Bootstrap 5.3 + htmx 2 + Alpine.js, bez SPA a build pipeline | Výkon, malý JS, jednoduchá PWA | ✓ Good — v1.0 (p95 51 ms, prísna CSP bez unsafe-eval) |
| Plánovacie dokumenty v slovenčine. V1 = P0 + P1 + P2 (všetky epiky E1–E12) | Rozhodnutie používateľa pri inicializácii | ✓ Good — v1.0 (90/90 požiadaviek) |
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
| Vlastný SMTP server Stalwart (`noreply@vostack.dev`) namiesto externého poskytovateľa | Kontrola nad doručovaním, SPF/DKIM/DMARC PASS | ✓ Good — Phase 7 |
| Vetvy `develop` → staging, `main` → produkcia so schválením vlastníka, staging s reseedom pri deployi | Testovanie iba na stagingu, produkcia nemení dáta testami | ✓ Good — quick 261028-fcr |
| Zálohy restic na serveri + ručný pull na PC, bez plateného off-site úložiska | Žiadny rozpočet na Storage Box, obnova overená na čistom prostredí | ⚠️ Revisit — ak sa objaví rozpočet na off-site úložisko |
| Úhrada na stornovanej faktúre blokovaná aj DB triggerom `payments_not_cancelled`, zdieľaný zámok `payments` pre úhrady, dobropisy a upomienky | Pravidlo D-15 nemôže obísť súbeh ani priamy INSERT | ✓ Good — quick 261110-kfy |

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
*Last updated: 2026-11-10 after v1.0 milestone*
