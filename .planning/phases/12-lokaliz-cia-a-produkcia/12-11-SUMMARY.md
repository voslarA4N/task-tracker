---
phase: 12-lokaliz-cia-a-produkcia
plan: 11
subsystem: ops
tags: [backup, restore, restic, rsync, runbook]
requires: [12-04]
provides:
  - "scripts/restore-test.sh: obnova posledného snapshotu db do dočasnej DB a porovnanie s manifestom (server aj lokálny režim)"
  - "scripts/pull-backups.sh: rsync šifrovaného restic repozitára na PC bez --delete a bez locks"
  - "docs/ops/backup.md: runbook záloh, pullu, obnovy na čistom prostredí a ročného archívu"
affects: [12-13]
tech-stack:
  added: []
  patterns: ["falošné docker/restic/curl/rsync na PATH v PHPUnit"]
key-files:
  created:
    - scripts/restore-test.sh
    - scripts/pull-backups.sh
    - tests/unit/Scripts/RestoreTestScriptTest.php
    - tests/unit/Scripts/PullBackupsScriptTest.php
  modified:
    - docs/ops/backup.md
key-decisions:
  - "Pull: repozitár ostáva root-only, vyhradený používateľ backuppull s command=sudo rrsync -ro a presným sudoers pravidlom (nastaví vlastník v 12-13)"
  - "Server režim povolí iba DB restore_check_*, RESTORE_KEEP sa tam ignoruje; task_tracker iba v lokálnom režime"
  - "Porovnanie manifestu: každý kľúč okrem created_at, env, dump_sha256; všetky nezhody sa vypíšu pred zlyhaním"
requirements-completed: []
duration: 25min
completed: 2026-11-05
---

# Phase 12 Plan 11: Test obnovy, pull na PC a runbook Summary

`restore-test.sh` obnoví posledný snapshot `db` do dočasnej DB (alebo v lokálnom režime do čistej WSL inštalácie), porovná ho s manifestom zo zálohy a vždy upratuje; `pull-backups.sh` stiahne repozitár na PC bez `--delete`; `docs/ops/backup.md` je prepísaný na celý cyklus restic.

## Commits
- 78a74e6 feat: restore test against backup manifest
- be0365d feat: pull encrypted restic repository to owner pc
- aef64d2 docs: restic backup, restore and archive runbook

## Testy
- `RestoreTestScriptTest`: 7 testov, OK. `PullBackupsScriptTest`: 4 testy, OK. `sh -n` oboch skriptov OK.
- Celú sadu spúšťa orchestrátor po vlne.

## Deviations from Plan
- Skripty a testy som písal naraz (bez samostatného RED commitu), každá úloha má jeden commit `feat`.
- Test používa regex na dátum predmetu e-mailu (pravidlo `app.forbiddenTime`), nie `gmdate`.
- Runbook odkazuje na `server.md` §21, ktorý vznikne až v 12-13.
- Runbook obsahuje aj ručné mazanie archívnych snapshotov starších ako 10 rokov (handoff z 12-10).

## Known Stubs
Tabuľka „Záznam testov obnovy“ je zámerne prázdna, vyplní ju 12-13.

## Threat Flags
None.

## Self-Check: PASSED
