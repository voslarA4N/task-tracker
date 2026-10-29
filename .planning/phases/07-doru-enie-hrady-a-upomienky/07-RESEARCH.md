# Phase 7: Doručenie, úhrady a upomienky - Research

**Researched:** 2026-10-29
**Domain:** transakčný e-mail s PDF prílohami (CI4 Email nad Stalwart SMTP), odvodené stavy faktúr v PostgreSQL pohľade, insert-only evidencia úhrad
**Confidence:** MEDIUM-HIGH (kód projektu overený čítaním; správanie CI4 Email a Stalwart `must-match-sender` z pamäti/web search, treba overiť v kontajneri)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
Plné znenie: `07-CONTEXT.md` `<decisions>` D-01..D-26 a doplnok D-27..D-29 (rozhodnutia vlastníka 2026-10-29). Plánovač MUSÍ čítať CONTEXT.md; kľúčové:
- D-01 Stalwart `ssl://mail.vostack.dev:465`, `noreply@vostack.dev`, php kontajner do externej siete `mail`, konfigurácia len cez `app.env`.
- D-02 staging `email.transport=log`: odoslanie sa zapíše do `invoice_sends` ako úspech (`transport='log'`), `message_id` sa generuje.
- D-03 jedna cesta odosielania (`AppMailer`/`InvoiceMailer`), prílohy z bufferu, CC/BCC, Reply-To, vlastný Message-ID.
- D-04 e-mail nikdy v transakcii vystavenia; D-05 jeden e-mail s 2 prílohami (faktúra + výkaz); D-06 adresáti `invoice_email`+`cc_emails`, editovateľné, validované.
- D-07 prílohy výlučne z `invoice_documents` cez `DocumentStore::read()` (sha256), ASCII názvy; externé faktúry sa neposielajú.
- D-08 resend na ľubovoľnú adresu; D-09 jazyk = `doc_language`; D-10 `invoice_sends` insert-only, záznam len po úspechu SMTP; D-11 `request_key` idempotencia; D-12 Message-ID `<uuid@vostack.dev>`, BCC archív, upomienka s In-Reply-To/References; D-13 multipart text+HTML.
- D-14..D-17 šablóny v DB (`email_templates`, key invoice|reminder, jazyky sk|pl|cs|en, fallback en, whitelist placeholderov cez `strtr`, UI Nastavenia → Šablóny e-mailov, `revision`, audit, stĺpcový UPDATE), predvyplnenie a úprava pred odoslaním.
- D-18..D-20 `payments` (suma voliteľná, default zostatok, nie budúci dátum, bcmath), zostatok = total − SUM(platné úhrady), `PaymentService` v transakcii s auditom.
- D-21..D-23 `invoice_status_v` len pripojením stĺpcov na koniec; „po splatnosti“ z `Clock` (nie `CURRENT_DATE`, nie cron); dashboard sekcia „Po splatnosti“.
- D-24 upomienka = šablóna `reminder` + PDF faktúry (bez výkazu), len pri zostatku > 0, iba ručne.
- D-25 staging seed modul `Modules\Deliveries`; D-26 zoznam povinných PHPUnit testov.
- D-27 identita odosielateľa per subjekt (From meno/adresa, Reply-To, BCC 0..n), adresa len z povolených domén (`email.allowedFromDomains`), čítané v čase odoslania, skutočne použité hodnoty sa ukladajú do `invoice_sends`.
- D-28 storno úhrady (nie delete/update) s povinným dôvodom a auditom, stornované viditeľné prečiarknuté.
- D-29 „odoslaná“ = úspešné odoslanie (`invoice`/`resend`) obsahujúce fakturačný e-mail klienta (To/CC, case-insensitive) ALEBO ručné „Označiť ako odoslanú“ (insert-only, audit, stornovateľné); odoslanie len sebe/účtovníčke stav nemení.

### Claude's Discretion
Názvy tried a tabuliek, rozdelenie do plánov, tvar rozšírenia `AppMailer`; či ukladať telo alebo len hash (default: predmet + `body_sha256`); správanie pri preplatení (default: odmietnuť); znenie seedovaných šablón a UI textov; limity (adresáti, dĺžky) a validácia adries; resend mimo klienta ako `kind='resend'`; SMTP timeout (zvýšiť na ~15 s); pripojenie siete `mail` len pre produkčný php.

### Deferred Ideas (OUT OF SCOPE)
Stav „stornovaná“ a dobropis (Phase 9); „uhradené v mene subjektu“ a prehľady (Phase 8/11); automatické a viacúrovňové upomienky, úroky; outbox fronta/worker; bounce/DSN a stav „doručená“; balík pre účtovníčku (Phase 11).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| MAIL-01 | Odoslať faktúru + výkaz klientovi, šablóna v jazyku dokladu, dátum a adresáti sa ukladajú | `InvoiceMailer`, `invoice_sends`, `email_templates`, Pattern 1, 2 |
| MAIL-02 | Znova odoslať uložené PDF na ľubovoľný e-mail, aj z mobilu, rovnaké bajty | `DocumentStore::read` + sha256, resend bottom sheet, test bajtovej zhody |
| MAIL-03 | Upomienka jedným klikom s PDF faktúry, dátum sa uloží | `kind='reminder'`, `In-Reply-To`, panel s predvyplnením |
| MAIL-04 | Úprava šablón e-mailov pre každý jazyk | `EmailTemplateService` podľa vzoru `LegalTextService` |
| PAY-01 | Zaznamenať jednu či viac úhrad (dátum, voliteľne suma) | `payments`, `PaymentService`, bcmath |
| PAY-02 | Dashboard so zoznamom po splatnosti | `InvoiceStatusQueries::overdue($today)`, Home sekcia |
| PAY-03 | „Čiastočne uhradená“ a zostatok zo súčtu úhrad | rozšírený `invoice_status_v` |
| INV-06 | Stavy vystavená/odoslaná/uhradená, „po splatnosti“ odvodené | view + odvodený príznak z `Clock` |
</phase_requirements>

