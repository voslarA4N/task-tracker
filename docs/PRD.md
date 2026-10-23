# PRD: Task Tracker (evidencia úloh a fakturácia)

| | |
|---|---|
| Verzia | 1.0 |
| Dátum | 2026-10-23 |
| Vlastník produktu | Tomáš Voslař |
| Stav | Schválené na implementáciu |
| Zdroj | [Špecifikácia `2026-10-23-task-tracker-design.md`](superpowers/specs/2026-10-23-task-tracker-design.md). Pri rozpore platí špecifikácia. |
| Makety | [`docs/mockups/`](mockups/) |

---

## 1. Prehľad

Task Tracker je webová aplikácia (PWA) pre jedného živnostníka-programátora. Nahrádza ručne vedený Excel na evidenciu úloh pre klientov, ich nacenenie, fakturáciu po mesiacoch (aj po častiach), vystavenie faktúr a výkazov práce v PDF, ich odoslanie e-mailom a sledovanie úhrad a daňových limitov.

## 2. Problém

- Úlohy, nacenenie a stavy sa evidujú ručne v Exceli s mesačnými blokmi. Vzorce sa ľahko pokazia (súčet za október 2026 vynecháva dva riadky).
- PDF výkaz pre klienta sa skladá ručne každý mesiac.
- Vlastné faktúry zatiaľ neexistujú. Fakturácia ide cez tretiu osobu a jej faktúra nevyhovuje.
- Veľké úlohy sa nedajú rozumne fakturovať po častiach počas viacerých mesiacov.
- Používateľ plánuje zmenu právnej formy: slovenská živnosť (EUR, neplatiteľ DPH) nahradená poľskou (PLN, zwolnienie podmiotowe). Excel nevie zaručiť, že staré doklady ostanú na pôvodnom subjekte.
- Úhrady a daňové limity obratu sa sledujú ručne, alebo vôbec.

## 3. Ciele a merateľné kritériá úspechu

| # | Cieľ | Kritérium úspechu |
|---|---|---|
| G1 | Nahradiť Excel | Od nasadenia sa nová úloha neeviduje v Exceli. Dáta za 09–10/2026 sú naimportované. |
| G2 | Fakturácia jedným klikom | Vystavenie faktúry a výkazu za mesiac a ich odoslanie trvá do 2 minút a nevyžaduje žiadnu ručnú úpravu PDF. |
| G3 | Správne čísla | Súčty na obrazovkách, vo výkaze, na faktúre a v exportoch sa vždy zhodujú (pokryté automatickými testami). |
| G4 | Nemenné doklady | Po zmene subjektu, klienta, sadzby alebo meny je PDF už vystavenej faktúry bit po bite rovnaké ako pred zmenou, alebo obsahovo identické. |
| G5 | Mobil | Pridanie úlohy a jej označenie ako zapracovanej na iPhone (PWA) trvá do 30 sekúnd. Používateľ sa neprihlasuje častejšie ako raz za 30 dní na dôveryhodnom zariadení. |
| G6 | Výkon a úspornosť | Odozva servera p95 pod 100 ms na bežných stránkach. Celá aplikácia (vrátane DB) beží na najmenšom VPS s rezervou, cieľovo do 512 MB RAM v pokoji. |
| G7 | Bezpečnosť | Prístup len s heslom a TOTP 2FA. Prihlásenia a zmeny sú zaznamenané. |

## 4. Používatelia

- **Vlastník (admin)**, jediný používateľ vo V1. Živnostník-programátor. Úlohy dostáva od klientov cez ich portály (All4Net: Taskman). Pracuje na PC aj na iPhone.
- **Účtovníčka (budúcnosť)**: obmedzený prístup len na čítanie faktúr a prehľadov a na exporty. Vo V1 sa pripraví dátový model a roly. UI pre rolu je voliteľné.
- **Klient (nepriamo)**: dostáva e-mailom PDF faktúru, výkaz a upomienky. Do aplikácie nevstupuje.

## 5. Rozsah

