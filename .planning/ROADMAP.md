# Roadmap: Task Tracker (evidencia úloh a fakturácia)

## Prehľad

Cesta od prázdneho repozitára po nasadenú PWA, ktorá vystavuje správne a nemenné faktúry a výkazy. Fázy sú zoradené podľa závislostí: základné konvencie, prihlásenie so skorým HTTPS stagingom, master dáta, úlohy a časti, izolované fakturačné jadro, dokumenty, doručenie a úhrady, kurzy, dobropisy, limity, prehľady a nakoniec lokalizácia s produkčným hardeningom.

Granularita: fine (12 fáz). Režim projektu: mvp (každá fáza dodáva použiteľnú vertikálnu schopnosť).

## Fázy

- [x] **Phase 1: Základ a infraštruktúra** - Docker/WSL2, CI4, PostgreSQL roly, Money, Clock, htmx/CSP/CSRF vzory, spike mPDF (completed 2026-10-23)
- [x] **Phase 2: Identita a skoré nasadenie** - Prihlásenie s povinnou TOTP, PWA kostra a HTTPS staging otestovaný na reálnom iPhone (completed 2026-10-25) (2FA od 2026-10-28 voliteľná, rozhodnutie vlastníka)
- [x] **Phase 3: Master dáta (subjekty a klienti)** - Verziované subjekty, klienti, právny dôvod oslobodenia, audit zmien (completed 2026-10-25)
- [x] **Phase 4: Úlohy, fakturačné časti a dashboard** - Evidencia úloh, rozdelenie na časti po mesiacoch, dashboard, import z Excelu (completed 2026-10-26)
- [x] **Phase 5: Fakturačné jadro** - Atómové vystavenie nemennej faktúry, číslovanie, kópia údajov, uložené PDF so sha256 (completed 2026-10-27)
- [x] **Phase 6: Dokumenty faktúry a výkazu** - Právne profily, PDF faktúra a výkaz, QR platba, editovateľné zákonné texty (completed 2026-10-28)
- [x] **Phase 7: Doručenie, úhrady a upomienky** - E-mail s PDF, opätovné odoslanie, úhrady, po splatnosti, upomienka (completed 2026-10-29)
- [ ] **Phase 8: Meny a kurzy** - NBP/ECB/ČNB kurzy s ručným prepisom a uložením do kópie údajov
- [ ] **Phase 9: Dobropisy** - Celé storno s dôvodom, odkazom na originál a uvoľnením častí
- [ ] **Phase 10: Limity obratu** - Tabuľka limitov, výpočet obratu, upozornenia a predkontrola pri vystavení
- [ ] **Phase 11: Prehľady, exporty a audit** - Mesačné a ročné prehľady, CSV/XLSX, ZIP pre účtovníčku, prehliadač denníka, PC zoznamy
- [ ] **Phase 12: Lokalizácia a produkcia** - Preklady PL/CZ/EN, zálohy s overenou obnovou, archív dokladov, hardening a meranie výkonu

## Phase Details

### Phase 1: Základ a infraštruktúra

**Goal**: Vývojár (vlastník) spustí identické Docker prostredie vo WSL2, v ktorom sú zafixované konvencie, ktoré sa dodatočne opravujú draho (peniaze, čas, DB roly, preklady, bezpečné htmx vzory).
**Mode:** mvp
**Depends on**: Nič (prvá fáza)
**Requirements**: NFR-04, NFR-07, UI-01
**Success Criteria** (what must be TRUE):

  1. Jedným `docker compose up` sa vo WSL2 spustí nginx, php-fpm, PostgreSQL a Mailpit; migrácie bežia pod rolou `app_owner` a aplikácia pripojená ako `app_rw` nevie meniť ani mazať tabuľky určené ako insert-only.
  2. Základná stránka sa zobrazí responzívne na iPhone aj PC (Bootstrap, dotykové ciele aspoň 44 px, bez horizontálneho posúvania) a všetky texty idú cez `lang()`.
  3. Automatické testy dokazujú, že trieda `Money` počíta hodiny × sadzba so zaokrúhlením half-up na 2 desatinné miesta nad `numeric`, bez float, a že riadky a súčty sa zhodujú.
  4. htmx požiadavka s platným CSRF tokenom prejde, bez tokenu je odmietnutá, chyba validácie sa vráti ako 422 so zobrazenou správou a prísna CSP nepovoľuje inline skripty.
  5. Spike potvrdí, že mPDF pod PHP 8.5 (`E_ALL`) vygeneruje PDF s diakritikou (ł, ń, ě, ť) do 2 s a s nameraným využitím pamäte.

**Plans**: 10 plans (5 vĺn, walking skeleton: `SKELETON.md`)
Plans:
**Wave 1**

- [x] 01-01-PLAN.md — Predpoklady prostredia, schválenie balíkov, Dockerfile dev/prod, compose a CI4 4.7.5 kostra (vlna 1)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 01-02-PLAN.md — nginx HTTPS na work-tracker.loc s bezpečnostnými hlavičkami, Makefile, make smoke, Mailpit (vlna 2)
- [x] 01-03-PLAN.md — Money (bcmath, half-up) a Clock cez TDD (vlna 2)
- [x] 01-04-PLAN.md — Responzívny layout podľa makety, lokálne knižnice, lang() sk/en, CSP-bezpečné views (vlna 2)
- [x] 01-05-PLAN.md — DB roly app_owner/app_rw, provisioning, testovacia DB, make reset, testy oprávnení (vlna 2)
- [x] 01-06-PLAN.md — Spike mPDF pod PHP 8.5 (CLI aj FPM, diakritika, čas, pamäť) (vlna 2)
- [x] 01-07-PLAN.md — PHPStan level 6 s pravidlom proti float a času mimo Clock/Money, php-cs-fixer (vlna 2)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 01-08-PLAN.md — Ukážková kalkulačka /demo s htmx vzormi: CSRF filter, 422, Vary, bezpečné cookies (vlna 3)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 01-09-PLAN.md — Uloženie výpočtov do insert-only tabuľky ako app_rw, zoznam so súčtom, parita s PG (vlna 4)

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 01-10-PLAN.md — GitHub Actions CI, plošný make check, zápis rozhodnutí do PROJECT.md, koncová kontrola (vlna 5)

