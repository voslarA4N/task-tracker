# Pitfalls Research

**Doména:** samohostovaná fakturácia a evidencia úloh pre jedného živnostníka (PHP 8.5, CodeIgniter 4.7, Shield, PostgreSQL, htmx, Alpine, mPDF, iOS PWA, SK/PL/CZ)
**Preskúmané:** 2026-10-23
**Celková istota:** MEDIUM. Prevažne znalosť domény a známe vzorce zlyhaní. V tejto relácii som nič nepreveroval živými zdrojmi (Context7, oficiálne dokumenty). Čokoľvek označené **[overiť]** treba pred implementáciou potvrdiť v dokumentácii alebo u účtovníčky. Právne tvrdenia (limity, dátumy vzniku povinnosti, kurzy) nie sú právne poradenstvo a rozhoduje o nich účtovníčka (riziko R1 v PROJECT.md).

Názvy fáz nižšie sú pracovné (F0 až F10). Roadmapper ich môže premenovať, dôležité je poradie závislostí:
F0 základ a infraštruktúra, F1 prihlásenie a 2FA, F2 subjekty a klienti, F3 úlohy a fakturačné časti, F4 faktúry (číslovanie, kópia údajov, PDF), F5 výkaz, e-mail, úhrady, F6 meny a kurzy, F7 limity, prehľady, exporty, F8 PWA a mobilné UX, F9 import z Excelu, F10 nasadenie, zálohy, obnova.

---

## Kritické pasce (spôsobia prepis alebo porušia Core Value)

### Pasca 1: Peniaze cez float alebo cez PHP `round()` na floatoch

**Čo sa pokazí:** súčty sa líšia o 0,01 medzi obrazovkou, výkazom, faktúrou a exportom (presne chyba, ktorú má dnes Excel). Dôvody: `(float)` z DB, Entity cast `float`, `json_encode` z floatov, `SUM()` v SQL proti súčtu v PHP, zaokrúhľovanie na rôznych miestach.

**Prečo sa to stane:** PDO pgsql vracia `numeric` ako string, ale CI4 Entity casty, `number_format`, JS v Alpine (živý prepočet) a `array_sum` ho potichu zmenia na float. Hodiny 1,25 × sadzba 27,00 = 33,75 sedí, ale 0,1 + 0,2 vzory prídu pri rozdelení hodín na časti.

**Ako sa vyhnúť:**
- Jedna trieda `Money` / `Hours` nad `brick/math` (BigDecimal) alebo `brick/money`, alebo BCMath. Žiadne `float` typy v doménovej vrstve, v PHPStan pravidle zakázať aritmetiku na floatoch.
- Pevné pravidlo, zapísané ako test: riadok = `round_half_up(hodiny × sadzba, 2)` (medzivýsledok má 4 desatinné miesta, zaokrúhli sa raz), súčet faktúry = súčet už zaokrúhlených riadkov (nikdy `round(sum(h) × sadzba)`). Výkaz a faktúra používajú tú istú funkciu. Zdroj pravdy je jedna funkcia, SQL `SUM` sa používa len na kontrolu v testoch.
- Do JSONB kópie ukladať sumy a hodiny **ako reťazce** (`"33.75"`), nie JSON čísla (PHP `json_encode` a JS ich robia floatmi).
- Živý prepočet v Alpine je len náhľad. Server ho vždy prepočíta a výsledok zobrazí z odpovede (htmx fragment), JS nikdy neposiela vypočítanú sumu ako vstup.
- Entity casty: `string` alebo vlastný cast na `Money`, nikdy `float`/`double`/`decimal` bez kontroly (CI4 `decimal` cast je tiež float-like **[overiť]**).
- Pri rozdelení úlohy na časti: suma časti = jej hodiny × sadzba časti (každá sadzba sa uloží na časť). Nikdy nepočítať „zostávajúcu sumu“ odčítaním, ale z hodín.

**Varovné signály:** v kóde `(float)`, `floatval`, `round(` na vstupe z DB; testy iba s „pekným“ číslami (27,00 × 2,00). Prehľad sa líši od súčtu faktúr.

**Fáza:** F0 (knižnica Money + pravidlá), F3 (časti), F4 (riadky faktúry). Test: property-based test, že súčet riadkov v PDF = hlavička = DB súčet pre náhodné hodiny po 0,25 a sadzby s 2 desatinami.

---

### Pasca 2: Prepočet meny (kurz) zaokrúhlený inak na každom mieste

**Čo sa pokazí:** suma v mene subjektu (PLN) na faktúre, v prehľade a v limite sa líši. Prepočet po riadkoch dáva iný súčet než prepočet súčtu. Opačný kurz (1/x) dáva iné centy ako publikovaný kurz.

**Ako sa vyhnúť:**
- Prepočítať **súčet faktúry** (nie riadky) jedným kurzom a zaokrúhliť raz (half-up na 2 desatinné). Rovnaké pravidlo pre všetky zdroje. Riadky v cudzej mene sa neprepočítavajú, alebo sa prepočítavajú len informatívne a označené.
- Kurz ukladať presne tak, ako ho publikuje zdroj (NBP 4 desatinné, ECB 4, ČNB 3), ako `numeric(14,6)`, spolu s `source`, `rate_date` (dátum, ku ktorému kurz patrí), `fetched_at`, `manual_override` a smerom (`base`/`quote`). Nikdy neukladať prevrátený kurz.
- **ECB** publikuje „1 EUR = X cudzej meny“. Pre SK subjekt s PLN faktúrou je prepočet **delením** (PLN → EUR). **ČNB** udáva kurz „za množstvo“ (napr. 100 HUF), pre CZK/EUR/PLN je množstvo 1, ale kód musí množstvo poznať. NBP tabuľka A udáva PLN za 1 jednotku. Jednotkové testy na všetky tri smery (EUR→PLN, PLN→EUR, CZK→EUR).
- Prehľady a limity čítajú iba uložený `amount_in_entity_currency` z kópie údajov (to už spec predpisuje, treba to vynútiť aj v SQL: žiadny JOIN na tabuľku kurzov v reportoch).

**Varovné signály:** funkcia `convert()` volaná v reportoch; kurz uložený bez dátumu a zdroja.

**Fáza:** F6, kontrola v F7.

---

### Pasca 3: Dátum kurzu „posledný pracovný deň pred dátumom dodania“ a poľské sviatky

**Čo sa pokazí:** kurz sa berie za deň, kedy NBP nič nepublikoval (víkend, sviatok, napr. 1. 11., 11. 11., 6. 1., Corpus Christi), API vráti 404 a vystavenie padne. Alebo vlastný kalendár sviatkov zastará či sa zmýli (nový sviatok 24. 12. je v Poľsku voľný od 2025 **[overiť]**).

**Ako sa vyhnúť:**
- Nepísať vlastný kalendár sviatkov. Opýtať sa NBP na **rozsah** (`/api/exchangerates/rates/a/{kod}/{od}/{do}/`, napr. od dátumu dodania − 10 dní do dátumu dodania − 1 deň) a vziať **posledný dostupný záznam**. API samo je autorita pre „pracovný deň“. Použiť len dni prísne pred dátumom dodania. Ak je rozsah prázdny, požiadať o ručné zadanie. **[overiť presný tvar URL a správanie 404 v dokumentácii NBP]**
- Pravidlo „deň pred dátumom dodania“ vs „deň pred vznikom daňovej povinnosti“ (v PL bežne skorší z dátumu dodania alebo faktúry) je právna otázka. Dátum, od ktorého sa hľadá, nech je v právnom profile konfigurovateľný (`delivery_date` | `issue_date`) a odovzdaný účtovníčke na potvrdenie. **[overiť u účtovníčky]**
- Kurzy cachovať v DB (unikátny kľúč zdroj + mena + dátum) a **stiahnuť ešte v kroku náhľadu**, nie počas vystavenia. Volanie externého API nesmie nikdy bežať v transakcii, ktorá drží zámok čítača čísiel (pozri pascu 5). Krátky timeout (3 s), pri zlyhaní ručné zadanie, s uloženým `manual_override=true`.
- ECB (SK): publikuje len v dni TARGET a asi o 16:00 SEČ, na dnešný deň ráno kurz ešte nie je. ČNB: kurz pre aktuálny deň po 14:30. Rovnaký princíp „posledný dostupný pred dátumom“. **[overiť časy publikácie]**

