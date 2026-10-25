# Phase 3: Master dáta (subjekty a klienti) - Context

**Gathered:** 2026-10-25
**Status:** Ready for planning

<domain>
## Phase Boundary

Vlastník spravuje svoje subjekty (SK, PL, pripravené CZ) s verziami údajov „platné od“, viacerými bankovými účtami, číselným radom a nemennou krajinou a menou. Ďalej spravuje klientov so všetkými údajmi, ktoré neskôr vstúpia do kópie údajov faktúry. Právny dôvod oslobodenia od DPH je štruktúrovaný kód v právnom profile. Každá zmena subjektov, klientov a nastavení sa zapíše do denníka zmien (AUD-01).

Požiadavky: ENT-01 až ENT-07, CLI-01 až CLI-07, SET-02, AUD-01.

**Nie je v tejto fáze:** prehliadanie denníka s filtrami (AUD-02), úprava zákonných textov v UI (SET-01, Phase 6), tabuľka limitov a jej UI (LIM-*, Phase 10), kurzy (Phase 8), samotné pridelenie čísla faktúry (Phase 5; tu je len konfigurácia radu a schéma).

**Spôsob získania rozhodnutí:** vlastník diskusiu výslovne odmietol („Spolieham sa na presnosť špecifikácie a PRD, odtiaľ zober všetko“). Všetky rozhodnutia nižšie sú preto prevzaté zo špecifikácie, PRD, REQUIREMENTS.md a výskumu (`.planning/research/`). **Pri rozpore platí špecifikácia** (PROJECT.md), s výnimkou odchýlok, ktoré sú už zapísané ako rozhodnutia v REQUIREMENTS.md (ENT-07).

</domain>

<decisions>
## Implementation Decisions

### Subjekt (dodávateľ)
- **D-01:** Viac subjektov. Každý má obdobie pôsobenia `active_from` až `active_to` (do je voliteľné), krajinu (SK, PL, CZ), menu, jazyk dokladov, DPH režim, vlastný číselný rad a predvolenú splatnosť (ENT-01, spec 6.1).
- **D-02:** **Krajina a mena sú na subjekte a po vytvorení sa nedajú zmeniť** (ENT-07, rozhodnutie zapísané v REQUIREMENTS.md). Iná krajina alebo mena znamená nový subjekt. Kontroluje to server (nie len skrytie poľa v UI) a pokrýva to test.
- **D-03:** Verzované údaje (ENT-02, ENT-05): obchodné meno, sídlo, IČO alebo NIP/REGON, DIČ, voliteľné IČ DPH / VAT-UE (NIP UE), údaj o zápise v registri (SK: živnostenský register, okresný úrad, číslo; PL: CEIDG), e-mail, telefón, jazyk dokladov, DPH režim, predvolená splatnosť.
- **D-04:** **Každá zmena údajov vytvorí novú verziu s dátumom „platné od“** (ENT-03). Verzie sa nemenia ani nemažú. `entity_versions` je **insert-only** (prvé použitie vzoru „GRANT + insert-only“: `app_rw` iba SELECT a INSERT, bez `grantMutable()`), `valid_from date NOT NULL`, `UNIQUE (entity_id, valid_from)`. Verzia platná k dátumu: `valid_from <= :d ORDER BY valid_from DESC LIMIT 1` (výskum, Pasca 7). Nová faktúra použije verziu platnú k **dátumu vystavenia**. Služba na výber verzie k dátumu vznikne už tu a otestuje sa.
- **D-05:** História verzií je viditeľná v nastaveniach subjektu (spec 6.1, ROADMAP kritérium 2). Zmena nemení staršie platné obdobie.
- **D-06:** Viac bankových účtov (IBAN, SWIFT/BIC, mena) na subjekte. Faktúra neskôr použije účet v mene faktúry, inak predvolený (ENT-06). Jeden účet je predvolený.
- **D-07:** Ukončený subjekt (`active_to` v minulosti) sa naďalej zobrazuje a je vybrateľný pre storno a opätovné odoslanie svojich dokladov (ENT-04). Pre nové faktúry sa predvyplní subjekt aktívny k dátumu vystavenia. Na prelome môžu byť aktívne oba subjekty naraz (spec 6.1). Prekryv období nie je chyba.
- **D-08:** Slovenský subjekt: EUR, neplatiteľ DPH. Poľský subjekt: PLN, zwolnienie podmiotowe (art. 113 ust. 1). Obidva musia ísť vytvoriť cez UI (ROADMAP kritérium 1).

