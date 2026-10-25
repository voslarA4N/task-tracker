# Phase 4: Úlohy, fakturačné časti a dashboard - Context

**Gathered:** 2026-10-25
**Status:** Ready for planning

<domain>
## Phase Boundary

Vlastník eviduje úlohy klientov s nacenením, rozdeľuje ich na fakturačné časti po mesiacoch (aj čiastočne, aj pred zapracovaním) a na mobile vidí dashboard (variant B) s tým, čo treba tento mesiac fakturovať. Reálne dáta za 09 a 10/2026 z `docs/All4net-fakturovanie-prace.xlsx` sú naimportované.

Požiadavky: TSK-01 až TSK-06, ALC-01, ALC-02, ALC-04, ALC-05, DSH-01, IMP-01.

**Nie je v tejto fáze:** faktúry a ich vystavenie (Phase 5), preto ani skutočné zamknutie časti faktúrou. Úplné ALC-03 (zamknutá časť, test „zmeň po vystavení“) sa dokončí v Phase 5. Tu sa pripraví stĺpec `invoice_id`, atómová podmienka `WHERE invoice_id IS NULL` a DB trigger. Ďalej tu nie je dashboardová dlaždica po splatnosti (PAY-02, Phase 7), upozornenia na limity (LIM-03, Phase 10), prehliadač denníka (AUD-02, Phase 11), PC tabuľkové zoznamy faktúr (Phase 11) ani preklady PL/CZ/EN (Phase 12, texty však idú cez `lang()`).

**Spôsob získania rozhodnutí:** vlastník zvolil „Prevziať zo špecifikácie“ (rovnako ako v Phase 3). Rozhodnutia nižšie sú prevzaté zo špecifikácie, PRD, REQUIREMENTS.md, ROADMAP a výskumu (`.planning/research/`). **Pri rozpore platí špecifikácia**, okrem rozhodnutí zapísaných v REQUIREMENTS.md (TSK-03). Body, ktoré dokumenty neriešia, sú v Claude's Discretion. Vlastník videl navrhnuté predvoľby (body 1 až 4) a schválil ich bez zmeny.

</domain>

<decisions>
## Implementation Decisions

### Úloha
- **D-01:** Polia úlohy: klient, URL na portáli, názov, nacenené hodiny `numeric(7,2)`, sadzba `numeric` (kópia z klienta pri vytvorení, prepisovateľná), nacenená suma (odvodená cez `Money`, half-up na 2 desatinné miesta), poznámka, dátum vytvorenia, stav `in_progress | done` a `done_at` (TSK-01, TSK-03, spec 6.3). Mena úlohy je mena klienta.
- **D-02:** Klient sa predvolí podľa **posledného použitého** klienta (TSK-01). Ponúkajú sa len nearchivovaní klienti. Nacenená suma sa prepočítava už pri písaní (Alpine, CSP build). Server sumu vždy prepočíta sám a hodnote z klienta neverí.
- **D-03:** Zmazať sa dá len úloha bez zamknutých častí (TSK-02). Nezamknuté časti sa zmažú spolu s úlohou v jednej transakcii a s auditom. Kontrolu robí server: atómovo v SQL a cez FK alebo trigger. Skrytie tlačidla v UI nestačí.
- **D-04:** Fakturačný stav sa **neukladá**, odvodzuje sa (výskum Vzor 4, pohľad typu `task_billing_v`). **Vyfakturovaná** = zapracovaná, bez nezamknutej časti a aspoň jedna časť zamknutá. **Čiastočne** = aspoň jedna zamknutá časť, ale nie vyfakturovaná. Inak **nevyfakturovaná** (TSK-03, rozhodnutie vlastníka v REQUIREMENTS.md). Kým neexistujú faktúry, budú všetky úlohy nevyfakturované. Logika sa preto testuje nad časťami s nastaveným `invoice_id` v testovacích dátach.
- **D-05:** Úloha zobrazuje rozdiel nacenených a fakturovaných hodín (napr. „+6 h“ alebo „−2 h“). Nič sa neblokuje: časti môžu presiahnuť nacenené hodiny (TSK-05, spec 6.3). Pri všetkých hodinách a sumách je vždy uvedená mena a popisok, ktorý odlišuje nacenené a fakturované (výskum, UX pasce).
- **D-06:** Zoznam „Aktuálne úlohy“ obsahuje úlohy, ktoré nie sú zapracované **alebo** majú nezamknuté časti (TSK-04, spec 6.3). Filtre: klient, stav, fakturačný mesiac a text v názve, URL a poznámke (TSK-06). Filtre sa aktualizujú cez htmx fragment a stav filtra je v URL. Zoznam sa načíta jedným agregačným dotazom bez N+1 (výskum, Pasce výkonu).

