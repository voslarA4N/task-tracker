# Jednorazový import úloh z Excelu (spark import:excel)

## Účel

Jednorazový import úloh za 09 a 10/2026 pre klienta All4Net (sadzba 27 €) zo súboru
`docs/All4net-fakturovanie-prace.xlsx`. Príkaz je len pre vývojové prostredie
(PhpSpreadsheet je v `require-dev`). V produkcii príkaz skončí chybou
"PhpSpreadsheet nie je nainštalovaný" a `spark list` nespadne.

## Predpoklady

- Beží vývojové prostredie (`make up`) s dev závislosťami.
- Klient All4Net existuje, má sadzbu 27,00 € a nie je archivovaný. Zistite jeho ID (Nastavenia, Klienti).
- Súbor `docs/All4net-fakturovanie-prace.xlsx` je mimo gitu (`*.xlsx` v `.gitignore`) a musí byť
  dostupný v kontajneri `php` (pri bind mount bez `docs/` ho skopírujte cez `docker compose cp`).

## Postup

1. Suchý beh (predvolený, nič nezapíše):
   `make spark ARGS="import:excel docs/All4net-fakturovanie-prace.xlsx --client <id>"`
   Voľby sa zadávajú s hodnotou za medzerou (`--client 1`), `--commit` až za cestu a `--client`.
   Voliteľne `--month RRRR-MM` obmedzí import na jeden mesiac (hodnota za medzerou, `--commit` posledný),
   pozri sekciu "Import len jedného mesiaca (--month)".
2. Skontrolujte report a porovnajte s očakávaním nižšie.
3. Ostrý beh: pridajte `--commit`:
   `make spark ARGS="import:excel docs/All4net-fakturovanie-prace.xlsx --client <id> --commit"`
   Výstup: `Vytvorené: {n}, preskočené (už importované): {n}, časti: {n}`.
4. Opakované spustenie s `--commit` musí vypísať `Vytvorené: 0`.

## Import len jedného mesiaca (--month)

`--month RRRR-MM` obmedzí celý beh (suchý beh, report, súčty po mesiacoch, riadok `POZOR:` aj ostrý zápis) na blok
daného mesiaca. Bez voľby sa príkaz správa ako doteraz.

1. Suchý beh:
   `make spark ARGS="import:excel docs/All4net-fakturovanie-prace.xlsx --client <id> --month 2026-09"`
   Report musí obsahovať riadok `Filter mesiaca: September 2026`, len mesiac 09/2026 (11 úloh, 89 h / 2 403,00 €)
   a nesmie sa v ňom objaviť október. Riadok `POZOR:` sa vypíše len vtedy, ak súbor obsahuje riadky
   s "Fakturované = ÁNO" a zapracovaním v danom mesiaci.
2. Ostrý beh: rovnaký príkaz s `--commit` na konci. Vytvorí len úlohy a časti za 09/2026.
3. Zamknutie: `invoices:lock-historical --client <id> --month 2026-09` (suchý beh), potom s
   `--expect-parts 11 --expect-hours 89 --expect-amount 2403 ... --commit` podľa sekcie WR-07.
4. Neskorší import októbra (`--month 2026-10` alebo bez filtra) septembrové riadky preskočí, lebo `import_key`
   je s filtrom aj bez neho rovnaký.

Chyby: neplatný formát (`2026-9`, `2026-13`, `sep`, voľba bez hodnoty) alebo mesiac, ktorý v súbore nie je, skončí
chybou a nič sa nezapíše. Súbor sa vždy číta celý, takže neplatný riadok v inom mesiaci import tiež zastaví.

## Očakávaný výsledok suchého behu

| Mesiac | Excel aj prepočet | Zapracované |
|--------|-------------------|-------------|
| 09/2026 | 89 h / 2 403,00 € | 89 h / 2 403,00 € |
| 10/2026 | 82 h / 2 214,00 € | 1 h / 27,00 € |

- Upozornenie na neúplný rozsah vzorca súčtu E a F v októbri (súčty E19:E22 a F19:F22 nepokrývajú riadky bloku 20 až 24).
  Súčet z Excelu sa zhoduje s prepočtom, no vzorec v Exceli je potrebné opraviť; toto upozornenie
  sa zobrazí aj pri nulovom rozdiele.
- Riadky bez špecifického odkazu: 11 (úlohy dostanú bez odkazu alebo so všeobecným odkazom, doplnia sa v UI).
- Hodnoty súčtov, ktoré sa nepodarí načítať (napr. viac ako 2 desatinné miesta), report vypíše ako
  "nenačítané", nikdy ich nevynechá potichu. Riadok s neplatnými hodinami zastaví import chybou.

## Čo import robí

- Pre každý riadok vytvorí úlohu cez `TaskService` (sadzba klienta, audit s `ip = 'cli'`).
- Riadok Zapracované = ÁNO: úloha je `done` a má jednu nezamknutú časť v mesiaci bloku (hodiny riadku × sadzba klienta).
- Riadok NIE: úloha `V práci` bez časti.
- Celý ostrý beh je jedna transakcia: pri chybe v ktoromkoľvek riadku sa nezapíše nič.

