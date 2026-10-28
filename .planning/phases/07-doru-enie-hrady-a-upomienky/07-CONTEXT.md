# Phase 7: Doručenie, úhrady a upomienky - Context

**Gathered:** 2026-10-29
**Status:** Ready for planning (otvorené otázky O-1 až O-3 rozhodol vlastník 2026-10-29, D-27 až D-29)

<domain>
## Phase Boundary

Vlastník po vystavení odošle klientovi **jedným krokom** e-mail s oboma uloženými PDF (faktúra `inv-2` a výkaz `rep-1`) zo šablóny v jazyku dokladu, kedykoľvek (aj z iPhonu) faktúru **znova odošle** na ľubovoľnú adresu s presne tými istými bajtmi, **zaznamená jednu alebo viac úhrad** (dátum, voliteľne suma), vidí **faktúry po splatnosti** na dashboarde a pošle **upomienku jedným kliknutím** s PDF faktúry. Šablóny e-mailov (faktúra, upomienka) sa upravujú v nastaveniach pre každý jazyk. Stav faktúry (vystavená, odoslaná, čiastočne uhradená, uhradená, po splatnosti) sa **odvodzuje SQL pohľadom** z udalostí; `invoices` ostáva insert-only.

V tejto fáze: MAIL-01, MAIL-02, MAIL-03, MAIL-04, PAY-01, PAY-02, PAY-03, INV-06 (okrem stavu „stornovaná“, ktorý dodá Phase 9).

Mimo tejto fázy: stav „stornovaná“ a dobropis (Phase 9, pohľad sa len rozšíri), kurz a „uhradené v mene subjektu“ (Phase 8 a 11), prehľady vyfakturované/uhradené a exporty (Phase 11), automatické upomienky (anti-feature), napojenie na banku (mimo V1), bounce/webhook spracovanie (vlastný Stalwart nemá webhook, mimo V1).

</domain>

<decisions>
## Implementation Decisions

Vlastník rozhodol: rozhodnutia sa berú zo špecifikácie, PRD, REQUIREMENTS.md a `.planning/research/`, bez ďalšej diskusie (pamäť „discuss-phase-from-spec“). Medzery sú v „Claude's Discretion“, skutočne otvorené body v „Otvorené otázky“ s predvolenou hodnotou.

### Transport a infraštruktúra e-mailu
- **D-01:** Produkčný SMTP je vlastný Stalwart `mail.vostack.dev` (doména `vostack.dev`, SPF/DKIM/DMARC nastavené). Aplikácia posiela ako `noreply@vostack.dev` cez implicitné TLS `ssl://mail.vostack.dev:465` s AUTH; produkčný php kontajner sa pripojí do externej siete `mail` (alias `mail.vostack.dev`, overený certifikát bez hairpin NAT). Konfigurácia výlučne cez `app.env` (`email.SMTPHost`, `SMTPPort = 465`, `SMTPCrypto = ssl`, `SMTPUser`, `SMTPPass`, `fromEmail`). Zdroj: owner 2026-10-28/29, `docs/ops/mail.md` §6, PRD R6, CLAUDE.md Constraints (Závislosti). Tým je splnená „závislosť od vlastníka“ z ROADMAP Phase 7 (jedna doména pre SK aj PL subjekt, žiadny ďalší SPF/DKIM pre subjekty).
- **D-02:** Staging má `email.transport = log` (nič sa neodošle, do logu idú len metadáta: druh, príjemca, predmet, nikdy telo ani prílohy). Dev používa Mailpit. Zdroj: owner 2026-10-28, Phase 2 O-3, `docs/ops/server.md`. Pri `log` transporte sa odoslanie **zaznamená do `invoice_sends` rovnako ako úspech** (s `transport = 'log'`), aby staging UI a stavy fungovali; `message_id` sa vygeneruje aj tak.
- **D-03:** Jedna cesta odosielania: `AppMailer` sa rozšíri (alebo nová trieda `InvoiceMailer` nad ním) o prílohy z bufferu (`attach($bytes, 'attachment', $name, 'application/pdf')`), CC/BCC, `Reply-To` a vlastnú hlavičku `Message-ID`. SecurityNotifier ostáva na pôvodnom API. Zdroj: ARCHITECTURE §Odoslanie e-mailu, PITFALLS Pasca 17.

