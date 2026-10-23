# Requirements: Task Tracker (evidencia úloh a fakturácia)

**Defined:** 2026-10-23
**Core Value:** Vystavená faktúra a výkaz sú správne a nemenné: súčty sa vždy zhodujú na obrazovke, vo výkaze, na faktúre aj v exportoch a žiadna neskoršia zmena už vystavený doklad nezmení.

Zdroj: `docs/PRD.md` (ID požiadaviek prevzaté bez zmeny) a `docs/superpowers/specs/2026-10-23-task-tracker-design.md` (pri rozpore platí špecifikácia, okrem odchýlok schválených nižšie). Nové požiadavky z výskumu (`.planning/research/SUMMARY.md`, medzery M1–M16) a rozhodnutia vlastníka sú označené **[nové]**. Rozsah V1 = priority P0 + P1 + P2 z PRD.

## Rozhodnutia vlastníka pri inicializácii (2026-10-23)

| Rozhodnutie | Dopad |
|---|---|
| Vystavené PDF je autoritatívny doklad: vygeneruje sa raz pri vystavení, uloží sa so `sha256` a opätovné odoslanie posiela tie isté bajty | Odchýlka od špecifikácie (tam len voliteľná cache). INV-13 |
| Krajina a mena subjektu sú nemenné (neverziujú sa). Iná krajina alebo mena = nový subjekt | Odchýlka od špecifikácie 6.1. ENT-07 |
| QR platba na faktúre patrí do V1 | INV-11 |
| Spätný dátum vystavenia je povolený s upozornením | INV-15 |
| Úloha je „vyfakturovaná“, ak je zapracovaná, nemá žiadnu nezamknutú časť a aspoň jedna časť je zamknutá | TSK-03 |
| Všetky medzery M1–M16 z výskumu patria do V1 | nové ID nižšie |

## v1 Requirements

### Prihlásenie a bezpečnosť (E1)

- [ ] **AUTH-01**: Používateľ sa prihlási e-mailom a heslom (Argon2id).
- [ ] **AUTH-02**: Používateľ musí mať TOTP 2FA (RFC 6238, Google/Microsoft Authenticator). Nastaví ju cez QR kód (generovaný lokálne) pri prvom prihlásení. TOTP kód sa nedá použiť dvakrát (replay ochrana).
- [ ] **AUTH-03**: Používateľ dostane 10 jednorazových záložných kódov (uložených ako hash) a môže ich pregenerovať. Použitý kód sa nedá použiť znova.
- [ ] **AUTH-04**: Počet pokusov o prihlásenie a o zadanie TOTP je obmedzený.
- [ ] **AUTH-05**: Používateľ si môže „zapamätať zariadenie“ na 30 dní. Na dôveryhodnom zariadení sa TOTP nevyžaduje. Zariadenia sa dajú zobraziť a odvolať. Po 30 dňoch alebo po odvolaní sa TOTP vyžaduje znova.
- [ ] **AUTH-06**: Používateľ si obnoví heslo e-mailom (časovo obmedzený token). Obnovenie hesla neobíde TOTP.
- [ ] **AUTH-07**: Denník prihlásení zaznamenáva úspech alebo neúspech, IP adresu, zariadenie a čas.
- [ ] **AUTH-08**: Dátový model obsahuje používateľov a roly `admin` a `accountant` (UI pre `accountant` je voliteľné).
- [ ] **AUTH-09** [nové]: Bez platného TOTP alebo záložného kódu sa nedá dostať na žiadnu stránku okrem prihlásenia (stav „čaká na 2FA“ nie je prihlásenie).

### Môj subjekt (E2)

