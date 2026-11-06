---
phase: 12-lokaliz-cia-a-produkcia
plan: 10
subsystem: ops
tags: [archive, export, restic, zip, sha256]
requires: [12-04]
provides:
  - "spark archive:year [rok]: ZIP všetkých PDF vystavených dokladov roka (všetky verzie) + zoznam-dokladov.csv so sha256"
  - "scripts/archive-yearly.sh: uloženie archívu do restic s tagom archive (bez forget)"
affects: [12-11, 12-13]
tech-stack:
  added: []
  patterns: ["PDF iba cez DocumentStore::readVersion, jedno PDF naraz cez dočasný súbor + ZipArchive::addFile"]
key-files:
  created:
    - app/Libraries/Export/YearArchive.php
    - app/Libraries/Export/YearArchiveResult.php
    - app/Commands/ArchiveYear.php
    - scripts/archive-yearly.sh
    - tests/integration/Export/YearArchiveTest.php
    - tests/unit/Scripts/ArchiveYearlyScriptTest.php
  modified: []
key-decisions:
  - "Vystavené v roku = origin issued a issue_date v roku (vrátane dobropisov a stornovaných); externé faktúry nie sú v archíve"
  - "Archív: dokumenty kind invoice a report, každá verzia; verzia > 1 má príponu -v{n}, kolízia -{document id}"
  - "Subjekt a klient v CSV zo snapshotu (snapshot->entity->trade_name, snapshot->client->name), nikdy z živých dát"
  - "Retencia 10 rokov = trvalé uchovanie (--keep-tag archive), skript nevolá forget"
requirements-completed: []
duration: 30min
completed: 2026-11-05
---

# Phase 12 Plan 10: Ročný archív dokladov Summary

`spark archive:year` zloží overiteľný ZIP všetkých uložených PDF roka (všetky verzie) s CSV indexom so sha256 a `archive-yearly.sh` ho uloží do restic s nemazateľným tagom `archive`.

## Commits
- 6de8aac test: failing yearly document archive test (RED)
- 38e2706 feat: yearly document archive command (GREEN)
- c882926 feat: yearly archive stored in restic with archive tag

## Testy
- `phpunit tests/integration/Export` (vrátane 9 testov YearArchiveTest) a `tests/unit/Scripts/ArchiveYearlyScriptTest.php` (7 testov): 26 testov, OK.
- `composer stan`: žiadna chyba v súboroch tohto plánu. Existujúce chyby `app.forbiddenTime` (gmdate/strtotime) v `ResticBackupScriptTest` a `ResticRetentionTest` pochádzajú z 12-04, nie som ich opravoval (mimo rozsahu).

## Poznámky
- CLI `memory_limit` v dev obraze je 256M (>= 128M), pamäť drží jedno PDF naraz, takže bez rizika (RESEARCH A4).
- Test poškodeného obsahu: CHECK sha256 v DB neumožní vložiť poškodený riadok, preto je `DocumentIntegrityError` pokrytý existujúcimi testami DocumentStore. `ArchiveYear` ho zachytáva ako chybu (`Archív sa nevytvoril`, EXIT_ERROR), `YearArchive` ho nechá prejsť a nikdy nevloží poškodené PDF.
- ZIP a `.sha256` majú práva 0600, adresáre 0700; zápis cez `tempnam` + `rename`.
- Wrapper pri zlyhaní restic nemaže ZIP v kontajneri (ponechaný na opakovanie); stage sa maže vždy.
- Wrapper: `ARCHIVE_YEAR_NOW` je testovací prepis roku pre argument bez hodnoty.

## Deviations from Plan
- [Rule 3 - Blocking] Adresáre `app/Libraries/Export` a `tests/integration/Export` mali vlastníka uid 82 bez zápisu pre používateľa; práva som zmenil na 777 cez `docker compose exec -u root` (rovnako ako ostatné adresáre v repozitári, git ich neeviduje).
- Poznámka k 12-11: runbook musí opísať ručné mazanie archívnych snapshotov starších ako 10 rokov (`restic forget <id> --prune`).

## Known Stubs
None.

## Threat Flags
None.

## Self-Check: PASSED
