---
phase: 8
slug: meny-a-kurzy
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-10-30
---

# Phase 8 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5 + `CIUnitTestCase` / `Tests\Support\PgTestCase` |
| **Config file** | `phpunit.xml.dist` (suites `unit`, `integration`; skupina `integration` na PG) |
| **Quick run command** | `docker exec task-tracker-php-1 vendor/bin/phpunit --testsuite unit --filter Fx` |
| **Full suite command** | `docker exec task-tracker-php-1 composer test` |
| **Estimated runtime** | ~60 seconds |

Poznámka: testy len v kontajneri (hostiteľské PHP 8.3 nemá `bcround`). PHPUnit nikdy nevolá živé API kurzov.

---

## Sampling Rate

- **After every task commit:** Run `docker exec task-tracker-php-1 vendor/bin/phpunit --testsuite unit --filter Fx`
- **After every plan wave:** Run `docker exec task-tracker-php-1 composer test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

Každá úloha má `<automated>` príkaz (všetky úlohy sú `tdd="true"`: test sa vytvorí v RED kroku tej istej úlohy, preto žiadna úloha neodkazuje na chýbajúci test). Poradie riadkov = poradie vĺn.

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | Test File (vytvorí úloha, TDD RED) | Status |
|---------|------|------|-------------|-----------|-------------------|------------------------------------|--------|
| 08-01-T1 | 08-01 | 1 | CUR-01 | integration | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'CurrenciesSchemaTest|CurrencyRepositoryTest|DbRolesTest'` | tests/integration/Master/CurrenciesSchemaTest.php, CurrencyRepositoryTest.php | ⬜ pending |
| 08-01-T2 | 08-01 | 1 | CUR-01 | feature | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'CurrencyListTest|Client|Entit|BankAccount|ViewsCspTest|ViewsHardcodedTextTest'` | tests/feature/Master/CurrencyListTest.php | ⬜ pending |
| 08-02-T1 | 08-02 | 1 | CUR-02, CUR-05 | unit | `docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'FxConverterTest|FxBoundDateTest'` | tests/unit/Fx/FxConverterTest.php, FxBoundDateTest.php | ⬜ pending |
| 08-02-T2 | 08-02 | 1 | CUR-02 | unit | `docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'FxQuoteTest|FxFormatTest|FxConverterTest|FxBoundDateTest'` | tests/unit/Fx/FxQuoteTest.php, FxFormatTest.php | ⬜ pending |
| 08-03-T1 | 08-03 | 2 | CUR-02, CUR-03 | unit | `docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'NbpRateProviderTest'` | tests/unit/Fx/NbpRateProviderTest.php | ⬜ pending |
| 08-03-T2 | 08-03 | 2 | CUR-02, CUR-03 | unit | `docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'EcbRateProviderTest|CnbRateProviderTest|NbpRateProviderTest'` | tests/unit/Fx/EcbRateProviderTest.php, CnbRateProviderTest.php | ⬜ pending |
| 08-04-T1 | 08-04 | 3 | CUR-05 | integration | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'FxSchemaTest|DbRolesTest|CurrenciesSchemaTest'` | tests/integration/Fx/FxSchemaTest.php | ⬜ pending |
| 08-04-T2 | 08-04 | 3 | CUR-02, CUR-03, CUR-05 | integration | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'FxServiceCacheTest|FxSchemaTest'` | tests/integration/Fx/FxServiceCacheTest.php | ⬜ pending |
| 08-05-T1 | 08-05 | 4 | CUR-02 | integration | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'SnapshotBuilderFxTest|SnapshotBuilderTest|SampleSnapshotShapeTest|LangKeysTest|PreviewTokenScopeTest|DocumentsSamplesCommandTest|InvoiceIssuerTest'` | tests/integration/Invoicing/SnapshotBuilderFxTest.php | ⬜ pending |
| 08-05-T2 | 08-05 | 4 | CUR-02 | integration (+ human-check PDF) | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'MpdfInvoiceInv2RendererTest|LegacyInv1UnchangedTest|InvoiceDocumentTest'` | MpdfInvoiceInv2RendererTest.php (rozšíriť) | ⬜ pending |
| 08-08-T1 | 08-08 | 4 | CUR-05 | integration | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'FxSettingsServiceTest|FxSchemaTest'` | tests/integration/Fx/FxSettingsServiceTest.php | ⬜ pending |
| 08-08-T2 | 08-08 | 4 | CUR-05 | feature | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'FxSettingsTest|FxSettingsServiceTest|LegalTextsControllerTest|ViewsCspTest|ViewsHardcodedTextTest|LangKeysTest|LayoutTest'` | tests/feature/Settings/FxSettingsTest.php | ⬜ pending |
| 08-10-T1 | 08-10 | 5 | CUR-02, CUR-03 | integration | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'CurrencyGuardTest|InvoiceSchemaTest|InvoiceImmutabilityTest'` | CurrencyGuardTest.php (prepísať) | ⬜ pending |
| 08-10-T2 | 08-10 | 5 | CUR-02, CUR-03 | integration | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'InvoiceIssuerFxTest|CurrencyGuardTest|InvoiceIssuerTest|IssueRollbackTest|IssueIdempotencyTest|InvoiceSchemaTest|InvoiceImmutabilityTest'` | tests/integration/Invoicing/InvoiceIssuerFxTest.php | ⬜ pending |
| 08-06-T1 | 08-06 | 6 | CUR-02, CUR-03, CUR-05 | integration | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'InvoiceDraftFxTest|InvoiceDraftServiceTest|PreviewTokenScopeTest|CurrencyGuardTest|InvoiceIssuerFxTest|StagingSeed'` | tests/integration/Invoicing/InvoiceDraftFxTest.php | ⬜ pending |
| 08-06-T2 | 08-06 | 6 | CUR-02, CUR-03 | feature (+ human-check iPhone) | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'InvoicingFxPreviewTest|InvoicingPreviewTest|InvoicingIssueTest|InvoicingScreenTest|ViewsCspTest|ViewsHardcodedTextTest|LangKeysTest'` | tests/feature/Invoicing/InvoicingFxPreviewTest.php | ⬜ pending |
| 08-07-T1 | 08-07 | 7 | CUR-02, CUR-03 | unit + integration | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'FxInputTest|PreviewInputTest|IssueInputTest|InvoiceManualFxTest|InvoiceDraftFxTest|InvoiceIssuerFxTest'` | tests/unit/Fx/FxInputTest.php, tests/integration/Invoicing/InvoiceManualFxTest.php | ⬜ pending |
| 08-07-T2 | 08-07 | 7 | CUR-02, CUR-03 | feature (+ human-check iPhone) | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'FxManualEntryTest|InvoicingFxPreviewTest|InvoicingPreviewTest|InvoicingIssueTest|ViewsCspTest|ViewsHardcodedTextTest|LangKeysTest'` | tests/feature/Invoicing/FxManualEntryTest.php | ⬜ pending |
| 08-09-T1 | 08-09 | 8 | CUR-02, CUR-03 | feature + integration | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'InvoiceFxDisplayTest|InvoiceFxImmutableTest|InvoiceDetailTest|InvoicesListTest|InvoicesListStatusTest|InvoiceQueriesTest|ViewsCspTest|ViewsHardcodedTextTest|LangKeysTest'` | tests/feature/Invoicing/InvoiceFxDisplayTest.php, tests/integration/Invoicing/InvoiceFxImmutableTest.php | ⬜ pending |
| 08-09-T2 | 08-09 | 8 | CUR-02 | integration (+ human-check staging) | `make test-db && docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'StagingSeederTest|HistoryPlannerTest'` | StagingSeederTest.php (rozšíriť) | ⬜ pending |