- [ ] **ENT-01**: Používateľ spravuje viac subjektov. Každý má obdobie pôsobenia od–do, krajinu (SK, PL, CZ), menu, jazyk dokladov, DPH režim, vlastný číselný rad a predvolenú splatnosť.
- [ ] **ENT-02**: Subjekt má obchodné meno, sídlo, IČO alebo NIP/REGON, DIČ, údaj o zápise v registri, e-mail a telefón.
- [ ] **ENT-03**: Každá zmena údajov subjektu vytvorí novú verziu s dátumom „platné od“. História verzií je viditeľná a verzie sa nemažú. Nová faktúra použije verziu platnú k dátumu vystavenia a zmena adresy nezmení už vystavenú faktúru.
- [ ] **ENT-04**: Ukončený subjekt môže naďalej zobrazovať, znova odosielať a stornovať (dobropis) svoje doklady.
- [ ] **ENT-05** [nové, M2]: Subjekt má voliteľné IČ DPH / VAT-UE (NIP UE), ktoré sa tlačí na faktúru, keď to vyžaduje režim fakturácie.
- [ ] **ENT-06** [nové, M4]: Subjekt má viac bankových účtov (IBAN, SWIFT/BIC, mena). Faktúra použije účet v mene faktúry (alebo predvolený).
- [ ] **ENT-07** [nové, rozhodnutie]: Krajina a mena subjektu sú po vytvorení nemenné. Verziujú sa len ostatné údaje.

### Klienti (E3)

- [ ] **CLI-01**: Používateľ pridá, upraví a archivuje klienta (klient sa nemaže).
- [ ] **CLI-02**: Klient má názov, adresu, krajinu, IČO, DIČ, IČ DPH/VAT ID, e-mail na faktúry a e-maily v kópii.
- [ ] **CLI-03**: Klient má hodinovú sadzbu a menu (EUR, PLN, CZK), jazyk dokladov (prebije jazyk subjektu) a splatnosť (prebije predvolenú).
- [ ] **CLI-04**: Klient má názov a vzor odkazu na portál úloh (napr. Taskman).
- [ ] **CLI-05**: Klient má režim fakturácie tuzemský / EÚ B2B / mimo EÚ, ktorý určuje text zákonnej poznámky na faktúre.
- [ ] **CLI-06**: Klient má príznak „započítavať do limitu obratu“ (predvolene áno).
- [ ] **CLI-07** [nové, M3]: Identifikátor klienta má typ (IČO/NIP, EÚ VAT ID s prefixom krajiny, iné ID, žiadne), aby bol neskorší export FA(3) možný.

### Úlohy (E4)

- [ ] **TSK-01**: Používateľ vytvorí úlohu: klient (predvolený posledný použitý), URL, názov, nacenené hodiny, sadzba (predvyplnená z klienta, dá sa prepísať), poznámka. Nacenená suma sa prepočítava hneď pri písaní.
- [ ] **TSK-02**: Používateľ upraví a zmaže úlohu. Zmazať sa dá len úloha bez zamknutých častí.
- [ ] **TSK-03**: Úloha má stav *V práci* / *Zapracovaná* (s dátumom). Fakturačný stav sa odvodzuje automaticky: *vyfakturovaná* = zapracovaná, bez nezamknutej časti a aspoň jedna časť zamknutá; *čiastočne* = aspoň jedna zamknutá časť, ale nie vyfakturovaná; inak *nevyfakturovaná*.
- [ ] **TSK-04**: Zoznam „Aktuálne úlohy“ ukazuje úlohy, ktoré nie sú zapracované alebo majú nevyfakturované hodiny.
- [ ] **TSK-05**: Úloha zobrazuje rozdiel medzi nacenenými a fakturovanými hodinami (napr. „+6 h“). Nič sa neblokuje.
- [ ] **TSK-06**: Používateľ filtruje a vyhľadáva úlohy (klient, stav, mesiac, text).

### Fakturačné časti (E5)

- [ ] **ALC-01**: Úloha má 0 až N fakturačných častí: hodiny, fakturačný mesiac (RRRR-MM), sadzba, suma. Mena sa preberá z klienta.
- [ ] **ALC-02**: Nezamknutú časť možno upraviť, presunúť do iného mesiaca alebo zmazať.
- [ ] **ALC-03**: Časť na vystavenej faktúre je zamknutá a odkazuje na faktúru. Pokus o jej úpravu odmietne server (nielen UI).
- [ ] **ALC-04**: Pri „Označiť ako zapracovanú“ sa zobrazí panel: mesiac (Aktuálny / Ďalší / Iný), hodiny (predvolený zostatok nacenených hodín) a živý prepočet sumy. Po potvrdení sa vytvorí časť.
- [ ] **ALC-05**: Časť sa dá pridať aj k nezapracovanej úlohe (čiastočná fakturácia veľkej úlohy). Detail úlohy ukazuje vyfakturované, naplánované a zostávajúce hodiny.