**Varovné signály:** v kóde zoznam sviatkov, `-1 day` bez slučky, HTTP volanie vo vnútri `transStart()`.

**Fáza:** F6. Testy s dátumami: dodanie v pondelok po víkende, 2. 11. po 1. 11., 31. 12., 1. 1., Veľká noc.

---

### Pasca 4: Únik nemennosti (PDF sa „prepočíta“ zo živých dát alebo zmení po aktualizácii)

**Čo sa pokazí (konkrétne cesty úniku):**
1. Šablóna číta živého klienta/subjekt/úlohu (JOIN) namiesto kópie. Zjavné.
2. Kópia je, ale **texty zákonných poznámok, preklady popiskov (lang súbory), názov úlohy, URL portálu (šablóna odkazu klienta), „nacenené hodiny“ úlohy a „fakturované doteraz“** sa ťahajú zo živých dát. Výkaz „16 z 40 h“ sa po neskoršej zmene odhadu úlohy zmení.
3. PDF sa pri „znovu odoslať“ **vygeneruje nanovo** po aktualizácii mPDF, fontov, ICU (od ICU 72 sa zmenili medzery a NBSP pri formátovaní čísiel pre pl/sk/cs, takže `1 080,00 zł` môže mať iný znak), PHP, alebo zmene šablóny. Vzhľad a bajty sa líšia od toho, čo klient dostal.
4. `intl` formátovanie závisí od locale v kontajneri, a obrázok Dockeru sa po rebuilde zmení.
5. Mutovateľné stavy (odoslaná, uhradená) sa ukladajú do toho istého riadku ako nemenné polia a nič nezabráni UPDATE na ostatné stĺpce.

**Ako sa vyhnúť:**
- **PDF faktúry a výkazu sa pri vystavení vygeneruje raz a uloží ako autoritatívny artefakt** (so `sha256`, verziou šablóny, verziou mPDF). „Znova odoslať“ a „stiahnuť“ vždy servíruje uložené bajty. Regenerácia je len núdzová funkcia (uložený súbor chýba) a označí sa. Spec to nechával ako „cache [builder]“, odporúčam to povýšiť na požiadavku. Zvážiť uloženie PDF ako `bytea` v samostatnej tabuľke `invoice_documents` (veľkosť ~50 až 150 kB, stovky dokladov, atomicky v rovnakom `pg_dump`, žiadny rozpad DB a súborov pri zálohe). Ak súbory, tak adresovať podľa hashu a zálohovať spolu s dumpom.
- Kópia údajov (JSONB) obsahuje všetko, čo PDF zobrazuje: položky (názov úlohy, URL, hodiny, sadzba), predvypočítané sumy, kurz, texty zákonných poznámok v jazyku dokladu, **už preložené popisky** alebo aspoň `template_version` + `lang_pack_hash`, formátovacie reťazce.
- Šablóna sa verzuje (`template_version` v kópii), staré verzie sa v kóde nemažú. Fonty (TTF) vložiť do repozitára a pripnúť, nespoliehať na systémové fonty. `composer.lock` je záväzný, mPDF sa aktualizuje len vedome so „golden“ testom.
- Formátovanie čísel nerobiť cez `NumberFormatter` počas renderu z kópie, alebo ho pripnúť (vlastné formátovanie s explicitným oddeľovačom).
- V DB vynútiť: `BEFORE UPDATE OR DELETE` trigger na `invoices` (okrem povolených stĺpcov), `invoice_items`, `invoice_documents`, `audit_log` vyhodí výnimku. Stav (odoslaná, uhradená, storno) držať v samostatných tabuľkách (`invoice_events`, `payments`, `credit_notes`) alebo povoliť v triggeri len konkrétne stĺpce. Aplikačná DB rola nemá právo UPDATE/DELETE na tieto tabuľky.
- JSONB nezachová poradie kľúčov ani biele znaky. Ak je potrebné kryptograficky dokázať obsah, hashovať kanonickú serializáciu uloženú ako `text`/`json`, nie `jsonb`.

**Varovné signály:** funkcia `renderInvoicePdf(invoiceId)` ktorá robí `model->find()` na živé entity; `Time::now()` vo vnútri šablóny; PDF sa generuje pri každom stiahnutí; test „zmeň klienta po vystavení“ neexistuje.

**Fáza:** F4 (návrh kópie a triggerov), F5 (výkaz). Povinný test: vystav, zmeň adresu klienta, sadzbu, názov úlohy, zákonný text, lang súbor, potom porovnaj bajty stiahnutého PDF (musia byť identické) a skúšobnú regeneráciu (musí byť vizuálne zhodná).

---

### Pasca 5: Číslovanie bez medzier a súbeh v PostgreSQL

**Čo sa pokazí:**
- Použije sa `SEQUENCE`/`SERIAL`/`IDENTITY`: pri rollbacku vznikne diera (sekvencie nie sú transakčné). Dobropis a zlyhané vystavenie dieru vyrobia.
- `SELECT max(number)+1` alebo `SELECT ... FOR UPDATE` na neexistujúci riadok (prvá faktúra mesiaca: žiadny riadok, nič sa nezamkne, dve požiadavky dostanú rovnaké číslo).
- Zámok držaný počas dlhej práce (PDF, e-mail, HTTP na NBP): blokuje, alebo pri páde PHP procesu nedrží nič a nastane zmätok.
- Číslo pridelené pri vytvorení konceptu a koncept sa zmaže (diera).
- Číslo sa odvodzuje z dátumu vystavenia, ktorý používateľ môže ručne zmeniť: poradie čísel nesedí s dátumami (`202611001` s dátumom 31. 10.).
- Zmena formátu číslovania v strede roka kolíduje s existujúcimi číslami.
- Dobropisy a faktúry zdieľajú rad, alebo storno „znovupoužije“ číslo.

**Ako sa vyhnúť:**
- Tabuľka `numbering_counters(entity_id, series, period_key, last_value)` s primárnym kľúčom na prvé tri stĺpce. `period_key` je výsledok formátu (napr. `202610` pri tokene `MM`, `2026` inak, `''` bez resetu). Pridelenie jedným atomickým príkazom v transakcii vystavenia:
  `INSERT ... VALUES (…, start-1+1) ON CONFLICT (entity_id, series, period_key) DO UPDATE SET last_value = numbering_counters.last_value + 1 RETURNING last_value`
  Tento príkaz zamkne riadok až do COMMIT a vytvára riadok, ak chýba. Rollback vráti čítač späť, takže **žiadna diera**. Pri `READ COMMITTED` (predvolené) funguje bez opakovania. Nepoužívať `SERIALIZABLE` pre túto cestu bez retry slučky.
