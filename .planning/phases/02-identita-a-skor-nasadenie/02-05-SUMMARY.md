---
phase: 02-identita-a-skor-nasadenie
plan: 05
subsystem: mail
tags: [email, security-notice, mailpit, i18n]
requires: [02-01]
provides: [AppMailer, SecurityNotifier, notify:test]
affects: [02-10, 02-16, 02-18, 02-19]
key-files:
  created:
    - app/Libraries/Mail/AppMailer.php
    - app/Libraries/Auth/SecurityNotifier.php
    - app/Views/emails/security_notice.php
    - app/Language/sk/Notify.php
    - app/Language/en/Notify.php
    - app/Commands/NotifyTest.php
    - tests/integration/Mail/SecurityNotifierTest.php
  modified:
    - app/Config/Email.php
    - .env.example
key-decisions:
  - "O-3 = metadata: pri email.transport=log sa loguje iba kind, prijemca, predmet (nie telo)"
  - "Dynamicke jazykove kluce cez premennu, aby LangKeysTest (regex na literal lang('X.y')) nehlasil falosne chyby"
duration: 15min
completed: 2026-10-24
---

# Phase 02 Plan 05: Bezpečnostné e-maily Summary

**AppMailer (CI4 Email alebo metadata-only log podľa `Config\Email::$transport`) a SecurityNotifier so šiestimi druhmi upozornení v sk/en, bez URL a tajomstiev, overené cez Mailpit.**

## Commits
- df4f1bd: test(02-05) RED
- ab9d711: feat(02-05) GREEN

## Verification
- SecurityNotifierTest + I18n testy zelené, `composer check` zelený (165 testov).
- `spark notify:test owner@example.test` doručil správu do Mailpitu (predmet "Work Tracker: účet je dočasne zamknutý").
- `printDebugger` v AppMailer = 0, `http` v šablóne = 0.

## Deviations from Plan
- [Rule 3] `assertLogged` porovnáva presne a `TestLogger::$op_logs` je protected, test preto používa `TestLogger::didLog(..., false)` (podreťazec).
- [Rule 3] Odstránený zastaraný 0-bajtový `.git/index.lock` (žiadny git proces nebežal).
- Dynamické kľúče `Notify.subject_*`/`body_*` skladané do premennej (obchádza regex v LangKeysTest); parita sk/en je overená testom.

## Known Stubs
None.

## Self-Check: PASSED
