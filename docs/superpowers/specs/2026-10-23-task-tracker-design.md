# Task Tracker: evidencia úloh a fakturácia (návrh)

Dátum: 2026-10-23 · Stav: návrh na schválenie

Legenda: **[dohodnuté]** = rozhodol používateľ, **[návrh]** = rozhodnutie Clauda, ktoré používateľ ešte nepotvrdil výslovne, **[builder]** = necháva sa na implementátora.

---

## 1. Zámer a prečo

Používateľ (programátor-živnostník, jediný používateľ) dnes vedie úlohy pre klienta v Exceli (`All4net-fakturovanie-prace.xlsx`). Má v ňom mesačné bloky, v každom riadku odkaz do Taskmanu, názov, nacenené hodiny, sumu a stĺpce Zapracované a Vyfakturované (ÁNO/NIE). Hodinová sadzba je jediná pre celý súbor (27 €). Na konci mesiaca z toho **ručne** robí PDF výkaz práce, posiela ho klientovi e-mailom a platby sleduje ručne v banke. V Exceli sú chyby v súčtoch: októbrový súčet E25/F25 počíta len riadky 19–22.

Aplikácia má Excel nahradiť:
- evidencia úloh viacerých klientov,
- fakturácia po častiach a po mesiacoch,
- vystavenie **výkazu aj faktúry** jedným klikom a ich odoslanie e-mailom,
- sledovanie úhrad.

Pozadie, ktoré ovplyvňuje návrh: najbližšie mesiace fakturuje ako **slovenský živnostník, neplatiteľ DPH, v EUR**. Potom slovenskú živnosť zruší a založí **poľskú (PLN, zwolnienie podmiotowe z VAT)**. Staré doklady sa po zmene subjektu nesmú „prepísať“ na nový subjekt.

Úspech: všetko funguje, ako je tu popísané. Používateľ: „vždy to bude lepšie ako Excel“.

## 2. Ciele

1. Rýchla evidencia úloh na mobile aj na PC.
2. Fakturácia úlohy v ľubovoľnom mesiaci, aj po častiach na viac mesiacov.
3. Generovanie PDF výkazu a PDF faktúry spĺňajúcej náležitosti podľa SK alebo PL, plus odoslanie e-mailom.
4. Nemenné vystavené doklady s kópiou údajov z času vystavenia.
5. Prehľady a limity pre dane.
6. Bezpečný prístup (heslo a TOTP 2FA).
7. Inštalácia ako PWA na iPhone.
8. Čo najrýchlejšia aplikácia s minimálnou spotrebou zdrojov servera.

## 3. Mimo rozsahu (non-goals) prvej verzie

- **Integrácia s KSeF.** Faktúry do KSeF zatiaľ odosiela účtovníčka [dohodnuté]. Dátový model ale musí neskôr umožniť export alebo odoslanie (štruktúrované dáta faktúry, nielen PDF).
- **Napojenie na banku.** Úhrady sa označujú ručne [dohodnuté].
- **Meranie času (stopky, timesheety).** Fakturujú sa zadané hodiny [návrh].
- **Režim platiteľa DPH.** Netreba ho implementovať, ale model ho nesmie znemožniť (napríklad sadzba DPH na položke, predvolene 0 alebo „bez DPH“) [návrh].
- **Offline režim** (práca bez pripojenia) [návrh].
- **Klientsky portál a viac firiem v jednej inštalácii (SaaS).**
- **Synchronizácia s Taskmanom alebo inými portálmi.** Odkaz je len URL [návrh].

## 4. Čo by bolo zlyhaním, aj keby to technicky fungovalo (anti-goals)

- Vystavená faktúra alebo výkaz sa zmení, napríklad po zmene adresy, sadzby, meny alebo subjektu.
- Súčty nesedia (presne tá chyba, aká je dnes v Exceli).
- Aplikácia je ťažkopádna alebo pomalá na mobile, alebo náročná na server (SPA s veľkým JS balíkom, zbytočné služby).
- Používateľ sa z PWA na iPhone musí stále znova prihlasovať.

## 5. Používatelia a prístup

