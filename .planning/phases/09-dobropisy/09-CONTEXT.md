# Phase 9: Dobropisy - Context

**Gathered:** 2026-10-31
**Status:** Ready for planning

<domain>
## Phase Boundary

Vlastník opraví chybnú vystavenú faktúru vystavením **dobropisu**, ktorý je vždy **celé storno** pôvodnej faktúry. Dobropis je samostatný nemenný doklad (riadok v `invoices` s `type = 'credit_note'`) s vlastným radom subjektu, vlastným dátumom vystavenia, povinným dôvodom a typom opravy a odkazom na číslo a dátum pôvodnej faktúry. Obsahuje záporné položky celej pôvodnej faktúry (aj pri cudzej mene s kurzom pôvodnej faktúry). Pôvodná faktúra sa zobrazí ako **stornovaná**, jej údaje ani uložené PDF sa nezmenia. Časti (billing allocations) zo stornovanej faktúry sa uvoľnia a dajú sa fakturovať znova (aj do iného mesiaca). Dobropis má vlastné uložené PDF so `sha256` a odošle sa e-mailom rovnako ako faktúra. Storno je možné aj pre ukončený subjekt (ENT-04).

V tejto fáze: INV-07, INV-10 (a stav „stornovaná“ z INV-06, ktorý Phase 7 nechala na túto fázu).

Mimo tejto fázy: čiastkové dobropisy (v2+), odpočet dobropisov od obratu a limity (Phase 10, tu sa len uložia správne stĺpce), prehľady a exporty (Phase 11), KSeF FA(3) export a číslo KSeF pôvodnej faktúry (mimo V1), evidencia vrátenia peňazí klientovi (mimo V1).

</domain>

<decisions>
## Implementation Decisions

Vlastník rozhodol: rozhodnutia sa berú zo špecifikácie, PRD, REQUIREMENTS.md a `.planning/research/` bez ďalšej diskusie (pamäť „discuss-phase-from-spec“). Medzery, ktoré dokumenty nepokrývajú (D-07, D-08, D-10, D-12, D-17, D-18), vlastník potvrdil 2026-10-31 prevzatím navrhnutých predvolených hodnôt. Zvyšok je v „Claude's Discretion“.