## Summary

Fáza je prevažne „lepidlo“ nad hotovými vecami: PDF sú v `invoice_documents` (bytea + sha256, `DocumentStore::read()` overuje hash), `invoice_status_v` existuje (dnes `issued|external`), `AppMailer` už vie `log` transport, vzor editovateľných textov v DB je `LegalTextService`, vzor idempotencie `InvoiceIssuer` (`request_key`). Nové sú 4 tabuľky (`invoice_sends`, `payments`, `email_templates`, plus storno/ručné označenie), rozšírenie view, `InvoiceMailer`, `PaymentService`, `EmailTemplateService`, UI (panel odoslania, úhrady, šablóny, dashboard sekcia) a infraštruktúra (sieť `mail`, `.env`).

Hlavné riziká: (1) CI4 `Email` si pravdepodobne **prepíše vlastný `Message-ID`** a zdieľaná inštancia `service('email')` drží stav medzi odoslaniami; (2) Stalwart štandardne vynucuje, že hlavička/envelope From patrí prihlásenému účtu alebo jeho aliasom (`mustMatchSender`), čo ovplyvní D-27 (From inej adresy než `noreply@`); (3) okno „SMTP prešlo, INSERT do `invoice_sends` zlyhal“ (duplicitné odoslanie pri retry); (4) `CREATE OR REPLACE VIEW` smie len pripájať stĺpce na koniec; (5) „po splatnosti“ musí ísť z `Clock`, nie `CURRENT_DATE`.

**Primary recommendation:** Postaviť `InvoiceMailer` ako tenkú službu nad novým, na odoslanie vytvoreným `Email` objektom (nie zdieľaná služba), po SMTP úspechu zapísať `invoice_sends` + audit v jednej krátkej transakcii; stav odvodzovať jedným rozšíreným `invoice_status_v` (LEFT JOIN na agregáty `payments`/`invoice_sends`) a „po splatnosti“ počítať v PHP dotaze s parametrom dňa z `Clock`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Odoslanie e-mailu, prílohy, Message-ID | API / Backend (`InvoiceMailer`) | Externý SMTP (Stalwart) | tajomstvá, bajty z DB, nikdy z klienta |
| Idempotencia dvojkliku | Backend + DB (`UNIQUE request_key`) | Browser (`submit-once.js`) | server je zdroj pravdy |
| Odvodený stav, zostatok, po splatnosti | Database (view) | Backend (parameter dňa z `Clock`) | INV-06, `invoices` insert-only |
| Pravidlo „odoslaná“ (D-29) | Database (view) | Backend (flag `counts_as_sent` pri zápise) | odvodené, nie ukladané |
| Šablóny a placeholdery | Backend (`strtr` whitelist) | DB (`email_templates`) | bez vykonateľného kódu |
| UI panely (bottom sheet) | Frontend Server (CI4 views + htmx) | Browser (Alpine CSP) | existujúci vzor |
| Sieť `mail`, env | Infra (compose, `app.env`) | — | D-01 |

## Standard Stack

Žiadne nové balíky. Všetko je už v projekte. [VERIFIED: CLAUDE.md Technology Stack, kód]

| Library | Version | Purpose |
|---------|---------|---------|
| `codeigniter4/framework` | 4.7.5 | `Email` (SMTP, prílohy z bufferu), Validation |
| bcmath, ext-intl | PHP 8.5 | zostatky, formát (`PdfFormat` bez ICU) |
| Mailpit `axllent/mailpit:v1.32` | dev | integračný test cez REST API `GET /api/v1/messages` |

**Package Legitimacy Audit:** fáza neinštaluje žiadne externé balíky. Packages removed: none. Flagged [SUS]: none. (slopcheck sa nespúšťal, nie je čo overovať.)

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| CI4 Email | `symfony/mailer` / PHPMailer | spoľahlivé Message-ID/príloha API, ale nová závislosť proti rozhodnutému stacku; použiť len ak CI4 Email neumožní nastaviť Message-ID ani cez podtriedu |

## Architecture Patterns

### Tok odoslania
```
Detail faktúry (htmx panel) --POST invoices/{id}/send {request_key, to, cc, subject, body}
  -> Controller: validácia (CcEmails/filter_var, bez CR/LF, limit)
  -> InvoiceMailer::send(cmd)
       1. SELECT invoice_sends WHERE request_key  -> ak existuje, vráť predchádzajúci výsledok
       2. DocumentStore::read(invoice) [+ read(report)]  (sha256 overené, chyba = 500 + log critical)
       3. identita odosielateľa z entity (D-27) + overenie domény voči allowedFromDomains
       4. build Email (nová inštancia): From/Reply-To/To/Cc/Bcc, Message-ID, text+HTML, prílohy z bufferu
       5. SMTP (transport=log -> len log metadáta)         <-- MIMO transakcie
       6. úspech -> Transaction::run { INSERT invoice_sends; AuditLogger::record }
       7. zlyhanie -> log (bez tela), chyba v UI + "Skúsiť znova" (rovnaký request_key je OK, nič nebolo zapísané)
  -> htmx fragment (história odoslaní + stavový odznak)
```