- **V1:** jeden používateľ, administrátor [dohodnuté].
- **Do budúcna:** obmedzený prístup napríklad pre účtovníčku (čítanie faktúr a prehľadov, export) [dohodnuté]. V1 má mať tabuľku používateľov a roly (`admin`, `accountant`), aby pridanie účtu nevyžadovalo zmenu modelu. Rolu `accountant` v UI implementuj, ak je to lacné [builder].

### Prihlásenie [dohodnuté: heslo a 2FA cez autentifikačnú aplikáciu]

- E-mail a heslo (Argon2id). Obmedzenie počtu pokusov o prihlásenie.
- **TOTP 2FA je povinná** (RFC 6238, kompatibilná s Google aj Microsoft Authenticator). Aktivuje sa cez QR kód pri prvom prihlásení.
- **Záložné kódy** (napríklad 10 jednorazových) pre prípad straty telefónu [návrh].
- Obnovenie hesla e-mailom [návrh].
- „Zapamätať zariadenie“ na 30 dní, aby sa PWA na iPhone neodhlasovala. TOTP sa na dôveryhodnom zariadení nevyžaduje pri každom otvorení [návrh].
- Denník prihlásení: úspešné aj neúspešné, IP adresa, zariadenie.

## 6. Doménový model

### 6.1 Môj subjekt (dodávateľ) s históriou [dohodnuté]

Nastavenia vlastného právneho subjektu, ktoré sa dajú meniť:
- obchodné meno,
- sídlo,
- krajina (SK/PL, pripraviť aj CZ),
- IČO alebo NIP/REGON, DIČ,
- údaj o zápise v registri (SK: živnostenský register, okresný úrad, číslo; PL: CEIDG),
- bankový účet (IBAN, SWIFT),
- e-mail, telefón,
- **mena**, **jazyk dokladov**,
- **DPH režim** (SK neplatiteľ / PL zwolnienie podmiotowe, art. 113 ust. 1),
- formát číslovania faktúr,
- predvolená splatnosť.

- Každá zmena vytvorí **novú verziu** s dátumom „platné od“. Staré verzie sa nemažú a v nastaveniach je viditeľná história.
- Pri prechode na poľskú živnosť ide **o samostatný subjekt** (vlastný záznam s vlastnou históriou verzií, obdobím pôsobenia od–do, vlastným číselným radom a vlastným obratom), nie o verziu toho istého subjektu [návrh].
- Na prelome môžu existovať oba subjekty naraz (napr. októbrová práca fakturovaná slovenskou živnosťou začiatkom novembra). Subjekt na faktúre sa predvyplní podľa dátumu vystavenia a dá sa zmeniť pri vystavení [návrh].
- Ukončený subjekt môže stále zobrazovať, znova odosielať a stornovať (dobropis) svoje doklady [návrh].

### 6.2 Klient [dohodnuté: viac klientov]

- Identifikačné a fakturačné údaje (názov, adresa, krajina, IČO, DIČ, IČ DPH, e-mail na faktúry, prípadne viac adries v kópii).
- **Hodinová sadzba a mena** (dnes All4Net 27 € bez DPH).
- **Šablóna odkazu na portál úloh** (Taskman pri All4Net, iný portál pri inom klientovi), jazyk dokladov pre klienta (prebije jazyk subjektu), splatnosť (prebije predvolenú), archivácia namiesto mazania.
- Zmeny klienta sa neprenášajú do vystavených dokladov (rieši to kópia údajov na doklade, pozri 6.5). Samostatná história klienta nie je potrebná [návrh].

### 6.3 Úloha

- Polia: klient, URL na portáli, názov, **nacenené hodiny** (odhad, ktorý vidí klient), **sadzba v čase nacenenia** (skopíruje sa z klienta, dá sa prepísať) a z nich **nacenená suma**, poznámka, dátum vytvorenia.
- Stav:
  - **V práci**: nie je zapracovaná.
  - **Zapracovaná**: klient potvrdil, že je hotová; dátum zapracovania.
  - Fakturačný stav sa odvodzuje z fakturačných častí: nevyfakturovaná, čiastočne vyfakturovaná, vyfakturovaná.
- **Fakturované hodiny sa môžu líšiť od nacenených** [dohodnuté]. Aplikácia zobrazí rozdiel (napríklad „+6 h“), ale nič neblokuje.
- Úloha je „aktuálna“, kým nie je zapracovaná **alebo** má nevyfakturované časti.