### Dobropis ako doklad (INV-07, INV-10)
- **D-01:** Dobropis = **riadok v `invoices`** s `type = 'credit_note'`, `origin = 'issued'`, `corrected_invoice_id` = pôvodná faktúra, vyplneným `correction_reason` a `correction_type`. Schéma je pripravená z Phase 5 (D-19), CHECK `(type = 'credit_note') = (corrected_invoice_id IS NOT NULL)` a `type = 'credit_note' OR total >= 0` už platia. Tabuľka `invoices` ostáva plne insert-only. Zdroj: ARCHITECTURE (dátový model, „Dobropis = riadok v invoices“), Phase 5 D-19.
- **D-02:** Dobropis je **vždy celé storno**: jeden dobropis na jednu pôvodnú faktúru. V DB sa to vynúti čiastočným unikátnym indexom na `corrected_invoice_id WHERE type = 'credit_note'`, v službe zámkom pôvodnej faktúry (napr. `pg_advisory_xact_lock` alebo `SELECT … FOR UPDATE` na pôvodnom riadku) v tej istej transakcii ako vystavenie. Dobropis sa nedá stornovať ďalším dobropisom a nedá sa vystaviť na dobropis. Zdroj: spec §6 „Dobropis je v V1 vždy celé storno“, PROJECT.md Key Decisions, PITFALLS Pasca 20.
- **D-03:** Zapisuje ho jediná služba **`CreditNoteIssuer`** (vedľa `InvoiceIssuer`, rovnaký vzor: príkaz, náhľad s preview tokenom, `request_key uuid` proti dvojitému odoslaniu, jedna transakcia: číslo, riadky, snapshot, PDF, uvoľnenie častí, audit). Kontroléry do `invoices*` priamo nezapisujú. Zdroj: ARCHITECTURE („jediný zapisovateľ“), SUMMARY §Invoicing.
- **D-04:** Číslo dobropisu sa prideľuje z **radu `kind = 'credit_note'` subjektu pôvodnej faktúry** (predvolený formát `DB-RRRRMM###`, `EntityService::DEFAULT_CREDIT_NOTE_FORMAT`), obdobie čítača podľa **dátumu vystavenia dobropisu**, cez existujúci `NumberAllocator` (riadkový zámok na `invoice_counters`, bez medzier, nikdy `SEQUENCE`). Stornovaná faktúra si svoje číslo nechá navždy. Zdroj: ROADMAP kritérium 1, PITFALLS Pasca 4 („dobropis má vlastný rad“), Phase 3 rady.
- **D-05:** Obsah: **kópia všetkých riadkov pôvodnej faktúry so zápornou sumou** (rovnaký popis, hodiny/množstvo, sadzba, kód DPH; záporné je množstvo alebo suma podľa toho, čo zachová rovnosť `riadok = round(hodiny × sadzba, 2)`, rozhodne plán), `total = −total pôvodnej`, `line_count` rovnaký. Riadky dobropisu sa počítajú z uložených riadkov pôvodnej faktúry v DB, nie zo živých častí ani z JSONB. Súčet dobropisu + pôvodnej faktúry = presne 0,00 (test). Zdroj: ROADMAP kritérium 2, FEATURES „kópia pôvodnej faktúry so zápornými množstvami“, CLAUDE.md (bcmath).
- **D-06:** Údaje strán (subjekt, klient, bankový účet, jazyk dokladu, právny režim, kód oslobodenia, mena) sa preberajú zo **snapshotu pôvodnej faktúry**, nie zo živých nastavení. Dôvod: nemennosť, zhoda s opravovaným dokladom a funkčnosť pre ukončený subjekt aj zmenených klientov. `entity_id`, `entity_version_id`, `client_id`, `currency`, `doc_language`, `vat_regime`, `billing_mode` = hodnoty pôvodnej faktúry. Vlastný snapshot dobropisu (nová `snapshot_schema`) obsahuje navyše blok `correction` (číslo a dátum vystavenia pôvodnej faktúry, dôvod, typ opravy, rozsah `full`). Zdroj: Core Value, INV-03, ENT-04, PITFALLS Pasca 20.

### Dátumy a obdobie (INV-10)
- **D-07:** **Dátum vystavenia dobropisu** = dnes (`Clock`, Europe/Bratislava), dá sa zmeniť; spätný dátum je povolený s varovaním rovnako ako pri faktúre. Nesmie byť skorší ako dátum vystavenia pôvodnej faktúry (odmietne sa). **Dátum dodania** a **`turnover_date`** = hodnoty pôvodnej faktúry, `billing_month` = mesiac pôvodnej faktúry. **Dátum splatnosti** = dátum vystavenia dobropisu (schéma ho vyžaduje, dobropis sa neuhrádza). Zdroj: vlastník 2026-10-31, ARCHITECTURE („turnover_date pre dobropis = delivery_date originálu“), PITFALLS §Dobropisy (obdobie pôvodnej faktúry), ROADMAP poznámka.
- **D-08:** Obrat: dobropis sa od obratu odpočíta v **období pôvodnej faktúry** (cez `turnover_date` a `counts_toward_limit` skopírované z pôvodnej faktúry). V tejto fáze sa len uložia stĺpce, výpočet je Phase 10. Voľba je predvolená a čaká na potvrdenie účtovníčkou (otvorená otázka, nie blokujúca). Zdroj: ROADMAP poznámka, SUMMARY otázka 8, PITFALLS §Dobropisy.