### Odporúčaná štruktúra
```
app/Libraries/Delivery/  InvoiceMailer, SendCommand, SendResult, EmailTemplateService, TemplateRenderer, SenderIdentity
app/Libraries/Receivables/ PaymentService, InvoiceStatusQueries
app/Controllers/ InvoiceDelivery (send/resend/remind/mark-sent), Payments, Settings/EmailTemplates
app/Database/Migrations/ 2026-10-29-0000NN_*  (email_templates+seed, invoice_sends, payments(+void), entity sender fields, view)
app/Libraries/StagingSeed/Modules/Deliveries.php
```

### Pattern 1: Stav ako rozšírený view (len pripojiť na koniec)
Aktuálny view (`2026-10-27-000004`) má stĺpce `id … created_at, status`. Nový `CREATE OR REPLACE VIEW` musí zachovať prvých 17 stĺpcov s rovnakým názvom, typom a poradím (vrátane `status` na pozícii 17, jeho typ ostáva `text`; hodnoty sa môžu zmeniť) a nové stĺpce pridať **za** ne. [CITED: postgresql.org/docs/current/sql-createview.html: "The new query must generate the same columns … and may add additional columns to the end"] Kostra:
```sql
CREATE OR REPLACE VIEW invoice_status_v AS
SELECT i.id, ..., i.created_at,                                   -- pôvodných 16
       CASE WHEN i.origin='external' THEN 'external'
            WHEN COALESCE(p.paid,0) >= i.total AND i.total > 0 THEN 'paid'
            WHEN COALESCE(p.paid,0) > 0 THEN 'partially_paid'
            WHEN s.sent_at IS NOT NULL THEN 'sent'
            ELSE 'issued' END AS status,                          -- 17., rovnaká pozícia
       s.sent_at, COALESCE(s.send_count,0) AS send_count, r.last_reminder_at,
       COALESCE(p.paid,0)::numeric(12,2) AS paid_amount,
       (i.total - COALESCE(p.paid,0))::numeric(12,2) AS balance, p.last_paid_on
FROM invoices i
LEFT JOIN (SELECT invoice_id, SUM(amount) paid, MAX(paid_on) last_paid_on FROM payments pa
           WHERE NOT EXISTS (SELECT 1 FROM payment_voids v WHERE v.payment_id = pa.id) GROUP BY invoice_id) p ON p.invoice_id=i.id
LEFT JOIN (... invoice_sends ... counts_as_sent ... GROUP BY invoice_id) s ON ...
```
Pozor: `total >= 0` pre faktúry, ale `total = 0` faktúra (ak je povolená) by bola hneď „uhradená“; rozhodnúť (odporúčanie: `total > 0` podmienka a nulové faktúry považovať za `issued`/`sent`). Typy nových stĺpcov nech sú explicitné (`::numeric(12,2)`). „Po splatnosti“ sa **nedáva do view** (view je bez „dnes“): `InvoiceStatusQueries::overdue(string $today)` filtruje `due_date < :today AND balance > 0 AND origin='issued' AND type='invoice'`; Phase 9 pridá `status <> 'cancelled'`.

Pravidlo D-29 „odoslaná“: najjednoduchšie je pri zápise `invoice_sends` vyrátať a **uložiť** boolean `counts_as_sent` (adresa klienta bola v To/CC, case-insensitive) alebo `kind='manual'`; view potom len `bool_or`/`MAX(sent_at) FILTER (WHERE counts_as_sent AND NOT voided)`. Takto view nepotrebuje porovnávať JSON s aktuálnym `clients.invoice_email` (ktorý sa môže neskôr zmeniť; faktúra je nemenná a stav „odoslaná“ tiež nemá meniť spätne). `last_reminder_at` = `MAX(sent_at) FILTER (kind='reminder')`.