### 5.1 V rozsahu V1

Epiky E1–E12 nižšie.

### 5.2 Mimo rozsahu V1

- Odosielanie do KSeF. Rieši účtovníčka. Dáta musia byť štruktúrované kvôli neskoršiemu exportu.
- Napojenie na banku a automatické párovanie platieb.
- Meranie času (stopky, timesheety).
- Režim platiteľa DPH. Model ho nesmie znemožniť.
- Offline práca s dátami.
- Klientsky portál, multi-tenant SaaS.
- Synchronizácia s Taskmanom a inými portálmi.

## 6. Funkčné požiadavky

Priority: **P0** = nutné pre spustenie, **P1** = nutné vo V1, môže prísť po prvom nasadení, **P2** = príprava na budúcnosť.

### E1 Prihlásenie a bezpečnosť

| ID | Požiadavka | Priorita |
|---|---|---|
| AUTH-01 | Prihlásenie e-mailom a heslom (Argon2id). | P0 |
| AUTH-02 | Povinná TOTP 2FA (RFC 6238; Google, Microsoft Authenticator). Nastavenie cez QR kód pri prvom prihlásení. | P0 |
| AUTH-03 | 10 jednorazových záložných kódov. Dajú sa pregenerovať. | P0 |
| AUTH-04 | Obmedzenie počtu pokusov o prihlásenie a o zadanie TOTP. | P0 |
| AUTH-05 | „Zapamätať zariadenie“ na 30 dní. Na dôveryhodnom zariadení sa TOTP nevyžaduje. Zariadenia sa dajú odvolať. | P0 |
| AUTH-06 | Obnovenie hesla e-mailom (časovo obmedzený token). | P1 |
| AUTH-07 | Denník prihlásení: úspech alebo neúspech, IP adresa, zariadenie, čas. | P0 |
| AUTH-08 | Používatelia a roly `admin` a `accountant` v dátovom modeli. | P2 |

**Kritériá prijatia (E1):**
- Bez platného TOTP alebo záložného kódu sa nedá dostať na žiadnu stránku okrem prihlásenia.
- Použitý záložný kód sa nedá použiť znova.
- Po 30 dňoch alebo po odvolaní zariadenia sa TOTP vyžaduje znova.

### E2 Môj subjekt (dodávateľ)

| ID | Požiadavka | Priorita |
|---|---|---|
| ENT-01 | Viac subjektov. Každý má obdobie pôsobenia od–do, krajinu (SK, PL, CZ), menu, jazyk dokladov, DPH režim, vlastný číselný rad a predvolenú splatnosť. | P0 |
| ENT-02 | Údaje subjektu: obchodné meno, sídlo, IČO alebo NIP/REGON, DIČ, údaj o zápise v registri, IBAN a SWIFT, e-mail, telefón. | P0 |
| ENT-03 | Každá zmena údajov vytvorí novú verziu s dátumom „platné od“. História verzií je viditeľná. Verzie sa nemažú. | P0 |
| ENT-04 | Ukončený subjekt môže naďalej zobrazovať, znova odosielať a stornovať (dobropis) svoje doklady. | P0 |

**Kritériá prijatia (E2):**
- Zmena adresy subjektu nezmení už vystavenú faktúru.
- Nová faktúra použije verziu subjektu platnú k dátumu vystavenia.

### E3 Klienti

| ID | Požiadavka | Priorita |
|---|---|---|
| CLI-01 | Pridanie, úprava a archivácia klienta (nemaže sa). | P0 |
| CLI-02 | Údaje: názov, adresa, krajina, IČO, DIČ, IČ DPH/VAT ID, e-mail na faktúry a e-maily v kópii. | P0 |
| CLI-03 | Hodinová sadzba a mena (EUR, PLN, CZK), jazyk dokladov (prebije jazyk subjektu), splatnosť (prebije predvolenú). | P0 |
| CLI-04 | Názov a vzor odkazu na portál úloh (napr. Taskman). | P1 |
| CLI-05 | Režim fakturácie: tuzemský / EÚ B2B / mimo EÚ. Určuje text zákonnej poznámky na faktúre. | P0 |
| CLI-06 | Príznak „započítavať do limitu obratu“ (predvolene áno). | P0 |