### Odoslanie faktúry (MAIL-01) a opätovné odoslanie (MAIL-02)
- **D-04:** E-mail sa **nikdy neposiela vo vnútri transakcie vystavenia** a jeho zlyhanie nemení faktúru, číslo ani časti. Vystavenie ostáva v `InvoiceIssuer` (Phase 5 D-15, Phase 6 D-20); odoslanie je samostatný request po commite. Zdroj: ROADMAP Phase 7 poznámky, INV-14, ARCHITECTURE Anti-vzor 5.
- **D-05:** „Jedným krokom“: po úspešnom vystavení detail faktúry hneď ponúkne panel „Odoslať klientovi“ s predvyplnenými adresátmi, predmetom a telom; jedno potvrdenie pošle **jeden e-mail s dvoma prílohami** (faktúra + výkaz). Na detaile neodoslanej faktúry je tlačidlo „Odoslať“ trvalo. Zdroj: ROADMAP cieľ a kritérium 1, PRD G2, ARCHITECTURE („vystaviť → hneď spustiť odoslanie, ale 2 oddelené volania“).
- **D-06:** Adresáti prvého odoslania = `clients.invoice_email` (To) + `clients.cc_emails` (CC), dajú sa pred odoslaním upraviť (pridať/odobrať, validácia adries na serveri, najviac napr. 10). Zdroj: CLI-02, spec §6.2.
- **D-07:** Prílohy sa berú **výlučne z `invoice_documents`** cez `DocumentStore::read()` s kontrolou `sha256` (nikdy sa neregenerujú), takže klient dostane presne tie isté bajty. Názvy príloh ASCII podľa jazyka dokladu a sanitizovaného čísla (napr. `Faktura_202610001.pdf`, `Vykaz_202610001.pdf`), rovnaké pravidlo ako D-21 Phase 6. Faktúra schema 1 bez výkazu pošle len faktúru. Externé faktúry (`origin = 'external'`) nemajú PDF a nedajú sa odoslať. Zdroj: INV-13, MAIL-02 kritérium 2, Phase 6 D-02/D-21, PITFALLS Pasca 17.
- **D-08:** Opätovné odoslanie: na ľubovoľnú adresu (predvyplnená adresou klienta), z tej istej šablóny „faktúra“, s rovnakými prílohami; dostupné aj pre faktúry ukončeného subjektu. Mobilný bottom sheet (htmx), 44 px ciele, bez horizontálneho posúvania. Zdroj: MAIL-02, spec §6.5 a §7 Mobil, ENT-04, NFR-08.
- **D-09:** Jazyk e-mailu = `doc_language` faktúry (snapshot: klient, inak subjekt), nie jazyk UI. Zdroj: MAIL-01, spec §7 („jazyk dokladov (faktúra, výkaz, e-maily)“).
- **D-10:** Každé úspešné odoslanie sa zapíše do novej insert-only tabuľky `invoice_sends` (`invoice_id`, `kind invoice|resend|reminder`, `sent_at timestamptz`, `recipients` To/CC, `subject`, `message_id`, `smtp_response`, `transport`, `request_key uuid UNIQUE`, `created_by`) a do `audit_log`. Záznam vzniká **len po úspechu SMTP**; zlyhanie sa zobrazí ako chyba s tlačidlom „Skúsiť znova“ a zapíše do aplikačného logu (bez tela). `app_rw`: len `SELECT, INSERT`. Zdroj: ARCHITECTURE (dátový model, „záznam v `invoice_sends` len po úspechu“), PITFALLS Pasca 17 (Message-ID, odpoveď SMTP), MAIL-01 („ukladá sa dátum a adresáti“).
- **D-11:** Ochrana proti dvojkliku: formulár nesie `request_key` (uuid), druhý request s tým istým kľúčom nič nepošle a vráti predchádzajúci výsledok (vzor Phase 5 D-06 / WR-08). Zdroj: PITFALLS Pasca 17 („idempotenčný kľúč proti dvojkliku“).
- **D-12:** `Message-ID` generuje aplikácia (`<uuid@vostack.dev>`), ukladá ho a posiela **BCC kópiu ako archív** na adresu nastavenú pri subjekte (D-27). Upomienka nesie `In-Reply-To`/`References` na `Message-ID` posledného odoslania faktúry, aby sa u klienta zaradila do vlákna. Zdroj: ROADMAP poznámky („BCC archív a `Message-ID` pre dohľadanie“), PITFALLS Pasca 17.
- **D-13:** Formát: `multipart` text + HTML, kde HTML je jednoduchá automatická konverzia textovej šablóny (odseky, `esc()`), bez obrázkov a externých zdrojov. Predmet obsahuje číslo faktúry. Zdroj: PITFALLS Pasca 17.