### Dôvod a typ opravy (INV-10)
- **D-09:** **Dôvod** je povinný voľný text (1 až 500 znakov, `correction_reason`). Zobrazí sa na PDF dobropisu.
- **D-10:** **Typ opravy** (`correction_type`) je výber z pevného zoznamu kategórií (vlastník 2026-10-31): `data_error` (chybné údaje odberateľa alebo dodávateľa), `amount_error` (chybná suma, hodiny alebo sadzba), `fx_error` (chybný kurz), `order_cancelled` (zrušená zákazka alebo plnenie), `other`. Zoznam je v kóde (PHP enum alebo konštanta) s prekladmi v `lang()`, hodnota vyhovuje existujúcemu CHECK `^[a-z_]{3,30}$`. Rozsah opravy je vždy „celé storno“ a ukladá sa v snapshote (`correction.scope = 'full'`), aby sa neskôr dalo odvodiť KSeF `RodzajFaktury = KOR` / `TypKorekty`. Zdroj: INV-10, FEATURES M6 a §Dobropis / faktura korygująca.

### Mena a kurz
- **D-11:** Dobropis faktúry v cudzej mene **preberá kurz pôvodnej faktúry** (`fx_rate`, `fx_source`, `fx_date`, `fx_table_no`, `fx_date_basis` a snapshot blok `fx`), nikdy nesťahuje nový kurz. `total_entity_ccy = −total_entity_ccy pôvodnej` (skopírované, nie prepočítané, aby súčet bol presne 0). PDF ukáže kurz aj druhú sumu rovnako ako pôvodná faktúra. Zdroj: ROADMAP kritérium 2, Phase 8 CONTEXT („Dobropis v cudzej mene preberá kurz pôvodnej faktúry: Phase 9“).

### Uvoľnenie častí (INV-07)
- **D-12:** Pri vystavení dobropisu sa v tej istej transakcii **uvoľnia všetky časti** zamknuté na pôvodnú faktúru (`billing_allocations.invoice_id → NULL`). Uvoľnenie robí len nová `SECURITY DEFINER` funkcia (analogicky `lock_allocations()`), `alloc_guard` dostane jedinú výnimku: prechod `invoice_id: X → NULL` bez zmeny ostatných stĺpcov, a to len ak existuje dobropis s `corrected_invoice_id = X`. `app_rw` nedostane `UPDATE (invoice_id)`. Uvoľnené časti ostanú v pôvodnom fakturačnom mesiaci; presun do iného mesiaca robí používateľ existujúcou funkciou presunu častí (Phase 4), potom ich vyfakturuje novou faktúrou. Zdroj: ROADMAP kritérium 3, ARCHITECTURE (alloc_guard výnimka), komentár v `CreateBillingAllocations` migrácii, PITFALLS Pasca 20.
- **D-13:** História sa nemaže: `invoice_lines.source_allocation_id` (bez FK) na pôvodnej faktúre ostáva. Fakturačný stav úlohy a „vyfakturované hodiny“ sa počítajú len z nestornovaných faktúr (overiť `task_billing` pohľad a dashboard; ak počítajú z `billing_allocations.invoice_id`, uvoľnenie to vyrieši samo). Test: faktúra → dobropis → nová faktúra tých istých častí, súčty sedia a časti majú jedinú aktívnu faktúru. Zdroj: PITFALLS §väzba položka → časť a Pasca 20.
- **D-14:** Importované historické (externé) faktúry (`origin = 'external'`) sa **nedajú stornovať dobropisom** (nemajú vlastné číslo, platba išla tretej osobe, Phase 5 D-22, WR-07). Tlačidlo sa pri nich nezobrazí a služba ich odmietne. Zdroj: vlastník 2026-10-31.

