---
phase: 12-lokaliz-cia-a-produkcia
plan: 01
subsystem: i18n
tags: [locale, settings, security, translations, pl, cs]
requires: []
provides:
  - "POST /settings/security/locale (setLocale) a karta Jazyk rozhrania"
  - "supportedLocales = sk, pl, cs, en"
  - "AuthEvent::LocaleChanged"
  - "PL a CS Account.php a App.php"
  - "LangKeysTest parita pl, cs, en po súboroch"
affects: [12-06, 12-07, 12-08, 12-09]
tech-stack:
  added: []
  patterns: ["whitelist locale, PRG s flash v novom jazyku"]
key-files:
  created:
    - app/Views/settings/_language.php
    - app/Language/pl/Account.php
    - app/Language/pl/App.php
    - app/Language/cs/Account.php
    - app/Language/cs/App.php
    - tests/feature/Settings/LocaleTest.php
  modified:
    - app/Config/App.php
    - app/Config/Routes/settings.php
    - app/Controllers/Settings/Security.php
    - app/Libraries/Auth/AuthEvent.php
    - app/Views/settings/security.php
    - app/Language/sk/Account.php
    - app/Language/en/Account.php
    - tests/unit/I18n/LangKeysTest.php
    - tests/feature/Settings/SecurityPageTest.php
key-decisions:
  - "Zmena jazyka sa auditom zapisuje iba pri skutočnej zmene hodnoty"
requirements-completed: [UI-02]
duration: 25min
completed: 2026-11-04
---

# Phase 12 Plan 01: Výber jazyka rozhrania Summary

Karta "Jazyk rozhrania" na /settings/security s POST endpointom, whitelistom supportedLocales (sk, pl, cs, en), auditom locale_changed a úplným PL/CS prekladom Account.php a App.php.

## Commits
- e13d1fb test: failing LocaleTest a parita jazykov
- 5b8cdf0 feat: karta, trasa, setLocale, audit, sk/en kľúče
- de393eb feat: PL a CS Account.php a App.php

## Tests
LocaleTest, tests/unit/I18n, tests/unit/Security, tests/feature/Settings: 161 testov OK. PHPStan bez chýb.

## Deviations from Plan

**1. [Rule 1 - Bug] Regex zástupných symbolov v LangKeysTest**
- Plánovaný `/\{[^}]+\}/` zlyhával na ICU plural reťazcoch (vnorené zátvorky, vetvy sa medzi jazykmi líšia). Porovnávajú sa iba názvy argumentov (`{0}`, `{name}`, `{1, plural,`).

**2. [Rule 1 - Bug] SecurityPageTest počet CSRF tokenov**
- Nová karta pridáva formulár, počet tokenov 5 zmenený na 6.

**3.** Task 2 a 3 boli overené spoločne (LocaleTest potrebuje PL/CS texty), commitnuté oddelene.

## Known Stubs
None.

## Self-Check: PASSED