## Idempotencia

Každá úloha má kľúč `import_key` (mesiac, URL, názov, hodiny, poradie duplikátu). Opakovaný beh preskočí už
importované úlohy. Zmena názvu, URL alebo hodín v Exceli znamená novú úlohu.

## Čo import nerobí

- Nevytvára zamknuté časti ani faktúry (aj riadky s "Fakturované = ÁNO" sa importujú len ako nezamknuté časti).
  Suchý aj ostrý beh preto vypíše po mesiacoch, koľko riadkov s "Fakturované = ÁNO" a zapracovaním (hodiny a suma)
  bude po importe nezamknutými časťami na fakturáciu, a súhrnný riadok `POZOR: ...`. Tieto časti by sa inak mohli
  vyfakturovať druhýkrát. Tieto časti sa zamykajú historickou externou faktúrou, postup je v sekcii
  "Zamknutie historicky vyfakturovaných častí (WR-07)" nižšie.
- Neprepisuje ani nemaže existujúce úlohy.

## Zamknutie historicky vyfakturovaných častí (WR-07)

Príkaz `invoices:lock-historical` zamkne importované časti jedného klienta a mesiaca na jednu historickú externú
faktúru. Tá nespotrebuje číslo z radu `RRRRMM###`, nemá PDF a nezapočítava sa do obratu ani limitov
(`counts_toward_limit = false`).

**Kedy:** po importe a pred prvým ostrým vystavením faktúry, v dev aj na produkcii. Časti z riadkov
"Fakturované = ÁNO" sa tak už nedajú vyfakturovať druhýkrát. Príkaz nevyžaduje PhpSpreadsheet, beží aj v produkčnom
obraze.

**Obmedzenie výberu:** príkaz nevyberá podľa stĺpca "Fakturované = ÁNO" (ten sa v DB neuchováva, Q3 v 05-RESEARCH),
ale podľa mesiaca, `tasks.import_key IS NOT NULL` a toho, že časť vznikla pri importe (jej `created_at` je najviac
1 minútu po `created_at` importovanej úlohy, import zakladá úlohu aj časť v jednej transakcii). Časti, ktoré vlastník
pridal k importovanej úlohe až neskôr v aplikácii, sa predvolene **nezamknú**. Suchý beh ich vypíše
(`Vynechané časti pridané v aplikácii ... (ID ...)`), zahrnúť ich možno iba výslovne voľbou `--include-app-parts`
(vtedy aj `--expect-*` musia zodpovedať súčtu vrátane nich). Importované, no nevyfakturované časti mesiaca by príkaz
stále zamkol, poistkou proti tomu sú `--expect-*`.

**Odkiaľ brať hodnoty `--expect-*`:** nikdy zo suchého behu `invoices:lock-historical`, ale z riadku správy importu
`POZOR: N riadkov označených Fakturované = ÁNO (H h / A)` (`ImportReportFormatter::billedWarning`, súčty z
`ImportPlan::billedParts`) pre daný mesiac.

Postup:

1. Uložiť výstup `spark import:excel ... --month 2026-09` (voľba `--month` zúži riadok `POZOR: ...` na jeden mesiac,
   netreba rozpis robiť ručne v Exceli). Ak súbor neobsahuje riadky "Fakturované = ÁNO", riadok `POZOR:` sa
   nevypíše a hodnoty `--expect-*` sa overia podľa súčtov Zapracované daného mesiaca.
2. Suchý beh (predvolený, nič nezapíše):

   ```sh
   make spark ARGS="invoices:lock-historical --client <id> --month 2026-09"
   ```

3. Porovnať počet, hodiny a sumu suchého behu s riadkom správy importu.
4. Ostrý beh iba pri zhode. Pri nezhode nič nespúšťať a riešiť ručne:

   ```sh
   make spark ARGS="invoices:lock-historical --client <id> --month 2026-09 --expect-parts 11 --expect-hours 89 --expect-amount 2403 --ref 'Historická faktúra 09/2026 (fakturované mimo aplikácie)' --commit"
   ```

S `--commit` sú povinné všetky tri `--expect-*`. Pri nezhode skutočnosti s očakávaním (`expectation_mismatch`) sa nič
nezapíše.

**Príklad pre vývojovú DB:** overené hodnoty pre 09/2026 sú 11 častí, 89 h, 2 403,00 EUR (zhoda správy importu so
suchým behom). Takto bol príkaz spustený aj na dev DB (klient 1, externá faktúra id 1).

**Idempotencia:** druhý beh skončí chybou `nothing_to_lock`, keďže už nie sú žiadne nezamknuté importované časti.
Faktúra je insert-only, časti zamknuté faktúrou už nejde meniť ani mazať. Audit: `invoice/external_lock`.