- Číslo sa prideľuje **až ako posledný krok** vystavenia, v rovnakej transakcii ako INSERT faktúry, položiek, kópie údajov a zamknutia častí. Koncept nemá číslo.
- Unikátny index `(entity_id, series, invoice_number)` ako druhá poistka.
- Externé volania (kurz, e-mail) mimo transakcie. PDF: buď render vo vnútri transakcie (≤2 s, jeden používateľ, zámok nikoho nebolí, fatálna chyba/OOM zavrie spojenie a PG vráti rollback), alebo po COMMIT s idempotentným opakovaním. Rozhodnúť a zdokumentovať; odporúčam vo vnútri transakcie, pretože to zachová „žiadna faktúra bez PDF“.
- Počiatočné číslo radu: nastaví čítač len ak riadok pre `period_key` neexistuje. Zmena formátu nové `period_key` neovplyvní staré čísla (kľúč je vyrenderovaná predpona), kolíziu zachytí unikátny index.
- Dátum vystavenia: predvolene dnes (v lokálnej TZ subjektu), spätné dátumovanie len s upozornením a nie skôr než dátum poslednej vystavenej faktúry v rade. **[overiť u účtovníčky, či musí byť poradie čísel chronologické]**
- Dobropis má vlastný rad (`series='credit'` alebo prefix). Stornovaná faktúra si číslo nechá navždy.
- Sanitizovať číslo v názve súboru (formát dovoľuje pevný text, napr. `/`, a ten v názve prílohy robí problém).
- CI4: používať `$db->transException(true)->transStart()`/`transComplete()` alebo `$db->transaction(fn)` **[overiť dostupnosť v 4.7]**. Bez výnimiek môže pri chybe ostať transakcia nedokončená. Zakázať trvalé spojenia (`pConnect=false`).
- Test súbehu: dve **samostatné** spojenia PDO (alebo dva procesy) vystavujú naraz 50 faktúr, výsledok sú čísla 1..50 bez diery a bez duplicity. Test v jednom spojení (alebo zabalený do testovacej transakcie `DatabaseTestTrait`) súbeh neodhalí.

**Varovné signály:** v migrácii `SERIAL` pre číslo faktúry; `max(` v kóde; žiadny test s dvoma spojeniami.

**Fáza:** F4. Je to najdôležitejšia jednotlivá úloha v projekte.

---

### Pasca 6: Vystavenie faktúry z iných častí, než aké používateľ videl (staré formuláre, súbeh tabov)

**Čo sa pokazí:** v náhľade je 5 častí, medzitým iný tab (alebo mobil) jednu presunul či upravil. Vystaví sa faktúra s inou sumou, než bola v náhľade. Alebo sa dve vystavenia pre tohto klienta a mesiac zbehnú naraz a časť sa zamkne dvakrát.

**Ako sa vyhnúť:**
- Náhľad vráti `preview_token` (hash zoradeného zoznamu `part_id:version/hours/rate` + kurz). Vystavenie ho porovná so stavom po `SELECT ... FOR UPDATE` na časti. Ak sa líši, nevystaví, zobrazí rozdiel.
- Zamknutie častí: `UPDATE parts SET invoice_id=… WHERE id IN (…) AND invoice_id IS NULL`, kontrola `affected_rows == očakávané`. Trigger zakáže úpravu zamknutej časti. Verzia (`updated_at`/`row_version`) pri editácii časti (optimistický zámok).
- Väzba „položka faktúry → časť“ je trvalá (`invoice_items.part_id`), aktuálny „zámok“ je odvodený od nestornovanej faktúry. Dobropis nesmie prepísať históriu (ak sa časť uvoľní a vyfakturuje znova, majú ju dve faktúry). Fakturačný stav úlohy a „vyfakturované hodiny“ počítať len z položiek nestornovaných faktúr.
- Tlačidlo „Vystaviť“ s `hx-disabled-elt` a idempotenčným kľúčom (dvojklik na mobile).

**Fáza:** F3 a F4.

---

### Pasca 7: Verzie „platné od“ a hranice dátumov/časových pásiem

**Čo sa pokazí:**
- Kontajnery bežia v UTC. `CURRENT_DATE`/`date('Y-m-d')` o 00:30 miestneho času 1. novembra ešte ukazuje 31. október. Faktúra dostane číslo `202610…` a predvolený dátum dodania zlý mesiac.
- Rozdiel Europe/Bratislava vs Europe/Warsaw nie je problém (rovnaký posun a rovnaké DST pravidlá). Problém je lokálny čas vs UTC a použitie rôznych „dnes“ na rôznych miestach.
- „Posledný deň mesiaca“ počítaný ako `+30 days` (február, 31-dňové mesiace). Prechod 31. 12. → 1. 1.: faktúra za december vystavená v januári má číslo `2026 01…`, dodanie 31. 12. a do limitu patrí do **starého** roka, kým „fakturačný mesiac“, „mesiac vystavenia“ a „mesiac dodania“ sú tri rôzne veci. Excelová reč „mesiac“ ich zlieva.
- Verzia subjektu sa vyberá podľa nesprávneho dátumu (dnes vs dátum vystavenia vs dodania), alebo sa prekrývajú intervaly („platné od“ bez „do“), alebo je dve verzie s rovnakým `valid_from`. Úprava historickej verzie na mieste pokazí história.
- Pri zmene medzi dvoma subjektmi (SK končí, PL začína) sa vyberie subjekt len podľa dnešného dňa a koncept z októbra dostane nový subjekt.

**Ako sa vyhnúť:**
- Jedna služba `Clock` (injektovaná), jediný zdroj „dnes“ v časovom pásme subjektu (alebo aplikácie, `Europe/Bratislava` pre oba). V testoch zmrazená. Zakázať `date()`, `time()`, `new DateTime()` bez `Clock` (PHPStan/grep pravidlo).
- Biznis dátumy ako `date`, okamihy ako `timestamptz`. Nastaviť `TZ`, `date.timezone`, CI4 `appTimezone` a PG `timezone` explicitne a rovnako. Do DB vždy posielať lokálny dátum z `Clock`, nikdy sa nespoliehať na `CURRENT_DATE` v dotazoch s bizniz významom.
- Posledný deň mesiaca cez `DateTimeImmutable::modify('last day of this month')`. Testy: február v prestupnom a bežnom roku, 30./31. dni, 31. 12., polnoc, prechod DST (poslednú nedeľu marca/októbra).
- Verzie subjektu: `valid_from date NOT NULL`, unikát `(entity_id, valid_from)`, výber `WHERE valid_from <= :d ORDER BY valid_from DESC LIMIT 1`. Verzie sa nemenia na mieste (len nová verzia, úprava histórie len s auditom). Dátum, podľa ktorého sa verzia vyberá, je dátum vystavenia (spec) a zapíše sa do kópie. Pri kolízii obdobia pôsobenia subjektu (`active_from`/`active_to`, exclusion constraint) UI varuje, ale dovolí vystaviť na ukončený subjekt s potvrdením (spec to dovoľuje).
- Stav „po splatnosti“ je odvodený v čase dotazu z `Clock`, nikdy ukladaný ani riešený cronom.
- V UI a exportoch pomenovať tri dátumy odlišne: „fakturačný mesiac“, „dátum vystavenia“, „dátum dodania“. Každý prehľad uvádza, podľa ktorého sa počíta.

**Fáza:** F0 (Clock, TZ), F2 (verzie), F4, F7.

---

### Pasca 8: Výpočet limitov obratu (SK, PL) je jemnejší než „súčet za rok“

