# Phase 1: Základ a infraštruktúra - Context

**Gathered:** 2026-10-23
**Status:** Ready for planning

<domain>
## Phase Boundary

Vlastník spustí jedným `make up` (pod ním `docker compose up`) identické Docker prostredie vo WSL2 (nginx, php-fpm, PostgreSQL 18, Mailpit) na `https://work-tracker.loc`. V prostredí sú od začiatku zafixované konvencie, ktoré by sa neskôr opravovali draho:

- DB roly `app_owner` (migrácie) a `app_rw` (aplikácia, bez UPDATE/DELETE na insert-only tabuľkách),
- trieda `Money` (bez float, half-up na 2 desatinné miesta),
- služba `Clock`,
- všetky texty cez `lang()`,
- responzívny základný layout (Bootstrap 5.3),
- bezpečné htmx vzory (CSRF, 422, CSP, `Vary: HX-Request`),
- spike mPDF pod PHP 8.5.

Patrí sem aj vývojový workflow: Makefile, testovacia DB, GitHub Actions CI, PHPStan a cs-fixer.

Požiadavky: NFR-04, NFR-07, UI-01. Mimo rozsahu: prihlásenie a TOTP, PWA manifest a SW, staging (Phase 2), doménové tabuľky (Phase 3+).

</domain>

<decisions>
## Implementation Decisions

### Vývojový workflow: príkazy
- **D-01:** Všetky bežné príkazy idú cez **Makefile** v koreni repozitára, ktorý obaluje `docker compose exec`. Minimálne ciele sú `up`, `down`, `shell`, `composer ARGS=…`, `spark ARGS=…`, `migrate`, `reset`, `test`, `stan`, `cs`, `cs-fix`, `check` (stan + cs + test), `certs`. Ciele sú zdokumentované v README (`make help`).

