# Feature Research

**Domain:** Fakturácia a evidencia úloh pre jedného živnostníka (SK živnosť, potom PL JDG), samostatne hostovaná PWA
**Researched:** 2026-10-23
**Confidence:** MEDIUM (porovnanie s konkurenciou a štruktúra FA(3) vychádzajú z tréningových znalostí a čiastočného webového overenia. Právne body sú označené a musí ich potvrdiť účtovníčka, pozri R1 v PRD.)

## Metodika a spoľahlivosť

- Konkurenčné produkty (SuperFaktúra, iDoklad, Fakturoid, inFakt, wFirma, Invoice Ninja, Harvest): zoznam funkcií je z tréningových znalostí, **neoverený na živých stránkach** (LOW až MEDIUM). Slúži na odhad, čo používateľ „očakáva“, nie ako zdroj pravdy.
- Overené webom (MEDIUM): limit PL 240 000 zł od 1. 1. 2026 (novela z 24. 6. 2025, art. 113 ust. 1), FA(3) je povinná štruktúra KSeF od 1. 2. 2026, členenie FA(3) na Naglowek / Podmiot1 / Podmiot2 / Podmiot3 / Fa / Stopka.
- **Rozpor zdrojov (SK limit):** zdroje uvádzajú buď 50 000 € za kalendárny rok, alebo 49 790 € za ľubovoľných 12 po sebe idúcich mesiacov. Prah okamžitej registrácie 62 500 € sa zhoduje vo všetkých zdrojoch. Pozri [aksamec.sk](https://www.aksamec.sk/dph-registracia-2026/), [pravnenoviny.sk](https://pravnenoviny.sk/zmeny-v-registracii-na-dph-nove-limity-nove-terminy-nove-povinnosti/), [sita.sk](https://sita.sk/vofinanciach/od-januara-platia-nove-pravidla-pre-registraciu-na-dph-podnikatelia-musia-sledovat-obrat-ovela-pozornejsie/). **Dôsledok pre model:** spôsob výpočtu limitu musí podporovať „kĺzavých 12 mesiacov“ aj „kalendárny rok“ (PRD LIM-01 hovorí len o roku platnosti).

## Zhrnutie rozdielov oproti PRD (čo PRD vynecháva)

| # | Chýba v PRD | Závažnosť | Kam patrí |
|---|-------------|-----------|-----------|
| M1 | **Kód DPH na položke** (`23`, `0`, `zw`, `np`, `oo`), nielen číselná sadzba. FA(3) P_12 používa textové kódy (`zw`, `np I`, `np II`, `oo`). | Vysoká (model) | INV-02, INV-08 |
| M2 | **Vlastné VAT-UE / NIP UE číslo subjektu** (PL subjekt fakturujúci EÚ B2B musí mať VAT-UE registráciu a uvádza `PL` + NIP). ENT-02 má len DIČ/NIP. | Vysoká | ENT-02 |
| M3 | **Typ identifikátora klienta + prefix krajiny** (NIP, VAT UE s kódom krajiny, iné ID, bez ID). FA(3) Podmiot2 to rozlišuje. CLI-02 má voľné polia IČO/DIČ/IČ DPH. | Vysoká | CLI-02 |
| M4 | **Viac bankových účtov na subjekt (podľa meny)**, vrátane SWIFT/BIC. PL subjekt fakturujúci v EUR potrebuje EUR IBAN. ENT-02 má jeden IBAN. | Vysoká | ENT-02 |
| M5 | **Právny dôvod oslobodenia ako štruktúrované pole** (art. 113 ust. 1 / ust. 9, text) v kópii údajov, nielen ako voľný „zákonný text“. FA(3) Adnotacje/Zwolnienie: P_19 + P_19A. | Stredná | INV-03, SET-01 |
| M6 | **Dobropis: povinný dôvod, odkaz na pôvodnú faktúru (číslo + dátum)**, vlastný dátum vystavenia, uloženie typu opravy. INV-07 to nešpecifikuje. | Vysoká | INV-07 |
| M7 | **Stav „čiastočne uhradená“** a viac úhrad na faktúru. PAY-01 pripúšťa sumu, INV-06 nemá stav. | Stredná | INV-06, PAY-01 |
| M8 | **QR platba na faktúre** (SK: PAY by square, PL: QR/Przelew, CZ: QR platba). Pri SK fakturácii je to pre klientov očakávané. | Stredná (UX), nie zákon | INV-05 |
| M9 | **Predkontrola limitu pred vystavením** („táto faktúra prekročí limit“). LIM-03 upozorňuje až po vystavení. Pri PL prekročenie limitu znamená DPH od tej tržby. | Vysoká (biznisovo) | LIM-03 |
| M10 | **Limit s kĺzavým 12-mesačným oknom** (SK). Pozri rozpor vyššie. | Stredná | LIM-01/02 |
| M11 | **Archív pre účtovníčku** (ZIP faktúr a výkazov za mesiac + CSV). RPT-02 pokrýva len CSV/XLSX. | Stredná | RPT-02 |
| M12 | **Retencia dokladov** (SK 10 rokov, PL spravidla 5 rokov od konca roka, v oboch prípadoch bez mazania). NFR-05 rotuje zálohy 14/8/12, čo nepokrýva archiváciu PDF. | Stredná | NFR-05 |
| M13 | **Forma úhrady** (prevodom/hotovosť) a poznámka k úrokom z omeškania na faktúre. | Nízka | INV-02 |
| M14 | **Hash/uloženie vygenerovaného PDF pri vystavení** (nie iba „cache“). G4 hovorí „bit po bite“, čo bez uloženia PDF a jeho kontrolného súčtu nie je splniteľné pri aktualizácii mPDF. | Vysoká | INV-03, INV-05 |
| M15 | **Základ dátumu kurzu je konfigurovateľný** (dátum dodania vs. vystavenia). Pre PL daň z príjmu sa zvyčajne berie deň pred dosiahnutím príjmu, pre limit/DPH iné pravidlo. CUR-02 to fixuje na dátum dodania. | Nízka až stredná | CUR-02 |
| M16 | **Atómové vystavenie** (pridelenie čísla + uloženie kópie + vygenerovanie PDF v jednej transakcii, zlyhanie e-mailu nesmie uvoľniť ani preskočiť číslo). | Vysoká | INV-04 |

## Feature Landscape

### Table Stakes (používateľ očakáva)

| Feature | Prečo sa očakáva | Zložitosť | Poznámky |
|---------|------------------|-----------|----------|
| Vystavenie faktúry s PDF podľa krajiny | Základ každého nástroja (SuperFaktúra, iDoklad, Fakturoid, inFakt, wFirma) | HIGH | INV-05. Právny profil (krajina x DPH režim x režim fakturácie), nie natvrdo v šablóne |
| Súvislé číslovanie bez medzier, rad na subjekt | Zákonná požiadavka (SK účtovný doklad, PL art. 106e ust. 1 pkt 2: číslo jednoznačne identifikuje faktúru v rámci radu) | MEDIUM | INV-04. Transakčný zámok. `###` musí vedieť prerásť 999 (nezalamovať) |
| Adresár klientov s ID/VAT ID | Univerzálne | LOW | CLI-01/02, doplniť M3 |
| Stavy faktúry (vystavená, odoslaná, uhradená, po splatnosti) | Univerzálne | LOW | INV-06, doplniť M7 |
| Odoslanie faktúry e-mailom s PDF a opätovné odoslanie | Univerzálne | MEDIUM | MAIL-01/02. SMTP poskytovateľ ešte nevybraný (R6) |
| Dobropis / opravná faktúra | SK dobropis, PL faktura korygująca | MEDIUM | INV-07, doplniť M6 |
| Zoznam po splatnosti a upomienka | Univerzálne | LOW | PAY-02, MAIL-03 (ručná, nie automatická) |
| Viac mien a kurz | iDoklad, Fakturoid, inFakt to majú | MEDIUM | CUR-01..04, doplniť M15 |
| Viac jazykov dokladov | SuperFaktúra, Fakturoid, Invoice Ninja | MEDIUM | UI-02, jazyk klienta prebíja jazyk subjektu |
| Export CSV/XLSX pre účtovníčku | Univerzálne | LOW | RPT-02, doplniť M11 |
| Prehľad tržieb mesiac/rok a podľa klienta | Univerzálne | LOW | RPT-01 |
| Sledovanie limitu obratu k registrácii DPH | iDoklad, Fakturoid a inFakt ho majú v tej či onej forme | MEDIUM | LIM-01..03, doplniť M9, M10 |
| Nemennosť vystaveného dokladu | Účtovné a daňové požiadavky, jadro hodnoty | HIGH | INV-03. Kópia v JSONB plus uložené PDF a hash (M14) |
| Vyhľadávanie a filtrovanie faktúr a úloh | Univerzálne | LOW | TSK-06 (P1), doplniť filter faktúr podľa stavu a klienta |
| QR platba na faktúre | V SK/CZ takmer štandard (SuperFaktúra, iDoklad, Fakturoid) | MEDIUM | M8. PAY by square vyžaduje knižnicu na kompresiu (LZMA), overiť v PHP 8.5 |
| Viac bankových účtov podľa meny | Štandard pri cudzomenovej fakturácii | LOW | M4 |
| Bezpečný prístup (heslo, 2FA) | Faktúrové dáta sú citlivé | MEDIUM | E1 |
| Audit/denník zmien | Očakávané pri účtovných dátach | MEDIUM | E11 |
| Zálohy a obnova | Bez toho nie je nástroj dôveryhodný | MEDIUM | NFR-05, doplniť M12 |

### Differentiators (konkurenčná výhoda, zhodná s Core Value)

| Feature | Hodnota | Zložitosť | Poznámky |
|---------|---------|-----------|----------|
| Fakturácia úlohy po častiach cez mesiace (alokácie, zamykanie) | Žiadny z konkurentov (Harvest fakturuje čas, nie nacenené úlohy s čiastkami) to nerieši tak, ako to používateľ potrebuje | HIGH | E4 a E5, jadro produktu |
| Výkaz práce s odkazmi na portál klienta (Taskman) a „16 z 40 h“ | Presne to, čo používateľ dnes skladá ručne | MEDIUM | E7, REP-01 |
| Samostatné subjekty s verziami „platné od“ a prechodom SK -> PL | SaaS nástroje riešia jednu firmu s jednou krajinou | HIGH | E2, ENT-03/04 |
| Nastaviteľné zákonné texty a režim fakturácie na klientovi | Nepotrebuje programátora pri zmene právneho výkladu | MEDIUM | CLI-05, SET-01 |
| Limity v tabuľke, nie v kóde, s upozornením na chýbajúci rok | Reaguje na každoročné zmeny (SK 49 790 -> 50 000, PL 200 000 -> 240 000) | MEDIUM | LIM-01 |
| Predkontrola limitu pri vystavení (M9) | Chráni pred neúmyselnou stratou oslobodenia | LOW až MEDIUM | Súčet obratu + hodnota novej faktúry vs. limit, výstraha v náhľade INV-01 |
| Jednoklikové vystavenie faktúry + výkazu + odoslanie, do 2 minút | G2 | MEDIUM | Skladanie E5->E6->E7->E8 |
| Rýchle pridanie úlohy a zapracovanie na iPhone do 30 s | G5 | MEDIUM | DSH-01, panel zapracovania |
| Úsporná prevádzka (512 MB, bez SPA) | G6 | MEDIUM | NFR-01/02 |
| Balík pre účtovníčku (ZIP) a rola `accountant` | Samostatný užitočný prínos pri KSeF | LOW až MEDIUM | M11, AUTH-08 |
| VIES overenie VAT ID klienta pri režime EÚ B2B | Pre reverse charge je platné VAT ID podmienkou | LOW | Voliteľné, P2. Verejné API, zlyhanie nesmie blokovať |
| Vyhľadanie klienta podľa IČO/NIP v registri (RPO, GUS/REGON) | Konkurencia to má, ale používateľ má pár klientov | MEDIUM | Odložiť, nízka hodnota pri jednotkách klientov |

### Anti-Features (zámerne nebudovať)

| Feature | Prečo sa žiada | Prečo je problematická | Alternatíva |
|---------|----------------|------------------------|-------------|
| Opakované (recurring) faktúry | Fakturoid, SuperFaktúra, Invoice Ninja ich majú | Obsah faktúry sa mesačne mení (časti úloh), automatické vystavenie by porušilo kontrolu náhľadu | Obrazovka „Fakturácia mesiaca“ s predvyplneným mesiacom |
| Automatické upomienky (cron) | Štandard konkurencie | Riziko chybného odoslania klientovi, ďalšia pohyblivá časť na malom VPS | Ručná upomienka jedným klikom (MAIL-03) |
| Časomerač, timesheety | Harvest | Mimo rozsahu, fakturujú sa nacenené hodiny | Zadané hodiny na časti |
| Banka, párovanie platieb | iDoklad, Fakturoid, inFakt | Mimo rozsahu, bezpečnostné a licenčné náklady | Ručné označenie úhrady |
| Online platby (Stripe, PayPal) | Invoice Ninja, Fakturoid | Zvyšuje PCI a právnu záťaž, klient platí prevodom | QR platba (M8) |
| Klientsky portál | Invoice Ninja, Harvest | Mimo rozsahu, nová plocha útoku | E-mail s PDF |
| Náklady, účtovníctvo, DPH priznania, kontrolný výkaz | wFirma, inFakt, iDoklad | Je to úloha účtovníčky, obrovský rozsah | Exporty a ZIP balík |
| Režim platiteľa DPH (výpočty sadzieb, súhrnné výkazy) | Plnohodnotné fakturačné nástroje | Mimo rozsahu V1 | Len kód DPH na položke a stĺpce, ktoré to nezablokujú (M1) |
| Úprava vystavenej faktúry | Užívateľ opraví preklep | Porušuje Core Value | Dobropis a nové vystavenie |
| Mazanie faktúr, koncepty s predpridelením čísla | Pohodlie | Medzery v číslovaní, porušenie zákona | Náhľad bez čísla, číslo sa pridelí až pri vystavení v transakcii |
| Čiastkové dobropisy vo V1 | iDoklad, inFakt ich majú | Zložitá logika uvoľňovania častí | Celé storno a nové vystavenie (rozhodnutie PROJECT.md) |
| Návrhár šablón faktúry | SuperFaktúra, Fakturoid | Riziko vynechania zákonných náležitostí | Jediný čistý dizajn podľa právneho profilu |
| Zálohové a proforma faktúry, cenové ponuky | Bežné pri konkurencii | Samostatné právne režimy (PL faktura zaliczkowa, SK zálohová) | Odložiť na V2 ak sa objavia |
| Odosielanie do KSeF | Povinné pre PL, inFakt a wFirma ho majú | Rieši účtovníčka | Štruktúrované dáta, pozri FA(3) nižšie |
| Offline režim s dátami, push notifikácie | PWA lákadlo | Zložitosť, iOS obmedzenia | Cache statických súborov a offline stránka |

## Zákonné náležitosti faktúry (čo musí PDF a dáta obsahovať)

Tabuľky sú podklad pre „právne profily“ (SET-01). **Konkrétny text a rozsah pred nasadením potvrdí účtovníčka.** Pod tabuľkami je označená spoľahlivosť.

### SK, neplatiteľ DPH (zákon 431/2002 Z. z. o účtovníctve, § 3a Obchodného zákonníka)

| Náležitosť | Poznámka | Spoľahlivosť |
|------------|----------|--------------|
| Obchodné meno, sídlo/miesto podnikania, IČO | § 3a ObZ, pri FO aj údaj o zápise (živnostenský register, okresný úrad, číslo) | MEDIUM |
| DIČ dodávateľa | Neplatiteľ nemá IČ DPH | MEDIUM |
| Údaje odberateľa (názov, sídlo, IČO) | Účtovný doklad | MEDIUM |
| Číslo dokladu, dátum vystavenia, dátum dodania, splatnosť | Dátum dodania môže byť totožný s dátumom vystavenia | MEDIUM |
| Predmet plnenia a suma (položky) | Jedna položka na fakturačnú časť | MEDIUM |
| Platobné údaje (IBAN, VS) | Praktická, nie nutne zákonná | HIGH |
| „Nie som platiteľ DPH.“ | Nie je zákonom predpísané slovo, ale praktický štandard | MEDIUM |
| Podpisový záznam osoby | § 10 zákona 431/2002 ho formálne vyžaduje na účtovnom doklade, v praxi sa pri elektronických faktúrach nepoužíva. Overiť s účtovníčkou. | LOW |

Pre neplatiteľa neplatí § 71 zákona 222/2004 (náležitosti faktúry platiteľa) ani § 25 (opravný doklad), dobropis je teda účtovný doklad s odkazom na pôvodný. Zachovať referenciu a dôvod.

### PL, zwolnienie podmiotowe (art. 106e, art. 113 ust. 1 / ust. 9 ustawy o VAT)

| Náležitosť | Poznámka | Spoľahlivosť |
|------------|----------|--------------|
| Data wystawienia | art. 106e ust. 1 pkt 1 | MEDIUM |
| Kolejny numer w ramach jednego lub więcej ciągów | pkt 2. Rad jednoznačne identifikuje faktúru | MEDIUM |
| Imiona/nazwy i adresy sprzedawcy i nabywcy, NIP sprzedawcy | pkt 3 až 4 | MEDIUM |
| NIP nabywcy (ak je podatnikom) | Pre EÚ klienta VAT ID s prefixom krajiny | MEDIUM |
| Data dokonania lub zakończenia dostawy | Uvádza sa, ak sa líši od dátumu vystavenia | MEDIUM |
| Nazwa (rodzaj) usługi, miara/ilość, cena jednostkowa netto, wartość netto | Jednotka `godz.` / `h`. Podľa FA(3): P_7, P_8A, P_8B, P_9A, P_11 | MEDIUM |
| Podstawa prawna zwolnienia | pkt 19: „art. 113 ust. 1“ alebo „ust. 9“. FA(3): Adnotacje/Zwolnienie P_19 = 1, P_19A = text | MEDIUM |
| Kwota należności ogółem | pkt 15. FA(3): P_15 | MEDIUM |
| Termín płatności, forma platby, číslo účtu | Praktické | MEDIUM |
| Adnotácia „odwrotne obciążenie“ (reverse charge) | pkt 18. Pri službách podľa art. 28b voči EÚ B2B sa používa. **Pri zwolnieniu podmiotowym neisté**. | LOW |
| Registrácia VAT-UE a vlastné `PL` + NIP na faktúre pri B2B EÚ | Podľa výkladu je povinná aj pri zwolnieniu podmiotowym | LOW |

### EÚ B2B cezhranične (art. 28b ustawy o VAT, PL subjekt -> SK klient)

- Miesto poskytnutia služby je pravdepodobne pri nadobúdateľovi (SK), preto sa neúčtuje PL VAT (FA(3) P_12 = `np II`, výkaz VAT-UE), v poznámke zvyčajne „odwrotne obciążenie“ a odkaz na art. 28b (MEDIUM, preveriť).
- Takéto tržby sa pravdepodobne **nezapočítavajú do limitu 240 000 zł** (miesto plnenia mimo územia PL). Webové vyhľadanie to **nepotvrdilo ani nevyvrátilo** (LOW). Preto príznak „započítavať do limitu“ na klientovi (CLI-06) ostáva nevyhnutný a predvolená hodnota „áno“ je bezpečná (konzervatívna).
- Dáta na faktúre pri tomto režime: VAT ID klienta s prefixom, vlastné VAT-UE, text poznámky, kód DPH (M1).
- Neplatiteľ SK fakturujúci PL klientovi: DPH sa neúčtuje, bez reverse charge, ale na limit SK sa tržba započítava. Overiť s účtovníčkou.

### Dobropis / faktura korygująca

| Údaj | SK dobropis | PL faktura korygująca (art. 106j) |
|------|-------------|-----------------------------------|
| Označenie dokladu | „Dobropis“, vlastný rad alebo prefix | „FAKTURA KORYGUJĄCA“ v nadpise |
| Odkaz na pôvodný doklad | Číslo (a dátum) pôvodnej faktúry | Číslo, dátum a (po KSeF) číslo KSeF pôvodnej faktúry |
| Dôvod | Odporúčaný, uložiť povinne | `PrzyczynaKorekty` v FA(3), povinnosť závisí od výkladu, ukladať vždy |
| Obsah | Záporné položky alebo rozdiel | Položky pred a po alebo rozdiel, suma korekty |
| Vlastný dátum vystavenia | Áno | Áno |
| Vlastný číselný rad | Áno | Áno (zvyčajne prefix `FK`) |

Pre V1 (celé storno): kópia pôvodnej faktúry so zápornými množstvami a odkazom, uvoľnenie častí, stav pôvodnej „stornovaná“. Správne je ukladať aj typ korekty, aby sa FA(3) `RodzajFaktury = KOR` dalo neskôr odvodiť.

### Limity obratu 2026

| Krajina | Hodnota | Poznámka | Spoľahlivosť |
|---------|---------|----------|--------------|
| PL | 240 000 zł od 1. 1. 2026 (art. 113 ust. 1) | V prvom roku pomerne k počtu dní (ust. 9). Prechodné pravidlo pre tých, čo mali v 2025 predaj 200 až 240 tis. zł | MEDIUM (infor.pl, Sejm) |
| PL | Prekročenie = strata oslobodenia od tej tržby | Preto predkontrola (M9) | LOW (z tréningových znalostí) |
| SK | 50 000 € kalendárny rok, alebo 49 790 € kĺzavých 12 mesiacov | **Zdroje sa rozchádzajú** | LOW |
| SK | 62 500 €: registrácia okamžite | Zhoda zdrojov | MEDIUM |

Zmena SK limitu na 85 000 € bola podľa jedného zdroja zamietnutá parlamentom v máji 2026 (LOW, jeden zdroj).

## Dátový model pre budúcu FA(3) (INV-08)

FA(3) je povinná štruktúra KSeF od 1. 2. 2026. Použiť ako **kontrolný zoznam polí** v kópii údajov a v tabuľkách faktúry. Presnú schému (XSD) brať z [ksef.podatki.gov.pl](https://ksef.podatki.gov.pl) pri fáze KSeF. Zoznam nižšie je zo znalostí, rámcovo overený webom (MEDIUM pre členenie, LOW pre konkrétne názvy polí).

| Blok FA(3) | Čo musí model uložiť | Poznámka k modelu |
|------------|----------------------|-------------------|
| Naglowek | Kód a variant formulára (3), dátum a čas vytvorenia, informácia o systéme | Odvodené pri exporte |
| Podmiot1 (predávajúci) | NIP, názov, adresa (kód krajiny, riadky 1 a 2), e-mail, telefón | Zo verzie subjektu platnej k vystaveniu (ENT-03). Adresa ako riadky + kód krajiny ISO |
| Podmiot2 (kupujúci) | Jeden z: NIP, kód EÚ + VAT UE, kód krajiny + iné ID, bez ID. Názov, adresa | Preto M3 (typ ID + prefix krajiny) |
| Podmiot3 (ďalšie osoby) | Voliteľné, V1 nepotrebné | Nevytvárať |
| Fa: základ | Kód meny, P_1 dátum vystavenia, P_1M miesto vystavenia (voliteľné), P_2 číslo, P_6 dátum dodania (alebo obdobie `OkresFa`) | Pole „miesto vystavenia“ pridať na subjekt |
| Fa: sumy | P_13_x základ podľa sadzby/kódu, P_14_x daň, P_15 spolu | Pri 0 DPH len `P_13_x` a `P_15`. Uložiť sumy po skupinách kódov DPH |
| Fa: kurz | Kurz a sumy v PLN (`KursWalutyZ`, P_14_xW) pri cudzej mene s DPH | Ukladá sa už podľa CUR-02 |
| Adnotacje | P_16 (metóda kasowa), P_17 (samofakturowanie), P_18 (odwrotne obciążenie), P_18A (split payment), Zwolnienie: P_19 a P_19A/B/C (právny základ), NoweSrodkiTransportu P_22, P_23 (trojstranná), PMarzy | Pre V1 uložiť minimálne P_18 (príznak) a P_19 + dôvod oslobodenia, ostatné predvolene „nie“ |
| RodzajFaktury | VAT, KOR, ZAL, ROZ, UPR | V1: `VAT` a `KOR`. Pole „typ dokladu“ v tabuľke |
| Korekty | `PrzyczynaKorekty`, `TypKorekty`, `DaneFaKorygowanej` (dátum, číslo, číslo KSeF pôvodnej) | M6 |
| FaWiersz (riadky) | NrWierszaFa, P_7 názov, P_8A jednotka, P_8B množstvo, P_9A cena jednotková netto, P_11 hodnota netto, P_12 sadzba/kód (`23`, `0`, `zw`, `np I`, `np II`, `oo`) | M1. Množstvo = hodiny, jednotka = `godz.` |
| Platnosc | Zaplacono, DataZaplaty, TerminPlatnosci (dátum), FormaPlatnosci, RachunekBankowy (NrRB, SWIFT, názov banky) | M4, M7, M13 |
| WarunkiTransakcji, Stopka | Voliteľné. Stopka: údaje z registra (KRS/CEIDG) | Údaj o zápise na subjekte |
| Číslo KSeF | Pridelené po odoslaní | Nullable stĺpec, naplní sa až v budúcej fáze |

Pravidlá:
- Ukladať **každú sumu ako číslo v stĺpcoch** (net, VAT, gross na riadku aj na faktúre), nielen v JSONB, aby sa export dal generovať bez parsovania.
- Mená krajín ako ISO 3166 kód. Adresy ako štruktúra (ulica, číslo, PSČ, mesto, krajina), nie jeden reťazec.
- Zákonné texty uložiť do kópie údajov **aj s kódom dôvodu** (napr. `ART_113_1`, `ART_28B_RC`), z ktorého sa pri exporte odvodí P_19/P_18.

## Feature Dependencies

```
E1 Prihlásenie
    └──potrebné pre──> všetko ostatné

E2 Subjekty (verzie) ──requires──> ENT-02 doplnenie (VAT-UE, bankové účty)
E3 Klienti (typ ID, režim fakturácie, príznak limitu)
E4 Úlohy ──requires──> E3
E5 Fakturačné časti ──requires──> E4, CUR-01 (mena z klienta)
E6 Faktúry ──requires──> E2, E3, E5, INV-04 (číslovanie), E9 (kurzy)
    ├──requires──> kód DPH na položke (M1)
    ├──requires──> uložené PDF + hash (M14)
    └──requires──> atómové vystavenie (M16)
E7 Výkaz ──requires──> E6 (časti na faktúre)
E8 Odoslanie ──requires──> E6, E7, SMTP nastavenie
PAY (úhrady) ──requires──> E6
INV-07 Dobropis ──requires──> E6, uvoľnenie častí (E5)
LIM (limity) ──requires──> E6, INV-07, CLI-06, CUR-04
    └──enhances──> predkontrola limitu v náhľade INV-01
E10 Prehľady ──requires──> E6, PAY, CUR-04
ZIP balík pre účtovníčku ──requires──> E6, E7, RPT-02
INV-08 FA(3) pripravenosť ──requires──> M1, M2, M3, M4, M5, M6 (dátové polia od začiatku)
IMP-01 Import ──requires──> E3 (klient All4Net), E4, E5
E11 Audit ──enhances──> E2, E3, E4, E6, LIM
```

### Poznámky k závislostiam

- **E6 vyžaduje M1, M14, M16 od začiatku:** zmena schémy po prvých reálnych faktúrach je drahá, nemenné doklady sa nedajú migrovať.
- **LIM vyžaduje dobropisy:** obrat = faktúry mínus dobropisy, preto INV-07 musí vzniknúť pred LIM alebo spolu s ním (PRD M4 to poradie dodržiava).
- **Konflikt:** DPH režim platiteľa (anti-feature) vs. kód DPH na položke. Rozhodnutie: uložiť kód a sadzbu, nepočítať dane z platiteľských pravidiel.
- **Konflikt:** upraviť vystavenú faktúru vs. Core Value. Nikdy.

## MVP Definition

### Launch With (v1, zhoda s PRD P0 + doplnenia)

- [ ] E1 prihlásenie s TOTP (P0 položky) — bez toho sa nesmie nasadiť
- [ ] E2 subjekty s verziami, VAT-UE pole, viac bankových účtov (M2, M4)
- [ ] E3 klienti s typom ID a prefixom, režimom fakturácie, príznakom limitu (M3)
- [ ] E4 a E5 úlohy a časti so serverovým zamykaním
- [ ] E6 faktúra: číslovanie, kópia údajov, kód DPH na položke (M1), uložené PDF a hash (M14), atómové vystavenie (M16), SK a PL právny profil
- [ ] E7 výkaz
- [ ] E8 odoslanie e-mailom, ručná úhrada
- [ ] E9 kurzy s ručným prepisom
- [ ] DSH-01 dashboard, PWA kostra, import (IMP-01)
- [ ] LIM-01..03 so seedom SK a PL a predkontrolou (M9)

### Add After Validation (v1.x, v rámci P1/P2 z PRD)

- [ ] INV-07 dobropis s dôvodom a odkazom (M6) — najneskôr pred prvou opravou, poradie PRD M4
- [ ] MAIL-03/04 upomienky a šablóny
- [ ] RPT-01/02 prehľady a export plus ZIP balík pre účtovníčku (M11)
- [ ] QR platba (M8) — ak ju klienti vyžadujú, inak odložiť
- [ ] Stav „čiastočne uhradená“ (M7)
- [ ] AUD-01/02 audit
- [ ] Jazyky PL, CZ, EN, AUTH-06 obnovenie hesla

### Future Consideration (v2+)

- [ ] Export FA(3) a odoslanie do KSeF — rieši účtovníčka, dátový model pripravený
- [ ] Rola `accountant` v UI (AUTH-08)
- [ ] VIES overenie VAT ID, vyhľadanie klienta v registri
- [ ] Zálohové faktúry, čiastkové dobropisy
- [ ] Režim platiteľa DPH

## Feature Prioritization Matrix

| Feature | Hodnota pre používateľa | Náklady | Priorita |
|---------|--------------------------|---------|----------|
| Nemenná faktúra + kópia + uložené PDF (M14) | HIGH | MEDIUM | P1 |
| Číslovanie, atómové vystavenie (M16) | HIGH | MEDIUM | P1 |
| Časti a zamykanie | HIGH | HIGH | P1 |
| Kód DPH na položke (M1) | HIGH | LOW | P1 |
| VAT-UE, typ ID klienta, viac účtov (M2, M3, M4) | HIGH | LOW | P1 |
| Právne profily SK/PL a režimy fakturácie | HIGH | MEDIUM | P1 |
| Kurzy a prepočty | HIGH | MEDIUM | P1 |
| Predkontrola limitu (M9) | HIGH | LOW | P1 |
| Dobropis s dôvodom (M6) | HIGH | MEDIUM | P1 |
| Odoslanie e-mailom, úhrady | HIGH | MEDIUM | P1 |
| Výkaz práce | HIGH | MEDIUM | P1 |
| QR platba (M8) | MEDIUM | MEDIUM | P2 |
| Čiastočná úhrada (M7) | MEDIUM | LOW | P2 |
| ZIP pre účtovníčku (M11) | MEDIUM | LOW | P2 |
| Kĺzavé 12-mesačné okno limitu (M10) | MEDIUM | LOW | P2 (P1 ak SK fakturuje viac mesiacov) |
| Konfigurovateľný dátum kurzu (M15) | LOW | LOW | P2 |
| VIES, register klientov | LOW | MEDIUM | P3 |
| KSeF export | MEDIUM | HIGH | P3 (mimo V1) |

## Competitor Feature Analysis

Údaje o produktoch z tréningových znalostí (LOW až MEDIUM), neoverené na živých stránkach.

| Funkcia | SuperFaktúra / iDoklad / Fakturoid (SK/CZ) | inFakt / wFirma (PL) | Invoice Ninja / Harvest | Náš prístup |
|---------|------------------------------------------|----------------------|--------------------------|-------------|
| Faktúry PDF podľa krajiny | Áno, vrátane PAY by square QR | Áno, PL JPK a KSeF | Áno, generické šablóny | Právny profil, jednoduchý dizajn, QR neskôr |
| Opakované faktúry | Áno | Áno | Áno | Nie (anti-feature) |
| Zálohové a proforma | Áno | Áno (zaliczkowa) | Áno | Odložiť |
| Párovanie banky | Áno | Áno | Čiastočne | Nie, ručne |
| Náklady a daňové priznania | iDoklad, Fakturoid (základ) | wFirma, inFakt (plné) | Invoice Ninja (náklady) | Nie, účtovníčka |
| Časomerač | Fakturoid (základ) | Nie | Harvest (jadro) | Nie, nacenené hodiny |
| Klientsky portál / online platba | Čiastočne | Čiastočne | Áno | Nie |
| Sledovanie limitu DPH | Áno (základ) | Áno (limit zwolnienia) | Nie | **Áno, nastaviteľné, s predkontrolou** |
| Multi-subjekt s históriou | Obmedzene (jedna firma = jeden účet) | Obmedzene | Viac firiem, bez verzií | **Áno, samostatné subjekty s radmi** |
| Fakturácia po častiach z úloh | Nie | Nie | Čiastočne (Harvest projekty) | **Áno, jadro** |
| Výkaz s odkazmi na portál | Nie | Nie | Nie | **Áno** |
| KSeF | Nie | Áno | Nie | Neskôr, dátový model pripravený |

## Sources

- Vlastné dokumenty: `docs/PRD.md`, `docs/superpowers/specs/2026-10-23-task-tracker-design.md`, `.planning/PROJECT.md`
- PL limit 240 000 zł: [infor.pl, novela](https://ksiegowosc.infor.pl/wiadomosci/6852348,nowy-limit-zwolnienia-z-vat-od-2026-roku-240-tys-zl-sejm-uchwalil-nowelizacje-ustawy-o-podatku-od-towarow-i-uslug.html), [Sejm, uzasadnienie druku 1232](https://api.sejm.gov.pl/sejm/term10/prints/1232/1232-uzasadnienie.docx) (MEDIUM)
- SK prahy DPH: [aksamec.sk](https://www.aksamec.sk/dph-registracia-2026/), [pravnenoviny.sk](https://pravnenoviny.sk/zmeny-v-registracii-na-dph-nove-limity-nove-terminy-nove-povinnosti/), [beancount.io](https://beancount.io/sk/blog/2026/10/05/slovakia-vat-threshold-85000-rejected-registration-rules-guide) (LOW, zdroje sa rozchádzajú)
- FA(3) všeobecná štruktúra: [akademialtca.pl](https://akademialtca.pl/blog/ksef-schema-e-faktury-i-jej-budowa), [fakturowo.pl](https://www.fakturowo.pl/pomoc/struktura-e-faktury), [firmove.pl](https://firmove.pl/aktualnosci/finanse/ksiegowosc/faktura-ustrukturyzowana-co-musisz-wiedziec-o-e-fakturze-w-ksef) (MEDIUM). Oficiálna XSD: ksef.podatki.gov.pl, **neprečítaná**, overiť pri fáze KSeF
- Zákonné náležitosti SK/PL, art. 106e, 106j, 28b, 113: tréningové znalosti, **neoverené na texte zákona** (MEDIUM až LOW). Overiť s účtovníčkou a aktuálnym znením predpisov
- Konkurenčné produkty: tréningové znalosti (LOW až MEDIUM)

---
*Feature research for: fakturácia a evidencia úloh pre živnostníka SK -> PL*
*Researched: 2026-10-23*