**Čo sa pokazí:**
- **SK:** spec uvádza „50 000 € za kalendárny rok“. Povinnosť registrácie k DPH sa podľa môjho poznania viaže na obrat za **predchádzajúcich 12 po sebe idúcich kalendárnych mesiacov** (kĺzavo), nie na kalendárny rok. **[overiť, rozpor so spec, rozhoduje účtovníčka]** Výpočet len za kalendárny rok limit nezachytí v polovici roka.
- **PL:** prvý rok podnikania pomerne podľa dní (art. 113 ust. 9): limit × (dni činnosti v roku / 365). Počet dní vrátane dňa začatia, 365 aj v prestupnom roku (zákon používa 365 **[overiť]**). Hodnota 240 000 zł (od 2026) a súvisiace výnimky sa menia, preto tabuľka limitov, nie kód. Do limitu sa nezapočítavajú niektoré položky (napr. predaj majetku, služby s miestom plnenia mimo PL). **[overiť]**
- Dátum rozhodujúci pre obrat: dodanie vs vystavenie vs úhrada. Pre PL môže vznik daňovej povinnosti nastať skôr (vystavenie faktúry pred dodaním, záloha). Spec zvolil „dátum dodania“ ako predvolené a nastaviteľné. Nechať to nastaviteľné aj v kóde (strategy `delivery_date | issue_date | payment_date`).
- **Dobropisy:** či zníženie obratu patrí do obdobia pôvodnej faktúry alebo obdobia dobropisu. Pri V1 „vždy celé storno“ je najčistejšie odpočítať v období **pôvodnej faktúry** (storno ju celú ruší). Voľbu zapísať a testovať. Zmena minulých rokov spätne mení historický obrat, upozorniť v UI.
- Faktúra v cudzej mene sa do limitu počíta v **uloženej prepočítanej** sume, nie aktuálnym kurzom (spec).
- Faktúry klientov s príznakom „nezapočítať do limitu“ a počiatočný obrat treba zahrnúť do rovnakej funkcie, inak dashboard a export ukážu rôzne čísla.
- Chýbajúci riadok limitu pre aktuálny rok: spec hovorí „použi posledný známy a upozorni“. Chybou je upozornenie zabudnúť alebo ho potlačiť.
- Obrat sa počíta za **subjekt**, nie za používateľa, a pri prechode SK → PL sa neprenáša.

**Ako sa vyhnúť:** jedna funkcia `TurnoverCalculator` so stratégiami (`calendar_year`, `rolling_12m`, `pro_rata_first_year`), prahmi z tabuľky, a testmi (hraničné dni 1. 1., 31. 12., začiatok činnosti 15. 7., dobropis v inom roku, cudzia mena, vylúčený klient, počiatočný obrat). Dashboard aj export používajú tú istú funkciu. Zobraziť na obrazovke vzorec („od–do, spôsob, zahrnuté faktúry“) pre kontrolu s účtovníčkou.

**Fáza:** F7 (+ otázky účtovníčke pred F7).

---

### Pasca 9: Vlastná TOTP nad Shieldom (2FA bypass, replay, zamknutie)

**Čo sa pokazí a prevencia:**
- **Čiastočne prihlásený stav** (heslo OK, TOTP čaká) sa berie ako prihlásený. Overiť, že `auth()->loggedIn()`, filter `session` a vlastné routy odmietnu stav „pending“ (Shield Actions to riešia, ale vlastná akcia a vlastné filtre ho môžu obísť). **[overiť v zdrojoch Shield 1.x]** Integračný test: po kroku hesla je `/dashboard` nedostupný.
- **Remember-me Shield vs „zapamätať zariadenie“ pre TOTP** sú dve rôzne veci. Nepoužiť remember cookie ako dôkaz 2FA, a naopak. **[overiť, či Shield remember-me prihlásenie spustí Actions]**. Dôveryhodné zariadenie: náhodný 256-bitový token, v DB iba hash, viazaný na používateľa, platnosť 30 dní, rotácia pri použití, zoznam a odvolanie zariadení, odvolanie pri zmene hesla a resete 2FA. Cookie `__Host-` prefix, `Secure`, `HttpOnly`, `SameSite=Lax`.
- **Replay:** uložiť posledný použitý časový krok (`last_used_step`) a odmietnuť kód s krokom ≤ posledný. Okno ±1 krok (30 s). Porovnanie v konštantnom čase.
- **Rate limit** na 2FA kroku zvlášť, per používateľ (nie len IP): 6 číslic = 10^6 kombinácií a 3 platné kódy v okne. Pri jedinom používateľovi tvrdé zamknutie účtu je DoS-vektor, preto rastúce oneskorenie + upozornenie e-mailom, nie trvalý zámok. Shield Throttler používa cache, takže cache ovládač musí byť perzistentný (nie `dummy`).
- **Tajomstvo TOTP** šifrovať v pokoji (CI4 Encryption, kľúč v `.env`). Strata alebo rotácia kľúča = zamknutie. Kľúč zálohovať oddelene (pozri pasca 17). QR kód generovať **lokálne** (nikdy cez externú QR API, ktorá by tajomstvo videla), tajomstvo zobraziť raz, aktivovať až po overení prvého kódu, otpauth URL nelogovať.
- **Záložné kódy:** 10 kódov s ≥ 64 bitmi entropie, ukladať hashované (SHA-256 s pepper alebo `password_hash`), zobraziť raz, jednorazové s atomickým `UPDATE ... WHERE used_at IS NULL` a kontrolou počtu riadkov, regenerácia zneplatní staré, upozornenie pri < 3 zostávajúcich.
- **Obnova hesla e-mailom nesmie obísť TOTP** (inak je 2FA len ozdoba). Po resete hesla sa vyžiada TOTP alebo záložný kód a odvolajú sa dôveryhodné zariadenia.
- **Fixácia relácie:** regenerovať session ID po hesle aj po TOTP.
- **Hodiny servera:** NTP/chrony na VPS, inak kódy zlyhávajú.
- Denník prihlásení: zaznamenať aj neúspešné kroky 2FA, nikdy nelogovať kódy ani tajomstvá.
- Záloha plánu B (spec to povoľuje): vlastné prihlásenie nad CI4 sessions. Rozhodnúť po prvom prototype v F1, nenechať tlačiť Shield v presile.

**Fáza:** F1. Vyžaduje samostatný bezpečnostný checklist a testy (replay, bypass, brute-force, záložný kód dvakrát).

---

### Pasca 10: iOS PWA (stále odhlasovanie, izolované úložisko, cachované HTML s CSRF)