### Fakturačná časť
- **D-07:** Tabuľka `billing_allocations`: `task_id`, `hours numeric(7,2) CHECK (hours > 0)`, `billing_month` (RRRR-MM), `rate`, `amount` (uložená, `= round(hours*rate, 2)` cez `CHECK`), `currency` (kópia z klienta pri vzniku) a `invoice_id bigint NULL` (NULL = nezamknutá). Partial index `(…, billing_month) WHERE invoice_id IS NULL` (výskum ARCHITECTURE, ALC-01).
- **D-08:** Sadzba časti sa predvolí sadzbou úlohy a dá sa prepísať (spec 6.4). Suma sa vždy počíta cez `Money`, nikdy cez float.
- **D-09:** Úprava, presun do iného mesiaca a zmazanie sú dovolené len pre nezamknutú časť (ALC-02). Server to kontroluje **v tom istom SQL príkaze** (`UPDATE/DELETE … WHERE id = :id AND invoice_id IS NULL` s kontrolou počtu riadkov), nie postupom „načítaj, skontroluj, ulož“. Pri neúspechu vráti 409/422 so správou. Ako posledná línia slúži `BEFORE UPDATE OR DELETE` trigger, ktorý odmietne zmenu zamknutej časti (výskum: obrana v troch vrstvách). Výnimka pre uvoľnenie dobropisom sa doplní až v Phase 9, keď existujú dobropisy. Tu trigger zamknutú časť odmieta vždy.
- **D-10:** Zmazanie časti na mobile vyžaduje potvrdenie (panel zospodu) (výskum, UX pasce).
- **D-11:** Panel „Označiť ako zapracovanú“ (ALC-04, spec 6.4, maketa `task-flows.html`): mesiac Aktuálny / Ďalší / Iný… (predvolený je aktuálny), hodiny (predvolené na zostatok nacenených hodín mínus všetky existujúce časti, dajú sa upraviť), živý prepočet sumy. Po potvrdení sa v jednej transakcii nastaví stav `done` s `done_at` (dnes podľa `Clock`), vytvorí sa časť a zapíše sa audit.
- **D-12:** Časť sa dá pridať aj k nezapracovanej úlohe tlačidlom „+ Pridať časť do mesiaca“ (ALC-05). Detail úlohy ukazuje **vyfakturované** (zamknuté), **naplánované** (nezamknuté) a **zostávajúce** (nacenené − všetky časti, nikdy pod 0 v ukazovateľi) hodiny, zoznam častí (zamknuté s číslom faktúry, ostatné upraviteľné) a odkaz na portál (spec 7 Detail úlohy).
- **D-13:** Všetky zmeny úloh a častí sa zapisujú cez existujúci `AuditLogger` v tej istej transakcii (AUD-01, vzor z Phase 3).

### Dashboard (variant B)
- **D-14:** Obsah podľa DSH-01 a makety `mobile-home.html`: hore aktuálny fakturačný mesiac a klient, dlaždice *Na fakturáciu tento mesiac* (suma a hodiny nezamknutých častí s fakturačným mesiacom = vybraný mesiac) a *V práci* (suma a hodiny nezapracovaných úloh). Pod nimi úlohy zoskupené podľa stavu: **Zapracované, do faktúry** / **Čiastočne vyfakturované** (s ukazovateľom priebehu) / **V práci**. Ďalej plávajúce tlačidlo „+“ a spodná lišta Prehľad / Úlohy / Faktúry / Viac. Položka „Úlohy“ v `_nav_items.php` sa stane aktívnym odkazom.
- **D-15:** Dlaždice sa po zmene časti obnovia cez `HX-Trigger` (napr. `allocationChanged`). Po úspešnom POST cez htmx sa používa `HX-Location` alebo `HX-Redirect`, nie 302 (výskum Vzor 5).