### Faktúry (E6)

- [ ] **INV-01**: Fakturácia mesiaca: používateľ vyberie klienta a mesiac, vidí náhľad nezamknutých častí, môže vyradiť časť (presunúť do iného mesiaca) a vybrať subjekt (predvolený podľa dátumu vystavenia). Náhľad nemá pridelené číslo.
- [ ] **INV-02**: Faktúra má jednu položku za každú časť (názov úlohy, hodiny, sadzba, suma), dátum vystavenia, dátum dodania (predvolene posledný deň mesiaca) a splatnosť. Variabilný symbol je číslo faktúry.
- [ ] **INV-03**: Pri vystavení sa do JSONB uloží kópia údajov: verzia subjektu, klient, mena, jazyk, DPH režim, režim fakturácie, zákonné texty, preložené popisky, URL portálu, kurz. Faktúra je odvtedy nemenná (vynútené aj DB oprávneniami/triggermi).
- [ ] **INV-04**: Číslovanie podľa nastaviteľného formátu s tokenmi `RRRR`, `RR`, `MM`, `#…` a pevným textom (predvolene `RRRRMM###`). Rad patrí subjektu. Poradie sa vynuluje každý mesiac, ak formát obsahuje `MM`, inak každý rok. Počiatočné číslo sa dá nastaviť. Čísla idú bez medzier a pridelenie je bezpečné voči súbehu (prvá faktúra 10/2026 = `202610001`, druhá `202610002`, prvá v 11/2026 = `202611001`).
- [ ] **INV-05**: PDF faktúry vzniká z kópie údajov, má jednoduchý dizajn a len zákonné náležitosti podľa právneho profilu krajiny, DPH režimu a režimu fakturácie (SK neplatiteľ s textom „Nie som platiteľ DPH.“ a údajom o zápise, PL art. 113, EÚ B2B). Jazyk podľa klienta alebo subjektu.
- [ ] **INV-06**: Faktúra má stavy vystavená → odoslaná → uhradená. „Po splatnosti“ sa odvodzuje. „Stornovaná“ nastane po dobropise.
- [ ] **INV-07**: Dobropis je celé storno pôvodnej faktúry so zápornými položkami, s vlastným radom alebo prefixom. Uvoľní zamknuté časti, aby sa dali fakturovať znova.
- [ ] **INV-08**: Všetky údaje faktúry sú uložené štruktúrovane (nielen v PDF a JSONB), aby bol neskôr možný export do KSeF FA(3).
- [ ] **INV-09** [nové, M1]: Každá položka má kód DPH (napr. `zw`, `np`, `0`, sadzba) s predvolenou hodnotou podľa právneho profilu. Výpočty pre platiteľa DPH sa neimplementujú.
- [ ] **INV-10** [nové, M6]: Dobropis má povinný dôvod, odkaz na číslo a dátum pôvodnej faktúry, vlastný dátum vystavenia a typ opravy.
- [ ] **INV-11** [nové, M8, rozhodnutie]: PDF faktúry obsahuje QR kód na platbu (SK: PAY by square; PL/CZ: zodpovedajúci štandard) zo snapshotu.
- [ ] **INV-12** [nové, M13]: Faktúra má formu úhrady (predvolene prevod) a voliteľnú poznámku (napr. o úrokoch z omeškania).
- [ ] **INV-13** [nové, M14, rozhodnutie]: PDF faktúry aj výkazu sa vygeneruje raz pri vystavení a uloží sa ako autoritatívny doklad so `sha256` a verziou šablóny. Zobrazenie a opätovné odoslanie použijú uložené bajty, nikdy sa neregenerujú.
- [ ] **INV-14** [nové, M16]: Vystavenie (zamknutie častí, výpočet, kópia údajov, pridelenie čísla, zápis faktúry a dokladov) prebehne v jednej transakcii. Zlyhanie neskoršieho kroku (napr. e-mail) nepreskočí ani neuvoľní číslo. Dva súbežné pokusy nikdy nevytvoria rovnaké ani preskočené číslo.
- [ ] **INV-15** [nové, rozhodnutie]: Dátum vystavenia môže byť v minulosti. Ak je skorší ako dátum poslednej faktúry v rade, aplikácia pred vystavením upozorní.

