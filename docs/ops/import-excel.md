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
2. Skontrolujte report a porovnajte s očakávaním nižšie.
3. Ostrý beh: pridajte `--commit`:
   `make spark ARGS="import:excel docs/All4net-fakturovanie-prace.xlsx --client <id> --commit"`
   Výstup: `Vytvorené: {n}, preskočené (už importované): {n}, časti: {n}`.
4. Opakované spustenie s `--commit` musí vypísať `Vytvorené: 0`.

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
- Neprepisuje ani nemaže existujúce úlohy.