### Šablóny e-mailov (MAIL-04)
- **D-14:** Šablóny sú v DB (`email_templates`: `key invoice|reminder`, `language sk|pl|cs|en`, `subject`, `body`, `revision`, `updated_at`), nie v jazykových súboroch. Seed pre všetky štyri jazyky (SK a PL ako primárne, CS a EN tiež, aby nikdy nechýbali). Chýbajúca šablóna jazyka → fallback `en`, nikdy prázdny e-mail. Zdroj: ARCHITECTURE (tabuľka `email_templates`, „editovateľné texty v DB, seeduje sa SK a PL, EN/CZ doplniť“), MAIL-04, vzor `legal_texts` (Phase 3 D-17, Phase 6 D-12/D-14).
- **D-15:** Placeholdery z **whitelistu** vykreslené cez `strtr` (žiadny vykonateľný kód): napr. `{{number}}`, `{{total}}` (formátovaná suma s menou), `{{balance}}`, `{{currency}}`, `{{issue_date}}`, `{{due_date}}`, `{{billing_month}}`, `{{variable_symbol}}`, `{{iban}}`, `{{client_name}}`, `{{entity_name}}`, `{{days_overdue}}`. Hodnoty sa berú zo snapshotu faktúry (sumy, dátumy, subjekt, banka) a z odvodeného stavu (zostatok, dni po splatnosti), formát cez `PdfFormat` v jazyku dokladu. Uloženie šablóny s neznámym placeholderom server odmietne. Zdroj: ARCHITECTURE §Odoslanie e-mailu.
- **D-16:** Obrazovka **Nastavenia → Šablóny e-mailov** (vzor Nastavenia → Zákonné texty z Phase 6 D-13): zoznam podľa druhu a jazyka, úprava predmetu a tela, zoznam povolených placeholderov, náhľad na vzorovej faktúre. Úprava v transakcii s `AuditLogger` (pred/po), `app_rw` má len `INSERT` a stĺpcový `UPDATE (subject, body, revision, updated_at)`, bez `DELETE`. Zdroj: MAIL-04, UI-03, AUD-01, Phase 6 D-14.
- **D-17:** Pred odoslaním (faktúra aj upomienka) sa predmet a telo z šablóny **predvyplnia a dajú sa pre konkrétny e-mail upraviť** v paneli; „jedno kliknutie“ znamená, že netreba nič meniť. Odoslaný predmet sa ukladá v `invoice_sends` (telo len ako `body_sha256` alebo vôbec, viď Discretion). Zdroj: MAIL-03 („jedným kliknutím zo šablóny“), spec §6.7.

### Úhrady (PAY-01, PAY-03)
- **D-18:** Nová tabuľka `payments` (`invoice_id`, `paid_on date`, `amount numeric(12,2) > 0`, `note`, `created_at`, `created_by`, `request_key`). Suma je v **mene faktúry**. Suma je vo formulári voliteľná: ak sa nevyplní, uloží sa **zostávajúci zostatok** (vždy explicitné číslo v DB, nikdy NULL). Dátum úhrady predvolene dnes (`Clock`), nesmie byť v budúcnosti. Peniaze ako string + bcmath, nikdy float. Zdroj: PAY-01, PAY-03, ARCHITECTURE (tabuľka `payments`), NFR-04.
- **D-19:** Viac úhrad na faktúru; **zostatok = `total − SUM(payments)`**, stav „čiastočne uhradená“ ⇐ `0 < SUM < total`, „uhradená“ ⇐ `SUM >= total`. Oprava omylom zadanej úhrady stornom (D-28). Úhrady nie sú povolené pre externé faktúry (bez splatnosti, platba išla tretej osobe, Phase 5 D-22). Zdroj: PAY-03, FEATURES M7.
- **D-20:** Zápis úhrady ide cez jednu službu (`PaymentService`) v transakcii s `AuditLogger`; `SELECT … FOR UPDATE` na riadok faktúry nie je potrebný (insert-only), ale kontrola preplatenia beží v tej istej transakcii. Zdroj: ARCHITECTURE (Receivables: `PaymentService`), PITFALLS Pasca 18.