### Výkaz práce (E7)

- [ ] **REP-01**: Ku každej faktúre vznikne PDF výkaz. Hlavička: subjekt, klient, obdobie. Tabuľka: odkaz na portál, názov, nacenené hodiny, fakturované hodiny v mesiaci (napr. „16 z 40 h“), suma. Ďalej súčty, sadzba a poznámka o DPH.
- [ ] **REP-02**: Výkaz obsahuje práve tie časti, ktoré sú na faktúre, a je rovnako nemenný.

### Odosielanie, úhrady a upomienky (E8)

- [ ] **MAIL-01**: Používateľ odošle faktúru a výkaz e-mailom klientovi (PDF prílohy) zo šablóny v jazyku klienta. Ukladá sa dátum a adresáti.
- [ ] **MAIL-02**: Používateľ faktúru znova odošle na ľubovoľný e-mail, aj z mobilu.
- [ ] **MAIL-03**: Používateľ odošle upomienku jedným kliknutím zo šablóny s PDF faktúry. Ukladá sa dátum odoslania.
- [ ] **MAIL-04**: Používateľ upraví šablóny e-mailov v nastaveniach pre každý jazyk.
- [ ] **PAY-01**: Používateľ ručne označí úhradu (dátum, voliteľne suma).
- [ ] **PAY-02**: Dashboard ukazuje zoznam faktúr po splatnosti.
- [ ] **PAY-03** [nové, M7]: Faktúra môže mať viac úhrad. Stav „čiastočne uhradená“ a zostatok sa odvodzujú zo súčtu úhrad.

### Meny a kurzy (E9)

- [ ] **CUR-01**: Podporované meny sú EUR, PLN a CZK. Zoznam sa dá rozšíriť bez zmeny modelu.
- [ ] **CUR-02**: Ak sa mena faktúry líši od meny subjektu, aplikácia stiahne kurz (PL: NBP, posledný pracovný deň pred dátumom dodania; SK: ECB; CZ: ČNB), dovolí ho ručne prepísať a uloží kurz, zdroj, dátum a prepočítanú sumu do kópie údajov. Obe sumy sa vytlačia na PDF.
- [ ] **CUR-03**: Ak API kurzov nie je dostupné, aplikácia si kurz vypýta ručne.
- [ ] **CUR-04**: Prehľady a limity počítajú v mene subjektu iba z uložených prepočítaných súm.
- [ ] **CUR-05** [nové, M15]: Dátum, ku ktorému sa hľadá kurz (predvolene dátum dodania), sa dá nastaviť v právnom profile krajiny.

### Prehľady, exporty a limity (E10)

- [ ] **DSH-01**: Dashboard (mobil, variant B): aktuálny fakturačný mesiac a klient; dlaždice *Na fakturáciu tento mesiac* a *V práci*; úlohy zoskupené podľa stavu; ukazovateľ priebehu pri čiastočnej fakturácii; tlačidlo „+“; spodná lišta Prehľad / Úlohy / Faktúry / Viac.
- [ ] **RPT-01**: Mesačný a ročný prehľad: vyfakturované a uhradené, podľa klienta, v mene subjektu.
- [ ] **RPT-02**: Export prehľadov a zoznamu faktúr do CSV a XLSX (s ochranou pred vložením vzorcov).
- [ ] **RPT-03** [nové, M11]: Export balíka pre účtovníčku za obdobie (ZIP: PDF dokladov a CSV zoznam).
- [ ] **LIM-01**: Tabuľka limitov je upraviteľná v nastaveniach (nie v kóde): krajina, rok platnosti, názov, suma, mena, spôsob výpočtu, prahy upozornenia. Počiatočné hodnoty: SK 50 000 € a okamžite pri 62 500 €; PL 240 000 zł, v prvom roku pomerne.
- [ ] **LIM-02**: Obrat sa počíta za subjekt a rok, predvolene z vystavených faktúr podľa dátumu dodania, mínus dobropisy, len klienti s príznakom „započítavať“. Pre každý subjekt a rok sa dá zadať počiatočný obrat.
- [ ] **LIM-03**: Dashboard upozorní pri 80 % a 100 % limitu a aj vtedy, keď pre aktuálny rok chýba riadok limitu (použije sa posledný známy).
- [ ] **LIM-04** [nové, M9]: Náhľad faktúry upozorní, ak by jej vystavenie prekročilo prah limitu.
- [ ] **LIM-05** [nové, M10]: Spôsob výpočtu limitu podporuje kalendárny rok, kĺzavých 12 mesiacov a pomerný prvý rok (výber v tabuľke limitov).