### Pattern 2: Odoslanie cez CI4 Email s prílohou z bufferu
- Príloha z bufferu: `$email->attach($bytes, 'attachment', $filename, 'application/pdf')` (neprázdny `$mime` => obsah sa berie ako buffer, nie cesta). [ASSUMED: CI4 docs/zdroj `Email::attach`, overiť v kontajneri `vendor/codeigniter4/framework/system/Email/Email.php`; vendor nie je v WSL checkoute, je vo volume]
- Použiť **novú inštanciu** `service('email', $config, false)` pre každé odoslanie (zdieľaná má stav príloh/hlavičiek), alebo `$email->clear(true)` pred použitím. [ASSUMED]
- Multipart: `mailType='html'` + `setAltMessage($text)`; `setMessage($html)`. [ASSUMED: CI4 Email API]
- From per subjekt: `setFrom($fromEmail, $fromName, $returnPath)` — tretí argument je envelope/Return-Path; nastaviť na SMTP-auth účet (`noreply@vostack.dev`). [ASSUMED]
- `Message-ID`: CI4 `buildHeaders()` volá `setHeader('Message-ID', getMessageID())` a tým pravdepodobne **prepíše** hodnotu nastavenú cez `setHeader()`. Riešenie: malá podtrieda `App\Libraries\Mail\AppEmail extends CodeIgniter\Email\Email` s override `protected function getMessageID()` vracajúcim vopred zvolenú hodnotu (`<uuid@vostack.dev>`). Prvý krok plánu: spike/test v kontajneri (po odoslaní cez Mailpit API prečítať `Message-ID` hlavičku a porovnať). [ASSUMED, MEDIUM — kritické pre D-12]
- `In-Reply-To`/`References` cez `setHeader()` (nie sú prepisované). `Reply-To` cez `setReplyTo()`. BCC cez `setBCC()`; pri `protocol=smtp` sa BCC nedáva do viditeľných hlavičiek, ide len v RCPT TO. [ASSUMED]
- Názvy príloh: ASCII `Faktura_<číslo>.pdf`/`Vykaz_<číslo>.pdf` (existujúci `Invoices::serve` používa prefixy `faktura-`/`wykaz-`/`invoice-`; zjednotiť do jednej pomocnej metódy a použiť v oboch miestach).
- `AppMailer::send(string,string,string,string)` ostáva pre `SecurityNotifier`; pridať `AppMailer::sendMessage(OutgoingMessage)` (alebo `InvoiceMailer` priamo). `log` transport vetva musí byť spoločná a logovať len `kind`, `to`, `subject`.
- Timeout: `Config\Email::$SMTPTimeout` zvýšiť na 15 (env `email.SMTPTimeout`). Prod `.env`: `SMTPHost=mail.vostack.dev`, `SMTPPort=465`, `SMTPCrypto=ssl`.

### Pattern 3: Idempotencia a okno medzi SMTP a INSERT
`request_key uuid UNIQUE` v `invoice_sends` + kontrola na začiatku (vzor `InvoiceIssuer` WR-08). Zvyškové riziko: SMTP úspech, potom pád pred INSERT → retry pošle druhý e-mail. Zmierniť: (a) `message_id` sa vygeneruje **pred** odoslaním a zapíše sa do app logu (`mail.sent message_id=…`), čo umožní dohľadanie; (b) pri DB chybe po úspešnom SMTP zobraziť špecifickú správu „e-mail odišiel, záznam sa nepodarilo uložiť“ a nenabádať k retry; (c) BCC archív (D-12). Nerobiť pre-insert „pending“ riadky (porušuje insert-only a D-10 „záznam len po úspechu“).

### Pattern 4: Šablóny
`EmailTemplateService` zrkadlí `LegalTextService`: transakcia, `AuditLogger::record('email_template', id, 'update', before, after)`, `revision+1`, GRANT `INSERT` + `UPDATE (subject, body, revision, updated_at)`, helper `GrantsPrivileges::grantUpdateColumns`. Render: whitelist konštanta kľúčov, `preg_match_all('/\{\{([a-z_]+)\}\}/')` na validáciu pri uložení (neznámy => odmietnuť), `strtr` pri renderi; hodnoty z snapshotu a zo `invoice_status_v`; formát cez `PdfFormat`. HTML verzia: `nl2br(esc($text))` po `strtr`, odseky po prázdnom riadku; predmet bez CR/LF. Hodnoty z klientskych dát (názov klienta) sú do HTML escapované; do hlavičiek (predmet) čistiť CR/LF (header injection).

### Pattern 5: Úhrady a storno
`payments(id, invoice_id FK, paid_on date, amount numeric(12,2) CHECK >0, note CHECK len<=300, request_key uuid UNIQUE, created_at, created_by)`; storno: samostatná insert-only tabuľka `payment_voids(payment_id UNIQUE FK, reason NOT NULL CHECK len 1..300, voided_at, voided_by)` (čistejšie než `voided_at` stĺpec, ktorý by vyžadoval UPDATE; zhoduje sa s „insert-only vzorom“ D-28). To isté pre ručné „odoslaná“: `kind='manual'` v `invoice_sends` (bez e-mailu, `recipients='[]'`) a `invoice_send_voids`, alebo jedna spoločná `delivery_voids`; odporúčanie: `invoice_send_voids(send_id UNIQUE, reason, voided_at, voided_by)`; view ignoruje stornované. `PaymentService::record()`: v `Transaction::run` zamknúť riadok faktúry nie je nutné (insert-only), ale preplatenie kontrolovať v tej istej transakcii **po** `SELECT balance FROM invoice_status_v WHERE id=… FOR UPDATE` nefunguje na view → pre ochranu pred súbehom dvoch úhrad použiť `pg_advisory_xact_lock(invoice_id)` alebo `SELECT 1 FROM invoices WHERE id=:id FOR SHARE`/`FOR UPDATE` (FOR UPDATE na `invoices` je povolený, `immutable_guard` blokuje len UPDATE/DELETE; overiť, že `app_rw` má na `invoices` `SELECT`, čo `FOR UPDATE` vyžaduje aj `UPDATE` privilégium → **FOR UPDATE by zlyhalo**; preto `pg_advisory_xact_lock`). [ASSUMED: PostgreSQL vyžaduje UPDATE privilégium pre FOR UPDATE; overiť v teste pod `app_rw`]. Preplatenie: `bccomp($amount, $balance, 2) > 0` => `PaymentRejected`. Dátum: `paid_on <= Clock today` (Europe/Bratislava) aj ako DB CHECK nie (CHECK s volatile `CURRENT_DATE` je zlý nápad); len serverová kontrola.