### Stavy a „po splatnosti“ (INV-06, PAY-02)
- **D-21:** Stav sa **neukladá**. `invoice_status_v` sa rozšíri cez `CREATE OR REPLACE VIEW` **len pripojením stĺpcov na koniec** (Phase 5 migrácia, Pitfall 6): `sent_at` (posledné odoslanie), `send_count`, `last_reminder_at`, `paid_amount`, `balance`, `last_paid_on`, a odvodený `status` (`external | issued | sent | partially_paid | paid`). „Po splatnosti“ je samostatný odvodený príznak (`due_date < :today AND balance > 0`), počítaný v dotaze s dátumom z `Clock` (Europe/Bratislava), nie `CURRENT_DATE` v pohľade a nie cron. Phase 9 doplní `cancelled` (stornovaná faktúra nikdy nie je po splatnosti). Zdroj: ROADMAP poznámky, INV-06, ARCHITECTURE (odvodený stav), PITFALLS (po splatnosti z `Clock`), Phase 5 D-20.
- **D-22:** Priorita zobrazovaného stavu: uhradená > čiastočne uhradená > odoslaná > vystavená; „po splatnosti“ je odznak navyše (platí aj pre čiastočne uhradenú). Odoslaná = podľa D-29 (odoslanie klientovi alebo ručné označenie). Zdroj: spec §6.5 (stavy), ARCHITECTURE.
- **D-23:** Dashboard (Home) dostane sekciu **„Po splatnosti“**: zoznam faktúr (číslo, klient, splatnosť, dni po splatnosti, zostatok) zoradený od najstaršej splatnosti, s akciami „Upomienka“ a „Úhrada“; ak nič nie je po splatnosti, sekcia sa nezobrazí. Rešpektuje filter klienta dashboardu. Zoznam faktúr dostane stavový odznak a filter podľa stavu. Zdroj: PAY-02, ROADMAP kritérium 4, DSH-01, UI-03.

### Upomienky (MAIL-03)
- **D-24:** Upomienka = e-mail zo šablóny `reminder` v jazyku dokladu s **PDF faktúry** (bez výkazu), adresát predvolene `invoice_email` + `cc_emails`, odoslaná jedným kliknutím z dashboardu alebo detailu faktúry (potvrdzovací panel s predvyplneným obsahom). Zapíše sa ako `invoice_sends.kind = 'reminder'` s dátumom; detail faktúry ukazuje históriu odoslaní a upomienok. Upomienka je ponúknutá len pre faktúru so zostatkom > 0; zvýraznená pri „po splatnosti“. Iba ručne, žiadne automatické upomienky. Zdroj: MAIL-03, spec §6.7, FEATURES (anti-feature automatické upomienky).

### Staging seed a testy
- **D-25:** Nový modul staging seedu (napr. `Modules\Deliveries`) za `Modules\Invoices` doplní deterministickú históriu: odoslané faktúry, úhrady (plné aj čiastočné), faktúry po splatnosti a upomienky, zapísané cez aplikačné služby (`InvoiceMailer` s `log` transportom, `PaymentService`) s `SeedClock`, nikdy surovým INSERTom. Zdroj: owner 2026-10-28 (staging seed moduly), quick 261028-hu8, `SeedModule` kontrakt.
- **D-26:** PHPUnit pokryje: zlyhanie SMTP nezmení faktúru ani nevytvorí `invoice_sends`; prílohy sú bajtovo zhodné s `invoice_documents` (sha256); idempotencia dvojkliku; vykreslenie šablón (whitelist, neznámy placeholder odmietnutý, fallback jazyka); odvodené stavy a zostatok (bcmath, viac úhrad, preplatenie, hranica dňa splatnosti s `FrozenClock`); `invoices`, `invoice_sends`, `payments` insert-only pod `app_rw`; audit úhrad, odoslaní a úprav šablón; `log` transport zapíše záznam bez odoslania. Integračný test odoslania cez Mailpit v dev/CI. Zdroj: NFR-06, Phase 5 D-23, PITFALLS checklist („dvojklik neposiela dvakrát“).