### Audit (E11)

- [ ] **AUD-01**: Denník zmien zaznamenáva používateľa, čas, entitu, ID, akciu a hodnoty pred a po, pre úlohy, časti, faktúry, klientov, subjekty, nastavenia a limity.
- [ ] **AUD-02**: Používateľ prehliada denník len na čítanie, s filtrami.

### Rozhranie, PWA a import (E12)

- [ ] **UI-01**: Responzívne rozhranie na Bootstrap 5.3 pre iPhone aj PC (dotykové ciele aspoň 44 px, bez horizontálneho posúvania).
- [ ] **UI-02**: Jazyky rozhrania SK, PL, CZ a EN, voľba používateľa. Formát čísel, dátumov a mien podľa jazyka.
- [ ] **UI-03**: Na PC sú tabuľkové zoznamy úloh a faktúr, obrazovka „Fakturácia mesiaca“ a nastavenia (subjekty, klienti, číslovanie, limity, šablóny, zákonné texty, používatelia, 2FA, zariadenia).
- [ ] **PWA-01**: Manifest (ikony vrátane `apple-touch-icon`, `display: standalone`) a service worker s cache statických súborov a offline stránkou. Inštalácia na iPhone cez „Pridať na plochu“. Používateľ sa na dôveryhodnom zariadení neprihlasuje častejšie ako raz za 30 dní.
- [ ] **IMP-01**: Import úloh za 09 a 10/2026 z `docs/All4net-fakturovanie-prace.xlsx` pre klienta All4Net (27 €). „Zapracované = ÁNO“ sa importuje ako zapracovaná úloha s nezamknutou časťou v danom mesiaci. Import má suchý beh a je opakovateľný bez duplikátov.
- [ ] **SET-01**: Zákonné texty a poznámky sa dajú upraviť pre každú krajinu, DPH režim, režim fakturácie a jazyk.
- [ ] **SET-02** [nové, M5]: Právny dôvod oslobodenia od DPH je štruktúrované pole s kódom (napr. `ART_113_1`, `ART_113_9`, reverse charge) v právnom profile a uloží sa do kópie údajov.

### Prevádzka a nefunkčné požiadavky