### Sender identity (D-27)
Nové stĺpce na nastaveniach subjektu mimo verziovanej kópie (`entities`, nie `entity_versions`; existujúce `entities.email` je NOT NULL): `mail_from_name`, `mail_from_email`, `mail_reply_to`, `mail_bcc jsonb` (pole). Zmena cez `EntityService` s auditom. Zoznam povolených domén: `Config\Email::$allowedFromDomains` (env `email.allowedFromDomains`, CSV; default `vostack.dev`). Validácia v `SenderIdentity::resolve()` pri uložení aj pri odoslaní (doména e-mailu po `@`, case-insensitive, presná zhoda). Ak je prázdne: From = `fromEmail` z configu, name = obchodné meno subjektu, Reply-To = `entities.email`, BCC = `entities.email` (defaulty z D-27).

**Stalwart (D-27 otvorený bod):** Stalwart štandardne vyžaduje, aby From/MAIL FROM patril autentifikovanému účtu alebo jeho aliasom (`MtaStageAuth.mustMatchSender`, default true; staršie `session.auth.must-match-sender`). [CITED: support.stalw.art (community, v0.16 era) a mail-server CHANGELOG v0.5.1; MEDIUM, nepotvrdené oficiálnymi docs pre v1.0.1, ktorú projekt používa (`stalwartlabs/stalwart:v1.0.1`)] Dôsledok: každá nová From adresa na `vostack.dev` musí byť v `docker/mail/stalwart/plan.ndjson` pridaná ako alias účtu `noreply` (a `scripts/mail-provision.sh` spustený), alebo sa `mustMatchSender` vypne (neodporúčané, otvára spoofing v rámci domény). Plán má obsahovať: (1) dokumentovať v `docs/ops/mail.md` postup „nový odosielateľ = alias na `noreply`“, (2) test na stagingu/prod pred prvým ostrým použitím (SMTP odmietne s 5xx => `InvoiceMailer` vráti zlyhanie, nič sa nezapíše). `docker/mail/stalwart/plan.ndjson` nie je dnes v `main` obsahovo preverený pre aliasy (len upsert `Account`); overiť presný tvar `aliases` v schéme v1.0.1.

### Dashboard a zoznam
`TaskQueries::dashboard`/`DashboardFilter` už existujú; pridať `InvoiceStatusQueries::overdue(string $today, ?int $clientId, int $limit)` a render partial `home/_overdue.php` (zoradené `due_date ASC`, `days_overdue = today - due_date` v SQL cez `(:today::date - due_date)`). Zoznam faktúr: pridať stĺpec stavu a filter (`status` + príznak overdue) do `InvoiceQueries::list`.

### Routing
`app/Config/Routes/invoices.php` má len GET; pridať POST `invoices/(:num)/send|resend|remind|mark-sent|payments`, `payments/(:num)/void`, `settings/email-templates` (GET/POST). Všetko za existujúcimi filtrami (auth, CSRF; htmx CSRF test existuje: `tests/feature/HtmxCsrfTest.php`).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| MIME multipart, kódovanie hlavičiek | vlastný MIME builder | CI4 `Email` (+ podtrieda len pre Message-ID) | boundary, quoted-printable, UTF-8 v predmete |
| Validácia adries | regex | `filter_var(FILTER_VALIDATE_EMAIL)` + existujúci `CcEmails` + odmietnutie CR/LF | header injection |
| Šablónovanie | `eval`/Blade/Twig | `strtr` + whitelist | žiadny vykonateľný kód (D-15) |
| Peniaze | float | bcmath string (`Money`, `bccomp`) | CLAUDE.md |
| Transakcie/audit | vlastné BEGIN/COMMIT | `Transaction::run`, `AuditLogger` | WR-01 vnorenie |
| Formát súm a dátumov | `number_format`/ICU | `PdfFormat` | ICU rozdiely pre `1 080,00 €` |
| Čas | `date()`/`NOW()` v biznise | `service('clock')` | testovateľnosť, `FrozenClock` |
| Stav faktúry | stĺpec status + UPDATE | view | anti-vzor 2 |

## Common Pitfalls

