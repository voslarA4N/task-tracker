# Phase 8: Meny a kurzy - Research

**Researched:** 2026-10-30
**Domain:** kurzy mien (NBP / ECB / CNB), bcmath prepočet, snapshot faktúry, CI4 HTTP klient, PG migrácie
**Confidence:** HIGH (API správanie overené živými volaniami 2026-10-30, integračné body overené v kóde)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
(skrátene, plné znenie je v `08-CONTEXT.md` D-01..D-15; planner ich MUSÍ dodržať)
- **D-01** Zoznam mien dátový: tabuľka `currencies` (`code char(3) PK`, desatinné miesta, `active`, poradie), seed EUR/PLN/CZK. 5 pevných `CHECK (currency IN (...))` (`entities`, `clients`, `entity_bank_accounts`, `billing_allocations`, `invoices`) sa novou migráciou nahradí FK na `currencies(code)`. PHP enum `Currency` sa nahradí čítaním z tabuľky.
- **D-02** Jedna faktúra = jedna mena (mena častí). `partsCurrency()` ostáva.
- **D-03** `assertCurrency`/`currency_mismatch` sa nahrádza požiadavkou na kurz. Rovnaká mena: `fx = null`, `total_entity_ccy = total`.
- **D-04** Bankový účet podľa meny faktúry (`BankAccountSelector`), `no_bank_account` ostáva.
- **D-05** Nastavenie v právnom profile krajiny: zdroj (`NBP|ECB|CNB`), základ dátumu (`delivery_date|issue_date`), pravidlo dňa (`previous_business_day` | `on_or_before`). Defaulty: PL NBP/delivery/previous_business_day, SK ECB/delivery/previous_business_day, CZ ČNB/delivery/on_or_before. Všetko konfigurácia.
- **D-06** Žiadny vlastný kalendár sviatkov, autoritou je API (NBP rozsah D-10..D-1 posledný prvok; ECB SDMX csvdata; ČNB `exrates/daily?date=`, `rate/amount`).
- **D-07** Kurz sa sťahuje v kroku náhľadu, nikdy v transakcii vystavenia. Timeout cca 3 s. Cache v DB (unikát zdroj + mena + deň). Faktúra sa nespolieha na cache, len na vlastnú kópiu.
- **D-08** Zmena dátumu dodania/vystavenia kurz znova vyhľadá; `previewToken` zahŕňa kurz.
- **D-09** Kurz sa ukladá tak, ako ho zdroj publikuje, `fx_rate numeric(18,8)` + smer. Nikdy prevrátený.
- **D-10** Prepočet súčtu (nie riadkov) jedným kurzom, bcmath, half-up na 2 des. raz. NBP/ČNB násobia (`total * rate / amount`), ECB pri mene subjektu EUR delí. Jediná funkcia s testami pre EUR→PLN, PLN→EUR, CZK→EUR, EUR→CZK, PLN→CZK.
- **D-11** Ručný prepis bez dôvodu; `fx_source='manual'`; uloží sa aj pôvodne stiahnutý kurz; audit_log záznam.
- **D-12** API zlyhá: upozornenie + ručné pole, vystavenie bez kurzu nejde (D-03), chyba do logu.
- **D-13** Ručný kurz v smere zdroja profilu s popisom („1 EUR = ___ PLN“), server validácia: kladné, max 8 des. miest.
- **D-14** Pri vystavení sa plnia stĺpce `fx_rate, fx_source, fx_date, fx_table_no, fx_date_basis, total_entity_ccy` + snapshot `fx` (zdroj, kurz, amount, smer, deň platnosti, tabuľka, základ, pravidlo, mena subjektu, prepočítaný súčet, príznak manual, pôvodný kurz, predformátovaný text pre PDF). `snapshot_schema` sa zvýši.
- **D-15** PDF: riadok pod súčtom (Kurz / z dátumu, tab. / Spolu v PLN), popisky v SK/PL/CS/EN v LABEL_KEYS, formát cez `MoneyFormat`/`PdfFormat`.

### Claude's Discretion
- Názvy a štruktúra tabuliek (`currencies`, cache kurzov, nastavenie podľa krajiny), UI nastavenia v `Settings/LegalTexts` s auditom.
- Rozhranie `RateProvider` (NBP, ECB, ČNB), CURLRequest s timeoutom, testy na fixtúrach (žiadne živé volania v PHPUnit).
- UX v náhľade (mobil, 44 px, htmx stav načítania, tlačidlo „Zadať ručne“).
- Druhá suma na detaile a v zozname faktúr z `total_entity_ccy`.
- Rozšírenie `StagingSeed` o faktúru v cudzej mene.
- Sťahovanie len na požiadanie (default) alebo aj predbežne.

### Deferred Ideas (OUT OF SCOPE)
- „Uhradené v mene subjektu“, kurzové rozdiely pri úhradách (Phase 11 / mimo V1).
- Prehľady a limity z `total_entity_ccy` (CUR-04, Phase 10/11).
- Dobropis v cudzej mene so zdedeným kurzom (Phase 9).
- UI na pridávanie mien (V1: migrácia/seed).
- KSeF `KursWalutyZ`.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| CUR-01 | Podporované meny EUR/PLN/CZK, rozšíriteľné bez zmeny modelu | tabuľka `currencies` + FK migrácia (sekcia Migrácia), náhrada `Currency` enum na 9 miestach |
| CUR-02 | Kurz v náhľade automaticky (PL NBP, SK ECB, CZ ČNB), ručný prepis, uložený v kópii + stĺpcoch | overené API správanie, `RateProvider`, `FxService`, `FxConverter`, snapshot `fx`, issuer |
| CUR-03 | Nedostupné API nezablokuje vystavenie, ručné zadanie | `RateFetchFailed`, manuálny režim v POST, `fx_missing` odmietnutie len keď kurz nie je ani ručný |
| CUR-05 | Dátum kurzu a pravidlo v právnom profile krajiny | tabuľka `fx_country_settings`, `FxSettingsService`, UI + audit |
</phase_requirements>

## Summary

Fáza je väčšinou integračná: všetky potrebné háčiky už existujú. `invoices` má stĺpce `fx_*` aj `total_entity_ccy` (dnes `total_entity_ccy = total`, insert v `InvoiceIssuer::insertInvoice`), `SnapshotBuilder` má `'fx' => null`, šablóna `invoice_inv2.php` už vypisuje `fx.text`, `previewToken` hashuje celý snapshot okrem `number/variable_symbol/payment`, takže kurz v `fx` je v tokene automaticky. Novo treba: tabuľku `currencies` + FK migráciu, tabuľku nastavení kurzu podľa krajiny, cache kurzov, `RateProvider` (3 implementácie) so zameniteľným HTTP klientom, `FxConverter` (bcmath), napojenie do `InvoiceDraftService`/`InvoiceIssuer`/`SnapshotBuilder`, ručný vstup v `_preview.php`, rozšírenie PDF a zobrazenie druhej sumy.