- [ ] **NFR-01**: Odozva servera p95 pod 100 ms na bežných stránkach, PDF pod 2 s. Žiadna SPA ani build pipeline, lokálne JS a CSS s dlhou cache, HTTP/2, kompresia, OPcache a preloading.
- [ ] **NFR-02**: Aplikácia beží na najmenšom VPS Hetzner Cloud (Docker Compose: nginx, php-fpm, PostgreSQL), cieľovo do 512 MB RAM v pokoji.
- [ ] **NFR-03**: HTTPS (Let's Encrypt), CSP, HSTS a ďalšie hlavičky, CSRF (aj pre htmx), secure a httpOnly cookies, tajomstvá v `.env` mimo gitu, šifrované TOTP tajomstvá.
- [x] **NFR-04**: Peniaze `numeric(12,2)`, hodiny `numeric(7,2)`, žiadny float. Jedna trieda na výpočet súm. Súčty na obrazovkách, vo výkaze, na faktúre a v exportoch sa vždy zhodujú (automatické testy).
- [ ] **NFR-05**: Denný šifrovaný `pg_dump` a PDF dokladov mimo servera, rotácia 14 denných / 8 týždenných / 12 mesačných, zdokumentovaná a otestovaná obnova.
- [ ] **NFR-06**: PHPUnit testy pre výpočty súm, číslovanie (vrátane súbehu), zamykanie častí, kópiu údajov, kurzy a limity.
- [x] **NFR-07**: Rovnaké Docker obrazy pre vývoj (WSL2) aj produkciu, vo vývoji Mailpit.
- [ ] **NFR-08**: Staging na HTTPS je k dispozícii skoro (spolu s prihlásením), aby sa PWA a cookies dali otestovať na reálnom iPhone.
- [ ] **NFR-09** [nové, M12]: Doklady sa nedajú zmazať (ani DB oprávneniami) a raz ročne vzniká archív dokladov, ktorý pokryje zákonnú dobu uchovávania (SK 10 rokov, PL podľa účtovníčky).

## v2 Requirements

Odložené. Sledované, ale nie v aktuálnom roadmape.

- **KSEF-01**: Export faktúr do formátu KSeF FA(3) a ich odoslanie do KSeF.
- **ACC-01**: Plné UI pre rolu `accountant` (čítanie faktúr a prehľadov, exporty), ak sa nespraví lacno vo V1.
- **VIES-01**: Overenie VAT ID klienta cez VIES a načítanie údajov z registrov.
- **INV-V2-01**: Zálohové a proforma faktúry.
- **INV-V2-02**: Čiastočný dobropis (oprava jednotlivých položiek).
- **VAT-01**: Režim platiteľa DPH s výpočtom dane.

## Out of Scope

| Feature | Reason |
|---------|--------|
| Napojenie na banku a automatické párovanie platieb | Úhrady sa označujú ručne (dohodnuté) |
| Meranie času (stopky, timesheety) | Fakturujú sa zadané hodiny |
| Offline práca s dátami | PWA cachuje len statické súbory a offline stránku |
| Klientsky portál, multi-tenant SaaS | Jeden používateľ, jedna inštalácia |
| Synchronizácia s Taskmanom a inými portálmi | Odkaz na portál je len URL |
| Opakované faktúry, automatické upomienky cez cron | Málo faktúr, upomienka je jedno kliknutie (anti-feature z výskumu) |
| Online platby | Nie je potreba, platí sa prevodom |
| Úprava alebo mazanie vystavených faktúr | Porušuje nemennosť, oprava len dobropisom |
| Predpridelené čísla na konceptoch | Spôsobujú medzery v rade |
| Návrhár šablón PDF | Jednoduchý pevný dizajn, len zákonné náležitosti |

## Otvorené otázky pre účtovníčku

Neblokujú roadmap, ale blokujú finálne znenie zákonných textov (INV-05, SET-01, SET-02) a nastavenie limitov (LIM-01, LIM-05). Úplný zoznam je v `.planning/research/SUMMARY.md` → Otvorené otázky.

1. SK limit: 50 000 € za kalendárny rok, alebo 49 790 € za kĺzavých 12 mesiacov? Čo sa započítava?
2. PL limit 240 000 zł: rozhodujúci dátum a výpočet pomerného prvého roka.
3. PL subjekt → SK B2B klient: zákonná poznámka (reverse charge alebo art. 113), VAT-UE, započítanie do limitu.
4. Rozhodujúci dátum kurzu (dodanie alebo vystavenie) a zaokrúhľovanie.
5. Do ktorého obdobia sa dobropis odpočíta.
6. Doba uchovávania dokladov.

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| NFR-04 | Phase 1 | Complete |
| NFR-07 | Phase 1 | Complete |
| UI-01 | Phase 1 | Pending |
| AUTH-01 | Phase 2 | Pending |
| AUTH-02 | Phase 2 | Pending |
| AUTH-03 | Phase 2 | Pending |
| AUTH-04 | Phase 2 | Pending |
| AUTH-05 | Phase 2 | Pending |
| AUTH-06 | Phase 2 | Pending |
| AUTH-07 | Phase 2 | Pending |
| AUTH-08 | Phase 2 | Pending |
| AUTH-09 | Phase 2 | Pending |
| PWA-01 | Phase 2 | Pending |
| NFR-02 | Phase 2 | Pending |
| NFR-03 | Phase 2 | Pending |
| NFR-08 | Phase 2 | Pending |
| ENT-01 | Phase 3 | Pending |
| ENT-02 | Phase 3 | Pending |
| ENT-03 | Phase 3 | Pending |
| ENT-04 | Phase 3 | Pending |
| ENT-05 | Phase 3 | Pending |
| ENT-06 | Phase 3 | Pending |
| ENT-07 | Phase 3 | Pending |
| CLI-01 | Phase 3 | Pending |
| CLI-02 | Phase 3 | Pending |
| CLI-03 | Phase 3 | Pending |
| CLI-04 | Phase 3 | Pending |
| CLI-05 | Phase 3 | Pending |
| CLI-06 | Phase 3 | Pending |
| CLI-07 | Phase 3 | Pending |
| SET-02 | Phase 3 | Pending |
| AUD-01 | Phase 3 | Pending |
| TSK-01 | Phase 4 | Pending |
| TSK-02 | Phase 4 | Pending |
| TSK-03 | Phase 4 | Pending |
| TSK-04 | Phase 4 | Pending |
| TSK-05 | Phase 4 | Pending |
| TSK-06 | Phase 4 | Pending |
| ALC-01 | Phase 4 | Pending |
| ALC-02 | Phase 4 | Pending |
| ALC-04 | Phase 4 | Pending |
| ALC-05 | Phase 4 | Pending |
| DSH-01 | Phase 4 | Pending |
| IMP-01 | Phase 4 | Pending |
| INV-01 | Phase 5 | Pending |
| INV-02 | Phase 5 | Pending |
| INV-03 | Phase 5 | Pending |
| INV-04 | Phase 5 | Pending |
| INV-08 | Phase 5 | Pending |
| INV-09 | Phase 5 | Pending |
| INV-13 | Phase 5 | Pending |
| INV-14 | Phase 5 | Pending |
| INV-15 | Phase 5 | Pending |
| ALC-03 | Phase 5 | Pending |
| NFR-06 | Phase 5 | Pending |
| INV-05 | Phase 6 | Pending |
| INV-11 | Phase 6 | Pending |
| INV-12 | Phase 6 | Pending |
| REP-01 | Phase 6 | Pending |
| REP-02 | Phase 6 | Pending |
| SET-01 | Phase 6 | Pending |
| MAIL-01 | Phase 7 | Pending |
| MAIL-02 | Phase 7 | Pending |
| MAIL-03 | Phase 7 | Pending |
| MAIL-04 | Phase 7 | Pending |
| PAY-01 | Phase 7 | Pending |
| PAY-02 | Phase 7 | Pending |
| PAY-03 | Phase 7 | Pending |
| INV-06 | Phase 7 | Pending |
| CUR-01 | Phase 8 | Pending |
| CUR-02 | Phase 8 | Pending |
| CUR-03 | Phase 8 | Pending |
| CUR-05 | Phase 8 | Pending |
| INV-07 | Phase 9 | Pending |
| INV-10 | Phase 9 | Pending |
| LIM-01 | Phase 10 | Pending |
| LIM-02 | Phase 10 | Pending |
| LIM-03 | Phase 10 | Pending |
| LIM-04 | Phase 10 | Pending |
| LIM-05 | Phase 10 | Pending |
| CUR-04 | Phase 10 | Pending |
| RPT-01 | Phase 11 | Pending |
| RPT-02 | Phase 11 | Pending |
| RPT-03 | Phase 11 | Pending |
| AUD-02 | Phase 11 | Pending |
| UI-03 | Phase 11 | Pending |
| UI-02 | Phase 12 | Pending |
| NFR-01 | Phase 12 | Pending |
| NFR-05 | Phase 12 | Pending |
| NFR-09 | Phase 12 | Pending |

**Coverage:**

- v1 requirements: 90 total
- Mapped to phases: 90
- Unmapped: 0 ✓

---
*Requirements defined: 2026-10-23*
*Last updated: 2026-10-23 after roadmap creation*