### 6.4 Fakturačná časť (alokácia) [dohodnuté]

- Úloha má 0 až N častí: **hodiny**, **fakturačný mesiac** (RRRR-MM), sadzba (predvolene sadzba úlohy) a suma.
- Ak časť ešte nie je na vystavenej faktúre, dá sa upraviť, presunúť do iného mesiaca aj zmazať.
- Po vystavení faktúry je časť **zamknutá** a odkazuje na faktúru.
- Veľkú úlohu (napríklad 200 h) možno fakturovať po častiach počas viacerých mesiacov, aj keď ešte nie je hotová. Zvyšok sa vyfakturuje neskôr.
- Pri označení úlohy ako zapracovanej sa zobrazí dialóg s výberom mesiaca (predvolený je aktuálny) a hodín (predvolený zostatok nacenených hodín, upraviteľný). Vytvorí sa z neho fakturačná časť.

### 6.5 Faktúra [dohodnuté: aplikácia vystavuje faktúry]

- Vystavuje sa **pre jedného klienta za jeden fakturačný mesiac**. Obsahuje nezamknuté časti tohto klienta s daným mesiacom.
- Pred vystavením sa zobrazí náhľad, kde sa dajú jednotlivé časti vyradiť (presunú sa do iného mesiaca).
- **Položky:** jedna položka za každú fakturačnú časť (názov úlohy, hodiny, sadzba, suma) [návrh]. Faktúra tak sama slúži ako súhrn a výkaz k nej pridáva odkazy na portál.
- Po vystavení je faktúra **nemenná**:
  - položky sa uložia ako riadky,
  - celá **kópia údajov** (subjekt v platnej verzii, klient, mena, jazyk, DPH režim, texty zákonných poznámok) sa uloží ako JSONB,
  - PDF sa generuje z kópie, nie z aktuálnych nastavení. Vygenerované PDF sa môže uložiť na disk ako cache [builder].
- Polia:
  - číslo,
  - dátum vystavenia, dátum dodania (predvolene posledný deň fakturačného mesiaca [návrh]), dátum splatnosti,
  - variabilný symbol (= číslo faktúry),
  - mena, súčet.
- Stavy: **vystavená**, **odoslaná** (dátum a adresáti), **uhradená** (dátum úhrady zadaný ručne), **po splatnosti** (odvodené), **stornovaná dobropisom**.
- **Dobropis (opravná faktúra)** odkazuje na pôvodnú faktúru a obsahuje záporné položky. Má vlastný číselný rad alebo prefix [builder] a uvoľní zamknuté časti, aby sa dali fakturovať znova [návrh].
- Dobropis je v V1 vždy celé storno pôvodnej faktúry [návrh].
- Faktúra sa dá kedykoľvek **znova odoslať** na ľubovoľný e-mail, aj z mobilu [dohodnuté].
- Pre budúci KSeF musia byť všetky údaje faktúry štruktúrované. Export do formátu FA(3) sa teraz neimplementuje.

#### Číslovanie [dohodnuté]

- Formát sa dá nastaviť pomocou tokenov. Predvolený je **`RRRRMM###`** (napríklad `202610001`): rok, mesiac a trojciferné poradové číslo.
- Navrhované tokeny: `RRRR`, `RR`, `MM`, `#…` (počet znakov `#` určuje počet číslic), ľubovoľný pevný text.
- Ak formát obsahuje `MM`, poradie sa začína od 1 každý mesiac. Ak obsahuje len rok, od 1 každý rok [návrh].
- Číselný rad patrí subjektu. Nový (poľský) subjekt má vlastný rad.
- Používateľ zatiaľ vlastné faktúry nevystavoval (doteraz fakturoval cez známeho), rad teda začína od 001. Počiatočné číslo radu sa napriek tomu dá nastaviť [návrh].
- Čísla musia ísť **bez medzier v poradí** a pridelenie čísla musí byť bezpečné voči súbehu (zámok v DB).
- Mesiac a rok v čísle sa berú z **dátumu vystavenia** [návrh].

#### Zákonné náležitosti