1. **Message-ID prepísaný CI4** — viď Pattern 2; test cez Mailpit API. Bez toho D-12 (dohľadanie, In-Reply-To) nefunguje.
2. **Zdieľaný `service('email')` s prílohami z predchádzajúceho odoslania** (príloha faktúry A v e-maile faktúry B je najhorší možný bug). Vždy nová inštancia alebo `clear(true)`; unit test dvoch odoslaní po sebe.
3. **Stalwart odmietne cudzie From** (D-27) — alias v `plan.ndjson`; chyba je 5xx pri MAIL FROM/DATA; UI zobrazí všeobecnú chybu, log obsahuje SMTP odpoveď.
4. **DMARC a From**: From musí byť na doméne s DKIM podpisom Stalwartu (D-27 allowlist). Reply-To môže byť čokoľvek.
5. **`CREATE OR REPLACE VIEW`** zlyhá pri zmene poradia/typu stĺpcov; `down()` nesmie `DROP VIEW` bez obnovy pôvodnej definície (obnoviť pôvodných 17 stĺpcov, nie zmazať: Phase 9 a ďalšie od neho závisia). Nové tabuľky pre `app_rw`: `GRANT SELECT, INSERT`, nie UPDATE/DELETE; `invoice_sends`, `payments` pridať aj do `immutable_guard` triggerov (vzor `2026-10-27-000005/6`), vrátane TRUNCATE guardu.
5b. `invoice_status_v` je owner-owned view; `app_rw` SELECT na nové tabuľky je potrebný len ak view beží s právami volajúceho (default: s právami ownera view, takže stačí GRANT SELECT na view). [ASSUMED: PG view security_invoker default off]
6. **„Po splatnosti“ hranica**: `due_date < today` (deň splatnosti ešte nie je po splatnosti). Test s `FrozenClock` na hranici a cez polnoc Europe/Bratislava (vzor `TimezoneConsistencyTest`).
7. **Čiastočná úhrada a default suma**: zostatok sa berie z view v tej istej transakcii; dvojklik chráni `request_key`; súbeh chráni advisory lock.
8. **E-mail sa odošle z controllera v čase HTTP requestu**: p95 < 100 ms sa týka bežných stránok, nie odoslania; SMTP timeout 15 s < `fastcgi_read_timeout 30s`. Mail sieť nedostupná => rýchla chyba (`SMTPTimeout`), UI nesmie visieť; tlačidlo `submit-once`.
9. **Veľkosť príloh**: faktúra + výkaz zvyčajne < 1 MB; nginx `client_max_body_size` sa týka vstupu, nie SMTP. Stalwart limit správy overiť (default 25 MB, [ASSUMED]).
10. **Log transport na stagingu**: zapíše `invoice_sends` s `transport='log'`; UI má odlíšiť (štítok „testovacie, neodoslané“), aby sa nezamenilo za skutočné doručenie. Seed musí používať rovnakú cestu.
11. **Externé faktúry a dobropisy**: `origin='external'` nie je odoslateľná ani zaplatiteľná; `type='credit_note'` (Phase 9) zatiaľ vylúčiť z overdue (`type='invoice'`).
12. **iOS**: panel ako bottom sheet, `inputmode="email"`, `autocapitalize="off"` pre adresy, 44 px ciele; žiadny inline štýl/skript (CSP); všetky texty cez `lang()`.
13. **PHP 8.5 deprecations** v testoch (`failOnDeprecation`): netreba nič špecifické, ale `Email` podtrieda musí zachovať signatúry.

## Code Examples

### Sender allowlist
```php
// SenderIdentity::assertAllowed (zdroj: D-27, vlastný vzor)
$domain  = strtolower(substr(strrchr($from, '@') ?: '', 1));
$allowed = array_map('strtolower', array_filter(array_map('trim', explode(',', config('Email')->allowedFromDomains))));
if ($domain === '' || ! in_array($domain, $allowed, true)) { throw new SenderRejected(); }
```
### Zostatok a overdue (bcmath + Clock)
```php
$balance = bcsub($row['total'], $row['paid_amount'], 2);
$today   = service('clock')->now()->setTimezone(new DateTimeZone('Europe/Bratislava'))->format('Y-m-d');
$rows = $db->query("SELECT id, number, due_date, balance, (:today:::date - due_date) AS days_overdue
                    FROM invoice_status_v WHERE origin='issued' AND type='invoice' AND balance > 0 AND due_date < :today:::date
                    ORDER BY due_date ASC, id ASC LIMIT :lim:", [...])->getResultArray();
```
(CI4 `:name:` bindings a `::date` cast: pri zápise skontrolovať escapovanie `:today:::date`; bezpečnejšie `CAST(:today: AS date)`.)

## State of the Art

| Old | Current | Impact |
|-----|---------|--------|
| status stĺpec + UPDATE | odvodený view z udalostí | `invoices` ostáva insert-only |
| outbox/worker | synchrónne odoslanie po commite | stačí pre 1 používateľa (deferred: outbox) |

## Runtime State Inventory
Nie je rename/refactor fáza; vynechané. Poznámka: `entities` dostane nové stĺpce s defaultmi => existujúce subjekty musia mať migráciou nastavené defaulty (From meno = obchodné meno, Reply-To a BCC = `entities.email`) alebo sa defaulty riešia v `SenderIdentity` pri `NULL` (odporúčané, bez data migrácie).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | CI4 `attach($bytes,'attachment',$name,$mime)` berie buffer pri neprázdnom `$mime` | Pattern 2 | iný spôsob prílohy; spike v prvom pláne |
| A2 | CI4 `buildHeaders()` prepisuje vlastný `Message-ID`; riešenie override `getMessageID()` | Pattern 2 | ak neprepisuje, podtrieda netreba; ak je metóda `private`, treba PHPMailer/Symfony |
| A3 | Zdieľaná `service('email')` drží stav; použiť novú inštanciu | Pattern 2 | menší problém |
| A4 | `setFrom` 3. argument = Return-Path/envelope | Pattern 2 | envelope môže ostať = From a Stalwart ho odmietne |
| A5 | Stalwart v1.0.1 má `mustMatchSender` default zapnuté a aliasy riešia nové From | Sender identity | ak je vypnuté, aliasy netreba; ak alias schéma iná, upraviť plán |
| A6 | `FOR UPDATE` na `invoices` pod `app_rw` zlyhá (chýba UPDATE privilégium) | Pattern 5 | použiť advisory lock tak či tak (bezpečné) |
| A7 | View beží s právami ownera, stačí GRANT SELECT na view | Pitfall 5b | doplniť GRANTy na podkladové tabuľky |
| A8 | Stalwart limit veľkosti správy ~25 MB | Pitfall 9 | zanedbateľné |
| A9 | BCC nejde do viditeľných hlavičiek pri smtp protokole | Pattern 2 | únik BCC adresy klientovi; overiť testom cez Mailpit (hlavičky správy) |