### Vývojová adresa a HTTPS
- **D-02:** Dev beží na **HTTPS s vlastnou doménou `work-tracker.loc`**. Doménu si vlastník pridá do Windows `hosts` (127.0.0.1). Certifikát generuje **mkcert**.
- **D-03:** CA z mkcert je dôveryhodná v **prehliadači vo Windows** (mkcert.exe) **aj vo WSL** (kópia CA do WSL trust store), aby curl a nástroje vo WSL prešli bez `-k`. Certifikáty sú mimo gitu (`.gitignore`). Cieľ `make certs` a krok po kroku v README.
- **D-04:** Keďže dev beží na HTTPS, cookies sú `secure` a `httpOnly` už vo vývoji a bezpečnostné hlavičky (CSP, HSTS s krátkym `max-age` v dev) sa testujú rovnako ako v produkcii. Konfigurácia nginx pre dev a prod sa líši iba zdrojom certifikátu (mkcert vs Let's Encrypt).

### CI
- **D-05:** **GitHub Actions** (repo `voslarA4N/task-tracker`), spustenie na push a PR. Kroky: build PHP obrazu, PHPUnit proti PostgreSQL 18 (service container, s rolami `app_owner` a `app_rw`), PHPStan, cs-fixer v režime `--dry-run`, kontrola, že `preload.php` nezhodí PHP (`php -d opcache.preload=… -r 'echo 1;'`).

### Kvalita kódu
- **D-06:** **PHPStan** s `codeigniter/phpstan-codeigniter` (level 6 ako východisko) a vlastným pravidlom alebo grep kontrolou, ktorá zakáže `date()`, `time()`, `new DateTime*()` a `float` pretypovanie na peniaze mimo `Clock`/`Money`. **php-cs-fixer** cez `codeigniter4/devkit`. Oboje sa spúšťa cez `make` aj v CI.
- **D-07:** **Žiadne git hooky.** Lokálne sa spúšťa `make check` a CI stráži pri pushi (commity cez WSL/9p ostanú rýchle).

### Testovacia databáza
- **D-08:** PHPUnit beží proti **samostatnej DB `task_tracker_test` v tom istom PostgreSQL kontajneri**, s rovnakými rolami. Pred behom sa zmigruje nanovo ako `app_owner` a testy bežia ako `app_rw`, aby sa overili oprávnenia. Dev dáta sa testami nikdy nedotknú.

### Dev dáta
- **D-09:** `make reset` zmaže a znova zmigruje dev DB a spustí **dev seeder**. Vo Phase 1 je takmer prázdny a v ďalších fázach pribudnú ukážkové subjekty a klienti. Seeder je chránený tak, aby nikdy nebežal v `production` prostredí.

### Claude's Discretion
Vlastník tieto oblasti nevybral na diskusiu. Planner rozhodne podľa odporúčaní z výskumu:
- **Layout a vzhľad:** základný layout sa drží makiet `docs/mockups/mobile-home.html` (Bootstrap primárna `#0d6efd`, pozadie `#f5f6f8`, systémový font `-apple-system, system-ui`), safe-area, `100dvh`, vstupy aspoň 16 px, dotykové ciele aspoň 44 px. Vo Phase 1 stačí kostra layoutu (hlavička, obsah, miesto pre spodnú lištu) a ukážková stránka, ktorá predvedie htmx vzory (formulár s 422, CSRF). Tmavý režim nie je požiadavkou, voliteľne cez Bootstrap `data-bs-theme`, ak to nič nestojí.
- **Peniaze:** **bcmath** (`bcround` z PHP 8.4+ s half-up), nie `brick/math`, ak testy potvrdia správne half-up aj pre záporné hodnoty (dobropisy). Rozhodnutie sa zapíše do PROJECT.md Key Decisions. `Money` je immutable hodnotový objekt nad decimal reťazcom. Pravidlo: riadok = half-up(hodiny × sadzba, 2), súčet = súčet zaokrúhlených riadkov.
- **Čas:** jedna služba `Clock` (injektovaná, v testoch zmrazená). Jedno časové pásmo `Europe/Bratislava` pre aplikáciu (SK aj PL sú v CET/CEST). `TZ`, `date.timezone`, CI4 `appTimezone` a PG `timezone` sú nastavené explicitne a zhodne.
- **mPDF spike:** pripnutý font vložený do repozitára, predvolene **DejaVu Sans** (alebo Noto Sans, ak spike ukáže lepší výsledok), overenie ł, ń, ě, ť, ž, ô. Vypnuté `useSubstitutions`, `autoLangToFont` a `autoScriptToLang`, `tempDir` na volume. Spike vygeneruje jednoduchú vzorovú faktúru s tabuľkou pod `E_ALL`, zmeria čas (cieľ < 2 s, aj studený štart s cache fontov) a pamäť. Výsledok sa zapíše do PROJECT.md.
- Mapovanie portov (443 a 80 s presmerovaním, Mailpit UI na `:8025`), štruktúra `docker/` adresára, presné názvy cieľov v Makefile.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Rozsah a požiadavky
- `.planning/ROADMAP.md` § Phase 1: cieľ, kritériá úspechu, poznámky (bcmath vs brick/math, named volumes, LF)
- `.planning/REQUIREMENTS.md`: NFR-04, NFR-07, UI-01
- `.planning/PROJECT.md`: obmedzenia (stack, výkon, pamäť 512 MB, integrita dát), Key Decisions
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` § 7 (UI, mobil, PWA) a § 8 (technológie, prostredie). **Pri rozpore so spec platí spec.**
- `docs/PRD.md`: zdrojové požiadavky s kritériami prijatia

### Stack a infraštruktúra
- `CLAUDE.md` (Technology Stack): verzie, Docker obrazy, ladenie PG/FPM/OPcache, nginx hlavičky a CSP, WSL2 poznámka
- `.planning/research/STACK.md`: plné zdôvodnenie verzií a knižníc
- `.planning/research/ARCHITECTURE.md`: štruktúra `app/` (Filters, Libraries/Platform), DB roly `app_owner`/`app_rw` (~r. 230), Money (~r. 304), Vzor 5 htmx fragmenty (~r. 311), i18n (~r. 394), poradie budovania (~r. 436)
- `.planning/research/PITFALLS.md`: Pasca 1 (float), Pasca 7 (Clock/TZ), Pasca 11 (htmx + CSRF), Pasca 13 (mPDF pamäť), Pasca 14 (PG na 512 MB), Pasca 19 (WSL2 pod `/mnt/c`)
- `.planning/research/SUMMARY.md`: otvorené body 9 (knižnica na peniaze) a 10 (CSRF s htmx)

### UI
- `docs/mockups/mobile-home.html`: farby, typografia a štýl kariet pre základný layout
- `docs/mockups/task-flows.html`: mobilné toky (len kontext pre layout)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- Žiadne. Repozitár obsahuje len plánovacie dokumenty a makety (greenfield). Kostra vznikne z `codeigniter4/appstarter` v4.7.5 (obsahuje `preload.php`).

### Established Patterns
- Zatiaľ žiadne. Táto fáza ich zakladá a ďalšie fázy na nich stoja.
- `.gitignore` už obsahuje `.env`, `/vendor/`, `/writable/`, `*.xlsx`, `.superpowers/`. Treba doplniť certifikáty z mkcert.

### Integration Points
- GitHub remote `origin` = `https://github.com/voslarA4N/task-tracker.git` (pre Actions).
- Projekt leží na `/mnt/c/personal-projects/task-tracker` (9p mount), preto `vendor/` a `writable/` idú do named volumes.

</code_context>

<specifics>
## Specific Ideas

- Vývojová doména je presne **`work-tracker.loc`** (nie `task-tracker`), záznam vo Windows `hosts`.
- mkcert CA má fungovať v Chrome/Edge vo Windows aj v curl/PHP vo WSL.

</specifics>

<deferred>
## Deferred Ideas

None. Diskusia ostala v rozsahu fázy.

</deferred>

---

*Phase: 01-zaklad-a-infrastruktura*
*Context gathered: 2026-10-23*