**Čo sa pokazí:**
- **Session cookie bez `Expires`** sa zahodí, keď iOS PWA proces ukončí. Používateľ sa stále prihlasuje (anti-goal v spec). Nastaviť perzistentnú cookie (`Max-Age`) pre reláciu aj remember-me, a `Session::$expiration` na dni, nie 7200 s.
- **Standalone PWA má oddelené úložisko od Safari.** Prihlásenie v Safari sa neprenesie, PWA treba po inštalácii prihlásiť znova a testovať práve v PWA. Odkazy z e-mailu sa otvoria v Safari, nie v PWA (iná relácia). **[overiť pre aktuálnu verziu iOS]**
- **`Session::$matchIP=true`** odhlási pri prepnutí Wi-Fi/LTE. Nechať `false`, a `regenerateDestroy=false` (súbežné htmx požiadavky pri regenerácii ID inak odhlásia).
- ITP limit 7 dní týka JS-nastaviteľného úložiska (`localStorage`, `document.cookie`). Ťažiť teda nemať z JS: autentifikačné cookie iba cez `Set-Cookie` zo servera. **[overiť]**
- **Service worker cachuje HTML** s CSRF tokenom: neskôr 403 na každom POST. Pravidlo: navigačné požiadavky **network-only** (offline stránka len ako fallback), cachuje sa len `/assets/*` s verziou v názve, žiadne HTML, žiadne API.
- **`sw.js` a `manifest.webmanifest` s dlhou cache** (spec chce „dlhú cache pre statické súbory“): prehliadač nikdy nenájde nový SW, appka uviazne na starej verzii. nginx: `sw.js` a manifest `Cache-Control: no-cache`, assety `immutable` s hashom v názve. Verziu v názve zabezpečiť helperom (hash/filemtime pri nasadení), keďže niet build pipeline.
- Autentifikované stránky `Cache-Control: private, no-cache` (nie `public`), po odhlásení back-tlačidlo nesmie ukázať dáta. `no-store` vypne bfcache, zvážiť.
- **Safe area:** `viewport-fit=cover`, spodná lišta a FAB s `env(safe-area-inset-bottom)`, `100dvh` namiesto `100vh`, vstupy s `font-size ≥ 16px` (inak iOS zoomuje), pozor na klávesnicu prekrývajúcu fixné prvky, `touch-action: manipulation` proti 300 ms/dvojklik zoomu.
- **PDF a externé odkazy v standalone:** otvorenie PDF môže uviaznuť v zobrazovači bez návratu. Odkazy na PDF a portál s `target="_blank"` (otvorí Safari/náhľad), testovať na reálnom zariadení. Stiahnutie cez `Content-Disposition`.
- `apple-touch-icon` 180×180 PNG, `apple-mobile-web-app-capable`, manifest ikony, status bar štýl. Žiadne `beforeinstallprompt` (iOS ho nemá), treba textový návod „Pridať na plochu“.
- Testovať na skutočnom iPhone od F1, nie až v poslednej fáze. Emulátor nereprodukuje zabíjanie procesu ani oddelené úložisko.

**Fáza:** F1 (cookie/session), F8 (PWA), a nginx hlavičky vo F0/F10.

---

### Pasca 11: htmx + CI4 CSRF a prehliadačová história

**Čo sa pokazí:**
- CI4 `CSRF::$regenerate = true` mení token po každej požiadavke. Paralelné htmx požiadavky a tlačidlo Späť dostanú starý token a 403 (CI4 vyhodí `SecurityException`). Riešenie: `regenerate=false` (token viazaný na reláciu, `csrfProtection='session'`) **[overiť názvy konfigurácie v 4.7]**, token posielať v `hx-headers='{"X-CSRF-TOKEN": "..."}'` na `<body>` (alebo cez `htmx:configRequest` z `<meta>`), a spracovať 403 globálne.
- Hlavička CSRF vs cookie CSRF: ak sa token nachádza v cachovanom HTML (pasca 10), prestane fungovať.
- **Čiastkové odpovede a história:** rovnaká URL vracia fragment pre htmx a plnú stránku pre navigáciu. Bez `Vary: HX-Request` prehliadač/cache vráti fragment pri Späť. Pridať `Vary: HX-Request` a spracovať `HX-History-Restore-Request` (vrátiť plnú stránku). Vypnúť ukladanie citlivých stránok do htmx history cache v `localStorage` (`htmx.config.historyCacheSize=0` alebo `hx-history="false"` na citlivých obrazovkách).
- **Vypršaná relácia:** server pošle prihlasovaciu stránku (200) a htmx ju vloží do fragmentu. Pre htmx požiadavky vracať `HX-Redirect: /login` (alebo 401 a spracovať). htmx 2 predvolene neswapuje 4xx/5xx, takže chybová odpoveď sa nezobrazí. Treba `htmx:responseError` s viditeľným upozornením (bez neho používateľ nevie, že „Vystaviť“ zlyhalo).
- **CSP vs Alpine/htmx:** štandardný Alpine používa `new Function` (potrebuje `unsafe-eval`). Použiť `@alpinejs/csp` build a logiku v `Alpine.data(...)` (žiadne zložité inline výrazy). htmx: `htmx.config.includeIndicatorStyles=false`, `allowEval=false`, `hx-on:*` inline atribúty zvážiť. Inline `style="width:40%"` (ukazovateľ priebehu úlohy) CSP blokuje, použiť `<progress>`/CSS triedy. CI4 CSP nonce a cachovanie HTML sa nezlučujú (pasca 10).
- Súbeh relácie: súborový/DB handler relácií zamyká reláciu počas požiadavky, takže 2 s generovanie PDF zablokuje ostatné htmx požiadavky z toho istého prehliadača. Pri DB handleri v PostgreSQL je stĺpec `data` typu `bytea` **[overiť v dokumentácii CI4]**. Pre PDF uvoľniť zámok relácie skôr (`session_write_close()` po overení).

**Fáza:** F0 (základ šablón, CSP, CSRF), potom vzor pre každý ďalší formulár.

---

### Pasca 12: Zálohy, ktoré nikto nikdy neobnovil

**Čo sa pokazí:** cron v kontajneri nebeží alebo zlyháva ticho, dump je prázdny/neúplný, šifrovací kľúč je len na serveri (strata servera = strata záloh), PDF súbory a DB sú z rôznych okamihov, rotácia zmaže všetko, útočník s prístupom na server vymaže aj zálohy, a obnova sa prvý raz skúša pri katastrofe. Rotácia 14/8/12 nepokrýva zákonnú archiváciu dokladov (SK účtovné doklady 10 rokov, PL zvyčajne 5 rokov **[overiť u účtovníčky]**).

**Ako sa vyhnúť:**
- Nástroj so šifrovaním a retenciou v jednom (`restic` alebo `borg`; `pg_dump -Fc` do stdin), `forget --keep-daily 14 --keep-weekly 8 --keep-monthly 12` priamo zodpovedá spec. Hetzner Storage Box podporuje borg a SFTP. Prístup servera **append-only** (borg `--append-only` v `authorized_keys`), mazanie a prune z iného miesta. **[overiť podporu u Hetzner]**
- Ak sú PDF v DB (pasca 4), jedna konzistentná záloha. Ak súbory, ukladať s hashom v DB a overovať.
- **Dead man's switch** (napr. healthchecks.io): zlyhanie alebo ticho = e-mail. Zálohovať aj `.env` kľúče (`encryption.key`, kľúč záloh) do správcu hesiel mimo servera.
- **Automatizovaný test obnovy** (mesačne alebo po každej zmene schémy): obnoviť poslednú zálohu do dočasnej DB a overiť počet faktúr, posledné číslo v každom rade, súčet súm, zhodu `sha256` PDF. Zdokumentovať postup obnovy (runbook) a vyskúšať ho **pred** ostrým nasadením.
- Ročný archív (nemenný export) nad rámec 12 mesačných.

**Fáza:** F10, ale skript zálohy a test obnovy už v F0/F4 pri prvom reálnom doklade.

---

## Stredné pasce