### E4 Úlohy

| ID | Požiadavka | Priorita |
|---|---|---|
| TSK-01 | Vytvorenie úlohy: klient (predvolený posledný použitý), URL, názov, nacenené hodiny, sadzba (predvyplnená z klienta, dá sa prepísať), poznámka. Nacenená suma sa prepočítava hneď pri písaní. | P0 |
| TSK-02 | Úprava a zmazanie úlohy. Zmazať sa dá len úloha bez zamknutých častí. | P0 |
| TSK-03 | Stav úlohy *V práci* / *Zapracovaná* (s dátumom). Fakturačný stav (nevyfakturovaná / čiastočne / vyfakturovaná) sa odvodzuje automaticky. | P0 |
| TSK-04 | Zoznam „Aktuálne úlohy“: nezapracované, alebo s nevyfakturovanými hodinami. | P0 |
| TSK-05 | Zobrazenie rozdielu medzi nacenenými a fakturovanými hodinami (napr. „+6 h“). Nič sa neblokuje. | P0 |
| TSK-06 | Filtrovanie a vyhľadávanie úloh (klient, stav, mesiac, text). | P1 |

### E5 Fakturačné časti

| ID | Požiadavka | Priorita |
|---|---|---|
| ALC-01 | Úloha má 0 až N fakturačných častí: hodiny, fakturačný mesiac (RRRR-MM), sadzba, suma. Mena sa preberá z klienta. | P0 |
| ALC-02 | Nezamknutú časť možno upraviť, presunúť do iného mesiaca alebo zmazať. | P0 |
| ALC-03 | Časť na vystavenej faktúre je zamknutá a odkazuje na faktúru. Nedá sa meniť. | P0 |
| ALC-04 | Pri „Označiť ako zapracovanú“ sa zobrazí panel: mesiac (Aktuálny / Ďalší / Iný), hodiny (predvolený zostatok nacenených hodín) a živý prepočet sumy. Po potvrdení sa vytvorí časť. | P0 |
| ALC-05 | Časť sa dá pridať aj k nezapracovanej úlohe (čiastočná fakturácia veľkej úlohy). | P0 |

**Kritériá prijatia (E5):**
- Pri 200 h úlohe so 4 časťami v 4 mesiacoch sa každá časť objaví na faktúre svojho mesiaca.
- Detail úlohy ukazuje vyfakturované, naplánované a zostávajúce hodiny.
- Pokus o úpravu zamknutej časti sa odmietne na serveri, nielen v UI.

### E6 Faktúry

| ID | Požiadavka | Priorita |
|---|---|---|
| INV-01 | Fakturácia mesiaca: výber klienta a mesiaca, náhľad nezamknutých častí, možnosť vyradiť časť (presunúť do iného mesiaca), výber subjektu (predvolený podľa dátumu vystavenia). | P0 |
| INV-02 | Vystavenie faktúry: jedna položka za každú časť (názov úlohy, hodiny, sadzba, suma). Dátum vystavenia, dátum dodania (predvolene posledný deň mesiaca), splatnosť. Variabilný symbol je číslo faktúry. | P0 |
| INV-03 | Pri vystavení sa do JSONB uloží kópia údajov: verzia subjektu, klient, mena, jazyk, DPH režim, režim fakturácie, zákonné texty, kurz. Faktúra je odvtedy nemenná. | P0 |
| INV-04 | Číslovanie podľa nastaviteľného formátu s tokenmi `RRRR`, `RR`, `MM`, `#…` a pevným textom. Predvolený formát je `RRRRMM###`. Rad patrí subjektu. Poradové číslo sa vynuluje každý mesiac, ak formát obsahuje `MM`, inak každý rok. Počiatočné číslo sa dá nastaviť. Čísla idú bez medzier a pridelenie je bezpečné voči súbehu. | P0 |
| INV-05 | PDF faktúry z kópie údajov. Jednoduchý dizajn, len zákonné náležitosti podľa právneho profilu krajiny, DPH režimu a režimu fakturácie (SK neplatiteľ, PL art. 113, EÚ B2B). Jazyk podľa klienta alebo subjektu. | P0 |
| INV-06 | Stavy: vystavená → odoslaná → uhradená. „Po splatnosti“ sa odvodzuje. „Stornovaná“ nastane po dobropise. | P0 |
| INV-07 | Dobropis: celé storno pôvodnej faktúry so zápornými položkami, vlastný rad alebo prefix. Uvoľní zamknuté časti, aby sa dali fakturovať znova. | P1 |
| INV-08 | Štruktúrované uloženie všetkých údajov faktúry, aby bol neskôr možný export do KSeF FA(3). | P2 |

