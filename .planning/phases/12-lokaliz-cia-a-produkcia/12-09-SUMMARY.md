---
phase: 12-lokaliz-cia-a-produkcia
plan: 09
subsystem: i18n
tags: [translations, cs]
requires: [12-01, 12-08]
provides:
  - "CS preklad 17 súborov: Deliveries, Clients, CreditNotes, Allocations, Bundle, PasswordReset, LegalTexts, Currency, AuditLog, EmailTemplates, TwoFactor, Dashboard, Payments, FxSettings, Notify, Reports, Export"
affects: [12-12]
key-files:
  created:
    - app/Language/cs/Deliveries.php
    - app/Language/cs/Clients.php
    - app/Language/cs/CreditNotes.php
    - app/Language/cs/Allocations.php
    - app/Language/cs/Bundle.php
    - app/Language/cs/PasswordReset.php
    - app/Language/cs/LegalTexts.php
    - app/Language/cs/Currency.php
    - app/Language/cs/AuditLog.php
    - app/Language/cs/EmailTemplates.php
    - app/Language/cs/TwoFactor.php
    - app/Language/cs/Dashboard.php
    - app/Language/cs/Payments.php
    - app/Language/cs/FxSettings.php
    - app/Language/cs/Notify.php
    - app/Language/cs/Reports.php
    - app/Language/cs/Export.php
key-decisions:
  - "Notify subjekty si ponechávajú prefix 'Work Tracker:'; Export prefixy názvov súborov (prehlad, faktury, balik) identické so sk"
  - "ICU plural pre dni po splatnosti v Dashboard má CS vetvy one/few/many/other"
  - "Dvojfaktorové -> dvoufázové ověření v Notify, konzistentne s TwoFactor"
requirements-completed: []
duration: 15min
completed: 2026-11-05
---

# Phase 12 Plan 09: Czech translation, second half Summary

17 súborov `app/Language/cs/*.php` s rovnakými kľúčmi a zástupnými symbolmi ako sk; spolu s 12-01 a 12-08 má cs všetkých 32 súborov. Terminológia Úkol / Faktura / Dobropis / Výkaz práce / Splatnost / Datum plnění / Protokol změn / Zabezpečení / Více. Všetky preklady sú **na prezretie vlastníkom**.

## Commits
- 51710f5 deliveries, clients, credit notes, allocations, bundle, password reset
- d8d738c legal texts, currency, audit log, email templates, two factor
- 8a19d00 dashboard, payments, fx, notify, reports, export

## Tests
`tests/unit/I18n` + `tests/feature/Settings/LocaleTest.php`: 25 testov, 5225 assertions OK; `php -l` všetkých 17 súborov čistý.

## Na kontrolu vlastníkom
- Obsah dokladu/e-mailu klientovi: LegalTexts (skupiny, znenia sa berú z DB, tu len štítky), EmailTemplates (štítky a názvy premenných), PasswordReset `mail*`, Notify `body_*`/`subject_*`, Deliveries štítky odoslania.
- Notify `body_totp_reset` v sk „dvojfaktorové“, v cs zjednotené na „dvoufázové“.
- „účetní“ (rod neutrálne) namiesto „účtovníčka“ v LegalTexts/Bundle/FxSettings.

## Kľúče s hodnotou identickou so sk (pre allow-list v 12-12)
- Deliveries: sendMeta, labelTo, labelFrom, attachMeta, historyTo, badgeClient, historyCc, historyDetail, labelMessageId, labelTransport, labelMarkNote, historyNote
- Clients: navTitle, listTitle, newTitle, groupEmails, addressLine1, postalCode, taxId, vatId, typeLocalId, modeDomestic, perHour, errorFormat, filterArchived, badgeArchived
- CreditNotes: labelType, typePlaceholder, typeFxError, cardTitle, labelNumber, labelClient, labelEntity, labelRate, linesTitle, lineMeta, errorType, scopeFull
- Allocations: fieldHours, hoursValue, doneMeta
- Bundle: entityLabel, previewCancelled
- PasswordReset: title, newTitle, password, mailSubject
- LegalTexts: navTitle, title, vatCode, keyVatNote, language, modeLine, profile
- Currency: cardTitle, rateLine, metaFrom, metaTable, confirmRate, confirmRateSource, manualLabel, manualPlaceholder, manualRateLine
- AuditLog: filterEntity, filterFrom, filterTo, colTime, colEntity, detail, pageN, changedCount, userSystem, detailTitle, detailMeta, colField, colAfter, emptyValue, entity_client, entity_entity, entity_payment, entity_payment_void, entity_turnover_limit
- EmailTemplates: kindCreditNote, lang_cs, lang_en, kind, language, sampleValues, var_iban
- TwoFactor: backupPlaceholder
- Dashboard: clientLabel, tileWip, tileWipCurrency, groupInProgress, overdueTitle, actionPayment, limitsTitle, limitsRow, limitsRowMeta
- Payments: labelNote, voidMeta, cardTitle
- FxSettings: fieldSource, countrySK, countryCZ
- Notify: subject_password_reset, labelTime, labelIp, signature
- Reports: entityLabel, entityOption, entityMeta, summaryCounted, colClient, noEntityLink, tabYear
- Export: csv, xlsx, colClient, sheetYear, sheetDocuments, colNumber, colType, colRate, colPaymentStatus, typeCreditNote, filePrefixReport, filePrefixInvoices, filePrefixBundle

## Deviations from Plan
None - plan executed as written.

## Known Stubs
None.

## Self-Check: PASSED