### Číselný rad
- **D-09:** Číselný rad patrí subjektu (spec 6.5 Číslovanie). Formát sa nastavuje tokenmi `RRRR`, `RR`, `MM`, `#…` (počet `#` je počet číslic) a pevným textom. Predvolený je `RRRRMM###`. Ak formát obsahuje `MM`, poradie sa resetuje mesačne, inak ročne. Počiatočné číslo radu sa dá nastaviť (predvolene 1).
- **D-10:** Formát čísla sa neverzuje so subjektom, patrí do `invoice_series` (výskum ARCHITECTURE). Dobropis má vlastný rad (`kind = invoice | credit_note`), aby sa schéma neskôr neprerábala. Zmena formátu je dovolená, len kým rad nemá vystavené doklady (výskum). V tejto fáze ešte žiadne neexistujú, kontrola sa však pripraví.
- **D-11:** Samotné pridelenie čísla (čítač, zámok, `ON CONFLICT … RETURNING`) je Phase 5. Tu vznikne len konfigurácia radu a validácia formátu (parser tokenov s testom).

### Klient
- **D-12:** Údaje klienta (CLI-02 až CLI-04): názov, adresa, krajina, IČO, DIČ, IČ DPH/VAT ID, e-mail na faktúry, e-maily v kópii (viac), hodinová sadzba `numeric` a mena (EUR, PLN, CZK), jazyk dokladov (voliteľný, prebije jazyk subjektu), splatnosť (voliteľná, prebije predvolenú), názov portálu úloh a vzor odkazu na portál.
- **D-13:** Typ identifikátora klienta (CLI-07): IČO/NIP, EÚ VAT ID s prefixom krajiny, iné ID, žiadne. Ukladá sa štruktúrovane kvôli neskoršiemu exportu FA(3).
- **D-14:** Režim fakturácie `domestic | eu_b2b | non_eu` (CLI-05) určuje text zákonnej poznámky. Pri `eu_b2b` sa na faktúru tlačí IČ DPH klienta (spec 6.5). Príznak „započítavať do limitu obratu“ je predvolene áno (CLI-06).
- **D-15:** Klient sa pridá, upraví a archivuje, nikdy sa nemaže (CLI-01). Klient sa neverzuje (spec 6.2: samostatná história klienta nie je potrebná). Nemennosť dokladov rieši kópia údajov na faktúre. Zmeny klienta idú do denníka.

### Právny profil a kód oslobodenia
- **D-16:** Právny dôvod oslobodenia od DPH je štruktúrované pole s kódom (napr. `ART_113_1`, `ART_113_9`, reverse charge `ART_28B_RC`) v právnom profile (SET-02). Profil je určený krajinou, DPH režimom a režimom fakturácie (spec 6.5 Zákonné náležitosti). Kód sa neskôr uloží do kópie údajov.
- **D-17:** `legal_texts` (krajina, DPH režim, režim fakturácie, jazyk, kľúč, text) sa seeduje **predbežne** pre SK a PL. Znenie potvrdí účtovníčka (ROADMAP, riziko R1). Úprava textov v UI je až Phase 6 (SET-01). Pri hľadaní textu platí poradie: presná zhoda, potom `billing_mode NULL`, potom jazyk `en`, inak chyba, nikdy ticho prázdny text (výskum).

### Denník zmien
- **D-18:** `audit_log` je append-only (insert-only cez GRANT): používateľ, čas, typ entity, ID, akcia, hodnoty pred a po v `jsonb`, IP (AUD-01). Zapisuje ho explicitná služba `AuditLogger` volaná zo služieb **v tej istej transakcii** ako zmena, nie DB triggery ani model callbacky (výskum ARCHITECTURE). Citlivé polia sa nikdy nezapisujú. V tejto fáze sa zapisujú zmeny subjektov, verzií, bankových účtov, radov, klientov a nastavení. Služba bude pripravená aj pre ďalšie entity.