- Faktúra obsahuje **iba náležitosti, ktoré vyžaduje zákon** v krajine subjektu, v jednoduchom a čistom dizajne [dohodnuté].
- Náležitosti a zákonné texty sa definujú pre každú krajinu a DPH režim („právny profil“), nie natvrdo v šablóne.
- **SK, neplatiteľ DPH** (zákon o účtovníctve č. 431/2002 a § 3a Obchodného zákonníka):
  - obchodné meno, sídlo, IČO, údaj o zápise do registra s číslom,
  - DIČ,
  - údaje odberateľa,
  - poradové číslo, dátum vystavenia a dodania, splatnosť,
  - položky a sumy,
  - platobné údaje,
  - text „Nie som platiteľ DPH.“
- **PL, zwolnienie podmiotowe** (art. 106e ustawy o VAT):
  - NIP predajcu a kupujúceho,
  - číslo faktúry, dátumy,
  - položky,
  - podklad oslobodenia (art. 113 ust. 1 alebo ust. 9 ustawy o VAT),
  - platobné údaje.
- Ešte treba overiť: pri poľskom subjekte a slovenskom B2B klientovi je miesto poskytnutia služby u klienta (art. 28b) a môže to znamenať inú poznámku na faktúre a povinnosti voči VAT-UE. **Toto overí používateľ s účtovníčkou.** Aplikácia má mať v právnom profile konfigurovateľný text poznámky.
- Presné znenie náležitostí implementátor overí voči aktuálnym predpisom pri implementácii.

### 6.6 Výkaz práce [dohodnuté]

- PDF pre klienta za mesiac:
  - hlavička (subjekt, klient, obdobie),
  - tabuľka: odkaz na portál, názov úlohy, nacenené hodiny, fakturované hodiny v tomto mesiaci, suma,
  - súčty, sadzba, poznámka o DPH režime.
- Obsahuje práve tie časti, ktoré sú na faktúre za daný mesiac. Ukladá sa a odosiela spolu s faktúrou.
- Ak je pre úlohu fakturovaná len časť, výkaz to ukáže (napríklad „16 z 40 h“).

### 6.7 Úhrady a upomienky [dohodnuté]

- Ručné označenie úhrady (dátum, prípadne suma).
- Zoznam faktúr po splatnosti.
- **Upomienka:** e-mail z predpripravenej šablóny (v jazyku klienta) s PDF faktúry, odoslaný jedným kliknutím. Dátum odoslania sa zaznamená.

### 6.8 Prehľady a limity [dohodnuté]

- **Mesačný a ročný prehľad príjmov**: vyfakturované a uhradené, podľa klienta, v mene subjektu. Export do **CSV a XLSX**.
- **Sledovanie obratu voči limitom** s upozornením na dashboarde (napríklad pri 80 % a 100 %). Limity sú **nastaviteľné hodnoty** pre každú krajinu, nie konštanty v kóde.
  - **SK:** registrácia k DPH pri 50 000 € za kalendárny rok, okamžite pri 62 500 €. Stav k 10/2026, treba overiť.
  - **PL:** 240 000 zł ročne pri zwolnieniu podmiotowom (od 1. 1. 2026). V prvom roku podnikania sa limit počíta pomerne podľa počtu dní (art. 113 ust. 9).
  - Obrat sa počíta za subjekt, nie za celé obdobie používania aplikácie.
  - Predvolene sa počíta z **vystavených faktúr podľa dátumu dodania** (mínus dobropisy), nie z úhrad. Spôsob výpočtu je nastaviteľný pre každú krajinu [návrh].
  - Pre každý subjekt a rok sa dá zadať **počiatočný obrat** (príjmy mimo aplikácie, napr. pred jej nasadením) [návrh].

### 6.9 Denník zmien (audit) [dohodnuté]

- Zaznamenáva sa kto, kedy, čo (entita, ID, akcia, zmenené polia pred a po) pre úlohy, časti, faktúry, klientov, subjekt, nastavenia a prihlásenia.
- Zobrazenie je len na čítanie a dá sa filtrovať.

### 6.10 Meny a kurzy [dohodnuté]