**UI hint**: yes
**Poznámky**: Štandardné vzory, research-phase netreba, okrem mPDF spiku. Rozhodnúť bcmath vs `brick/math` (odporúčanie bcmath) a výsledok mPDF spiku zapísať do PROJECT.md. Vo WSL2 dať `vendor/` a `writable/` do named volumes, `.gitattributes` s LF.

### Phase 2: Identita a skoré nasadenie

**Goal**: Vlastník sa prihlási heslom a povinnou TOTP 2FA na HTTPS stagingu a nainštalovanú PWA otestuje na reálnom iPhone, takže nič sa nevystavuje bez zabezpečeného prístupu (2FA od 2026-10-28 voliteľná, rozhodnutie vlastníka).
**Mode:** mvp
**Depends on**: Phase 1
**Requirements**: AUTH-01, AUTH-02, AUTH-03, AUTH-04, AUTH-05, AUTH-06, AUTH-07, AUTH-08, AUTH-09, PWA-01, NFR-02, NFR-03, NFR-08
**Success Criteria** (what must be TRUE):

  1. Používateľ sa prihlási e-mailom a heslom, pri prvom prihlásení naskenuje lokálne generovaný QR kód do autentifikátora a bez platného TOTP alebo záložného kódu sa nedostane na žiadnu stránku okrem prihlásenia; ten istý TOTP kód sa nedá použiť dvakrát.
  2. Používateľ získa 10 jednorazových záložných kódov, môže ich pregenerovať a použitý kód znova nefunguje; opakované chybné pokusy o heslo alebo TOTP sú obmedzené.
  3. Po „zapamätať zariadenie“ sa TOTP 30 dní nevyžaduje, zariadenia sa dajú zobraziť a odvolať a po odvolaní sa TOTP vyžaduje znova; obnova hesla e-mailom TOTP neobíde.
  4. Denník prihlásení ukazuje úspech/neúspech, IP, zariadenie a čas; model obsahuje roly `admin` a `accountant`.
  5. Staging na Hetzner VPS beží cez HTTPS (Let's Encrypt) s CSP, HSTS, secure a httpOnly cookies a na reálnom iPhone sa dá aplikácia pridať na plochu, spustí sa standalone, zobrazí offline stránku a po zapamätaní zariadenia nežiada prihlásenie častejšie ako raz za 30 dní.

**Plans**: 20 plans (12 vĺn, MVP vertikálne rezy)
Plans:
**Wave 1**

- [x] 02-01-PLAN.md — Schválenie balíkov (T-02-SC) a rozhodnutia vlastníka O-1..O-6 (checkpointy)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 02-02-PLAN.md — Príprava servera, Cloudflare, firewallu a GitHub secrets (bootstrap + runbook, akcie vlastníka)
- [x] 02-03-PLAN.md — Spike Shield + TOTP akcia, roly admin/accountant, Argon2id, spark user:create
- [x] 02-04-PLAN.md — nginx (realip CF, limit_req, sw.js/manifest, cert parametre), nginx obraz, compose.staging.yaml
- [x] 02-05-PLAN.md — Bezpečnostné e-maily: AppMailer (Mailpit / metadata-only log), SecurityNotifier

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 02-06-PLAN.md — Deny-by-default AuthGate, enumerácia rout, AuthTestCase
- [x] 02-07-PLAN.md — deploy.sh (pg_dump brána pred migráciou), smoke-staging, GHA build → GHCR → SSH deploy

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 02-08-PLAN.md — Prihlásenie heslom (auth layout, generická chyba, dummy hash), jazyk z Accept-Language

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 02-09-PLAN.md — Prvé nasadenie stagingu a overenie (HTTPS, hlavičky, realip, origin, pamäť)
- [x] 02-10-PLAN.md — Zámky 5/15 min (IP + účet) a insert-only denník prihlásení
- [x] 02-11-PLAN.md — PWA inštalácia: manifest, ikony, iOS meta, inštalačný panel

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 02-12-PLAN.md — Nastavenie TOTP (QR SVG, Sodium tajomstvo, aktivácia, pending 10 min)
- [x] 02-13-PLAN.md — Service worker a offline stránka

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 02-14-PLAN.md — Overenie TOTP pri prihlásení s atomickou replay ochranou

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 02-15-PLAN.md — Záložné kódy (zobrazené raz, .txt, potvrdenie, prihlásenie kódom)

**Wave 9** *(blocked on Wave 8 completion)*

- [x] 02-16-PLAN.md — Dôveryhodné zariadenia 30 dní, 2 h nečinnosť, odhlásenie podľa O-1

**Wave 10** *(blocked on Wave 9 completion)*

- [x] 02-17-PLAN.md — Viac → Zabezpečenie: 2FA, zariadenia s odvolaním, posledných 50 prihlásení
- [x] 02-18-PLAN.md — Obnova hesla e-mailom bez obídenia TOTP, spark user:password a user:reset-2fa

**Wave 11** *(blocked on Wave 10 completion)*

- [x] 02-19-PLAN.md — Zmena hesla a pregenerovanie záložných kódov s re-autentifikáciou

**Wave 12** *(blocked on Wave 11 completion)*

- [x] 02-20-PLAN.md — Nasadenie celej fázy, staging smoke, meranie pamäte, UAT na iPhone (vlastník)

**UI hint**: yes
**Poznámky**: **Potrebuje /gsd-research-phase** (Shield 1.4: pending stav, `ConditionalActionInterface`, remember-me, iOS cookies). Spike s časovým limitom, fallback: TOTP vo vlastnom filtri nad Shield, až potom vlastné prihlasovanie. Service worker nesmie cachovať HTML, `Vary: HX-Request`, CSRF bez regenerácie pri každej požiadavke. Vlastník: objednať Hetzner VPS (x86 vs ARM CAX), Storage Box.

### Phase 3: Master dáta (subjekty a klienti)

**Goal**: Vlastník spravuje svoje subjekty (s verziami údajov, bankovými účtami a nemennou krajinou a menou) a klientov so všetkým, čo neskôr vstúpi do kópie údajov faktúry; každá zmena sa zaznamená do denníka.
**Mode:** mvp
**Depends on**: Phase 2
**Requirements**: ENT-01, ENT-02, ENT-03, ENT-04, ENT-05, ENT-06, ENT-07, CLI-01, CLI-02, CLI-03, CLI-04, CLI-05, CLI-06, CLI-07, SET-02, AUD-01
**Success Criteria** (what must be TRUE):

  1. Používateľ vytvorí slovenský subjekt (EUR, neplatiteľ DPH) aj poľský subjekt (PLN) s obdobím pôsobenia, číselným radom, splatnosťou, IČ DPH/VAT-UE a viacerými bankovými účtami (IBAN, SWIFT) podľa meny; krajinu a menu po vytvorení zmeniť nejde.
  2. Zmena údajov subjektu (napr. adresy) vytvorí novú verziu „platné od“, história verzií je viditeľná, verzie sa nedajú zmazať a zmena nemení staršie platné obdobie.
  3. Ukončený subjekt je naďalej zobraziteľný a vybrateľný pre storno a opätovné odoslanie jeho dokladov.
  4. Používateľ pridá, upraví a archivuje klienta (bez mazania) s adresou, typom a hodnotou identifikátora, sadzbou, menou, jazykom, splatnosťou, odkazom na portál úloh, režimom fakturácie a príznakom „započítavať do limitu“.
  5. Právny dôvod oslobodenia od DPH je štruktúrované pole s kódom (napr. `ART_113_1`) v právnom profile a každá zmena subjektov, klientov a nastavení sa objaví v denníku zmien s hodnotami pred a po.

**Plans**: 10 plans (8 vĺn)
Plans:
**Wave 1**

- [x] 03-01-PLAN.md — audit_log insert-only, AuditLogger v transakcii, stĺpcový grant grantUpdateColumns, DbBool, MasterFixtures (vlna 1)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 03-02-PLAN.md — Vytvorenie SK/PL subjektu cez UI: entities, entity_versions, invoice_series, EntityService, verzia k dátumu, detail (vlna 2)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 03-03-PLAN.md — Zoznam subjektov, vstup z Viac, EntityDirectory (forSelect s ukončenými, activeAt) (vlna 3)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 03-04-PLAN.md — Nová verzia údajov „platné od“, história verzií so zmenami, Nová verzia z tejto (vlna 4)
- [x] 03-05-PLAN.md — Klienti: zoznam, pridanie, úprava, identifikátor, sadzba, režim fakturácie, e-maily v kópii (vlna 4)

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 03-06-PLAN.md — Upraviť a ukončiť subjekt, odmietnutie zmeny krajiny/meny, súhrnný test oprávnení (vlna 5)
- [x] 03-07-PLAN.md — Archivácia klienta a vzor odkazu na portál úloh so živou ukážkou (vlna 5)

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 03-08-PLAN.md — Bankové účty subjektu: IBAN mod-97, predvolený, archivácia, výber podľa meny (vlna 6)

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 03-09-PLAN.md — Číselný rad: SeriesFormat (TDD), SeriesService, formulár so živou ukážkou (vlna 7)

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 03-10-PLAN.md — Právne profily a predbežný seed textov, sekcia na detaile, dev seeder, záverečná kontrola fázy (vlna 8)

**UI hint**: yes
**Poznámky**: Štandardné vzory. Prvé použitie vzoru „GRANT + insert-only“. Seed `legal_texts` je len predbežný, znenie potvrdí účtovníčka. **Akcia: poslať otázky účtovníčke pri štarte tejto fázy** (limity, PL/SK B2B, kurz, retencia, dobropis, obsah dokladov, zoznam v research/SUMMARY.md). Vlastník vyberie SMTP poskytovateľa a DNS (SPF/DKIM/DMARC) pred Phase 7.

### Phase 4: Úlohy, fakturačné časti a dashboard

**Goal**: Vlastník eviduje úlohy klientov s nacenením, rozdeľuje ich na fakturačné časti po mesiacoch (aj čiastočne) a na mobile vidí dashboard s tým, čo treba tento mesiac fakturovať; reálne dáta z Excelu sú naimportované.
**Mode:** mvp
**Depends on**: Phase 3
**Requirements**: TSK-01, TSK-02, TSK-03, TSK-04, TSK-05, TSK-06, ALC-01, ALC-02, ALC-04, ALC-05, DSH-01, IMP-01
**Success Criteria** (what must be TRUE):

  1. Používateľ vytvorí úlohu (klient predvolený posledný použitý, URL, názov, nacenené hodiny, sadzba predvyplnená z klienta) a nacenená suma sa prepočítava už pri písaní; úlohu bez zamknutých častí môže upraviť aj zmazať.
  2. Pri „Označiť ako zapracovanú“ používateľ vyberie mesiac (Aktuálny / Ďalší / Iný) a hodiny (predvolený zostatok), vidí živý prepočet sumy a po potvrdení vznikne fakturačná časť; časť sa dá pridať aj k nezapracovanej úlohe a nezamknutú časť možno upraviť, presunúť do iného mesiaca alebo zmazať.
  3. Úloha ukazuje odvodený fakturačný stav (nevyfakturovaná / čiastočne / vyfakturovaná), rozdiel nacenených a fakturovaných hodín (napr. „+6 h“) a vyfakturované, naplánované a zostávajúce hodiny; zoznam „Aktuálne úlohy“ a filtre (klient, stav, mesiac, text) fungujú.
  4. Dashboard na iPhone ukazuje aktuálny fakturačný mesiac a klienta, dlaždice „Na fakturáciu tento mesiac“ a „V práci“, úlohy zoskupené podľa stavu, ukazovateľ priebehu a spodnú lištu Prehľad / Úlohy / Faktúry / Viac.
  5. Import úloh za 09 a 10/2026 z `docs/All4net-fakturovanie-prace.xlsx` má suchý beh s rozdielom proti súčtom v Exceli, „Zapracované = ÁNO“ vytvorí zapracovanú úlohu s nezamknutou časťou a opakovaný import nevytvorí duplikáty.

**Plans**: 13 plans (5 vĺn)
Plans:
**Wave 1**

- [x] 04-01-PLAN.md — Schéma tasks a billing_allocations, trigger alloc_guard, pohľad task_billing_v, grantDelete, fixtures (vlna 1)
- [x] 04-02-PLAN.md — Hours (bcmath), MoneyFormat, BillingMonth cez TDD (vlna 1)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 04-03-PLAN.md — PhpSpreadsheet (require-dev, checkpoint legitimity), ExcelReader s hyperlinkmi, ImportPlanner (suchý beh) (vlna 2)
- [x] 04-04-PLAN.md — TaskInput, TaskService (atómové zmazanie, zámok klienta), LastClient, zámok meny klienta (vlna 2)
- [x] 04-05-PLAN.md — AllocationService s atómovou podmienkou invoice_id IS NULL, kritérium E5 (vlna 2)
- [x] 04-06-PLAN.md — TaskFilter a TaskQueries: zoznam bez N+1, detail, súčty dashboardu po menách (vlna 2)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 04-07-PLAN.md — Formulár úlohy so živou sumou (BigInt), detail, zmazanie, aktívna navigácia Úlohy, CSS .work-* (vlna 3)
- [x] 04-08-PLAN.md — TaskService::markDone a reopen v jednej transakcii (vlna 3)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 04-09-PLAN.md — spark import:excel: suchý beh, idempotentný ostrý beh, runbook, checkpoint ostrého importu reálneho súboru (vlna 4)
- [x] 04-10-PLAN.md — Panel časti zospodu (pridať, upraviť, presunúť, zmazať), súhrn vyfakturované/naplánované/zostáva, 409 (vlna 4)
- [x] 04-12-PLAN.md — Zoznam Aktuálne úlohy s htmx filtrami a stavom v URL (vlna 4)

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 04-11-PLAN.md — Panel Označiť ako zapracovanú a návrat do V práci (vlna 5)
- [x] 04-13-PLAN.md — Dashboard variant B: dlaždice po menách, skupiny úloh, prepínač mesiaca a klienta (vlna 5)

**UI hint**: yes
**Poznámky**: Štandardné vzory. PhpSpreadsheet len v `require-dev`, import ako `spark` príkaz s idempotentným kľúčom, hyperlinky na Taskman. Zamykanie častí na serveri (ALC-03) sa dokončí vo Phase 5, keď vzniknú faktúry; tu je pripravený stĺpec `invoice_id` a atómová podmienka `WHERE invoice_id IS NULL`.

### Phase 5: Fakturačné jadro

**Goal**: Vlastník vyberie klienta a mesiac, skontroluje náhľad a vystaví faktúru, ktorá je atómovo vytvorená, správne očíslovaná, nemenná a uložená vrátane PDF so sha256; nič, čo sa zmení neskôr, ju nezmení.
**Mode:** mvp
**Depends on**: Phase 4
**Requirements**: INV-01, INV-02, INV-03, INV-04, INV-08, INV-09, INV-13, INV-14, INV-15, ALC-03, NFR-06
**Success Criteria** (what must be TRUE):

  1. Na obrazovke „Fakturácia mesiaca“ používateľ vyberie klienta, mesiac a subjekt, vidí náhľad nezamknutých častí bez pridelenia čísla, môže vyradiť časť do iného mesiaca a pri spätnom dátume vystavenia dostane upozornenie pred vystavením.
  2. Vystavená faktúra má jednu položku za každú časť s kódom DPH, dátum vystavenia, dodania (predvolene koniec mesiaca) a splatnosť; prvá faktúra 10/2026 je `202610001`, druhá `202610002`, prvá v 11/2026 `202611001`, bez medzier.
  3. 50 súbežných vystavení na dvoch samostatných spojeniach nevytvorí duplicitné ani preskočené číslo a zlyhanie neskoršieho kroku vystavenia vráti číslo aj uvoľní časti (jedna transakcia).
  4. Po vystavení zmena subjektu, klienta, sadzby alebo meny faktúru nezmení (kópia údajov v JSONB aj štruktúrované stĺpce), pokus o úpravu zamknutej časti je odmietnutý serverom aj databázou a zobrazenie uloženého PDF vráti presne tie isté bajty (zhodný sha256).
  5. Automatické testy (PHPUnit, vrátane testov pod rolou `app_rw`) potvrdzujú zhodu súčtov na obrazovke, vo faktúre a v exporte, číslovanie, zamykanie častí, kópiu údajov a test „zmeň všetko po vystavení a porovnaj bajty“.

**Plans**: 9 plans (5 vĺn)Plans:
**Wave 1**

- [x] 05-01-PLAN.md — Schéma invoices/lines/documents/counters, nemennosť v DB, FK zámku + lock_allocations(), invoice_status_v, vat_code, prestavba fixtures (vlna 1)
- [x] 05-02-PLAN.md — InvoiceCalculator (TDD), PdfFormat, popisky InvoicePdf (sk/en/pl/cs), mPDF renderer inv-1, testovacie dvojníky (vlna 1)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 05-03-PLAN.md — SnapshotBuilder, NumberAllocator (upsert), DocumentStore (bytea), InvoiceIssuer v jednej transakcii, rollback a idempotencia (vlna 2)
- [x] 05-04-PLAN.md — WR-07: spark invoices:lock-historical + beh na dev DB; scripts/backup.sh a docs/ops/backup.md (vlna 2)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 05-05-PLAN.md — Serverové kontroly (mena, ukončený subjekt, spätný dátum), InvoiceDraftService (náhľad), isFormatLocked (vlna 3)
- [x] 05-06-PLAN.md — Test súbehu 50 vystavení na 2 procesoch, test zámku čítača, test „zmeň všetko a porovnaj bajty“ (vlna 3)
- [x] 05-07-PLAN.md — Zoznam a detail faktúr, uložené PDF endpoint, navigácia Faktúry, CSS .inv-*, číslo faktúry pri zamknutej časti (vlna 3)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 05-08-PLAN.md — Obrazovka Fakturácia mesiaca: výber, náhľad, varovanie spätného dátumu, presun časti (vlna 4)

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 05-09-PLAN.md — Vystavenie z náhľadu: potvrdzovací panel, POST /invoices/issue, 409/422, zhoda súčtov obrazovka = faktúra = snapshot = PDF (vlna 5)

**UI hint**: yes
**Poznámky**: Najrizikovejšia fáza, izolovaná bez kurzov. **Potrebuje /gsd-research-phase** (zámky a atómové vystavenie, PDF v `bytea` vs súbor, render vo vnútri vs po transakcii, `transException` v CI4 4.7, determinizmus mPDF). Číslovanie jedným príkazom `INSERT … ON CONFLICT DO UPDATE … RETURNING`, nikdy `SEQUENCE` ani `MAX()+1`. Schéma musí byť od začiatku pripravená na dobropis (M6) a obsahovať všetky polia M1–M16. PDF je v tejto fáze funkčné minimum, finálne právne profily prídu vo Phase 6. NFR-06 sa priebežne dopĺňa aj v ďalších fázach (kurzy, limity). Skript zálohy sa začína už tu. Vlastník potvrdil: PDF je autoritatívny, spätný dátum povolený s varovaním. **WR-07 z Phase 4 (voľba C, 2026-10-26):** časti importované z riadkov „Fakturované = ÁNO“ sa zamknú na historickú externú faktúru (fakturované cez tretiu osobu), ktorá nespotrebuje číslo z vlastného radu; schéma faktúr to musí umožniť.

### Phase 6: Dokumenty faktúry a výkazu

**Goal**: Každá vystavená faktúra má profesionálne PDF so zákonnými náležitosťami podľa krajiny, režimu a jazyka, QR kódom na platbu a nemenný PDF výkaz práce, a zákonné texty sa dajú upraviť bez zásahu do kódu.
**Mode:** mvp
**Depends on**: Phase 5
**Requirements**: INV-05, INV-11, INV-12, REP-01, REP-02, SET-01
**Success Criteria** (what must be TRUE):

  1. PDF faktúry vzniká z uloženej kópie údajov v jazyku klienta (alebo subjektu) a obsahuje len náležitosti podľa profilu: SK neplatiteľ („Nie som platiteľ DPH.“ a údaj o zápise), PL art. 113 a EÚ B2B s príslušnou zákonnou poznámkou.
  2. Faktúra ukazuje formu úhrady (predvolene prevod), voliteľnú poznámku (napr. o úrokoch z omeškania), variabilný symbol rovný číslu faktúry a QR kód na platbu (SK PAY by square, PL/CZ zodpovedajúci štandard) vygenerovaný z uloženej kópie.
  3. Ku každej faktúre vznikne PDF výkaz s hlavičkou (subjekt, klient, obdobie), tabuľkou (odkaz na portál, názov, nacenené hodiny, „16 z 40 h“, suma), súčtami a poznámkou o DPH; obsahuje presne tie časti, ktoré sú na faktúre, a je uložený nemenne.
  4. Používateľ upraví zákonné texty a poznámky pre každú krajinu, DPH režim, režim fakturácie a jazyk v nastaveniach; nové znenie ovplyvní len doklady vystavené po zmene.

**Plans**: 9 plans (9 vĺn, sekvenčne)Plans:
**Wave 1**

- [x] 06-01-PLAN.md — Faktúra inv-2 so zákonnými náležitosťami podľa profilu: LegalProfile, snapshot schema 2, RendererRegistry, inv-1 nedotknuté (vlna 1)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 06-02-PLAN.md — Obsah výkazu práce v snapshote (ReportRows, ReportPdf vo 4 jazykoch) a renderer rep-1 so šablónou (vlna 2)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 06-03-PLAN.md — Výkaz pri vystavení v tej istej transakcii, povinný report v DB (trigger), nemennosť oboch PDF, tlačidlá Faktúra PDF a Výkaz PDF na detaile (vlna 3)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 06-04-PLAN.md — Forma úhrady a poznámka faktúry v náhľade (predvyplnená z invoice_note), upozornenie na predbežné znenie (vlna 4)

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 06-05-PLAN.md — QR PAY by square pre SK klientov (xz LZMA, bacon SVG), rollback pri zlyhaní QR (vlna 5)

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 06-06-PLAN.md — QR Platba (SPAYD) pre CZ a ZBP pre PL klientov, upozornenie v náhľade pri príliš dlhom čísle pre ZBP (vlna 6)

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 06-07-PLAN.md — Nastavenia → Zákonné texty: zoznam a úprava znenia s revíziou, auditom a stĺpcovými oprávneniami (vlna 7)

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 06-08-PLAN.md — Pridanie variantu znenia pre jazyk alebo režim fakturácie a zobrazenie použitého (náhradného) znenia (vlna 8)

**Wave 9** *(blocked on Wave 8 completion)*

- [x] 06-09-PLAN.md — spark documents:samples (vzorové PDF pre staging a účtovníčku), meranie výkonu (skupina performance), ručný UAT QR (vlna 9)

**UI hint**: yes
**Poznámky**: **Potrebuje /gsd-research-phase** (právne profily, SMTP, mPDF fonty a pamäť, PAY by square vyžaduje LZMA). **Závislosť od účtovníčky:** finálne znenie zákonných textov a náležitostí (§ 3a ObZ, art. 106e, art. 113 ust. 1 vs 9, reverse charge pri PL subjekt → SK B2B) sa potvrdzuje až s ňou; texty sú konfigurácia v DB, nie kód. Nasadiť na staging a posielať si vzorové PDF.

### Phase 7: Doručenie, úhrady a upomienky

**Goal**: Vlastník odošle faktúru s výkazom klientovi jedným krokom, opätovne ju odošle z mobilu, eviduje úhrady a vidí, čo je po splatnosti, a pošle upomienku jedným klikom.
**Mode:** mvp
**Depends on**: Phase 6
**Requirements**: MAIL-01, MAIL-02, MAIL-03, MAIL-04, PAY-01, PAY-02, PAY-03, INV-06
**Success Criteria** (what must be TRUE):

  1. Po vystavení používateľ odošle faktúru a výkaz e-mailom klientovi (PDF prílohy, šablóna v jazyku klienta) a v systéme vidí dátum a adresátov; zlyhanie e-mailu nezruší ani nezmení vystavenú faktúru.
  2. Používateľ znova odošle uložené PDF na ľubovoľný e-mail aj z mobilu a klient dostane presne tie isté bajty.
  3. Používateľ upraví šablóny e-mailov pre každý jazyk a odošle upomienku jedným kliknutím s PDF faktúry; dátum odoslania sa uloží.
  4. Faktúra prechádza stavmi vystavená, odoslaná, uhradená; „po splatnosti“ sa odvodzuje a dashboard ukazuje zoznam faktúr po splatnosti.
  5. Používateľ zaznamená jednu alebo viac úhrad (dátum, voliteľne suma) a stav „čiastočne uhradená“ a zostatok sa odvodia zo súčtu úhrad.

**Plans**: 14 plans (8 vĺn)
Plans:
**Wave 1**

- [x] 07-01-PLAN.md — Spike odosielania: AppEmail (Message-ID, BCC, obálka), AppMailer::sendMessage, overenie cez Mailpit (vlna 1)
- [x] 07-02-PLAN.md — Šablóny e-mailov v DB so seedom pre 4 jazyky, vykreslenie cez whitelist, jednotné názvy PDF (vlna 1)
- [x] 07-03-PLAN.md — Infra: produkčný php do siete mail (overlay, deploy.sh, CI), dokumentácia SMTP a odosielateľov (vlna 1)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 07-04-PLAN.md — Odoslanie (dáta a služba): invoice_sends a storná, stav Odoslaná v pohľade, InvoiceMailer (prílohy z DB, idempotencia, zápis po úspechu), SenderIdentity (vlna 2)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 07-05-PLAN.md — Odoslať klientovi (UI): kontrolér a sheet, stav a história na detaile, CSS fázy (vlna 3)
- [x] 07-07-PLAN.md — Identita odosielateľa pri subjekte (From, Reply-To, BCC, allowlist domén) (vlna 3)
- [x] 07-09-PLAN.md — Úhrady (dáta a služba): payments a storná, zostatok a stavy v pohľade, PaymentService, InvoiceStatusQueries (vlna 3)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 07-06-PLAN.md — Odoslať znova na ľubovoľnú adresu s rovnakými bajtmi, editor adresátov, história odoslaní (vlna 4)

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 07-08-PLAN.md — Nastavenia → Šablóny e-mailov: úprava s revíziou, whitelist premenných, náhľad (vlna 5)
- [x] 07-10-PLAN.md — Úhrady (UI): sheet zápisu a storna, karta Úhrady, odznaky stavu a Po splatnosti na detaile (vlna 5)

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 07-11-PLAN.md — Ručné Označiť ako odoslanú a jeho storno (vlna 6)
- [x] 07-12-PLAN.md — Dashboard Po splatnosti a zoznam faktúr so stavmi a filtrom (vlna 6)

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 07-13-PLAN.md — Upomienka jedným klepnutím z dashboardu a detailu (šablóna reminder, len faktúra, vlákno) (vlna 7)

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 07-14-PLAN.md — Staging seed modul Deliveries, 07-HUMAN-UAT.md a ručné overenie fázy na iPhone (vlna 8)

**UI hint**: yes
**Poznámky**: **Závislosť od vlastníka:** zvolený produkčný SMTP poskytovateľ a DNS SPF/DKIM/DMARC pre odosielateľov SK a PL. E-mail a HTTP volania nikdy vo vnútri transakcie vystavenia. BCC archív a `Message-ID` pre dohľadanie. Stavy sú odvodené SQL pohľadom, `invoices` ostáva insert-only.

### Phase 8: Meny a kurzy

**Goal**: Vlastník vystaví faktúru v inej mene než je mena subjektu (napr. PL subjekt, EUR klient) s kurzom z oficiálneho zdroja alebo ručne zadaným, ktorý je uložený vo faktúre a použitý všade jednotne.
**Mode:** mvp
**Depends on**: Phase 7
**Requirements**: CUR-01, CUR-02, CUR-03, CUR-05
**Success Criteria** (what must be TRUE):

  1. V náhľade faktúry s menou odlišnou od meny subjektu sa automaticky načíta kurz (PL: NBP, posledný pracovný deň pred dátumom dodania; SK: ECB; CZ: ČNB) a používateľ ho môže ručne prepísať pred vystavením.
  2. Pri nedostupnom API kurzov aplikácia vyzve na ručné zadanie a vystavenie nie je blokované.
  3. Kurz, zdroj, dátum a prepočítaná suma v mene subjektu sú uložené v kópii údajov a obe sumy sú vytlačené na PDF; neskoršia zmena kurzu v zdroji doklad nezmení.
  4. Dátum, ku ktorému sa kurz hľadá (predvolene dátum dodania), sa dá nastaviť v právnom profile krajiny a podporované meny (EUR, PLN, CZK) sa dajú rozšíriť bez zmeny modelu.

**Plans**: 10 plans (8 vĺn: 1: 08-01, 08-02 · 2: 08-03 · 3: 08-04 · 4: 08-05, 08-08 · 5: 08-10 · 6: 08-06 · 7: 08-07 · 8: 08-09)
Plans:
**Wave 1**

- [ ] 08-01-PLAN.md — Tabuľka currencies, FK namiesto CHECK, formuláre čítajú meny z DB (CUR-01)
- [ ] 08-02-PLAN.md — TDD: FxConverter, FxBoundDate, RateQuote/FxQuote, FxFormat

**Wave 2** *(blocked on Wave 1 completion)*

- [ ] 08-03-PLAN.md — TDD: poskytovatelia NBP, ECB, ČNB na fixtúrach + HTTP klient

**Wave 3** *(blocked on Wave 2 completion)*

- [ ] 08-04-PLAN.md — fx_country_settings, exchange_rate_cache a FxService (cache, výpadok, retry)

**Wave 4** *(blocked on Wave 3 completion)*

- [ ] 08-05-PLAN.md — Snapshot fx (schema 3), popisky v 4 jazykoch, PDF inv-2 s oboma sumami
- [ ] 08-08-PLAN.md — Nastavenia → Kurz mien podľa krajiny (CUR-05) s auditom

**Wave 5** *(blocked on Wave 4 completion)*

- [ ] 08-10-PLAN.md — Vystavenie s kurzom iba z cache, stĺpce fx_*, CHECK konzistencie, audit, assertFx

**Wave 6** *(blocked on Wave 5 completion)*

- [ ] 08-06-PLAN.md — Kurz v náhľade faktúry (.fx-card), potvrdenie a vystavenie z UI

**Wave 7** *(blocked on Wave 6 completion)*

- [ ] 08-07-PLAN.md — Ručný prepis kurzu, nedostupné API, Skúsiť znova

**Wave 8** *(blocked on Wave 7 completion)*

- [ ] 08-09-PLAN.md — Druhá suma na detaile a v zozname, test nemennosti, staging seed

**Poznámky**: **Potrebuje /gsd-research-phase** (SDMX parametre ECB, limit rozsahu NBP, časy publikácie). NBP cez rozsah `D-10..D-1` a posledný záznam, ECB je „za 1 EUR“ (delí sa), ČNB má `amount`. Kurz sa načíta v náhľade, nie sieťovým volaním v transakcii. **Závislosť od účtovníčky:** rozhodujúci dátum kurzu (dodanie vs vystavenie) a pravidlo zaokrúhľovania, predvolená hodnota je len konfigurácia.

### Phase 9: Dobropisy

**Goal**: Vlastník opraví chybnú faktúru vystavením dobropisu (celé storno), ktorý je rovnako nemenný, a časti z nej sa uvoľnia na opätovnú fakturáciu.
**Mode:** mvp
**Depends on**: Phase 8
**Requirements**: INV-07, INV-10
**Success Criteria** (what must be TRUE):

  1. Z vystavenej faktúry používateľ vystaví dobropis s povinným dôvodom a typom opravy; dobropis má vlastný rad alebo prefix, vlastný dátum vystavenia a odkaz na číslo a dátum pôvodnej faktúry.
  2. Dobropis obsahuje záporné položky celej pôvodnej faktúry (aj pri cudzej mene s uloženým kurzom), pôvodná faktúra sa zobrazí ako stornovaná a jej PDF sa nezmení.
  3. Časti zo stornovanej faktúry sa uvoľnia a dajú sa fakturovať znova do iného mesiaca; storno je možné aj pre ukončený subjekt.
  4. Dobropis má vlastné uložené PDF so sha256 a dá sa odoslať e-mailom rovnako ako faktúra.

**Plans**: TBD
**Poznámky**: Štruktúra dobropisu je pripravená už vo Phase 5. **Závislosť od účtovníčky:** PL dobropis (`PrzyczynaKorekty`, číslo KSeF pôvodnej faktúry) a obdobie, v ktorom sa dobropis odpočíta od obratu (predvolene obdobie pôvodnej faktúry).

### Phase 10: Limity obratu

**Goal**: Vlastník vidí, koľko z ročného limitu obratu za každý subjekt čerpá, dostane upozornenie včas a pred vystavením faktúry sa dozvie, či by prekročila prah.
**Mode:** mvp
**Depends on**: Phase 9
**Requirements**: LIM-01, LIM-02, LIM-03, LIM-04, LIM-05, CUR-04
**Success Criteria** (what must be TRUE):

  1. Používateľ upraví tabuľku limitov v nastaveniach (krajina, rok, názov, suma, mena, spôsob výpočtu, prahy upozornenia) bez zásahu do kódu; počiatočné hodnoty SK a PL sú len predvyplnený návrh.
  2. Obrat za subjekt a rok sa počíta z vystavených faktúr podľa dátumu dodania mínus dobropisy, len z klientov s príznakom „započítavať“, v mene subjektu iba z uložených prepočítaných súm; pre subjekt a rok sa dá zadať počiatočný obrat.
  3. Spôsob výpočtu sa dá zvoliť: kalendárny rok, kĺzavých 12 mesiacov alebo pomerný prvý rok, a zobrazí sa vzorec aj použitá hodnota.
  4. Dashboard upozorní pri 80 % a 100 % limitu aj vtedy, keď pre aktuálny rok chýba riadok limitu (použije sa posledný známy).
  5. Náhľad faktúry upozorní, ak by jej vystavenie prekročilo prah limitu.

**Plans**: TBD
**UI hint**: yes
**Poznámky**: **Potrebuje /gsd-research-phase.** **Silná závislosť od účtovníčky:** SK limit (50 000 € kalendárny rok vs 49 790 € kĺzavo, prah 62 500 €), PL 240 000 zł (rozhodujúci dátum, pomerný prvý rok, započítanie EÚ B2B). Do potvrdenia sa nesmie seedovať jedna hodnota ako pravda, zobrazuje sa prísnejšia hodnota so vzorcom. Obrat číta denormalizované stĺpce zapísané pri vystavení (`total_entity_ccy`, `counts_toward_limit`, `turnover_date`), nikdy JSONB ani živé nastavenia klienta.

### Phase 11: Prehľady, exporty a audit

**Goal**: Vlastník vidí mesačné a ročné prehľady, exportuje dáta do CSV a XLSX, pripraví balík pre účtovníčku, prehliada denník zmien a na PC pracuje v prehľadných tabuľkových zoznamoch.
**Mode:** mvp
**Depends on**: Phase 10
**Requirements**: RPT-01, RPT-02, RPT-03, AUD-02, UI-03
**Success Criteria** (what must be TRUE):

  1. Mesačný a ročný prehľad ukazuje vyfakturované a uhradené sumy aj po klientoch v mene subjektu a súčty sa zhodujú s faktúrami, výkazmi a dashboardom.
  2. Prehľady a zoznam faktúr sa dajú exportovať do CSV (`;` + BOM) a XLSX a bunka začínajúca vzorcom (`=`, `+`, `-`, `@`) sa neotvorí v Exceli ako vzorec.
  3. Export balíka pre účtovníčku za zvolené obdobie vytvorí ZIP s PDF dokladmi a CSV zoznamom.
  4. Používateľ prehliada denník zmien len na čítanie s filtrami (entita, používateľ, čas, akcia) a vidí hodnoty pred a po.
  5. Na PC sú dostupné tabuľkové zoznamy úloh a faktúr, obrazovka „Fakturácia mesiaca“ a nastavenia (subjekty, klienti, číslovanie, limity, šablóny, zákonné texty, používatelia, 2FA, zariadenia).

**Plans**: TBD
**UI hint**: yes
**Poznámky**: Štandardné vzory (OpenSpout na XLSX). Test zhody súčtov (NFR-04) rozšíriť o prehľady a exporty. Plné UI pre rolu `accountant` je v2.

### Phase 12: Lokalizácia a produkcia

**Goal**: Aplikácia je dostupná v SK, PL, CZ a EN a je pripravená na ostré používanie: zálohy s overenou obnovou, archív dokladov, nemožnosť mazať doklady, hardening a splnený výkonnostný cieľ na cieľovom VPS.
**Mode:** mvp
**Depends on**: Phase 11
**Requirements**: UI-02, NFR-01, NFR-05, NFR-09
**Success Criteria** (what must be TRUE):

  1. Používateľ prepne jazyk rozhrania medzi SK, PL, CZ a EN a čísla, dátumy a meny sa zobrazia podľa jazyka; test parity kľúčov potvrdzuje, že žiadny preklad nechýba.
  2. Denný šifrovaný `pg_dump` a PDF doklady idú mimo servera s rotáciou 14 denných / 8 týždenných / 12 mesačných, upozornenie pri vynechanej zálohe funguje a zdokumentovaná obnova na čistom prostredí bola úspešne vyskúšaná s reálnymi dátami.
  3. Doklady sa nedajú zmazať ani DB oprávneniami a raz ročne vznikne archív dokladov pokrývajúci zákonnú dobu uchovávania.
  4. Na cieľovom VPS je p95 odozvy bežných stránok pod 100 ms, PDF sa generuje pod 2 s, aplikácia sa drží cieľovo do 512 MB RAM v pokoji a beží HTTP/2 s kompresiou, OPcache a dlhou cache statických súborov.

**Plans**: TBD
**UI hint**: yes
**Poznámky**: Štandardné vzory, overiť len podmienky Hetzner Storage Box (port, append-only). Prune zálohy spúšťať mimo produkčného servera alebo s oddeleným kľúčom; kópia hesla repozitára a `encryption.key` mimo servera. **Závislosť od účtovníčky:** doba uchovávania (SK 10 rokov, PL podľa účtovníčky). Súčasťou je CLI reset 2FA a meranie `docker stats` a p95.

## Progress

**Poradie vykonania:** fázy sa vykonávajú v číselnom poradí 1 → 2 → ... → 12 (desatinné fázy 2.1, 2.2 sú urgentné vsuvky).

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Základ a infraštruktúra | 10/10 | Complete   | 2026-10-23 |
| 2. Identita a skoré nasadenie | 20/20 | Complete    | 2026-10-25 |
| 3. Master dáta (subjekty a klienti) | 10/10 | Complete    | 2026-10-25 |
| 4. Úlohy, fakturačné časti a dashboard | 13/13 | Complete    | 2026-10-26 |
| 5. Fakturačné jadro | 9/9 | Complete    | 2026-10-27 |
| 6. Dokumenty faktúry a výkazu | 9/9 | Complete    | 2026-10-28 |
| 7. Doručenie, úhrady a upomienky | 14/14 | Complete    | 2026-10-29 |
| 8. Meny a kurzy | 0/0 | Not started | - |
| 9. Dobropisy | 0/0 | Not started | - |
| 10. Limity obratu | 0/0 | Not started | - |
| 11. Prehľady, exporty a audit | 0/0 | Not started | - |
| 12. Lokalizácia a produkcia | 0/0 | Not started | - |