### Claude's Discretion
Plánovač a výskum môžu spresniť, nie obrátiť:
- Presné názvy tried a tabuliek (`InvoiceMailer`, `EmailTemplateService`, `PaymentService`, `InvoiceStatusQueries`), rozdelenie do plánov a tvar rozšírenia `AppMailer`.
- Či sa v `invoice_sends` ukladá aj vykreslené telo (alebo len jeho hash); predvolene len predmet a hash tela (telo je v BCC archíve).
- Správanie pri preplatení: predvolene server odmietne sumu vyššiu ako zostatok s chybou a jasnou správou (preplatky sú v praxi zriedkavé a dajú sa zapísať ako poznámka).
- Znenie seedovaných šablón (vecné, zdvorilé; upomienka jedna úroveň, bez hrozieb a úrokov, vlastník si ju upraví v UI), texty tlačidiel, toastov a rozloženie panelov na mobile a PC.
- Limit počtu adresátov a dĺžky predmetu a tela, validácia adries (`filter_var` + bez CR/LF).
- Či sa `invoice_sends` zapisuje pre resend aj na adresu mimo klienta ako `kind = 'resend'` (predvolene áno, vrátane adresátov).
- SMTP timeout (dnes 5 s) zvýšiť pre prílohy (napr. 15 s); pripojenie `mail` siete len pre produkčný php kontajner (staging má `log`).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Rozsah a požiadavky
- `.planning/ROADMAP.md` §Phase 7 — cieľ, 5 kritérií úspechu, poznámky (e-mail mimo transakcie, BCC archív, Message-ID, stav SQL pohľadom, `invoices` insert-only)
- `.planning/REQUIREMENTS.md` — MAIL-01..04, PAY-01..03, INV-06; súvisiace INV-13, INV-14, CLI-02, ENT-04, DSH-01, UI-03, AUD-01, NFR-04, NFR-06, NFR-08
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` §6.2 (e-mail na faktúry, CC), §6.5 (stavy, opätovné odoslanie), §6.7 (úhrady a upomienky), §7 (jazyk e-mailov, mobil: „znova odoslať faktúru“), §8 (CI4 Email, Mailpit)
- `docs/PRD.md` §6 E8 (MAIL-01..04, PAY-01..02), E6 INV-06, G2, R6

### Výskum
- `.planning/research/ARCHITECTURE.md` — kontext Delivery a Receivables, tabuľky `invoice_sends`, `payments`, `email_templates`, odvodený stav a `invoice_status_v`, §Odoslanie e-mailu, Anti-vzor 2 (status + UPDATE) a 5 (e-mail v transakcii), GRANT pre `app_rw`
- `.planning/research/PITFALLS.md` Pasca 17 (doručiteľnosť, Message-ID, BCC, Reply-To, ASCII prílohy, idempotencia), Pasca 18 (audit v transakcii), časť o `Clock` a „po splatnosti“, Pasca 11 (iOS PDF), checklist „E-mail“
- `.planning/research/FEATURES.md` M7 (viac úhrad), anti-feature automatické upomienky
- `.planning/research/SUMMARY.md` §Fáza 6 (dokumenty, doručenie, úhrady)

### Predchádzajúce fázy a quick tasky
- `.planning/phases/05-faktura-n-jadro/05-CONTEXT.md` — D-06 (idempotencia `request_key`), D-15 (jediný zapisovateľ), D-20 (insert-only, odvodený stav), D-22 (externé faktúry)
- `.planning/phases/06-dokumenty-fakt-ry-a-v-kazu/06-CONTEXT.md` — D-02 (bajty sa neregenerujú), D-13/D-14 (vzor editácie textov v DB s `revision` a auditom), D-21 (názvy súborov, trasy PDF)
- `.planning/phases/02-identita-a-skor-nasadenie/` — O-3 (log transport len metadáta), `SecurityNotifier`
- `.planning/quick/261028-hu8-staging-seed-s-vymyslenou-historiou-od-2/` — moduly staging seedu
- `.planning/quick/261028-w8p-vlastny-mailovy-server-stalwart-a-webmai/` — mailový server

### Prevádzka
- `docs/ops/mail.md` §6 (aplikácia: sieť `mail`, `ssl://mail.vostack.dev:465`, `noreply@vostack.dev`), §5 (DNS, DMARC `p=none` → sprísňovanie), schránky `info@`, `noreply@`
- `docs/ops/server.md` — `app.env` `email.*`, staging `email.transport = log`
- `docs/ops/staging.md` — staging deploy a reset seedu

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Libraries/Mail/AppMailer.php`: jediná cesta odosielania s `log` transportom (len metadáta); rozšíriť o prílohy, CC/BCC, Reply-To, Message-ID.
- `app/Config/Email.php` + `.env`/`app.env` `email.*`: dnes `SMTPCrypto = tls`, `SMTPPort = 25`, `SMTPTimeout = 5`, `mailType = text`; dev Mailpit `mailpit:1025`.
- `app/Libraries/Invoicing/DocumentStore.php`: `read($id, 'invoice'|'report')` s overením `sha256` → prílohy.
- `app/Controllers/Invoices.php` (`show`, `pdf`, `report`, `serve`) a `app/Views/invoices/show.php`, `_list.php`: miesto pre panel odoslania, históriu odoslaní, úhrady a stavový odznak; názov súboru PDF podľa jazyka.
- `app/Libraries/Invoicing/PdfFormat.php`: formát súm a dátumov bez ICU pre placeholdery.
- `app/Libraries/Invoicing/InvoiceIssuer.php`: vzor `request_key` idempotencie (WR-08) a transakcie s auditom.
- `app/Controllers/Settings/LegalTexts.php` + `app/Views/settings/legal-texts*` + `LegalTextService`: vzor obrazovky a služby pre Šablóny e-mailov.
- `app/Libraries/Audit/AuditLogger.php`, `app/Libraries/Platform/Clock.php` / `FrozenClock.php`.
- `app/Controllers/Home.php` + `app/Views/home/_body.php` (`TaskQueries::dashboard`, `DashboardFilter`): miesto pre sekciu „Po splatnosti“.
- `app/Libraries/StagingSeed/StagingSeeder.php` (zoznam modulov), `SeedModule`, `SeedContext`, `SeedClock`, `HistoryPlan`: nový modul doručení a úhrad.
- `app/Libraries/Master/CcEmails.php`: normalizácia a validácia zoznamu CC adries klienta.
- `tests/_support/PgTestCase.php` (`owner()`/`rw()`), `tests/integration/Mail/SecurityNotifierTest.php`.

### Established Patterns
- Insert-only tabuľky cez GRANT a `immutable_guard`; migrácie pod `app_owner`, testy aj pod `app_rw`.
- `invoice_status_v` sa mení iba `CREATE OR REPLACE VIEW` s pripojením stĺpcov na koniec (komentár v migrácii `2026-10-27-000004`).
- Biznis „dnes“ len z `Clock`; peniaze ako string + bcmath.
- htmx bottom sheety s `HX-Location`/`HX-Trigger`, CSP bez inline skriptov a štýlov, všetky texty UI cez `lang()` (sk + en súbory, pl/cs pre doklady).
- Editovateľné texty v DB so stĺpcovým `UPDATE`, `revision` a auditom (Phase 6).

### Integration Points
- `compose.server.yaml`: produkčný `php` do externej siete `mail` (dnes len `default` a `edge` pri nginx).
- `app/Config/Routes/invoices.php`: `invoices/(:num)/send`, `/resend`, `/remind`, `/payments` (POST, htmx fragmenty); `app/Config/Routes/settings.php`: `settings/email-templates`.
- `app/Views/more/index.php`: položka „Šablóny e-mailov“.
- Nové migrácie: `invoice_sends`, `payments`, `email_templates` (+ seed), rozšírenie `invoice_status_v`, GRANTy.
- Phase 9 doplní `cancelled` do pohľadu; Phase 11 použije `payments` pre „uhradené“ v prehľadoch.

</code_context>

<specifics>
## Specific Ideas

- Prvé ostré použitie: SK subjekt → All4Net (SK, EUR), e-mail v slovenčine s faktúrou a výkazom, z iPhonu opätovné odoslanie.
- Klient má dostať presne tie isté bajty ako sú uložené (sha256), nie nové PDF.
- Doručiteľnosť sa overí po nasadení: Gmail/Outlook „Show original“ SPF/DKIM/DMARC PASS pre e-mail z aplikácie (DMARC je zatiaľ `p=none`, `docs/ops/mail.md`).

</specifics>

<deferred>
## Deferred Ideas

- Stav „stornovaná“ a vylúčenie stornovaných z „po splatnosti“: Phase 9.
- „Uhradené v mene subjektu“ (prepočet úhrad kurzom zo snapshotu) a prehľady úhrad: Phase 8/11.
- Automatické upomienky, viacúrovňové upomienky, úroky z omeškania: mimo V1 (anti-feature).
- Outbox fronta a `spark` worker: len ak odosielanie prekročí ~3 s alebo pribudne hromadné odosielanie.
- Spracovanie bounce/DSN zo Stalwartu a stav „doručená“: mimo V1.
- Odoslanie balíka účtovníčke: Phase 11.

</deferred>

## Rozhodnutia vlastníka k otvoreným otázkam (2026-10-29)

Tieto rozhodnutia majú prednosť pred predvolenými hodnotami vyššie a patria k `<decisions>`:

- **D-27: Identita odosielateľa je nastaviteľná pre každý subjekt** (O-1, vlastník: „nech je to pre každý subjekt nastaviteľné, kto bude odosielateľ, reply-to a bcc“). Pri subjekte (nastavenia subjektu, nie verziovaná kópia údajov dokladu; zmena sa audituje) sú polia:
  - **Meno odosielateľa** (From display name). Predvolené: obchodné meno subjektu.
  - **Adresa odosielateľa** (From). Predvolené `noreply@vostack.dev`. **Musí byť na doméne, ktorú podpisuje náš mailový server** (dnes iba `vostack.dev`; zoznam povolených domén v `.env`, napr. `email.allowedFromDomains`), inak by DMARC u príjemcu správu odmietol. Validácia pri uložení aj pri odoslaní. Envelope sender (Return-Path) ostáva účet, ktorým sa aplikácia autentifikuje (SMTP auth `noreply@vostack.dev`); ak Stalwart vyžaduje zhodu From s prihláseným účtom, výskum overí nastavenie (povolenie `noreply@` posielať za ďalšie adresy domény) a plán ho zahrnie do `docs/ops/mail.md` / `plan.ndjson`.
  - **Reply-To**. Predvolené: e-mail subjektu.
  - **BCC archív** (0..n adries). Predvolené: e-mail subjektu; prázdne = bez BCC.
  - Hodnoty sa čítajú v čase odoslania (nie zo snapshotu faktúry), uložia sa do `invoice_sends` (skutočne použité From, Reply-To, BCC) kvôli dohľadaniu.
- **D-28: Oprava omylom zadanej úhrady = storno s auditom** (O-2 A). Úhrada sa nemaže ani neupravuje; storno je nový zápis (`payment_voids` alebo stĺpec `voided_at`/`voided_by`/`void_reason` cez insert-only vzor, rozhodne plán) s povinným dôvodom, zapíše sa do auditu a pohľad stornované úhrady nezapočíta. Potom sa zadá správna úhrada. V detaile faktúry sú stornované úhrady viditeľné (prečiarknuté) s dôvodom.
- **D-29: Kedy je faktúra „odoslaná“** (O-3, vlastník: „bude, keď odošlem klientovi alebo ručne cez tlačidlo označím FA ako odoslanú“):
  - automaticky, keď úspešné odoslanie (`invoice` alebo `resend`) obsahovalo medzi adresátmi (To/CC) fakturačný e-mail klienta (`clients.invoice_email` alebo adresu z `cc_emails`, porovnanie bez ohľadu na veľkosť písmen);
  - alebo **ručne tlačidlom „Označiť ako odoslanú“** v detaile faktúry (napr. faktúra odovzdaná inak). Ručné označenie je insert-only záznam (napr. `invoice_sends` s `kind = manual` bez e-mailu, alebo samostatná tabuľka, rozhodne plán), audituje sa a dá sa aj ono stornovať rovnakým vzorom ako D-28, ak bolo omylom;
  - odoslanie len sebe, účtovníčke alebo inej adrese sa zapíše do histórie odoslaní, ale stav „odoslaná“ samo nenastaví.

---

*Phase: 07-doru-enie-hrady-a-upomienky*
*Context gathered: 2026-10-29*
