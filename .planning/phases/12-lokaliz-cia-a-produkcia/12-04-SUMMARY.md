---
phase: 12-lokaliz-cia-a-produkcia
plan: 04
subsystem: ops
tags: [backup, restic, healthchecks, shell, tests]
requires: []
provides:
  - "scripts/backup-restic.sh: denná šifrovaná záloha (overený dump + manifest) do lokálneho restic repozitára"
  - "scripts/lib-restic-policy.sh: jediný zdroj retenčnej politiky 14/8/12 + keep-tag archive"
  - "scripts/lib-backup-notify.sh: check_env_file, ping_ok, notify_fail (pre 12-10 a 12-11)"
  - "backup.sh: BACKUP_NAME a BACKUP_COMPOSE_DIR"
affects: [12-10, 12-11, 12-13]
tech-stack:
  added: ["restic 0.18.1 (alpine apk, iba dev obraz)"]
  patterns: ["falošné docker/restic/curl na PATH v PHPUnit", "stderr krokov do dočasného logu, posledných 20 riadkov do e-mailu"]
key-files:
  created:
    - scripts/backup-restic.sh
    - scripts/lib-restic-policy.sh
    - scripts/lib-backup-notify.sh
    - tests/unit/Scripts/ResticBackupScriptTest.php
    - tests/unit/Scripts/ResticRetentionTest.php
  modified:
    - scripts/backup.sh
    - tests/unit/Scripts/BackupScriptTest.php
    - Dockerfile
key-decisions:
  - "restic beží na hostiteľovi ako root, pg_dump/psql v kontajneri db; skripty sa inštalujú root-owned v 12-13"
  - "Manifest kľúč=hodnota (rows.<tabuľka>, last_invoice_number, documents_sha256_digest, documents_bad_sha256, dump_sha256) je kontrakt pre 12-11"
  - "Upozornenie e-mailom cez curl --ssl-reqd s dočasným 0600 netrc (heslo nie v argumentoch)"
  - "Ping zlyhanie ani zlyhanie notifikácie nezhodí beh, iba varovanie bez URL"
requirements-completed: []
duration: 35min
completed: 2026-11-04
---

# Phase 12 Plan 04: Šifrované zálohy do restic Summary

Skript `backup-restic.sh` uloží overený pg_dump a manifest kontrolných hodnôt do lokálneho restic repozitára (tag db, stabilná cesta), rotuje 14/8/12 s nemazateľným tagom archive, pri úspechu pingne healthchecks a pri chybe pošle /fail ping a e-mail bez tajomstiev.

## Commits
- 81f3430 test: failing restic backup script tests (RED)
- 52820e9 feat: encrypted restic backup with rotation and alerts (GREEN)
- 1c8dabd test: restic retention keeps 14/8/12 and archive snapshots (+ restic v dev obraze)

## Testy
- `phpunit tests/unit/Scripts`: 86 testov, OK (vrátane 10 testov ResticBackupScriptTest a testStableNameAndComposeDir).
- `ResticRetentionTest` s reálnym restic 0.18.1: 2 testy, OK. Dokazuje 14 denných + trvalý archive snapshot a prečo je potrebné `--group-by host,tags`.

## Deviations from Plan
- Test retencie trvá ~40 s (plán: pod 30 s). Dominuje odomknutie kľúča (scrypt KDF) pri každom z ~45 volaní restic; `--no-scan` nepomohol. Funkčne bez dopadu.
- Dev obraz má restic 0.18.1 (alpine), server bude mať 0.19.1 (12-13); sémantika `forget` je rovnaká.
- Text e-mailu pre predmety archív/obnova má vlastnú prvú vetu (odvodené z UI-SPEC E, ktorý definuje iba zálohu).

## Known Stubs
None.

## Threat Flags
None.

## Self-Check: PASSED