### Stav „stornovaná“ (INV-06)
- **D-15:** Stav sa **neukladá**. `invoice_status_v` sa rozšíri cez `CREATE OR REPLACE VIEW` **len pripojením stĺpcov na koniec** (Pitfall 6): napr. `credit_note_id`, `credit_note_number`, a odvodený `status` dostane hodnotu `cancelled` (má prednosť pred `paid`/`sent`/`issued`). Stornovaná faktúra **nikdy nie je po splatnosti**, neponúka upomienku ani novú úhradu. Dobropis sám má v zozname vlastný stav (vystavený / odoslaný), nikdy nie je po splatnosti a nemá úhrady ani upomienky. Zdroj: Phase 7 D-21, ARCHITECTURE (odvodený stav), PITFALLS Pasca 20.
- **D-16:** Detail pôvodnej faktúry ukáže výrazné označenie „Stornovaná dobropisom č. … zo dňa …“ s odkazom na dobropis. Detail dobropisu ukáže „Opravuje faktúru č. … zo dňa …“ s odkazom. Uložené PDF pôvodnej faktúry sa nemení (žiadna pečiatka „STORNO“ do bajtov). Zdroj: ROADMAP kritérium 2, INV-13.

### Uhradená faktúra
- **D-17:** Dobropis je povolený aj pre **čiastočne alebo úplne uhradenú** faktúru (vlastník 2026-10-31). Náhľad dobropisu zobrazí varovanie so sumou prijatých úhrad („Prijaté úhrady X. Vrátenie peňazí klientovi sa rieši mimo aplikácie.“). Úhrady na pôvodnej faktúre ostávajú zaznamenané (insert-only), po storne sa už nedajú pridávať nové. Evidencia vrátenia peňazí je mimo V1. Zdroj: vlastník 2026-10-31, Phase 7 D-19/D-28.

### PDF dobropisu (ROADMAP kritérium 4)
- **D-18:** Dobropis má **vlastné PDF** vygenerované raz pri vystavení v tej istej transakcii, uložené cez `DocumentStore` do `invoice_documents` (`kind = 'invoice'` na id dobropisu) so `sha256`, verziou šablóny (napr. `cn-1`) a rendererom, nikdy sa neregeneruje. **Výkaz práce sa k dobropisu negeneruje**; deferred trigger `invoice_complete_check` sa novou migráciou upraví, aby `report` nevyžadoval pre `type = 'credit_note'`. Nadpis podľa jazyka dokladu (vlastník 2026-10-31): SK „Dobropis“, PL „Faktura korygująca“, CZ „Opravný daňový doklad“, EN „Credit note“. Na PDF: číslo a dátum vystavenia dobropisu, **odkaz na číslo a dátum vystavenia pôvodnej faktúry**, typ opravy, dôvod, záporné riadky a súčet, kurz (ak je), právne texty z profilu (rovnaká revízia ako pôvodná faktúra, ak sa dá; rozhodne plán). Bez QR platby. Texty nadpisov sú v `lang()`/legal texts, finálne znenie môže upraviť účtovníčka. Zdroj: INV-10, INV-13, FEATURES §Dobropis / faktura korygująca, Phase 6.

### E-mail (ROADMAP kritérium 4)
- **D-19:** Dobropis sa odosiela **rovnakým mechanizmom ako faktúra** (`InvoiceMailer`, `invoice_sends` na id dobropisu, ručné označenie odoslania, opätovné odoslanie). Nová šablóna `email_templates.key = 'credit_note'` pre každý jazyk (sk, pl, cs, en), editovateľná v Nastaveniach ako ostatné; CHECK na `key` sa rozšíri migráciou a seedne sa predvolený text. Príloha: **iba PDF dobropisu**. Adresáti predvolene `invoice_email` + `cc_emails` klienta zo snapshotu pôvodnej faktúry. Zdroj: vlastník 2026-10-31, Phase 7 D-10, D-24.

### Bezpečnosť a audit
- **D-20:** Vystavenie dobropisu sa zapíše do `audit_log` (pôvodná faktúra, dobropis, dôvod, typ, počet uvoľnených častí). Akcia je POST s CSRF, dvojkrokový tok náhľad → potvrdenie, server overí preview token, `request_key` a že pôvodná faktúra je vlastná (`origin = 'issued'`), typu `invoice` a ešte nestornovaná. Zdroj: Phase 5 vzor, PITFALLS.