Mapa požiadaviek na testy (pôvodná z 08-RESEARCH.md) je pokrytá riadkami vyššie: CUR-01 → 08-01; CUR-02 → 08-02..08-07, 08-09, 08-10; CUR-03 → 08-03, 08-04, 08-06, 08-07, 08-09, 08-10; CUR-05 → 08-02, 08-04, 08-06, 08-08.

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/_support/FakeRateHttpClient.php` + `tests/_support/fixtures/fx/*` (NBP rozsah, NBP 400, ČNB daily JSON aj TXT, ECB csv, ECB prázdne) → 08-03 Task 1/2
- [ ] `tests/_support/FxFixtures.php` (PL subjekt PLN + klient EUR + bankový účet EUR, `seedRate`, `restoreFxSettings`) → 08-04 Task 1
- [ ] `MasterFixtures::resetMasterTables` rozšírené o `exchange_rate_cache` (NIE `fx_country_settings` ani `currencies`: seed tabuľky sa neresetujú, nastavenia vracia `restoreFxSettings`) → 08-04 Task 1

Wave 0 podporné súbory vznikajú v plánoch 08-03 a 08-04 pred prvým testom, ktorý ich potrebuje (08-04 Task 2 a neskôr). `wave_0_complete` sa nastaví na true po vykonaní 08-04.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Živé API NBP/ECB/ČNB vrátia kurz v náhľade | CUR-02 | PHPUnit nikdy nevolá živé API | Na staging: PL subjekt + EUR klient, otvoriť náhľad, overiť kurz NBP a tabuľku; to isté pre SK (ECB) a CZ (ČNB) |
| Vzhľad riadku kurzu na PDF | CUR-02 | vizuálna kontrola | Vystaviť na staging, otvoriť PDF, porovnať s ukážkou v 08-CONTEXT D-15 |
| Ovládanie na iPhone (44 px, „Zadať ručne“) | CUR-03 | dotykové UX | Na iPhone PWA otvoriť náhľad, prepnúť na ručný kurz a vystaviť |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 60s (jednotlivé `--filter` príkazy; `make test-db` iba pri nových migráciách)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