## Open Questions (RESOLVED)

1. **Message-ID v CI4** — vyriešiť spike-om v prvej úlohe (Wave 0). Ak je override nemožný, použiť `symfony/mailer` (vyžaduje potvrdenie vlastníka: nová závislosť).
   RESOLVED: Plánovač overil zdroj CI4 v4.7.5 (`system/Email/Email.php`): `getMessageID()` je `protected` a `buildHeaders()` ho volá, preto `App\Libraries\Mail\AppEmail` prepisuje `getMessageID()`. Potvrdí spike v 07-01 (Task 1 `AppMailerMessageTest`, Task 2 `MailpitDeliveryTest`). `symfony/mailer` je iba záloha a len so súhlasom vlastníka (žiadna nová závislosť v pláne).
2. **Alias schéma Stalwart v1.0.1** — overiť v `docker/mail/stalwart/config.json`/admin API, kde sa zapisujú aliasy (nepodarilo sa overiť z oficiálnych docs).
   RESOLVED: Alias netreba pre bežné odosielanie: SMTP obálka (MAIL FROM) je vždy SMTP účet aplikácie cez `AppEmail::sendCommand('from')` override a `email.envelopeFrom` (07-01; A4 bol nesprávny, CI4 posiela MAIL FROM z hlavičky From). Postup s aliasom v Stalwarte pre ďalšiu From adresu je zdokumentovaný ako záloha v 07-03 (`docs/ops/mail.md`, `docs/ops/server.md` sekcia 20); From doména je obmedzená allowlistom `email.allowedFromDomains` (07-04 `SenderIdentity`, 07-07).
3. **Faktúra s `total = 0`** — stav? Odporúčanie: nikdy „uhradená“ automaticky.
   RESOLVED: Nikdy automaticky „uhradená“: pohľad v2 má podmienku `i.total > 0` pre `paid` a na faktúru so zostatkom 0 sa úhrada zapísať nedá (`already_paid`) (07-09). Odoslanie je povolené ako pri iných faktúrach (07-04).
4. **Tvar ručného „odoslaná“** — odporúčanie: `invoice_sends.kind='manual'` + `invoice_send_voids`; plán rozhodne.
   RESOLVED: `invoice_sends.kind = 'manual'` s `transport = 'none'` (CHECK) a storno cez insert-only `invoice_send_voids`; tabuľky a pohľad vznikajú v 07-04, služba a UI v 07-11.

## Environment Availability

| Dependency | Required By | Available | Notes |
|------------|------------|-----------|-------|
| Docker/PHP kontajner (vendor, PHPUnit, PG) | testy | nie je v tomto WSL checkoute (vendor mimo `/mnt/c`, volume) | spustiť testy v kontajneri podľa existujúceho postupu |
| Mailpit v dev | integračný test | ✓ (`compose.override.yaml`, `axllent/mailpit:v1.32`) | REST API `:8025/api/v1/messages` |
| Stalwart na prod | produkčné odoslanie | ✓ podľa `docs/ops/mail.md` | sieť `mail` pre php kontajner (nie je pripojená) |
| Staging | `transport=log` | ✓ | bez skutočného SMTP |

**Blocking:** žiadne. Pre prvé ostré odoslanie: pripojenie `mail` siete v `compose.server.yaml` a `app.env` (prod).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^10.5.16 (CIUnitTestCase), JS testy `tests/js/*.test.js` |
| Config | `phpunit.xml.dist` (existujúci), `tests/_support/PgTestCase.php` (`owner()`/`rw()`) |
| Quick run | `vendor/bin/phpunit --group integration tests/integration/Mail tests/integration/Receivables` (spúšťať v php kontajneri) |
| Full suite | `vendor/bin/phpunit` |