- Podporované meny: **EUR, PLN, CZK**. Zoznam má byť rozšíriteľný bez zmeny modelu.
- Mena sa nastavuje **pre každého klienta** (sadzba klienta je v jeho mene). Mena subjektu je jeho „účtovná“ mena.
- Ak sa mena faktúry líši od meny subjektu, faktúra pri vystavení uloží do kópie údajov:
  - **kurz**, jeho zdroj a dátum,
  - **sumu prepočítanú na menu subjektu**,
  - a obe vytlačí na PDF.
- Zdroj kurzu sa riadi krajinou subjektu (konfigurovateľné v právnom profile) [návrh]:
  - PL: priemerný kurz **NBP** z posledného pracovného dňa pred dátumom dodania,
  - SK: kurz **ECB** (referenčný),
  - CZ: kurz **ČNB**.
- Kurz sa stiahne automaticky z verejného API príslušnej banky a pri vystavení sa dá ručne prepísať. Ak API nie je dostupné, vystavenie si kurz vypýta.
- Prehľady, exporty a sledovanie limitov počítajú v mene subjektu z uložených prepočítaných súm (nikdy nie aktuálnym kurzom).
- Jedna faktúra má vždy jednu menu. Fakturačné časti úlohy preberajú menu klienta.

## 7. Používateľské rozhranie

- Rozhranie je **responzívne**: mobil (hlavne iPhone) aj PC.
- Návrh je založený na **Bootstrap 5** [dohodnuté: Bootstrap], jednoduchý a čistý.
- **Jazyky rozhrania: SK, PL, CZ, EN** [dohodnuté]. Jazyk si volí používateľ.
- Jazyk dokladov (faktúra, výkaz, e-maily) sa riadi podľa klienta alebo subjektu, nezávisle od jazyka rozhrania. Preklady sú v súboroch jazykov frameworku.
- Formát čísel, dátumov a meny sa riadi jazykom (napríklad `1 080,00 €` / `1 080,00 zł`).

### Mobil [dohodnuté podľa makiet]

Hlavné činnosti na mobile: pridať úlohu, označiť ju ako zapracovanú, skontrolovať stav úloh a znova odoslať faktúru.

- **Hlavná obrazovka (variant B):**
  - hore aktuálny fakturačný mesiac a vybraný klient,
  - dve dlaždice: *Na fakturáciu tento mesiac* (suma a hodiny zapracovaných alebo pridelených častí) a *V práci* (suma a hodiny),
  - pod tým úlohy zoskupené podľa stavu: Zapracované, do faktúry / Čiastočne vyfakturované (s ukazovateľom priebehu) / V práci,
  - plávajúce tlačidlo „+“,
  - spodná lišta: Prehľad / Úlohy / Faktúry / Viac.
- **Nová úloha:** klient (predvolený posledný), URL, názov, odhad hodín, sadzba (predvyplnená) a živý prepočet ceny, poznámka.
- **Zapracované:** panel zospodu s výberom mesiaca (tlačidlá Aktuálny / Ďalší / Iný…) a hodín (predvolený zostatok) a živý prepočet sumy.
- **Detail úlohy:**
  - ukazovateľ: vyfakturované / naplánované / zostáva,
  - zoznam fakturačných častí (zamknuté s číslom faktúry, ostatné upraviteľné),
  - tlačidlá „+ Pridať časť do mesiaca“ a „Označiť ako zapracovanú“,
  - odkaz na portál.

Makety sú v `.superpowers/brainstorm/` (mobile-home.html, task-flows.html).

### PC

- Rovnaké funkcie, viac miesta.
- Tabuľkový prehľad úloh a faktúr.
- Obrazovka „Fakturácia mesiaca“ (výber klienta a mesiaca, náhľad častí, vystavenie, odoslanie).
- Nastavenia (subjekt a história, klienti, číslovanie, limity, šablóny e-mailov, používatelia, 2FA).
- Rozloženie [builder].

### PWA [dohodnuté]

- Web app manifest (názov, ikony vrátane `apple-touch-icon`, `display: standalone`, farba témy).
- Service worker, ktorý cachuje statické súbory (CSS, JS, ikony) a ponúka offline stránku „bez pripojenia“.
- Inštalácia na iPhone cez „Pridať na plochu“.
- Dáta sa offline necachujú [návrh].

## 8. Technológie [dohodnuté so zmenami používateľa]

Všetko v **najnovších stabilných verziách** [dohodnuté]:

