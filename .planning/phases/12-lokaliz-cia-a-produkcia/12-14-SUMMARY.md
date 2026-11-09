---
phase: 12-lokaliz-cia-a-produkcia
plan: 14
subsystem: ops
tags: [backup, restic, restore, archive, deploy]
requires: [12-02, 12-03, 12-05, 12-12, 12-13]
provides: [prod-backups-live, verified-clean-restore]
key-files:
  created: []
  modified:
    - docs/ops/backup.md
    - docs/ops/server.md
    - scripts/install-backup.sh
key-decisions:
  - "Root kroky zlúčené do jednorazového skriptu wt-backup-setup.sh (mimo gitu), vlastník ho spustil raz cez sudo"
  - "Jednorazový prod archív 2026 (krok 8a) neschválený: archív overený na stagingu, prvý prod beh je cron 2027-01-02"
  - "Heslo repozitára vymenené (restic key passwd) po tom, čo sa dostalo do chatu; v repozitári je jediný kľúč"
  - "D-05 akceptovaný odklon: kópia mimo servera iba ručným pullom na PC"
requirements-completed: [NFR-05, NFR-09]
completed: 2026-11-09
---

# Plan 12-14: zálohy v ostrej prevádzke a overená obnova

Fáza 12 je nasadená na stagingu aj produkcii (PR #13, smoke OK) a denné šifrované restic zálohy bežia na produkcii z root cronu, s upozorneniami a overenou obnovou reálnych dát na čistom prostredí vo WSL.

## Task 1: nasadenie
- Staging: CI zelené, smoke OK (vrátane statiky z 12-05), pamäť 76 MiB, `archive:year` v obraze.
- Produkcia: PR #13 zlúčený vlastníkom, prod deploy zelený, verejný smoke OK. Pamäť a `spark list` cez SSH na prod Claude nespustil (permission), overenie zostáva na vlastníkovi.
- Pred produkciou prebehol code review fázy (1 critical, 5 warning opravené) a oprava testu (busybox `date +%N`).

## Task 2: zálohy na serveri
- `install-backup.sh` doplnený o inštaláciu `bzip2`, keď chýba `bunzip2` (b1648dd).
- Výstupy: restic 0.19.1 x86_64; staging `OK: snapshot db staging`, `OK: obnova` (50 faktúr), `OK: archive 2026`; prod `OK: snapshot db prod` (862387f0), vynútená chyba doručila e-mail „Záloha zlyhala“ bez tajomstiev, `OK: obnova` na serveri (2 faktúry, 202611001); tri cron riadky; pull kľúč nainštalovaný.
- healthchecks.io: `work-tracker-backup` a `work-tracker-restore-test` up, nočný cron 2026-11-09 04:10 pingol; `work-tracker-archive` čaká na 2027-01-02.
- Test vynechanej zálohy (period 1 min) vlastník zatiaľ nepotvrdil, zostáva ako ručný krok.

## Task 3: obnova na čistom prostredí
- restic 0.19.1 vo WSL (overený SHA256SUMS), pull `OK`, `restic check` bez chýb, po výmene hesla jediný kľúč.
- Samostatný compose projekt `wt-restore-test` s prod `encryption.key`: snapshot 3b4b244d, `OK: obnova`, invoices=2, last_invoice_number=202611001, invoice_documents=4, bad_sha256=0.
- Vlastník sa prihlásil produkčným účtom vrátane 2FA. Potom `down -v`, adresár a lokálna kópia `encryption.key` zmazané, dev stack znova spustený.

## Deviations
- Heslo repozitára aj ping URL sa dostali do chatu; heslo vymenené, ping URL vlastník môže vymeniť pregenerovaním checkov.
- `.env` čistej kópie mal 600 a FPM ho nevedel čítať; opravené na 640 so skupinou 82.

## Self-Check: PASSED