### Phase Requirements → Test Map
| Req | Behavior | Type | Command / File | Exists |
|-----|----------|------|----------------|--------|
| MAIL-01 | 2 prílohy, šablóna v jazyku dokladu, `invoice_sends` po úspechu | integration | `tests/integration/Mail/InvoiceMailerTest.php` (MockEmail + Mailpit) | ❌ W0 |
| MAIL-01/D-04 | zlyhanie SMTP nezmení faktúru ani nezapíše send | integration | `InvoiceMailerFailureTest.php` | ❌ W0 |
| MAIL-02 | bajty príloh == `invoice_documents` (sha256) | integration | `InvoiceMailerAttachmentTest.php` | ❌ W0 |
| D-11 | dvojklik s rovnakým `request_key` pošle raz | integration | `InvoiceMailerIdempotencyTest.php` | ❌ W0 |
| MAIL-03 | upomienka: kind, In-Reply-To, len faktúra, len zostatok>0 | integration | `ReminderTest.php` | ❌ W0 |
| MAIL-04 | whitelist, neznámy placeholder odmietnutý, fallback `en`, audit + revision | unit/integration | `tests/unit/Mail/TemplateRendererTest.php`, `EmailTemplateServiceTest.php` | ❌ W0 |
| D-27 | doména mimo allowlist odmietnutá; defaulty; uložené použité hodnoty | unit | `SenderIdentityTest.php` | ❌ W0 |
| PAY-01/03 | viac úhrad, default zostatok, preplatenie odmietnuté, bcmath, budúci dátum | integration | `tests/integration/Receivables/PaymentServiceTest.php` | ❌ W0 |
| D-28 | storno úhrady, nezapočíta sa, audit, dôvod povinný | integration | `PaymentVoidTest.php` | ❌ W0 |
| INV-06/PAY-02 | stavy a priorita, odoslaná pravidlo D-29, overdue hranica s `FrozenClock` | integration | `InvoiceStatusViewTest.php` | ❌ W0 |
| NFR (insert-only) | `app_rw` nemôže UPDATE/DELETE `invoice_sends`, `payments`, voids; `email_templates` len stĺpcový UPDATE | integration | rozšíriť `DbRolesTest.php` / `InvoiceImmutabilityTest.php` | ❌ W0 |
| view kompat. | pôvodných 17 stĺpcov nezmenených | integration | `InvoiceStatusViewColumnsTest.php` | ❌ W0 |
| Staging seed | `Modules\Deliveries` deterministický | integration | `tests/integration/StagingSeed/` | ❌ W0 |
| UI | dashboard sekcia, panel odoslania, CSRF htmx | feature | `tests/feature/Invoicing/DeliveryTest.php` | ❌ W0 |
| JS | submit-once na paneli | js | `tests/js/` existujúci vzor | čiastočne |

### Sampling Rate
- Per task commit: príslušný súbor `phpunit <file>`
- Per wave merge: `vendor/bin/phpunit --group integration`
- Phase gate: celá suite zelená + manuálne: Gmail „Show original“ SPF/DKIM/DMARC PASS, resend z iPhonu

### Wave 0 Gaps
- [ ] Spike Message-ID/attach/BCC správanie CI4 Email (test pozerá Mailpit API alebo `MockEmail`)
- [ ] Fixtures: vystavená faktúra s dokumentmi (`IssueFixtures`/`InvoiceFixtures` už existujú, doplniť helper na platby/odoslania)
- [ ] `tests/_support` Mailpit klient (voliteľný, preskočiť ak `MAILPIT_URL` chýba)

## Security Domain

### Applicable ASVS Categories (level 1)
| Category | Applies | Control |
|----------|---------|---------|
| V2/V3 Auth, Session | yes (existujúce) | Shield + TOTP, všetky nové trasy za auth filtrom |
| V4 Access Control | yes | jediný vlastník; kontrola existencie faktúry, `origin='issued'` |
| V5 Input Validation | yes | `filter_var`, CR/LF zákaz, limity, whitelist placeholderov, `esc()` v HTML tele |
| V6 Cryptography | yes | SMTP implicit TLS (`ssl`), `SMTPPass` len v `app.env`; žiadne tajomstvá v logu |
| V7 Logging | yes | log len metadáta, nikdy telo/prílohy (D-02), audit odoslaní/úhrad/šablón |
| V13 API/CSRF | yes | CSRF pre POST, htmx hlavičky (existujúce) |

### Threat Patterns
| Pattern | STRIDE | Mitigation |
|---------|--------|------------|
| Header injection cez predmet/adresy | Tampering | odmietnuť CR/LF, `filter_var`, CI4 `validate` |
| Spoofing From na cudziu doménu | Spoofing | allowlist domén, Stalwart `mustMatchSender` |
| Odoslanie cudzích bajtov / regenerované PDF | Tampering | `DocumentStore::read` + sha256, nikdy render |
| Template injection | Tampering/EoP | `strtr` whitelist, bez eval |
| Open relay / spam cez resend | Abuse | auth povinná, limit adresátov (napr. 10), audit |
| Únik BCC | Info disclosure | test hlavičiek správy |
| Dvojité odoslanie/úhrada | Repudiation | `request_key UNIQUE` |
| Úprava/mazanie záznamov | Tampering | GRANT insert-only + `immutable_guard` |

## Sources

### Primary (HIGH)
- Kód projektu: `app/Libraries/Mail/AppMailer.php`, `app/Config/Email.php`, `DocumentStore.php`, `Invoices.php`, migrácie `2026-10-27-000002/4`, `2026-10-28-000002`, `docs/ops/mail.md`, `compose.mail.yaml`, `tests/integration/Mail/SecurityNotifierTest.php`
- `07-CONTEXT.md`, `CLAUDE.md`

### Secondary (MEDIUM)
- PostgreSQL docs, CREATE VIEW (pravidlo pripájania stĺpcov), z pamäti, nie živé volanie v tomto behu
- Stalwart `must-match-sender`: support.stalw.art (community), CHANGELOG mail-server v0.5.1 (web search)

### Tertiary (LOW)
- CI4 `Email` interné správanie (attach buffer, Message-ID, setFrom returnPath): z pamäti; `vendor/` nie je v checkoute, nepodarilo sa overiť. Context7 a ctx7 sa nepoužili (nedostupné v tomto behu).

## Metadata
**Confidence:** stack HIGH (nič nové), architektúra HIGH (opiera sa o existujúce vzory), CI4 Email detaily LOW-MEDIUM (spike), Stalwart sender policy MEDIUM.
**Research date:** 2026-10-29 | **Valid until:** 2026-11-28