### Claude's Discretion
- Presný tvar záporných riadkov (záporné hodiny vs. záporná suma pri kladných hodinách), pokiaľ platí CHECK riadku a súčet = −pôvodný.
- Či `CreditNoteIssuer` zdieľa časť `InvoiceIssuer` (spoločná trieda, trait) alebo je samostatný.
- Názvy nových stĺpcov pohľadu, nového rendereru a verzie šablóny.
- Umiestnenie tlačidla „Vystaviť dobropis“ (detail faktúry, prípadne menu akcií) a vzhľad náhľadu, v súlade s UI-SPEC/UX pravidlami (44 px ciele, jedna ruka).
- Či dobropis pri vystavení zobrazí ponuku „Prejsť na uvoľnené časti“ alebo „Presunúť do mesiaca …“ ako skratku k existujúcim funkciám.
- Rozšírenie staging seedu o 1 až 2 dobropisy (odporúčané pre UAT).
- PHPUnit pokrytie: súčty (dobropis + faktúra = 0 v mene faktúry aj subjektu), číslovanie (rad dobropisov, súbeh dvoch dobropisov na tú istú faktúru), uvoľnenie častí a výnimka `alloc_guard`, kópia údajov (zmena subjektu/klienta po vystavení nezmení dobropis), kurz prevzatý z pôvodnej faktúry, ukončený subjekt.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Požiadavky a rozsah
- `.planning/ROADMAP.md` §Phase 9 — cieľ, kritériá úspechu, poznámky (závislosť od účtovníčky)
- `.planning/REQUIREMENTS.md` — INV-07, INV-10, INV-06 (stav stornovaná), ENT-04
- `docs/PRD.md` — INV-06, INV-07, ENT-04, LIM-02 (mínus dobropisy)
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` §faktúry (riadky 126–128), §ukončený subjekt (riadok 85), §limity (riadok 192)
- `.planning/PROJECT.md` — Key Decisions („Dobropis je vo V1 vždy celé storno a uvoľní zamknuté časti“)

### Výskum
- `.planning/research/ARCHITECTURE.md` — dátový model `invoices` (type, corrected_invoice_id, turnover_date pre dobropis), odvodený stav, `alloc_guard` výnimka pre dobropis, `CreditNoteIssuer`
- `.planning/research/PITFALLS.md` — Pasca 4 (vlastný rad), §väzba položka → časť, §Dobropisy a obrat, Pasca 20 (vystavenie dobropisu a stav faktúr)
- `.planning/research/FEATURES.md` — M6, §Dobropis / faktura korygująca (SK vs PL), KSeF korekty
- `.planning/research/SUMMARY.md` — otvorené otázky 8 (obdobie) a 10 (PL `PrzyczynaKorekty`, KSeF)

### Predchádzajúce fázy
- `.planning/phases/05-faktura-n-jadro/05-CONTEXT.md` — D-19 (stĺpce pre dobropis), D-20, D-22 (externé faktúry), vzor vystavenia a preview tokenu
- `.planning/phases/06-dokumenty-fakt-ry-a-v-kazu/06-CONTEXT.md` — D-05 (`invoice_complete_check`, dokument `report`), PDF uloženie a renderery
- `.planning/phases/07-doru-enie-hrady-a-upomienky/07-CONTEXT.md` — D-10 (`invoice_sends`), D-19 (úhrady), D-21 (`invoice_status_v`, Phase 9 doplní `cancelled`), D-24 (upomienky), D-28 (storno úhrady)
- `.planning/phases/08-meny-a-kurzy/08-CONTEXT.md` — D-09, D-10, D-14 (fx stĺpce a snapshot), dobropis preberá kurz

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Database/Migrations/2026-10-27-000002_CreateInvoices.php`: `type`, `corrected_invoice_id`, `correction_reason`, `correction_type` a CHECK-y pre dobropis už existujú.
- `app/Libraries/Master/SeriesService.php` + `EntityService::DEFAULT_CREDIT_NOTE_FORMAT` (`DB-RRRRMM###`): rad `credit_note` existuje pre každý subjekt, nastaviteľný v `app/Views/settings/entities/_series.php`.
- `app/Libraries/Invoicing/NumberAllocator.php`: transakčné číslovanie s riadkovým zámkom.
- `app/Libraries/Invoicing/InvoiceIssuer.php`, `IssueCommand.php`, `IssueInput.php`, `IssueRejected.php`, `InvoiceCalculator.php` (preview token), `SnapshotBuilder.php` (vrátane `fxBlock`): vzor pre `CreditNoteIssuer`.
- `app/Libraries/Invoicing/DocumentStore.php`, `RendererRegistry.php`, `MpdfInvoiceInv2Renderer.php`: uloženie PDF so `sha256`, nový renderer pre dobropis.
- `app/Libraries/Delivery/InvoiceMailer.php`, `EmailTemplateService.php`, `ManualSendService.php`: odoslanie a šablóny (`key` CHECK `invoice|reminder` treba rozšíriť).
- `app/Libraries/Receivables/InvoiceStatusQueries.php`, `PaymentService.php`: stav a úhrady (zablokovať úhrady a upomienky pre stornovanú faktúru a dobropis).
- `app/Libraries/Fx/FxFormat.php`: text kurzu pre PDF.