### Pasca 13: mPDF a pamäť/výkon na malom VPS
**Čo sa pokazí:** mPDF je pamäťovo náročný (desiatky až stovky MB pri veľkých tabuľkách a Unicode fontoch), prvé generovanie po štarte generuje cache fontov (pomalé, p95 pod 2 s nesplnené), zapisuje do `tempDir` v `vendor/` (read-only súborový systém v kontajneri), a pomalé automatické prepínanie fontov (`autoScriptToLang`, `useSubstitutions`) spomaľuje.
**Prevencia:**
- `tempDir` a cache fontov na zapisovateľnom volume. Vypnúť `useSubstitutions`, `autoLangToFont`, `autoScriptToLang`. Jeden pripnutý font s pokrytím SK/PL/CZ (napr. Noto Sans/DejaVu, **[overiť pokrytie ł, ń, ě, ť]**), subset.
- Samostatný FPM pool pre PDF s vlastným `memory_limit` (256 MB), hlavný pool 128 MB. `pm = ondemand` alebo `static` s 2 až 3 workermi, `pm.max_requests` ~500 proti únikom.
- Dlhé výkazy (200 riadkov) paginovať a nepoužívať ťažké CSS (`float`, vnorené tabuľky). Test: výkaz s 300 riadkami a pamäťový peak v CI.
- mPDF/PHP 8.5: kompatibilita s novými deprecáciami **[overiť]**. CI4 môže deprecácie premeniť na výnimky podľa `Config\Exceptions` (`logDeprecations`) **[overiť]**. Spike v F0: vygenerovať vzorové PDF s diakritikou pod PHP 8.5 s prísnym `error_reporting`.
**Fáza:** F0 (spike), F4 (výkon), F10 (FPM/limits).

### Pasca 14: PostgreSQL v kontajneri na 512 MB
**Čo sa pokazí:** predvolené `shared_buffers=128MB`, `max_connections=100` a autovacuum zjedia pamäť. OOM-killer zabije postmastera, nasleduje crash recovery. Docker `mem_limit` nižší než súčet procesov zabíja kontajnery náhodne. Bez swapu pri špičke nastane pád. Pozn.: najmenšie plány Hetzner Cloud mávajú viac RAM než 512 MB **[overiť na cenníku]**, takže 512 MB je skôr rozpočet v pokoji než tvrdý limit, a nemá zmysel kvôli nemu obetovať stabilitu.
**Prevencia:** `shared_buffers=64MB`, `work_mem=4MB`, `maintenance_work_mem=32MB`, `effective_cache_size=192MB`, `max_connections=20`, `wal_buffers` auto, malý `max_wal_size`. Žiadne `pConnect`. Pridať swapfile/zram. Explicitné `mem_limit` pre každú službu a monitoring (`docker stats`, alert pri OOM). Preveriť po 30 dňoch bloat tabuliek `sessions`, `audit_log` a autovacuum.
**Fáza:** F0 (konfigurácia), F10 (overenie pod záťažou).

### Pasca 15: Import z Excelu
**Čo sa pokazí:**
- Zlé súčty v Exceli (E25/F25 počíta len riadky 19 až 22) sa **prevezmú** namiesto prepočítania.
- Odkaz do Taskmanu je **hyperlink bunky** (`getHyperlink()->getUrl()`), zobrazený text je len „TM-123“, importuje sa text a URL sa stratí.
- Vzorce: `getValue()` vráti reťazec vzorca, použiť `getCalculatedValue()`/`getOldCalculatedValue()` podľa potreby. Zlúčené bunky nesú hodnotu len v ľavej hornej. Skryté riadky a prázdne oddeľovače mesačných blokov.
- Slovenské desatinné čiarky v textových bunkách („1,5“, „27,00 €“), nezalomiteľné medzery, dátumy ako sériové čísla (epocha 1900 vs 1904), „ÁNO/NIE“ s diakritikou a rozdielnou Unicode normalizáciou (`Normalizer::normalize`, `mb_strtoupper`, trim NBSP).
- PhpSpreadsheet je pamäťovo ťažký. Spustiť ako CLI s vlastným `memory_limit`, `setReadDataOnly(true)`, `IReadFilter`, nie cez FPM.
- Opakované spustenie vyrobí duplicity. Súbor obsahuje dáta klienta a **nesmie** do gitu (`*.xlsx` je už ignorovaný).
**Prevencia:** import je **dry-run najprv**: zobrazí rozdiel „Excel súčet vs prepočítaný“ po mesiacoch (a vyznačí riadky, kde `hodiny × 27 ≠ suma`), idempotentný kľúč (hash `mesiac+url+názov+hodiny`), jedna transakcia, výsledok sa overí PHPUnit testom nad skutočným súborom (alebo anonymizovanou kópiou). Importuje sa cez tú istú doménovú logiku ako UI, nie surovými INSERT.
**Fáza:** F9 (po F3 a F6).

### Pasca 16: Export CSV/XLSX
**Čo sa pokazí:** slovenský Excel očakáva `;` a UTF-8 BOM, desatinná čiarka inak sa čísla otvoria ako text. Názov úlohy začínajúci `=`, `+`, `-`, `@` sa vykoná ako vzorec (CSV/XLSX injection). PhpSpreadsheet na export plní pamäť. Sumy v exporte vypočítané inak než na faktúre.
**Prevencia:** čísla ako číselné bunky s formátom (XLSX) alebo oddeľovač podľa jazyka (CSV), prefixovať nebezpečné texty apostrofom, zvážiť streamovací writer (napr. `openspout/openspout`) namiesto PhpSpreadsheet **[overiť]**. Export číta tie isté uložené sumy ako prehľady. Test: súčet exportu = súčet dashboardu.
**Fáza:** F7.

### Pasca 17: Doručiteľnosť e-mailu (SPF, DKIM, DMARC) a „odoslaná“, ktorá nič nehovorí
**Čo sa pokazí:** faktúra s PDF skončí v spame, lebo odosielateľ nie je autentifikovaný alebo sa „From“ nezhoduje s doménou (alignment). Každý subjekt (SK/PL) má iného odosielateľa a každá doména potrebuje vlastný SPF/DKIM. CI4 Email nepodpisuje DKIM, spolieha sa na poskytovateľa. Stav „odoslaná“ znamená len, že SMTP server správu prijal, nie doručil. Dvojklik pošle dvakrát. Diakritika v názve prílohy robí problém.
**Prevencia:** výber poskytovateľa (otvorená vec v PROJECT.md) **pred** F5, nastaviť SPF, DKIM, DMARC (`p=none` → `quarantine`) a otestovať doručenie do Gmail/Seznam/Onet/Outlook. Ukladať `Message-ID`, adresátov a odpoveď SMTP. Vždy BCC kópia vlastníkovi ako archív. Príloha s ASCII názvom (`Faktura_202610001.pdf`), multipart text + HTML, predmet s číslom faktúry, `Reply-To`. Stav „odoslaná“ až po úspešnom SMTP, zlyhanie viditeľné s tlačidlom znova, idempotenčný kľúč proti dvojkliku. Neposielať v transakcii vystavenia. Pre bounce spracovanie zvoliť poskytovateľa s webhookom/logom. **[overiť podmienky SMTP v CI4: `SMTPCrypto`, `newline`, `attach()` s bufferom]**
**Fáza:** F5, DNS už vo F0/F10.

### Pasca 18: Audit log, ktorý sa dá obísť alebo uniká tajomstvá
**Čo sa pokazí:** audit sa zapisuje v kóde kontrolérov (zabudne sa pri novej ceste), mimo transakcie zmeny, v `try/catch`, ktorý chybu zhltne, alebo obsahuje hash hesla, TOTP tajomstvo, záložné kódy. Dá sa upraviť/zmazať.
**Prevencia:** zápis v tej istej transakcii vo vrstve modelu/servisu (alebo triggerom), zoznam polí vylúčených z auditu, append-only trigger, bez `try/catch`. Zápis zlyhá, zlyhá celá zmena.
**Fáza:** F0 (mechanizmus), plný rozsah priebežne.