**Kritériá prijatia (E6):**
- Prvá faktúra subjektu v októbri 2026 má číslo `202610001`, druhá `202610002` a prvá v novembri `202611001`.
- Dva súbežné pokusy o vystavenie nikdy nevytvoria rovnaké ani preskočené číslo.
- Faktúra slovenského subjektu obsahuje text „Nie som platiteľ DPH.“ a údaj o zápise v živnostenskom registri.

### E7 Výkaz práce

| ID | Požiadavka | Priorita |
|---|---|---|
| REP-01 | PDF výkaz ku každej faktúre. Hlavička: subjekt, klient, obdobie. Tabuľka: odkaz na portál, názov, nacenené hodiny, fakturované hodiny v mesiaci (napr. „16 z 40 h“), suma. Ďalej súčty, sadzba a poznámka o DPH. | P0 |
| REP-02 | Výkaz obsahuje práve tie časti, ktoré sú na faktúre, a je rovnako nemenný. | P0 |

### E8 Odosielanie, úhrady a upomienky

| ID | Požiadavka | Priorita |
|---|---|---|
| MAIL-01 | Odoslanie faktúry a výkazu e-mailom klientovi (PDF prílohy) zo šablóny v jazyku klienta. Ukladá sa dátum a adresáti. | P0 |
| MAIL-02 | Opätovné odoslanie na ľubovoľný e-mail, aj z mobilu. | P0 |
| PAY-01 | Ručné označenie úhrady (dátum, voliteľne suma). | P0 |
| PAY-02 | Zoznam faktúr po splatnosti na dashboarde. | P0 |
| MAIL-03 | Upomienka jedným kliknutím zo šablóny s PDF faktúry. Ukladá sa dátum odoslania. | P1 |
| MAIL-04 | Šablóny e-mailov sa dajú upraviť v nastaveniach pre každý jazyk. | P1 |

### E9 Meny a kurzy

| ID | Požiadavka | Priorita |
|---|---|---|
| CUR-01 | Podporované meny EUR, PLN a CZK. Zoznam sa dá rozšíriť bez zmeny modelu. | P0 |
| CUR-02 | Ak sa mena faktúry líši od meny subjektu: automatické stiahnutie kurzu (PL: NBP, posledný pracovný deň pred dátumom dodania; SK: ECB; CZ: ČNB), možnosť ho ručne prepísať a uloženie kurzu, zdroja, dátumu a prepočítanej sumy do kópie údajov. Obe sumy sa vytlačia na PDF. | P0 |
| CUR-03 | Ak API kurzov nie je dostupné, aplikácia si kurz vypýta ručne. | P0 |
| CUR-04 | Prehľady a limity počítajú v mene subjektu iba z uložených prepočítaných súm. | P0 |

### E10 Prehľady, exporty a limity