Kľúčový návrh (odporúčanie, v rozsahu Claude's Discretion): pri vystavení issuer **nikdy nevolá sieť**. Pre režim `auto` si issuer deterministicky prepočíta "bound date" z dátumov a nastavenia krajiny a prečíta riadok z cache tabuľky (ktorú naplnil náhľad). Pre režim `manual` ide kurz a dátum z POST (validované). Token náhľadu viaže výsledok. Tým sa vyhneme (a) sieti v transakcii, (b) tomu, aby klient mohol sfalšovať "oficiálny" kurz (server berie auto kurz len z vlastnej cache).

**Primary recommendation:** Jeden `FxService` (sieť + cache, volaný len z náhľadu) a jeden čistý `FxConverter::convert()`; issuer číta iba cache (`FxService::cached()`), nikdy provider. Ručný kurz = POST polia `fx_mode=manual`, `fx_rate`, `fx_date`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Zoznam mien | Database | API/Backend | FK na `currencies`, PHP len číta (`CurrencyRepository`) |
| Sťahovanie kurzu | API/Backend (preview request) | — | sieť mimo transakcie, timeout 3 s |
| Cache kurzov | Database | — | `exchange_rate_cache`, pomôcka, nie zdroj pravdy |
| Prepočet sumy | API/Backend (`FxConverter`) | — | jediné miesto, bcmath |
| Ručný prepis/UI stav | Frontend Server (HTML + htmx) | API/Backend validácia | server overuje formát a kladnosť |
| Nemennosť kurzu | Database + snapshot | — | `invoices.snapshot.fx` + stĺpce, insert-only |
| Text na PDF | API/Backend (SnapshotBuilder predformátuje) | PDF šablóna | šablóna nič nepočíta ani nepreklada |
| Nastavenie kurzu podľa krajiny | Database + Settings controller | — | editovateľné, audit |

## Standard Stack

Žiadne nové externé balíky. Všetko už v projekte. [VERIFIED: composer.json, CLAUDE.md]

| Komponent | Verzia | Použitie |
|-----------|--------|----------|
| CI4 `CURLRequest` (`service('curlrequest')`) | 4.7.5 (framework) | HTTP klient, `timeout`, `connect_timeout`, `http_errors=false` |
| PHP bcmath (`bcmul`, `bcdiv`, `bcround` + `RoundingMode`) | PHP 8.5 (v kontajneri) | prepočet; `Money` už používa `bcround(..., RoundingMode::HalfAwayFromZero)` |
| PHPUnit | 11.5 (composer.json) | testy; bežia v kontajneri `task-tracker-php-1` (host má PHP 8.3.6, `bcround` tam nie je) |
| PostgreSQL | 18 | `numeric(18,8)`, FK, unikátne kľúče |

**Package Legitimacy Audit:** fáza neinštaluje žiadne externé balíky, audit netreba. Žiadne `npm`/`composer require`.

**Poznámka k prostrediu:** testy a `php` s `bcround` spúšťať cez `docker exec task-tracker-php-1 ...` (host PHP 8.3 nemá `bcround`/`RoundingMode`). [VERIFIED: `php -v` na hoste 8.3.6; `Money.php` používa `bcround`]

## Overené správanie API (živé volania 2026-10-30)

| Zdroj | URL | Overené správanie |
|-------|-----|-------------------|
| NBP rozsah | `https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-20/2026-10-29/?format=json` | 200, `{"table":"A","code":"EUR","rates":[{"no":"209/A/NBP/2026","effectiveDate":"2026-10-27","mid":4.4056},...]}`, vynechaný víkend (23. -> 26.). `no` = číslo tabuľky, `mid` = PLN za 1 jednotku. [VERIFIED: live] |
| NBP bez dát | rozsah len cez víkend (2026-11-07..08) | **400** `Invalid date range` (nie 404!). Jednodňový dotaz na nepracovný deň: 404 `Brak danych`. [VERIFIED: live] |
| NBP budúci dátum | `.../2026-11-30/` | 400 `Błędny zakres dat / Invalid date range` [VERIFIED: live] |
| NBP neznáma mena | `/rates/a/xxx/...` | 404 [VERIFIED: live] |
| ČNB | `https://api.cnb.cz/cnbapi/exrates/daily?date=2026-10-29&lang=EN` | 200, `{"rates":[{"validFor":"2026-10-29","order":208,"currencyCode":"EUR","amount":1,"rate":24.335},...]}` [VERIFIED: live] |
| ČNB víkend / budúcnosť | `date=2026-10-31` (sobota), `date=2026-12-31` | 200, vráti **posledný publikovaný** (`validFor 2026-10-29`). ČNB teda samo implementuje "on or before". [VERIFIED: live] |
| ČNB zlý formát | `date=31.10.2026` | 400 `VALIDATION_ERROR` (musí byť `YYYY-MM-DD`) [VERIFIED: live] |
| ECB SDMX | `https://data-api.ecb.europa.eu/service/data/EXR/D.PLN.EUR.SP00.A?startPeriod=2026-10-28&endPeriod=2026-10-30&format=csvdata` | 200 CSV, hlavička `KEY,FREQ,CURRENCY,...,TIME_PERIOD,OBS_VALUE`, riadky vzostupne podľa dátumu. Stĺpce podľa **názvu hlavičky** (`TIME_PERIOD`, `OBS_VALUE`, `CURRENCY`), nie podľa pozície (pozícia 3/7/8 bola len test). 30. 10. ráno ešte nebol publikovaný (posledný 29. 10.). [VERIFIED: live] |
| ECB bez dát | rozsah 2026-11-07..08 | **200 s prázdnym telom** (nie chyba). Prázdne telo = žiadny kurz v rozsahu. [VERIFIED: live] |

Dôsledky pre kód:
- NBP: 400 aj 404 pri rozsahu sú **"žiadny kurz"** (nie "API padlo"). Pri rozsahu D-10..D-1 sa nemá stať (10 dní vždy obsahuje pracovný deň), ale kód to musí zachytiť ako `RateNotFound` -> ručné zadanie.
- ECB: prázdne telo/jediný riadok hlavičky = `RateNotFound`. Rozsah D-10..bound, vziať **posledný** riadok.
- ČNB: žiadny rozsah netreba, `date=bound` vráti posledný publikovaný (overiť, že `validFor <= bound`; ak `validFor > bound`, odmietnuť). Vybrať prvok podľa `currencyCode`; kurz = `rate / amount`.
- NBP limit rozsahu 93 dní [CITED: CLAUDE.md, nepotvrdené oficiálnym dokumentom], v kóde 10 dní.
- Časy publikácie: ECB okolo 16:00 CET (referenčný kurz "2.15 pm CET" v metadátach), ČNB po 14:30, NBP v pracovné dni dopoludnia. [VERIFIED: ECB metadata v CSV; ostatné CITED: PITFALLS Pasca 3, LOW pre presné časy]. Preto `bound = D-1` (default) je takmer vždy finálny; `on_or_before` s `D = dnes` môže byť dopoludnia nefinálny (pozri cache).

## Architecture Patterns

### Tok údajov

```
GET /invoicing/preview (htmx)
  -> InvoiceDraftService::draft(..., FxInput)
       -> FxSettingsService::forCountry(entity.country)   [source, basis, rule]
       -> if invoice.currency == entity.currency: fx=null
       -> else FxService::resolve(settings, entityCcy, invoiceCcy, anchorDate)
              bound = rule==previous_business_day ? anchor-1 : anchor
              cache hit (final)?  -> RateQuote
              else RateProvider(source)::latestOnOrBefore(ccy, bound)  [CURLRequest 3 s]
                   -> upsert exchange_rate_cache ; log+RateUnavailable on failure
       -> FxInput(manual?) overrides -> RateQuote(source='manual', ...)
       -> SnapshotBuilder::build(..., ?FxQuote)  -> snapshot.fx (+ FxConverter total_entity)
       -> InvoiceCalculator::previewToken(snapshot)   (kurz je v tokene)
  -> _preview.php: kurz, zdroj, prepočítaná suma, "Zadať ručne", chybový stav

POST /invoicing/issue
  -> IssueInput (+ fx_mode, fx_rate, fx_date)  -> IssueCommand (+ FxOverride)
  -> InvoiceIssuer (v transakcii, BEZ siete):
       manual -> FxQuote z POST (validovaný)
       auto   -> FxService::cached(...)  (iba SELECT z cache)  ; chýba -> IssueRejected('fx_missing')
       SnapshotBuilder::build(...) -> token zhoda inak 'preview_stale'
       INSERT invoices (fx_*, total_entity_ccy) ; audit 'issue' (+ fx, manual príznak)
```

### Odporúčaná štruktúra
```
app/Libraries/Fx/
  RateProvider.php            # interface: latestOnOrBefore(string $currency, string $bound): RateQuote
  NbpRateProvider.php  EcbRateProvider.php  CnbRateProvider.php
  RateHttpClient.php          # interface get(string $url): HttpResult{status, body}; CurlRateHttpClient + FakeRateHttpClient (tests)
  RateQuote.php               # readonly VO: source, currency, rate(string), amount(int), validFor, tableNo, direction
  FxConverter.php             # čistá bcmath funkcia
  FxService.php               # resolve() (sieť+cache), cached() (iba DB)
  FxSettingsService.php       # CRUD nastavenia + audit
  FxQuote.php / FxSnapshot.php# zostavenie `fx` poľa snapshotu vrátane predformátovaného textu
  RateUnavailable.php  RateNotFound.php
app/Libraries/Master/CurrencyRepository.php  # nahrádza enum Currency
app/Controllers/Settings/FxSettings.php      # alebo sekcia v LegalTexts
```

### Pattern 1: HTTP klient oddelený od provideru (testovateľnosť)
**What:** `RateHttpClient` je malé rozhranie vracajúce `status` + `body`. Produkčná implementácia obalí `service('curlrequest', ['timeout'=>3,'connect_timeout'=>2,'http_errors'=>false])`; testy používajú `FakeRateHttpClient` s mapou URL -> (status, telo z fixtúry). `CURLRequest` hádže pri sieťovej chybe `CodeIgniter\HTTP\Exceptions\HTTPException`; zachytiť `Throwable` a premeniť na `RateUnavailable`. Dôvod: so zapnutým `http_errors` (default `true`, `CURLOPT_FAILONERROR`) by 400 z NBP vyhodil výnimku namiesto štatusu, preto `false`, aby sa dalo rozlíšiť "žiadny kurz" (400/404) od "API padlo" (5xx, timeout). [VERIFIED: vendor CURLRequest.php:824 `http_errors` -> CURLOPT_FAILONERROR; CITED: CI4 docs `MockCURLRequest::setOutput`]
**Fixtúry**: uložiť skutočné odpovede (NBP rozsah, ČNB daily, ECB csv, ECB prázdne telo, NBP 400) do `tests/_support/fixtures/fx/`. Test aj kontroluje presnú volanú URL (parametre `D-10`..bound).

### Pattern 2: Jednotná "bound date" sémantika
`anchor` = `delivery_date` alebo `issue_date` (podľa `date_basis`). `bound = anchor - 1 deň` pre `previous_business_day`, `bound = anchor` pre `on_or_before`. Všetky tri providery vracajú "posledný kurz s `validFor <= bound`" a vrátia skutočný `validFor`. Tým sa dve pravidlá z D-05 zredukujú na jeden parameter a cache kľúč.
- NBP: rozsah `[bound-10, bound]`, posledný prvok `rates`.
- ECB: `startPeriod=bound-10`, `endPeriod=bound`, posledný riadok CSV.
- ČNB: `date=bound`, kontrola `validFor <= bound`.

### Pattern 3: Smer a prepočet
`RateQuote.direction`: `'multiply'` (NBP, ČNB: entita = faktúra × rate / amount) alebo `'divide'` (ECB: rate = cudzia mena za 1 EUR, entita EUR = faktúra / rate). Pár (mena subjektu, mena faktúry) musí sedieť na zdroj:
| Zdroj | Mena subjektu | Mena faktúry | Smer | Poznámka |
|-------|---------------|--------------|------|----------|
| NBP | PLN | EUR, CZK (tabuľka A) | multiply | `mid` = PLN za 1 jednotku |
| ČNB | CZK | EUR, PLN | multiply | `rate/amount`, HUF 100 |
| ECB | EUR | PLN, CZK | divide | `OBS_VALUE` = ccy za 1 EUR |
Iná kombinácia (napr. SK subjekt s mimo-EUR menou, alebo mena nie je v zdroji) = `RateNotFound`/`unsupported_pair` -> ručný kurz. Implementovať ako mapu `source => home currency` v kóde (zdroj je nemenný dátový fakt o API), nie ako ďalšie nastavenie.

### Pattern 4: Snapshot `fx` (D-14), navrhnutý tvar
```php
'fx' => [
  'source' => 'NBP',            // NBP|ECB|CNB|manual
  'manual' => false,
  'rate' => '4.40900000',       // presne ako zdroj, string; uložiť normalizovane (max 8 des.)
  'amount' => 1,                // ČNB môže byť 100
  'direction' => 'multiply',    // multiply|divide
  'valid_for' => '2026-10-28',  // effectiveDate / validFor / TIME_PERIOD, pri manual: dátum zadaný/predvolený
  'table_no' => '210/A/NBP/2026',   // ČNB: order (napr. '208'), ECB: null, manual: null
  'date_basis' => 'delivery_date',
  'day_rule' => 'previous_business_day',
  'anchor_date' => '2026-10-29',
  'entity_currency' => 'PLN',
  'invoice_currency' => 'EUR',
  'total' => '4409.00',         // total_entity_ccy
  'fetched' => null | [...],    // pri manual: pôvodný stiahnutý {source, rate, amount, valid_for, table_no}
  'text' => ['rate_line' => '...', 'date_line' => '...', 'total_line' => '...'] // predformátované v jazyku dokladu
]
```
Pozn.: `fx.text` v šablóne je dnes `string` (`esc((string)($s['fx']['text'] ?? ''))`); šablónu treba rozšíriť na 3 riadky a pravý stĺpec s `total_line` (D-15). Hodnoty sa neformátujú v šablóne: `SnapshotBuilder` ich predformátuje cez `MoneyFormat::format($total, $ccy, $docLang)` a `PdfFormat::date/amount` (ak `PdfFormat::amount` je zámerný PDF formátovač, použiť ho konzistentne s ostatnými sumami na PDF, aby `zł`/`€` a NBSP boli rovnaké). Kurz sa zobrazuje s natívnymi desatinami zdroja (NBP 4, ČNB 3), nie so 8 ("4,2512", nie "4,25120000"): `rtrim` nul za desatinnou čiarkou, min. počet des. = počet publikovaných (nie je v `numeric(18,8)`, preto uložiť do snapshotu aj `rate_display`), alebo jednoduchšie `rtrim(rtrim($rate,'0'),'.')` s min. 2 des.
Popisky (`LABEL_KEYS`): pridať `fxRate`, `fxFrom`, `fxTable`, `fxTotalIn`, `fxManual`, `fxManualFrom` do `SnapshotBuilder::LABEL_KEYS` a do `app/Language/{sk,pl,cs,en}/InvoicePdf.php`. **Pozor:** `labels` sa počíta pre každú faktúru z jazykového súboru; staré faktúry majú svoje uložené labels, nič sa nemení. Pridanie kľúča mení token náhľadu len raz pri nasadení (nezávadné). `lang()` volaný s `$docLanguage`: existuje test na úplnosť prekladov (tests/unit/I18n), doplniť všetky 4 jazyky.

### Anti-Patterns
- **Volať provider v `InvoiceIssuer`** (sieť pod zámkom čítača). Issuer číta len cache alebo POST ručný kurz.
- **Počítať prepočet po riadkoch.** Len súčet jedným kurzom (D-10).
- **`(float)`** kdekoľvek; JSON z NBP/ČNB obsahuje čísla `4.4056`, `15.359`. `json_decode` ich dá ako float! Parsovať s `JSON_BIGINT_AS_STRING` nepomôže pre desatiny. **Riešenie:** použiť regex na surové telo, alebo `json_decode` s float a vrátiť späť cez `number_format`... nebezpečné. Odporúčané: pred `json_decode` zabaliť desatinné čísla pre kľúče `mid`/`rate` do reťazca regexom (`preg_replace('/"(mid|rate)":\s*([0-9.]+)/', '"$1":"$2"', $body)`) a až potom dekódovať, takže kurz nikdy nie je PHP float. ECB CSV sa číta ako string stĺpec (`str_getcsv`). Test: kurz `4.4090` musí ostať `4.4090`, nie `4.409`. (NBP posiela `4.4090`; po float-roundtripe by sa stratili koncové nuly, čo je pre výpočet nezávadné, no vyhnúť sa floatu je projektové pravidlo a CLAUDE.md ho zakazuje.)
- **Ukladať prevrátený kurz** (D-09).
- **Hardkódovať sviatky.**
- **Pridať `NOW()`-závislú logiku do `previewToken`** (kurz sa nesmie meniť medzi náhľadom a vystavením inak než zmenou cache riadku; viď cache nižšie).

## Prepočet a zaokrúhlenie (FxConverter)

```php
// Source: vzor z app/Libraries/Platform/Money.php (bcround + RoundingMode::HalfAwayFromZero)
final class FxConverter
{
    /** @param numeric-string $total 2 des. (môže byť záporné, dobropis) */
    public static function convert(string $total, string $rate, int $amount, string $direction): string
    {
        // validácia: ^-?\d{1,10}(\.\d{1,2})?$ ; rate ^\d{1,10}(\.\d{1,8})?$ a > 0; amount >= 1
        $exact = $direction === 'multiply'
            ? bcdiv(bcmul($total, $rate, 10), (string) $amount, 12)   // 2+8 des. násobenie je presné
            : bcdiv(bcmul($total, (string) $amount, 2), $rate, 12);   // ECB: total / rate (amount=1)
        return bcround($exact, 2, RoundingMode::HalfAwayFromZero);
    }
}
```
- Násobenie `total(2) × rate(8)` má presne 10 des. miest, `bcmul(..., 10)` je bezstratové. Delenie `amount`/`rate` skracuje (truncate) na 12 des.; chybný výsledok by mohol vzniknúť len ak by skutočný podiel ležal do 1e-12 pod hranicou ...5, čo pri 4-desatinných kurzoch a sumách do 12 cifier nenastáva v praxi. Zdokumentovať a mať test s hraničnými hodnotami (`x.xx5` presne).
- Záporné súčty (Phase 9 dobropis): `HalfAwayFromZero` je symetrické; rovnaká funkcia sa použije.
- Smer `divide`: pri ECB deliť kurzom `OBS_VALUE`, nikdy neukladať `1/rate`.
- Testovacie vektory (overiť v teste ručne vypočítané): 1000.00 EUR × 4.4090 = 4409.00 PLN; 4409.00 PLN -> EUR cez ECB `divide` s 4.3938: 1003.46...; CZK -> EUR (ČNB EUR 24.335 multiply: EUR faktúra pre CZ subjekt = 1000 × 24.335 = 24335.00 CZK; opačne CZK faktúra pre SK subjekt cez ECB divide 24.369); hraničné `0.005`, `x.xx5` presne hore, `x.xx4999...` dole, HUF-ako `amount=100`, súčet 0.00, veľký súčet 9999999999.99 × 99.99999999 (kontrola šírky `numeric(12,2)` výsledku: `total_entity_ccy` je `numeric(12,2)`? ak je stĺpec užší než výsledok -> odmietnuť s `fx_overflow`).

## Migrácie (nová migrácia, nikdy edit starej; `spark migrate -g owner`)

1. `CreateCurrencies`: `currencies(code char(3) PK CHECK (code ~ '^[A-Z]{3}$'), decimals smallint NOT NULL DEFAULT 2, active boolean NOT NULL DEFAULT true, sort_order smallint NOT NULL)`; seed EUR/PLN/CZK; `REVOKE INSERT ... FROM app_rw` (vzor `legal_profiles`: app len číta; nová mena = migrácia/seed, D-01).
2. `ReplaceCurrencyChecks`: pre každú z 5 tabuliek `ALTER TABLE t DROP CONSTRAINT <t>_currency_check; ALTER TABLE t ADD CONSTRAINT <t>_currency_fk FOREIGN KEY (currency) REFERENCES currencies(code)`. Názvy CHECK sú generované PG (`<tabuľka>_currency_check`); v migrácii zistiť názov dotazom na `pg_constraint` (alebo `DROP CONSTRAINT IF EXISTS` s predpokladaným názvom a overiť testom schémy), lebo inline CHECK pomenovanie PG pre jednostĺpcový check je `<table>_<column>_check` [ASSUMED: platí pre PG 18, overiť v migrácii dotazom]. `invoices` má guard trigger proti UPDATE/DELETE riadkov, ale `ALTER TABLE ... ADD CONSTRAINT FOREIGN KEY` riadky nemení; spúšťať ako owner. `invoice_lines` a ďalšie tabuľky s `currency` skontrolovať `grep currency` (nájdených je 5 tabuliek; `legal_*` nemajú).
3. `CreateFxCountrySettings`: `fx_country_settings(country char(2) PK CHECK (country IN ('SK','PL','CZ')), source text NOT NULL CHECK (source IN ('NBP','ECB','CNB')), date_basis text NOT NULL CHECK (date_basis IN ('delivery_date','issue_date')), day_rule text NOT NULL CHECK (day_rule IN ('previous_business_day','on_or_before')), revision integer NOT NULL DEFAULT 1, updated_at timestamptz NOT NULL)`; seed 3 riadky z D-05; `grantMutable('fx_country_settings')` (UPDATE) lebo ide o UI editovateľné nastavenie (vzor `legal_texts` je read-only pre insert, ale texty sa menia UPDATE; skontrolovať `LegalTextService`). Samostatná tabuľka je lepšia než stĺpce v `legal_profiles`, lebo `legal_profiles` je kľúčované (krajina, režim DPH, režim klienta) a nastavenie kurzu je len podľa krajiny (D-05: "v právnom profile krajiny"). Zmeny cez `AuditLogger::recordChange('fx_settings', ...)`; `entity_id` v audit_log je int, tak použiť ID riadku alebo mapu krajín na int (overiť podpis `AuditLogger::record(string, int, ...)`; odporúčanie: `GENERATED ALWAYS AS IDENTITY id` + `UNIQUE(country)`).
4. `CreateExchangeRateCache`: `exchange_rate_cache(id identity, source text, currency char(3) FK currencies, bound_date date, valid_for date, rate numeric(18,8) CHECK (rate > 0), amount integer NOT NULL DEFAULT 1 CHECK (amount >= 1), table_no text NULL, direction text, fetched_at timestamptz, UNIQUE(source, currency, bound_date))`. Kľúč je `bound_date`, nie `valid_for`, lebo "posledný kurz <= bound" je dotaz, ktorý bez uloženia bound-u nevie cache zodpovedať (chýbajúci novší kurz sa nedá vylúčiť). D-07 ("zdroj + mena + deň platnosti") sa dodrží s tým, že "deň" = bound. `app_rw`: SELECT, INSERT (default) + `grantMutable` pre `ON CONFLICT DO UPDATE` len nefinálnych riadkov. **Finálnosť:** `bound_date < dnes (v TZ aplikácie)` -> finálny, nikdy sa neprepisuje. `bound_date >= dnes` -> nefinálny, preview ho obnoví, ak je starší než ~15 minút. Issuer číta riadok a model nekontroluje čas.
5. Voliteľne `CHECK (origin <> 'issued' OR fx_rate IS NOT NULL OR total_entity_ccy = total)` na `invoices`, plus `CHECK ((fx_rate IS NULL) = (fx_source IS NULL))`. Existujúce vystavené faktúry majú `total_entity_ccy = total`, takže spĺňajú. [ASSUMED: vhodné ako ochrana, planner rozhodne]

PHP `Currency` enum: 9 použití (`EntityInput`, `ClientInput`, `BankAccountInput`, `Country`, `Clients.form`, `_regime_fields`, `BankAccounts` controller...). Nahradiť `CurrencyRepository::codes()`/`isActive()`; `Country::defaultCurrency()` (mapuje krajinu na menu) môže ostať pevné (predvolená mena podľa krajiny nie je zoznam podporovaných mien).

## Integrácia do existujúceho kódu (konkrétne)

| Miesto | Zmena |
|--------|-------|
| `InvoiceGuards::assertCurrency` | zrušiť volanie (v `InvoiceDraftService` riadok ~139 a `InvoiceIssuer` riadky 164, 167). Ponechať metódu len ak sa použije inde; test `CurrencyGuardTest` prepísať na nový kontrakt (`fx_missing` namiesto `currency_mismatch`). Jazykové kľúče `errorCurrencyMismatch` nahradiť `errorFxMissing`, `errorFxUnavailable`, `errorFxInvalid` (SK+EN, `Invoicing.php`), a mapu v `Invoicing::errorMessage` (riadok ~209). |
| `PreviewInput::FIELDS` | pridať `fx_mode`, `fx_rate`, `fx_date` (whitelist, validácia formátu; `FIELDS` je pole GET parametrov náhľadu, preto sa preniesli aj do `IssueInput::SCALARS`). |
| `InvoiceDraftService::draft()` | nový parameter `?FxInput`; po výpočte `$calc` ak `entity.currency !== currency`: nájsť kurz (`FxService::resolve`), `RateUnavailable` -> chyba `fx_unavailable` v `Draft.errors` + `Draft.fx = ['state'=>'unavailable']` ale `canIssue=false` a **UI ukáže ručné pole**; ručný vstup validne -> `FxQuote`. `Draft` získa `?array $fx`. |
| `SnapshotBuilder::build()` | pridať posledný parameter `?FxQuote $fx = null`; ak `$fx !== null` naplniť `'fx'` (vrátane `FxConverter::convert`), inak `null`. Použiť pre `fx` aj v tom prípade, že entita a faktúra majú rovnakú menu (`null`). `schema` zvýšiť 2 -> 3 (D-14); všetky kontroly sú `snapshot_schema < 2`, nezlomia sa. Aktualizovať `Samples/SampleSnapshots`, `tests/_support/SnapshotSample` a `SampleSnapshotShapeTest`; pridať ukážkový PLN/EUR sample pre PDF test. |
| `InvoiceIssuer` | `IssueCommand` dostane `?FxOverride` (mode, rate, date). V kroku 6 (pred `build`) získať `FxQuote`: manual -> z cmd, auto -> `FxService::cached()` (SELECT, žiadna sieť). Insert: `fx_rate, fx_source, fx_date, fx_table_no, fx_date_basis` a `total_entity_ccy = snapshot.fx.total ?? total` (dnes `:total:` dvakrát). Audit `issue` doplniť o `fx_source`, `fx_rate`, `fx_manual` (D-11: vystavenie s ručným kurzom sa zapíše do audit_log; existujúci `audit->record(..'issue'..)` stačí doplniť o polia). |
| `InvoiceCalculator::previewToken` | bez zmeny (`fx` je v snapshote, `unset` sa týka len number/vs/payment). **Pozor:** `fx.fetched`/`fx.text` musia byť deterministické (žiadny čas sťahovania `fetched_at` v snapshote, inak token driftuje). `PreviewTokenScopeTest` rozšíriť o "zmena kurzu mení token". |
| `invoice_inv2.php:132` | rozšíriť blok: `Kurz ...` / `z dátumu ...` / `Spolu v PLN` (viď D-15); cez `esc()` a predformátované reťazce zo snapshotu. Šablóna nesmie čítať živé nastavenia. |
| Detail/zoznam | `InvoiceQueries` pridať `total_entity_ccy` a `entity` menu do selectu; `show.php`/`_list.php` zobraziť druhú sumu len ak `fx_rate IS NOT NULL`. |
| `LegalTexts` UI | pridať odkaz/sekciu "Kurzy" (nový `Settings/FxSettings` controller + view; trasy v `Config/Routes.php`, CSRF, owner filter ako ostatné `Settings/*`). |
| `StagingSeed` | pridať klienta v EUR k PL subjektu (PLN) a jednu faktúru s kurzom (manual alebo z cache fixtúry, bez živého volania) |

Ručný vstup v `_preview.php`: pole `fx_rate` (`inputmode="decimal"`, `min-height` 44 px, trieda Bootstrap, bez inline `style`), popisok z `lang()` ("1 EUR = ___ PLN" zložený zo zdroja/ccy), tlačidlo "Zadať ručne" prepínajúce cez Alpine CSP build (`x-show` len cez metódy/stav komponentu; CSP-build nepodporuje inline výrazy typu `!manual`, použiť gettery ako v existujúcom `isNotTransfer`). Zmena `fx_rate` -> htmx re-render náhľadu (GET s `fx_mode=manual`), nový token. Pri prepnutí späť na auto sa `fx_mode` pošle prázdny.

## Don't Hand-Roll

| Problém | Nerobiť | Použiť | Prečo |
|---------|---------|--------|-------|
| Pracovné dni / sviatky | kalendár | rozsah/`date` dotaz na API (D-06) | PL sviatky sa menia |
| HTTP s timeoutom | `file_get_contents`/raw curl | `service('curlrequest')` | existujúci framework, mockovateľný cez rozhranie |
| CSV parsing ECB | `explode(',')` | `str_getcsv` + mapovanie podľa hlavičky | polia v úvodzovkách (`TITLE_COMPL` obsahuje čiarky) |
| Peniaze | float | `bcmath` + `Money` | projektové pravidlo |
| Číselný formát na PDF | vlastné formátovanie | `MoneyFormat`/`PdfFormat` | NBSP, locale |
| Audit | vlastná tabuľka | `AuditLogger` | existuje |
| Číslovanie/zámok | — | existujúci issuer | fáza ho nemení, len pridáva kurz |

## Common Pitfalls

1. **Float z JSON** (kurz `4.4090`): pozri Anti-Patterns; parsovať kurz ako string.
2. **NBP vracia 400 (nie 404) pre prázdny rozsah**; jednodňové volanie 404. Ošetriť oboje ako `RateNotFound`, nie ako pád API (inak by sa zbytočne logoval error).
3. **ECB prázdne telo s HTTP 200** pri rozsahu bez pracovného dňa.
4. **Zámena smeru ECB** (delenie): jeden test na každý smer; pre `divide` popisok v UI "1 EUR = X PLN".
5. **ČNB `amount`** (HUF, JPY); pre EUR/PLN je 1, ale vždy deliť.
6. **Cache a token drift:** nefinálny riadok (bound >= dnes) sa môže medzi náhľadom a vystavením zmeniť -> `preview_stale`, správanie prijateľné a zdokumentované. Finálne riadky sa neprepisujú.
7. **Timeout počas ostrej prevádzky:** 3 s v requeste náhľadu rozbije p95 < 100 ms len pri cache miss. Pri zlyhaní krátko (napr. 60 s, `cache()` CI4) zapamätať zlyhanie, aby ďalšie re-rendery (zmena dátumu) nečakali znovu 3 s. Prioritne ručné zadanie ostáva dostupné.
8. **Preview re-fetch pri každej zmene dátumu:** `bound_date` kľúč zabezpečí, že rovnaký deň sa nesťahuje znova.
9. **PDF a staré faktúry:** `fx = null` musí ostať validné (blok je podmienený `! empty($s['fx'])`).
10. **`fx_date_basis` CHECK** `^[a-z_]{3,40}$` ← `delivery_date`/`issue_date` vyhovujú. `fx_source` CHECK `('NBP','ECB','CNB','manual')`, preto ČNB ukladať ako `CNB`, nie `CZ`.
11. **`total_entity_ccy` šírka:** stĺpec je `numeric(12,2)`; násobenie kurzom (napr. EUR->CZK ×24) môže presiahnuť; validovať.
12. **Rovnaká mena -> `fx = null`**: `Draft`/issuer nesmú vyžadovať kurz, `total_entity_ccy = total` (súčasné správanie, existujúce testy musia prejsť).
13. **PHP 8.5 deprecations:** testy bežia s `failOnDeprecation`; `RoundingMode` import `use RoundingMode;` (globálny enum PHP 8.4+).
14. **Pridanie LABEL_KEYS** vyžaduje preklady vo všetkých 4 jazykoch; chýbajúci kľúč v `InvoicePdf` vracia surový kľúč do PDF.
15. **Ručný kurz bezpečnosť:** vstup len regex `^\d{1,10}(\.\d{1,8})?$`, > 0, dátum validný a nie dlhšie než napr. 1 rok dozadu (voliteľne); žiadna dôvera v `fx_source`/`table_no` z POST (server ich nastaví na `manual`/null).

## Code Examples

### Provider NBP (kostra)
```php
// Source: overené živé volanie https://api.nbp.pl/api/exchangerates/rates/a/eur/2026-10-20/2026-10-29/?format=json
public function latestOnOrBefore(string $currency, string $bound): RateQuote
{
    $from = (new DateTimeImmutable($bound))->modify('-10 days')->format('Y-m-d');
    $res  = $this->http->get(sprintf('https://api.nbp.pl/api/exchangerates/rates/a/%s/%s/%s/?format=json', strtolower($currency), $from, $bound));
    if ($res->status === 400 || $res->status === 404) { throw new RateNotFound(); }
    if ($res->status !== 200) { throw new RateUnavailable('nbp_http_' . $res->status); }
    $body  = preg_replace('/"mid":\s*([0-9.]+)/', '"mid":"$1"', $res->body);
    $rates = json_decode($body, true, 8, JSON_THROW_ON_ERROR)['rates'] ?? [];
    $last  = end($rates) ?: throw new RateNotFound();
    return new RateQuote('NBP', $currency, (string) $last['mid'], 1, $last['effectiveDate'], $last['no'], 'multiply');
}
```
### Produkčný HTTP klient
```php
// Source: CI4 CURLRequest, options: timeout, connect_timeout, http_errors
$client = service('curlrequest', ['timeout' => 3, 'connect_timeout' => 2, 'http_errors' => false, 'headers' => ['Accept' => 'application/json']]);
$res = $client->get($url);   // Throwable pri sieťovej chybe -> RateUnavailable
return new HttpResult($res->getStatusCode(), (string) $res->getBody());
```
### Výber kurzu pre ČNB
```php
// amount môže byť 100; kurz sa ukladá ako publikovaný + amount, prepočet delí amountom vo FxConverter
foreach ($rows as $r) { if ($r['currencyCode'] === $currency) { ... new RateQuote('CNB', $currency, (string)$r['rate'], (int)$r['amount'], $r['validFor'], (string)$r['order'], 'multiply'); } }
```

## State of the Art

| Stará | Aktuálna | Dopad |
|-------|----------|-------|
| ČNB `daily.txt` | `api.cnb.cz/cnbapi/exrates/daily` (JSON) | JSON primárne, txt ako záloha (D-06) |
| ECB `eurofxref-daily.xml` | SDMX `data-api.ecb.europa.eu` csvdata | história a rozsah v jednom volaní |

## Assumptions Log

| # | Claim | Sekcia | Riziko ak nesprávne |
|---|-------|--------|---------------------|
| A1 | Názov CHECK constraintu je `<tabuľka>_currency_check` | Migrácie | migrácia padne; zistiť názvy dotazom na `pg_constraint` |
| A2 | NBP limit rozsahu 93 dní | API | nevplýva (používame 10 dní) |
| A3 | Presné časy publikácie (ČNB 14:30, NBP dopoludnia) | API | ovplyvní len nefinálne riadky; `bound=D-1` default je bezpečný |
| A4 | Voliteľný CHECK na `fx_rate/total_entity_ccy` | Migrácie | iba ochranná vrstva |
| A5 | Pravidlá defaultov (SK previous_business_day, CZ on_or_before) potvrdí účtovníčka | D-05 | zmena iba konfigurácie |
| A6 | Predpoklad, že `AuditLogger::record` prijíma int entity id | Migrácie 3 | overiť podpis pri implementácii, prípadne IDENTITY id |

## Open Questions

1. **Bump `snapshot_schema` na 3 (D-14).** Existujúce kontroly sú `< 2` (nerozbijú sa). Plánovať aktualizáciu `SampleSnapshotShapeTest`/`Samples`. Odporúčanie: bumpnúť, ako je rozhodnuté.
2. **Lazy vs synchronné sťahovanie v náhľade.** Odporúčanie: synchrónne pri cache miss s 3 s timeoutom + negatívna cache 60 s; lazy htmx variant ako možná optimalizácia.
3. **`exchange_rate_cache` kľúč** `bound_date` namiesto `valid_for` (odchýlka od doslovného D-07 znenia, zachovaná intencia). Planner to uvedie v pláne.
4. **Rozhodujúci dátum a zaokrúhlenie** čaká na účtovníčku (R1); všetko konfigurácia, nič natvrdo.

## Environment Availability

| Závislosť | Potrebné pre | Dostupné | Poznámka |
|-----------|--------------|----------|----------|
| Docker (`task-tracker-php-1`, `db`) | testy PHPUnit | ✓ | kontajnery bežia |
| Host PHP | — | 8.3.6 | nemá `bcround`/`RoundingMode`; všetko cez kontajner (PHP 8.5) |
| Prístup na NBP/ČNB/ECB | live smoke (manuálne UAT na staging) | ✓ | z tohto stroja overené; PHPUnit ho nepoužíva |
| bcmath, intl | prepočet, formát | ✓ (v obraze) | existujúci kód ich používa |

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5 + `CIUnitTestCase` / `Tests\Support\PgTestCase` |
| Config | `phpunit.xml.dist` (suites `unit`, `integration`; skupina `integration` na PG) |
| Quick run | `docker exec task-tracker-php-1 vendor/bin/phpunit --testsuite unit --filter Fx` |
| Full suite | `docker exec task-tracker-php-1 composer test` |

### Phase Requirements -> Test Map
| Req | Správanie | Typ | Súbor (nový) |
|-----|-----------|-----|--------------|
| CUR-01 | `currencies` seed EUR/PLN/CZK, FK odmietne neznámu menu, nová mena = INSERT bez zmeny schémy, `Currency` enum odstránený | integration | `tests/integration/Master/CurrenciesSchemaTest.php`, `CurrencyRepositoryTest.php` |
| CUR-01 | formuláre klienta/subjektu/účtu čítajú zoznam z DB | feature | `tests/feature/Master/CurrencyListTest.php` |
| CUR-02 | `FxConverter` všetky smery + zaokrúhlenie + `amount` | unit | `tests/unit/Fx/FxConverterTest.php` |
| CUR-02 | NBP/ECB/ČNB providery na fixtúrach: správna URL, posledný prvok, víkend, 2. 11., 1. 1., 11. 11., prázdny rozsah (NBP 400, ECB 200 prázdne), `amount` 100, kurz nie je float | unit | `tests/unit/Fx/NbpRateProviderTest.php`, `EcbRateProviderTest.php`, `CnbRateProviderTest.php` |
| CUR-02 | `bound_date` výpočet podľa `date_basis` a `day_rule` | unit | `tests/unit/Fx/FxBoundDateTest.php` |
| CUR-02 | `FxService` cache: hit (bez HTTP), miss -> insert, finálny riadok sa neprepisuje, nefinálny sa obnoví | integration | `tests/integration/Fx/FxServiceCacheTest.php` |
| CUR-02 | náhľad: PL subjekt + EUR klient -> kurz v snapshote, token sa zmení pri zmene kurzu/dátumu; ručný prepis | integration | `tests/integration/Invoicing/InvoiceDraftFxTest.php`, rozšíriť `PreviewTokenScopeTest` |
| CUR-02 | vystavenie: stĺpce `fx_*`, `total_entity_ccy`, snapshot `fx`, audit s manual, žiadne HTTP volanie (FakeHttp assert 0 volaní), rovnaká mena -> `fx` NULL a `total_entity_ccy = total` | integration | `tests/integration/Invoicing/InvoiceIssuerFxTest.php`; prepísať `CurrencyGuardTest` |
| CUR-02 | PDF obsahuje obe sumy a riadky kurzu (SK/PL/CS/EN), ručný kurz text | integration | rozšíriť `MpdfInvoiceInv2RendererTest` (využiť `PdfText`) |
| CUR-03 | API zlyhá (timeout/5xx) -> náhľad ukáže ručné pole, ručný kurz sa dá vystaviť; bez kurzu `fx_missing` | integration/feature | `InvoiceDraftFxTest`, `tests/feature/Invoicing/FxManualEntryTest.php` |
| CUR-03 | ručný kurz validácia (nula, záporný, 9 des., text, exponent) | unit | `tests/unit/Fx/FxInputTest.php` |
| CUR-03 | nemennosť: po zmene cache riadku a nastavenia krajiny vystavená faktúra a jej PDF ostávajú rovnaké (hash) | integration | `tests/integration/Invoicing/InvoiceFxImmutableTest.php` |
| CUR-05 | nastavenie krajiny: čítanie defaultov, zmena + audit + revision, validácia hodnôt, owner-only | integration+feature | `FxSettingsServiceTest.php`, `tests/feature/Settings/FxSettingsTest.php` |
| CUR-05 | zmena `date_basis` na `issue_date` mení `bound_date` a poslednú volanú URL | unit/integration | `FxBoundDateTest`, `InvoiceDraftFxTest` |

### Sampling Rate
- Per task commit: `phpunit --testsuite unit --filter Fx`
- Per wave merge: `composer test` (unit + integration)
- Phase gate: celá sada zelená, PHPStan (`composer analyse` ak je v skriptoch), kontrola prekladov SK/PL/CS/EN

### Wave 0 Gaps
- [ ] `tests/_support/FakeRateHttpClient.php` + `tests/_support/fixtures/fx/*` (NBP rozsah, NBP 400, ČNB daily, ECB csv, ECB prázdne)
- [ ] `tests/_support/FxFixtures.php` (PL subjekt PLN + klient EUR + bank. účet EUR, seed `fx_country_settings` a cache)
- [ ] aktualizácia `MasterFixtures::resetMasterTables` o `exchange_rate_cache`, `fx_country_settings`
- [ ] manuálny UAT na staging (živé API) po nasadení; PHPUnit nikdy nevolá živé API

## Security Domain

### Applicable ASVS Categories
| ASVS | Applies | Control |
|------|---------|---------|
| V4 Access control | yes | nastavenie kurzu len pre prihláseného vlastníka (existujúci filter `Settings/*`) |
| V5 Input validation | yes | whitelist `fx_mode`, regex kurzu a dátumu v `PreviewInput`/`IssueInput`, server nikdy neprijíma sumy ani zdroj z POST |
| V6 Cryptography | no | — |
| V7 Error handling/logging | yes | zlyhanie API do logu bez tela odpovede s citlivými údajmi; správy pre používateľa cez `lang()` |
| V12 Files/SSRF | yes | URL providerov sú konštanty; z používateľského vstupu ide do URL len validovaný kód meny (`^[A-Z]{3}$` z DB tabuľky) a dátum `Y-m-d` |

### Hrozby
| Vzor | STRIDE | Mitigácia |
|------|--------|-----------|
| Sfalšovaný "oficiálny" kurz cez POST | Tampering | auto režim berie kurz len zo serverovej cache; manual je vždy označený `manual` |
| SSRF cez kód meny/dátum | Tampering | konštantné hostiteľské mená, whitelist kódov z `currencies`, strict dátum |
| DoS blokovaním requestov (pomalé API) | DoS | timeout 3 s/2 s, negatívna cache 60 s |
| Zmena kurzu po vystavení | Tampering | snapshot + stĺpce, insert-only `invoices`, cache nie je zdroj |
| XSS v texte kurzu | Tampering | `esc()` v šablónach; texty z `lang()`/formátovačov |
| Nepovolená zmena nastavenia | EoP | CSRF + owner, audit |

## Sources

### Primary (HIGH)
- Živé volania 2026-10-30: `api.nbp.pl` (rozsah, 400/404), `api.cnb.cz/cnbapi/exrates/daily` (víkend, budúcnosť, zlý formát), `data-api.ecb.europa.eu` SDMX csvdata (rozsah, prázdne)
- Kód repozitára: `InvoiceDraftService`, `InvoiceIssuer`, `SnapshotBuilder`, `InvoiceCalculator::previewToken`, `InvoiceGuards`, `invoice_inv2.php`, migrácie `CreateInvoices`/`CreateLegalProfiles`, `GrantsPrivileges`, `Money.php`
- `CLAUDE.md` sekcia Kurzy mien, `08-CONTEXT.md`, `PITFALLS.md` Pasca 3

### Secondary (MEDIUM)
- CI4 `CURLRequest` zdroj vo vendor (`http_errors` -> `CURLOPT_FAILONERROR`), `MockCURLRequest::setOutput`

### Tertiary (LOW)
- Presné časy publikácie, limit NBP rozsahu 93 dní

## Metadata

**Confidence:** stack HIGH (žiadne nové balíky), API správanie HIGH (živé overenie), integračné body HIGH (prečítaný kód), cache/migračné detaily MEDIUM (navrhnuté, nie overené migráciou), právne defaulty LOW (čakajú na účtovníčku).
**Research date:** 2026-10-30
**Valid until:** 2026-11-30 (API sú stabilné; opätovne overiť pred nasadením)