- **PHP 8.5** (PHP-FPM, OPcache, preloading).
- **CodeIgniter 4.7.x** (PHP 8.5 podporuje až CI 4.7.0+; ku 7/2026 bola posledná 4.7.4, pred inštaláciou over najnovšiu).
- **Prihlasovanie:** CodeIgniter Shield.
  - Shield nemá vstavanú TOTP. Doplní sa vlastnou akciou 2FA nad overenou knižnicou (napríklad `spomky-labs/otphp` alebo `robthree/twofactorauth`) [builder: výber knižnice].
  - Ak by Shield bránil čistému riešeniu, je prípustné vlastné jednoduché prihlasovanie nad sessions CI4 [builder].
- **PostgreSQL** (najnovšia stabilná) [dohodnuté: MariaDB alebo PostgreSQL, výber Claude].
  - Sumy ako `numeric(12,2)`, hodiny ako `numeric(7,2)`.
  - Kópie údajov na dokladoch ako `jsonb`.
  - Nastavenia nízkej spotreby pamäte pre malý VPS.
- **Frontend:** **Bootstrap 5.3**, **htmx 2** (čiastočná aktualizácia stránky), **Alpine.js** pre drobnú interaktivitu (živý prepočet, panely).
  - **Žiadna SPA a žiadna build pipeline** [návrh].
  - Knižnice sa hostujú lokálne (nie z CDN) kvôli rýchlosti a PWA.
- **PDF:** mPDF (diakritika SK/PL/CZ).
- **E-mail:** SMTP cez knižnicu e-mailov CI4.
  - Poskytovateľa SMTP pre produkciu doplní používateľ [builder: pripraviť konfiguráciu cez `.env`].
  - Vo vývoji Mailpit.
- **XLSX export:** PhpSpreadsheet alebo ľahšia alternatíva [builder].

### Prostredie

- **Vývoj:** Docker Compose na lokálnom PC (Windows a WSL2) so službami nginx, php-fpm, postgres a mailpit. Projekt je v `/mnt/c/personal-projects/task-tracker`.
- **Git** od začiatku [dohodnuté]. Do `.gitignore` patrí `.superpowers/`, `.env`, `writable/`, `vendor/`.
- **Produkcia:** Hetzner Cloud alebo podobný VPS (najmenší plán).
  - Rovnaké Docker obrazy (produkčný profil).
  - HTTPS (Let's Encrypt).
  - Bezpečnostné hlavičky, sessions v DB alebo súboroch.
  - Postup nasadenia [builder].
- **Zálohy** [dohodnuté]:
  - denný `pg_dump` a uložené PDF dokladov, šifrované, mimo servera (napríklad Hetzner Storage Box),
  - rotácia (napríklad 14 denných, 8 týždenných, 12 mesačných) [návrh],
  - zdokumentovaná a otestovaná obnova.
- **Výkon:**
  - cieľ je odozva servera pod 100 ms pri bežných stránkach,
  - malý JS a CSS,
  - HTTP/2 a kompresia,
  - dlhá cache pre statické súbory s verziou v názve.

## 9. Import z Excelu [návrh]

Jednorazový import úloh za september a október 2026 zo súboru `All4net-fakturovanie-prace.xlsx` pre klienta All4Net (sadzba 27 €):
- „Zapracované = ÁNO“ sa importuje ako zapracovaná úloha s fakturačnou časťou v danom mesiaci,
- „Vyfakturované = NIE“ znamená, že časť je nezamknutá. V súčasnom súbore sú všetky riadky NIE, takže netreba riešiť import už vyfakturovaných častí.

Sú dve možnosti implementácie [builder]:
- jednorazový skript alebo seed,
- jednoduchý import súboru XLSX v nastaveniach.

Súbor je mimo repozitára a do gitu nepatrí.

## 10. Na implementátorovi

- schéma DB, názvy tabuliek a modulov, štruktúra kódu,
- rozloženie obrazoviek na PC,
- vzhľad PDF (jednoduchý, čistý, len zákonné údaje),
- konkrétne knižnice tam, kde je to vyznačené,
- testy (PHPUnit pre výpočty, číslovanie, zamykanie a kópie údajov sú nutnosť),
- skripty nasadenia.