### Pasca 19: Vývoj na WSL2 pod `/mnt/c`
**Čo sa pokazí:** bind mount z `/mnt/c` (9P) je extrémne pomalý (Composer, cache CI4, testy), PG dátový adresár na `/mnt/c` zlyhá na právach (chmod 0700), CRLF v shell skriptoch a entrypointoch zlomí kontajner (`\r: command not found`), merania p95 sú v dev nezmyselné.
**Prevencia:** `.gitattributes` s `* text=auto eol=lf` pre `*.sh`, PG dáta a `vendor/`, `writable/` v pomenovaných volumes, `.editorconfig`. Výkon merať len na produkčnom type prostredia (Hetzner), nie vo WSL2.
**Fáza:** F0.

### Pasca 20: Vystavenie dobropisu a stav faktúr
**Čo sa pokazí:** dobropis „vymaže“ pôvodnú faktúru namiesto záporných položiek, uvoľnenie častí zmaže väzbu na pôvodnú faktúru, sumy v prehľadoch sa zdvojujú (pôvodná + opravná), plus stav „po splatnosti“ pre stornovanú faktúru.
**Prevencia:** dobropis je samostatný doklad s vlastným radom, záporné položky odkazujú na pôvodné, pôvodná zostane nemenná. Reporty počítajú čisté obraty (pôvodná + dobropis). Stornovaná faktúra nikdy nie je „po splatnosti“. Test: faktúra → storno → nová faktúra tých istých častí, súčty sedia.
**Fáza:** F4/F5.

---

## Technický dlh

| Skratka | Okamžitý prínos | Dlhodobá cena | Kedy prijateľné |
|---------|-----------------|---------------|-----------------|
| PDF generované pri každom stiahnutí z kópie bez uloženia | Menej úložiska | Zmena fontu/ICU/mPDF zmení vzhľad starých dokladov | Nikdy ako primárna cesta |
| `float` v „jednoduchých“ prehľadoch | Rýchle písanie | Rozdiely o 0,01 medzi obrazovkami | Nikdy |
| SERIAL/sekvencia pre číslo faktúry | Triviálne | Diery v číslovaní | Nikdy |
| Limity natvrdo v PHP | Rýchlo | Zmena každý rok = nasadenie | Len ako seed |
| Vlastný zoznam poľských sviatkov | Jednoduché | Zastarané, chyby kurzu | Nikdy (použiť NBP rozsah) |
| Rola `accountant` len v DB bez kontrol v UI/servise | Rýchlejšie | Neskoršie presakovanie práv | Prijateľné, ak je každá akcia chránená filtrom na serveri |
| Ruka písané verzie assetov | Bez build | Zabudnuté verzie, stará cache | Len ak existuje skript pri nasadení |
| Editácia historickej verzie subjektu na mieste | Opraví preklep | Nejasná história | Len s auditom a len nepodstatné polia |

## Integračné záludnosti

| Integrácia | Častá chyba | Správny postup |
|------------|-------------|----------------|
| NBP API | Dopyt na jeden deň, 404 pre sviatky; volanie v transakcii | Dopyt na rozsah, posledný dostupný záznam, cache v DB, timeout 3 s, ručný prepis |
| ECB | Zabudnuté delenie pri opačnom smere, dnešný kurz ešte nie je | Kurz podľa smeru, posledný dostupný pred dátumom, uložiť smer a dátum |
| ČNB | Ignorované „množstvo“ | Ukladať množstvo, kurz za 1 jednotku |
| SMTP | Stav „odoslaná“ bez overenia, nepodpísaný odosielateľ | SPF/DKIM/DMARC, uložiť odpoveď servera, BCC archív, idempotencia |
| Hetzner Storage Box | Zálohy z rovnakého účtu, ktorý vie mazať | Append-only kľúč, prune z iného miesta |
| Let's Encrypt | Obnova certifikátu zlyhá ticho | Monitoring expirácie, certbot/Caddy hook, alert |
| Taskman odkazy | URL šablóna sa zmení a zmení staré výkazy | URL v kópii položky |

## Pasce výkonu

| Pasca | Príznaky | Prevencia | Kedy sa pokazí |
|-------|----------|-----------|----------------|
| N+1 dotazy v zozname úloh s odvodeným stavom | Zoznam > 100 ms | Jeden agregačný dotaz so `SUM` po častiach, indexy `(client_id, billing_month)` | ~500 úloh a viac |
| mPDF prvé spustenie bez cache fontov | PDF > 2 s po nasadení | Predhriať cache pri štarte | Každé nasadenie |
| FPM s predvolenými workermi | Swap, OOM | `pm` 2 až 3 workery, limity | Pri 2 súbežných PDF |
| Rastúca tabuľka `audit_log` a `sessions` | Spomalenie, bloat | Index podľa entity/času, čistenie relácií | Roky prevádzky |
| `json_decode` celého JSONB pri výpise zoznamu | Pomalé zoznamy | Do zoznamov čítať denormalizované stĺpce (číslo, suma, stav), JSONB len v detaile | Stovky faktúr |

## Bezpečnostné chyby

| Chyba | Riziko | Prevencia |
|-------|--------|-----------|
| Zámok účtu po N pokusoch | Útočník zamkne vlastníka | Rastúce oneskorenie, upozornenie e-mailom |
| Reset hesla obíde TOTP | 2FA bez hodnoty | Po resete vyžadovať TOTP/záložný kód |
| QR kód cez externú službu | Únik tajomstva | Generovať lokálne |
| Tajomstvá v logoch (CI4 debug toolbar, `.env` v dumpe chýb) | Únik | `CI_ENVIRONMENT=production`, vypnutý toolbar a zobrazovanie chýb |
| Rola `accountant` číta aj nastavenia a tajomstvá | Eskalácia | Filtre na serveri pre každú routu, test matice práv |
| Stiahnutie PDF cez predvídateľné URL `/invoice/123/pdf` bez kontroly | Únik dokladov | Autorizácia na každej ceste, žiadne verejné odkazy |
| Hlavičky CSP s `unsafe-inline`/`unsafe-eval` kvôli Alpine | Slabá CSP | `@alpinejs/csp` build, nonce |
| Otvorené presmerovanie po prihlásení (`?next=`) | Phishing | Povoliť len relatívne cesty |

## UX pasce

| Pasca | Dopad | Lepší postup |
|-------|-------|--------------|
| Mazanie nezamknutej časti bez potvrdenia na mobile | Strata dát jedným ťuknutím | Undo/potvrdenie panelom zospodu |
| Zobrazenie sumy bez menu a bez označenia (nacenená vs fakturovaná) | Zámena čísel | Vždy mena, popisok, rozdiel „+6 h“ |
| Chyby htmx odpovedí bez viditeľnej správy | Používateľ nevie, že vystavenie zlyhalo | Globálny handler `htmx:responseError` s toastom |
| Dotykové ciele < 44 px, tlačidlá pri hrane displeja | Nepresné ťuknutia | Safe-area, min. 44 px |
| Predvyplnený dátum/subjekt bez zdôraznenia pri prelome SK → PL | Faktúra z nesprávneho subjektu | Zvýrazniť subjekt, mesiac a číslo v náhľade, potvrdenie pri zmene |
| Zobrazenie čísla faktúry v náhľade pred pridelením | Číslo sa po vystavení líši | V náhľade „bude pridelené pri vystavení“ |

## „Vyzerá hotovo, ale nie je“ (kontrolný zoznam)