| ID | Požiadavka | Priorita |
|---|---|---|
| DSH-01 | Dashboard (mobil, variant B): aktuálny fakturačný mesiac a klient; dlaždice *Na fakturáciu tento mesiac* a *V práci*; úlohy zoskupené podľa stavu; ukazovateľ priebehu pri čiastočnej fakturácii; tlačidlo „+“; spodná lišta Prehľad / Úlohy / Faktúry / Viac. | P0 |
| RPT-01 | Mesačný a ročný prehľad: vyfakturované a uhradené, podľa klienta, v mene subjektu. | P1 |
| RPT-02 | Export prehľadov a zoznamu faktúr do CSV a XLSX. | P1 |
| LIM-01 | Tabuľka limitov **upraviteľná v nastaveniach** (nie v kóde): krajina, rok platnosti, názov, suma, mena, spôsob výpočtu, prahy upozornenia. Počiatočné hodnoty: SK 50 000 € a okamžite pri 62 500 €; PL 240 000 zł, v prvom roku pomerne. | P0 |
| LIM-02 | Výpočet obratu za subjekt a rok. Predvolene z vystavených faktúr podľa dátumu dodania, mínus dobropisy, len klienti s príznakom „započítavať“. Pre každý subjekt a rok sa dá zadať počiatočný obrat. | P0 |
| LIM-03 | Upozornenie na dashboarde pri 80 % a 100 % limitu. Upozornenie aj vtedy, keď pre aktuálny rok chýba riadok limitu (použije sa posledný známy). | P0 |

### E11 Audit

| ID | Požiadavka | Priorita |
|---|---|---|
| AUD-01 | Denník zmien: používateľ, čas, entita, ID, akcia, hodnoty pred a po. Pre úlohy, časti, faktúry, klientov, subjekty, nastavenia a limity. | P1 |
| AUD-02 | Prehliadanie denníka len na čítanie, s filtrami. | P1 |

### E12 Rozhranie, PWA a import

| ID | Požiadavka | Priorita |
|---|---|---|
| UI-01 | Responzívne rozhranie na Bootstrap 5.3 pre iPhone aj PC. | P0 |
| UI-02 | Jazyky rozhrania SK, PL, CZ a EN, voľba používateľa. Formát čísel, dátumov a mien podľa jazyka. | P0 (SK), P1 (PL, CZ, EN) |
| UI-03 | Na PC tabuľkové zoznamy úloh a faktúr, obrazovka „Fakturácia mesiaca“ a nastavenia (subjekty, klienti, číslovanie, limity, šablóny, zákonné texty, používatelia, 2FA, zariadenia). | P0 |
| PWA-01 | Manifest (ikony vrátane `apple-touch-icon`, `display: standalone`), service worker s cache statických súborov a offline stránkou. Inštalácia na iPhone cez „Pridať na plochu“. | P0 |
| IMP-01 | Import úloh za 09 a 10/2026 z `All4net-fakturovanie-prace.xlsx` pre klienta All4Net (27 €). „Zapracované = ÁNO“ sa importuje ako zapracovaná úloha s nezamknutou časťou v danom mesiaci. | P0 |
| SET-01 | Zákonné texty a poznámky sa dajú upraviť pre každú krajinu, DPH režim, režim fakturácie a jazyk. | P0 |

## 7. Nefunkčné požiadavky

