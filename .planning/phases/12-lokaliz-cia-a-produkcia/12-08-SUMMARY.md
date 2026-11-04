---
phase: 12-lokaliz-cia-a-produkcia
plan: 08
subsystem: i18n
tags: [translations, cs]
requires: [12-01]
provides:
  - "CS preklad Entities, Validation, Pwa, Demo, Invoicing, Invoices, Limits, Tasks, Users, DocumentSettings, Login"
affects: [12-09, 12-12]
tech-stack:
  added: []
  patterns: ["CS terminológia podľa UI-SPEC B"]
key-files:
  created:
    - app/Language/cs/Entities.php
    - app/Language/cs/Validation.php
    - app/Language/cs/Pwa.php
    - app/Language/cs/Demo.php
    - app/Language/cs/Invoicing.php
    - app/Language/cs/Invoices.php
    - app/Language/cs/Limits.php
    - app/Language/cs/Tasks.php
    - app/Language/cs/Users.php
    - app/Language/cs/DocumentSettings.php
    - app/Language/cs/Login.php
key-decisions:
  - "Validation.php v cs je prázdne pole, hlášky dodáva codeigniter4/translations (cs)"
  - "ICU plural pre dni po splatnosti má CS vetvy one/few/many/other"
requirements-completed: []
duration: 12min
completed: 2026-11-05
---

# Phase 12 Plan 08: Czech translation, first half Summary

11 súborov `app/Language/cs/*.php` s rovnakými kľúčmi a zástupnými symbolmi ako sk, terminológia Úkol / Faktura / Dobropis / Výkaz práce / Subjekt / Splatnost / Datum plnění / Protokol změn. Všetky preklady sú **na prezretie vlastníkom**.

## Commits
- af519fa feat(12-08): entities, validation, pwa, demo
- 56860e7 feat(12-08): invoicing and invoices
- f359489 feat(12-08): limits, tasks, users, document settings, login

## Tests
`tests/unit/I18n` + `tests/feature/Settings/LocaleTest.php`: 25 testov, 4414 assertions OK. `php -l` všetkých 11 súborov čistý.

## Na kontrolu vlastníkom
- Texty dokladu/e-mailu: Invoicing `qrReason*`, `paymentNote*`, `zbp*`; Invoices `bannerCancelled`/`bannerCorrects`.
- „Přegenerovat“ (regen*) je doslovný preklad, prípadne „Znovu vygenerovat“.
- Users `role_accountant` = „Účetní“; `infoIdleLogout` v množnom čísle („Byli jste odhlášeni“) ako v sk.
- Validation: `lang('Validation.required', ..., 'cs')` samostatne nespustené, súbor vracia `[]` ako sk.

## Kľúče s hodnotou identickou so sk (legitímna zhoda, pre test v 12-12)
- Entities: newTitle, detailTitle, groupIdentity, addressLine1, postalCode, companyIdSk, companyIdPl, regon, taxId, vatId, registryHintPl, email, validFrom, countrySK, countryCZ, langCs, langEn, regimePlExempt1131, regimePlExempt1139, errorFormat, validFromLine, statusEnded, emptyValue, navTitle, listTitle, periodFrom, historyFrom, badgeCurrent, bankIban, bankSwift, bankSwiftLine, badgeArchived, seriesCreditNotes, seriesFormat, seriesResetYearly, seriesTokensTitle, legalCode, senderEmailHint
- Pwa: appName
- Demo: title, hours, listHeading
- Invoicing: labelClient, labelEntity, labelItems, lineMeta, lang_en, lang_cs, paymentHeading, confirmPaymentNote, limitRow, limitRowSums
- Invoices: badgeIssued, rowMeta, labelEntity, labelClient, labelLegalText, labelSha, labelItems, labelDetails, lineMeta, reportPdf, statusIssued, filterStatus, filterOverdue, fxRate, fxSource, fxTable, statusCancelled, badgeCreditNote, statusCnIssued, creditNotePdf, labelCorrectionType, labelCorrectionScope, listCorrects, listCreditNote, filterCancelled, filterCreditNote, colNumber, colClient, colIssued, colStatus, filterEntity, filterClient, filterFrom, filterTo, versionsKindReport, versionsKindCreditNote
- Limits: navTitle, title, entityMeta, usageOf, cardMeta, formulaLimit, formulaLimitProRata, rowMeta, thresholdsList, fieldYear, errOpeningEntity, fieldEntity, fieldNote
- Tasks: client, note, status, stateInProgress, archivedBadge, perHourUnit, sectionData, empty, previewLabel, noteLabel, colClient, colStatus, colHours, colAlloc, filterClient, filterState, hoursValue, rowMeta
- Users: colTwofa, never, role_admin
- DocumentSettings: navTitle
- Login: appName, email, password

## Deviations from Plan
None - plan executed as written.

## Known Stubs
None.

## Self-Check: PASSED