- [ ] **Vystavenie:** chýba trigger/oprávnenie proti UPDATE, overiť pokusom o priamy `UPDATE invoices SET ...` pod aplikačnou rolou.
- [ ] **PDF:** po zmene klienta, sadzby, názvu úlohy a lang súboru sú stiahnuté bajty identické.
- [ ] **Číslovanie:** test s dvoma spojeniami, rollback po zlyhaní PDF nevytvorí dieru, prvá faktúra mesiaca je súbehovo bezpečná.
- [ ] **Súčty:** obrazovka = výkaz = faktúra = CSV = XLSX = dashboard pre náhodné vstupy (property test).
- [ ] **Kurz:** vystavenie v pondelok po víkende, 2. 11., 1. 1., NBP nedostupné (ručné zadanie), uložený zdroj a dátum.
- [ ] **Hranice času:** vystavenie o 00:30 miestneho času 1. dňa mesiaca, 31. 12., prestupný február, DST.
- [ ] **2FA:** pending stav neprístupný, replay kódu, záložný kód dvakrát, reset hesla, odvolanie zariadení.
- [ ] **iPhone PWA:** po 7 a 30 dňoch bez zmeny nadviazané prihlásenie, po „zabití“ appky, po prepnutí Wi-Fi/LTE; PDF sa dá zavrieť; spodná lišta nie je pod home indikátorom.
- [ ] **Service worker:** nová verzia sa nasadí do pár minút, HTML nie je v cache, `sw.js` nie je cachovaný.
- [ ] **htmx:** Späť/Vpred po fragmente, vypršaná relácia, 403 CSRF s paralelnými požiadavkami.
- [ ] **E-mail:** príjem v Gmaili a u klienta, hlavičky SPF/DKIM/DMARC pass, dvojklik neposiela dvakrát.
- [ ] **Limity:** hraničné dni, prvý rok PL pomerne, dobropis v inom roku, vylúčený klient, počiatočný obrat, chýbajúci riadok limitu pre rok.
- [ ] **Zálohy:** obnova do čistého prostredia dokončená a zdokumentovaná, kľúč mimo servera, alert pri tichu.
- [ ] **Import:** dry-run, rozdiel proti Excel súčtom, hyperlinky, idempotentné opakovanie.
- [ ] **Audit:** zmena cez UI aj cez servis zapíše záznam, bez tajomstiev, nedá sa upraviť.

## Stratégie obnovy

| Pasca | Cena obnovy | Postup |
|-------|-------------|--------|
| Diera v číslovaní | VYSOKÁ | Zdokumentovať dôvod (nepoužité číslo), konzultovať účtovníčku, nikdy nepreužívať číslo; opraviť príčinu |
| Zmenený starý doklad (únik nemennosti) | VYSOKÁ | Obnoviť uložené PDF a kópiu zo zálohy, porovnať hashe, opraviť zdroj úniku |
| Nesprávny kurz na vystavenej faktúre | STREDNÁ | Dobropis + nová faktúra s opraveným kurzom |
| Nesprávny limit/obrat | NÍZKA | Prepočet je odvodený z faktúr, oprava nastavení a stratégie |
| Zamknutie 2FA | STREDNÁ | Záložný kód, inak CLI príkaz na reset 2FA s fyzickým prístupom na server (zdokumentovať vopred) |
| Chýbajúce zálohy | VYSOKÁ | Žiadna, preto test obnovy; export faktúr a PDF z DB ako núdzový archív |
| Zlý import | NÍZKA | Transakcia + idempotentný kľúč, rollback a opakovanie |
| Spam/nedoručený e-mail | NÍZKA | Znova odoslať po oprave DNS, upozorniť klienta, použiť BCC archív |

## Mapa pascí na fázy

| Pasca | Fáza prevencie | Overenie |
|-------|----------------|----------|
| 1 Peniaze a float | F0, F3, F4 | Property test súčtov, lint proti `float` |
| 2 Zaokrúhlenie kurzu | F6 | Testy troch smerov a zdrojov |
| 3 Kurz a sviatky | F6 | Testy dátumov (víkend, sviatky, 1. 1.) |
| 4 Nemennosť/PDF | F4, F5 | Test „zmeň všetko po vystavení“, trigger test |
| 5 Číslovanie | F4 | Test dvoch spojení, rollback bez diery |
| 6 Staré náhľady/súbeh | F3, F4 | Test s dvomi tabmi, `preview_token` |
| 7 Dátumy a verzie | F0, F2, F4 | `Clock` testy, hraničné dni |
| 8 Limity | F7 (otázky pred ňou) | Vzorové scenáre potvrdené účtovníčkou |
| 9 TOTP | F1 | Bezpečnostný checklist, testy bypassu |
| 10 iOS PWA | F1, F8 | Test na skutočnom iPhone, nginx hlavičky |
| 11 htmx a CSRF | F0 a každá formulárová fáza | E2E scenáre Späť/relácia/CSRF |
| 12 Zálohy | F10 (skript od F4) | Automatizovaný test obnovy |
| 13 mPDF | F0 spike, F4, F10 | Peak pamäte a čas v CI |
| 14 PostgreSQL pamäť | F0, F10 | Záťažový test, `docker stats` |
| 15 Import | F9 | Dry-run, rozdiel proti Excelu |
| 16 Exporty | F7 | Zhoda súčtov, injection test |
| 17 E-mail | F5 | Test doručenia, hlavičky |
| 18 Audit | F0 | Test zápisu a nemennosti |
| 19 WSL2 | F0 | Čistý clone → `docker compose up` funguje |
| 20 Dobropis | F4, F5 | Cyklus faktúra → storno → nová faktúra |

## Otvorené otázky pre účtovníčku (pred F4 a F7)

1. SK: limit 50 000 € počítaný za kalendárny rok alebo za kĺzavých 12 mesiacov? Čo presne sa do obratu počíta? (spec vs moje poznatky, rozpor)
2. PL: dátum vzniku povinnosti (dodanie, vystavenie, záloha) pre započítanie do 240 000 zł, pomerný limit v prvom roku (dni vrátane dňa začatia, základ 365).
3. Cezhraničná fakturácia PL → SK B2B: zákonná poznámka (reverse charge vs art. 113), VAT-UE, započítanie do limitu (riziko R1).
4. Má byť poradie čísel chronologické vs dátum vystavenia? Smie sa spätne dátumovať?
5. Dátum, od ktorého sa hľadá kurz NBP (dodanie vs vystavenie) a či sa prepočítava súčet alebo riadky.
6. Doba uchovávania dokladov a či zálohy 14/8/12 stačia alebo je potrebný ročný archív.

## Zdroje

- `.planning/PROJECT.md`, `docs/superpowers/specs/2026-10-23-task-tracker-design.md` (platí pri rozpore), `docs/PRD.md` (nečítané v tejto relácii, len cez odvodené PROJECT.md a spec).
- Znalosť domény a známe vzorce zlyhaní (PostgreSQL sekvencie a zámky, ON CONFLICT DO UPDATE ... RETURNING, htmx história a `Vary`, Alpine CSP build, iOS PWA správanie, mPDF pamäť, ECB/NBP/ČNB konvencie). **Nepotvrdené živými zdrojmi v tejto relácii**, istota MEDIUM až LOW pri položkách označených **[overiť]**.
- Odporúčané overenie pred implementáciou (v poradí dôležitosti): dokumentácia NBP Web API; Shield (Actions, remember-me, pending stav); CI4 4.7 (`CSRF`, `Session`, `transException`, deprecácie PHP 8.5); mPDF kompatibilita s PHP 8.5; právne texty SK/PL k limitom (účtovníčka).

---
*Pitfalls research pre: samohostovaná fakturácia a evidencia úloh (SK/PL/CZ)*
*Preskúmané: 2026-10-23*