### Claude's Discretion
Vlastník tieto body nechal na plánovača. Rozhodne podľa výskumu a existujúcich vzorov:
- UX vzniku verzie: či formulár subjektu pri uložení vždy pýta dátum „platné od“ (predvolene dnes), a ako sa zobrazí, že sa zmena týka budúceho obdobia. Pevná požiadavka: žiadna úprava verzie na mieste a žiadne dve verzie s rovnakým `valid_from`. Oprava preklepu je tiež nová verzia (s auditom), plánovač určí, ako ju UI uľahčí (napr. dátum „platné od“ rovný dátumu pôvodnej verzie nie je možný kvôli unikátu, takže treba iný postup).
- Či sú bankové účty súčasťou verzie (kópia pri každej verzii) alebo samostatná tabuľka s archiváciou. Pevná požiadavka: zmena účtu nezmení vystavený doklad a je v denníku.
- Syntax vzoru odkazu na portál (napr. zástupný symbol `{id}`), validácia e-mailov v kópii, povinné a voliteľné polia, zobrazenie archivovaných klientov (filter alebo samostatný zoznam).
- Rozloženie obrazoviek nastavení (Viac → Subjekty, Viac → Klienti) na mobile aj PC, v súlade s maketami a vzorom `settings/security`.
- Dev seeder: ukážkový SK a PL subjekt a klient All4Net (sadzba 27 € bez DPH, Taskman), ktorý nikdy nebeží v `production` (Phase 1, D-09).
- Schéma `turnover_limits` sa môže pripraviť podľa výskumu, ale UI a logika limitov je Phase 10.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Požiadavky a rozsah
- `.planning/ROADMAP.md` § Phase 3: cieľ, kritériá úspechu, poznámky (vzor „GRANT + insert-only“, predbežný seed `legal_texts`, otázky pre účtovníčku)
- `.planning/REQUIREMENTS.md`: ENT-01 až ENT-07, CLI-01 až CLI-07, SET-02, AUD-01 a tabuľka rozhodnutí (odchýlka ENT-07)
- `docs/PRD.md` § E2, E3, E11: požiadavky a kritériá prijatia (zmena adresy nezmení vystavenú faktúru, nová faktúra použije verziu k dátumu vystavenia)
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` § 6.1, 6.2, 6.5 (Číslovanie, Zákonné náležitosti), 6.9, 7: **pri rozpore platí špecifikácia**

### Návrh a riziká
- `.planning/research/ARCHITECTURE.md` § Subjekty s verziami, Klienti, Ostatné tabuľky, Vynucovanie nemennosti, AuditLogger: navrhovaná schéma `entities`, `entity_versions`, `invoice_series`, `clients`, `legal_texts`, `audit_log` a oprávnenia rolí
- `.planning/research/PITFALLS.md` Pasca 7 (verzie „platné od“), číslovanie (zmena formátu, počiatočné číslo, sanitizácia čísla v názve súboru)
- `.planning/research/SUMMARY.md` § Otvorené otázky: zoznam otázok pre účtovníčku (body 1 až 10)

### UI
- `docs/mockups/mobile-home.html`, `docs/mockups/task-flows.html`: vizuálny jazyk (Bootstrap `#0d6efd`, pozadie `#f5f6f8`, spodná lišta Prehľad / Úlohy / Faktúry / Viac)
- `.planning/phases/02-identita-a-skor-nasadenie/02-UI-SPEC.md`: existujúci UI kontrakt pre nastavenia

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `grantMutable()` v migráciách (napr. `app/Database/Migrations/2026-10-24-000002_CreateUserSecurity.php`): opt-in UPDATE/DELETE pre `app_rw`. Insert-only tabuľky ho zámerne nevolajú (vzor `CreateAuthEvents`, `CreateDemoCalculations`).
- `app/Controllers/Settings/Security.php` a `app/Views/settings/*` (čiastkové views `_devices.php`, `_events.php`): vzor stránky nastavení s htmx fragmentmi.
- `app/Controllers/More.php` a `app/Views/more/index.php`: vstup Viac na mobile, sem pribudnú Subjekty a Klienti.
- `Money` (bcmath, `bcround` HalfAwayFromZero) a `Clock` z Phase 1: sadzba klienta a dátumy „platné od“.
- Demo vzor formulára s 422 a CSRF cez `HtmxCsrf` (`app/Controllers/Demo.php`).

### Established Patterns
- Migrácie vždy `spark migrate -g owner|tests_owner`. Testy bežia ako `app_rw` a overujú oprávnenia (testy rolí).
- Texty iba cez `lang()` (strážené `ViewsHardcodedTextTest`), žiadne inline skripty ani štýly (`ViewsCspTest`), Alpine CSP build.
- Kolácia sk-SK ICU: zoznamy klientov a subjektov sa triedia správne bez `COLLATE`.
- `numeric` sa z CI4 vracia ako string, počíta sa cez bcmath, nikdy `float` (PHPStan a grep kontrola).
- Denník prihlásení `auth_events` je insert-only, `audit_log` použije rovnaký vzor.

### Integration Points
- Phase 4 (úlohy) preberá sadzbu a menu z klienta a posledného použitého klienta.
- Phase 5 (vystavenie) číta verziu subjektu k dátumu vystavenia, `invoice_series` a `legal_texts` a zapisuje kópiu údajov.
- Phase 10 (limity) používa `counts_toward_limit` a subjekt.

</code_context>

<specifics>
## Specific Ideas

- Klient All4Net: sadzba 27 € bez DPH, portál Taskman (PROJECT.md Context).
- Vlastné faktúry zatiaľ neexistujú, rad začína od 001, počiatočné číslo sa však dá nastaviť.
- **Akcia pri štarte fázy (ROADMAP):** poslať účtovníčke otázky z `.planning/research/SUMMARY.md` § Otvorené otázky (body 1 až 10). Neblokujú túto fázu, blokujú finálne znenie textov (Phase 6) a limity (Phase 10).

</specifics>

<deferred>
## Deferred Ideas

None. Diskusia sa nekonala, rozsah ostáva podľa ROADMAP.

</deferred>

---

*Phase: 03-master-d-ta-subjekty-a-klienti*
*Context gathered: 2026-10-25*