| ID | Požiadavka |
|---|---|
| NFR-01 Výkon | Odozva servera p95 pod 100 ms na bežných stránkach. Generovanie PDF pod 2 s. Žiadna SPA ani build pipeline. JS a CSS hostované lokálne, minimálne, s dlhou cache. HTTP/2 a kompresia. OPcache a preloading. |
| NFR-02 Zdroje | Beží na najmenšom VPS Hetzner Cloud (Docker Compose: nginx, php-fpm, PostgreSQL). Cieľová spotreba RAM v pokoji do 512 MB. |
| NFR-03 Bezpečnosť | HTTPS (Let's Encrypt), bezpečnostné hlavičky (CSP, HSTS…), CSRF ochrana, secure a httpOnly cookies, tajomstvá v `.env` mimo gitu, šifrované TOTP tajomstvá. |
| NFR-04 Integrita dát | Peniaze ako `numeric(12,2)`, hodiny ako `numeric(7,2)`, žiadny float. Vystavené doklady sú nemenné, kontroluje to serverová logika. Číslovanie je transakčné. |
| NFR-05 Zálohy | Denný `pg_dump` a PDF dokladov, šifrované, mimo servera (napr. Hetzner Storage Box). Rotácia 14 denných, 8 týždenných, 12 mesačných. Zdokumentovaná a otestovaná obnova. |
| NFR-06 Testy | PHPUnit pre výpočty súm, číslovanie, zamykanie častí, kópiu údajov, kurzy a limity. |
| NFR-07 Prenositeľnosť | Rovnaké Docker obrazy pre vývoj (WSL2 a Docker) aj produkciu. Vo vývoji Mailpit. |
| NFR-08 Prístupnosť a UX | Ovládateľné jednou rukou na iPhone. Dotykové ciele aspoň 44 px. Bez horizontálneho posúvania. |

## 8. Technológie

| Vrstva | Voľba |
|---|---|
| Jazyk | PHP 8.5 (FPM) |
| Framework | CodeIgniter 4.7.x (najnovšia stabilná) |
| Autentifikácia | CodeIgniter Shield a TOTP knižnica (`spomky-labs/otphp` alebo `robthree/twofactorauth`) |
| Databáza | PostgreSQL (najnovšia stabilná) |
| Frontend | Bootstrap 5.3, htmx 2, Alpine.js |
| PDF | mPDF |
| E-mail | SMTP (CI4 Email), vo vývoji Mailpit |
| XLSX | PhpSpreadsheet alebo ľahšia alternatíva |
| Infraštruktúra | Docker Compose, Git a GitHub, Hetzner Cloud VPS |

## 9. Predpoklady, závislosti a riziká

| # | Položka | Dopad | Riešenie |
|---|---|---|---|
| R1 | Zákonná poznámka a povinnosti (VAT-UE, započítanie do limitu) pri fakturácii z PL subjektu slovenskému B2B klientovi nie sú overené. | Nesprávna faktúra. | Overí účtovníčka. V aplikácii je to nastaviteľné (CLI-05, CLI-06, SET-01). |
| R2 | Limity DPH sa menia z roka na rok. | Chybné upozornenia. | Limity sa upravujú v nastaveniach (LIM-01) a aplikácia upozorní na chýbajúci rok. |
| R3 | Dostupnosť API kurzov (NBP, ECB, ČNB). | Nedá sa vystaviť faktúra v cudzej mene. | Ručné zadanie kurzu (CUR-03). |
| R4 | Shield nemá natívnu TOTP. | Viac práce na E1. | Vlastná akcia 2FA. Ako záloha vlastné jednoduché prihlasovanie nad sessions CI4. |
| R5 | Povinnosť KSeF pre poľský subjekt. | Budúca integrácia. | Mimo V1. Štruktúrované dáta (INV-08). |
| R6 | Produkčný poskytovateľ SMTP ešte nie je zvolený. | Neodídu e-maily. | Konfigurácia cez `.env`. Vyberie vlastník pred nasadením. |

## 10. Míľniky (návrh poradia)

1. **M1 – Základ:** Docker prostredie, CI4 a PostgreSQL, prihlásenie s TOTP, PWA kostra, layout a jazyk SK.
2. **M2 – Evidencia:** subjekty s verziami, klienti, úlohy, fakturačné časti, dashboard (variant B), import z Excelu.
3. **M3 – Fakturácia:** číslovanie, vystavenie faktúry s kópiou údajov, PDF faktúry a výkazu, odoslanie e-mailom, úhrady, meny a kurzy.
4. **M4 – Prehľady a kontrola:** limity, prehľady a exporty, dobropisy, upomienky, audit, jazyky PL, CZ, EN.
5. **M5 – Produkcia:** nasadenie na Hetzner, HTTPS, zálohy a test obnovy.

Každý míľnik končí funkčnou, otestovanou a commitnutou verziou.