### Import z Excelu
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

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Požiadavky a rozsah
- `.planning/ROADMAP.md` § Phase 4: cieľ, kritériá úspechu, poznámky (PhpSpreadsheet len `require-dev`, `spark` import, ALC-03 až v Phase 5)
- `.planning/REQUIREMENTS.md`: TSK-01 až TSK-06, ALC-01 až ALC-05, DSH-01, IMP-01 a tabuľka rozhodnutí vlastníka (definícia „vyfakturovaná“)
- `docs/PRD.md` § E4, E5, E10 (DSH-01), E12 (IMP-01): kritériá prijatia E5 (200 h úloha, 4 časti, 4 mesiace; vyfakturované, naplánované a zostávajúce hodiny)
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` § 6.3, 6.4, 7 (Mobil), 9: **pri rozpore platí špecifikácia**

### Návrh a riziká
- `.planning/research/ARCHITECTURE.md` § Klienti, úlohy, časti; Vynucovanie nemennosti (trigger `alloc_guard`, GRANT); Vzor 4 (odvodené stavy); Vzor 5 (htmx fragmenty, `HX-Trigger`, `HX-Location`); Odporúčané poradie výstavby, krok 3
- `.planning/research/PITFALLS.md` Pasca 7 (fakturačný mesiac vs dátum vystavenia vs dátum dodania), Pasca 15 (import z Excelu), Pasce výkonu (N+1), UX pasce
- `.planning/research/STACK.md` a `CLAUDE.md`: PhpSpreadsheet 5.10.0 len `require-dev`, hyperlinky v `sheet1.xml.rels`

### UI
- `docs/mockups/mobile-home.html`: dashboard variant B, dlaždice, skupiny, spodná lišta
- `docs/mockups/task-flows.html`: nová úloha, panel „Zapracované“, detail úlohy (vyfakturované / naplánované / zostáva)
- `.planning/phases/03-master-d-ta-subjekty-a-klienti/03-UI-SPEC.md` a `.planning/phases/02-identita-a-skor-nasadenie/02-UI-SPEC.md`: existujúci UI kontrakt

### Zdrojové dáta
- `docs/All4net-fakturovanie-prace.xlsx`: zdroj importu, mimo gitu, obsahuje dáta klienta

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Libraries/Platform/Money.php` (bcmath, half-up) a `Clock.php`/`FrozenClock.php`: suma časti, nacenená suma, aktuálny mesiac a `done_at`.
- `app/Libraries/Platform/Transaction.php`: transakčný obal pre „Zapracované“ (stav + časť + audit) a import.
- `app/Libraries/Audit/AuditLogger.php`: audit úloh a častí v tej istej transakcii.
- `app/Libraries/Master/ClientService.php`, `Currency.php`, `PortalUrlTemplate.php`: sadzba a mena klienta, vzor odkazu na portál (odvodenie URL z ID úlohy pri zadávaní).
- `app/Controllers/Settings/Clients.php` a views nastavení: vzor CRUD s htmx fragmentmi, 422 a CSRF (`HtmxCsrf`).
- `app/Views/layouts/_nav_items.php`: spodná lišta, „Úlohy“ je dnes `aria-disabled`.
- `app/Controllers/Home.php` a `app/Views/home/index.php`: miesto pre dashboard (Prehľad).
- `app/Commands/*`: vzor `spark` príkazov (napr. `UserCreate.php`) pre `import:excel`.

### Established Patterns
- Migrácie `spark migrate -g owner|tests_owner`. `app_rw` dostane DML iba cez `grantMutable()`. Testy bežia ako `app_rw` a overujú oprávnenia aj triggery.
- `numeric` sa z CI4 vracia ako string a počíta sa cez bcmath. PHPStan pravidlo zakazuje float a čas mimo `Clock`.
- Texty iba cez `lang()` (`ViewsHardcodedTextTest`), žiadne inline skripty ani štýly (`ViewsCspTest`), Alpine CSP build.
- Služby v `app/Libraries/<Kontext>/` (Phase 3 použila `Master/`). Úlohy a časti patria do nového kontextu (napr. `Work/`). Do budúcich `invoices*` nezapisujú, faktúry poznajú len cez `billing_allocations.invoice_id`.
- Routy v module `app/Config/Routes/*.php`.

### Integration Points
- Phase 5 (vystavenie) číta nezamknuté časti klienta a mesiaca cez partial index a nastavuje `invoice_id` atómovo s podmienkou `invoice_id IS NULL`.
- Phase 9 (dobropis) rozšíri trigger o uvoľnenie časti po dobropise.
- Phase 7 a 10 pridajú do dashboardu dlaždice po splatnosti a limity.

</code_context>

<specifics>
## Specific Ideas

- Klient All4Net: sadzba 27 € bez DPH, portál Taskman. Excel má mesačné bloky 09 a 10/2026 a chybný októbrový súčet E25/F25 (počíta len riadky 19 až 22). Suchý beh tento rozdiel musí ukázať.
- Cieľ G5 (PRD): pridanie úlohy a označenie ako zapracovanej na iPhone do 30 s. Dotykové ciele aspoň 44 px, ovládanie jednou rukou.
- Kritérium E5: úloha s 200 h a so 4 časťami v 4 mesiacoch, každá časť v správnom mesiaci (test).

</specifics>

<deferred>
## Deferred Ideas

Žiadne. Diskusia sa nekonala, rozsah ostáva podľa ROADMAP.

</deferred>

---

*Phase: 04-lohy-faktura-n-asti-a-dashboard*
*Context gathered: 2026-10-25*