### Established Patterns
- `invoices*` sú insert-only (triggery + práva `app_rw`), stav sa odvodzuje pohľadom `invoice_status_v`, ktorý sa rozširuje iba pripájaním stĺpcov na koniec.
- Zámok častí: `alloc_guard()` + `SECURITY DEFINER lock_allocations()` (`2026-10-27-000003_LockAllocationsToInvoices.php`); nikdy `GRANT UPDATE (invoice_id)` pre `app_rw`.
- Deferred trigger `invoice_complete_check` (`2026-10-28-000001_RequireReportDocument.php`) vyžaduje dokumenty pri vystavení.
- Peniaze ako string + bcmath, `Clock` pre dátumy (Europe/Bratislava), `Transaction::run`.
- Audit do `audit_log`, CSRF, htmx náhľad → potvrdenie, `request_key uuid`.

### Integration Points
- Detail faktúry (tlačidlo „Vystaviť dobropis“, označenie stornovanej), zoznam faktúr a dashboard (stav `cancelled`, vylúčenie z „po splatnosti“).
- Fakturačný stav úlohy / `task_billing` pohľad a dashboard „nevyfakturované“ po uvoľnení častí.
- Nastavenia e-mailových šablón (nová šablóna `credit_note`).
- Staging seed (`app/Libraries/StagingSeed/`).

</code_context>

<specifics>
## Specific Ideas

- Nadpisy PDF: SK „Dobropis“, PL „Faktura korygująca“, CZ „Opravný daňový doklad“, EN „Credit note“.
- Varovanie pri uhradenej faktúre: „Prijaté úhrady X. Vrátenie peňazí klientovi sa rieši mimo aplikácie.“
- Kľúčový test: faktúra → dobropis → nová faktúra tých istých častí v inom mesiaci, súčty sedia na obrazovke, v PDF aj v stĺpcoch.

</specifics>

<deferred>
## Deferred Ideas

- Čiastkové dobropisy (oprava časti faktúry, „pred a po“): v2+.
- Evidencia vrátenia peňazí klientovi pri storne uhradenej faktúry: mimo V1.
- KSeF číslo pôvodnej faktúry a FA(3) `DaneFaKorygowanej` / `PrzyczynaKorekty` export: mimo V1 (údaje sa ukladajú).
- Otvorené otázky pre účtovníčku (neblokujú implementáciu, riešia sa konfiguráciou alebo textami): obdobie odpočtu dobropisu od obratu (predvolene obdobie pôvodnej faktúry), povinné náležitosti PL faktury korygującej pre neplatiteľa, presné znenie nadpisov a textov.

</deferred>

---

*Phase: 09-dobropisy*
*Context gathered: 2026-10-31*
