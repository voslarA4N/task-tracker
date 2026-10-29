---
phase: 8
slug: meny-a-kurzy
status: draft
nyquist_compliant: false
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

Vyplní plánovač podľa úloh. Mapa požiadaviek na testy (z 08-RESEARCH.md §Validation Architecture):

| Requirement | Správanie | Test Type | Súbor | File Exists | Status |
|-------------|-----------|-----------|-------|-------------|--------|
| CUR-01 | tabuľka `currencies` (EUR/PLN/CZK), FK odmietne neznámu menu, nová mena = INSERT, `Currency` enum odstránený | integration | `tests/integration/Master/CurrenciesSchemaTest.php`, `CurrencyRepositoryTest.php` | ❌ W0 | ⬜ pending |
| CUR-01 | formuláre klienta/subjektu/účtu čítajú zoznam mien z DB | feature | `tests/feature/Master/CurrencyListTest.php` | ❌ W0 | ⬜ pending |
| CUR-02 | `FxConverter` smery, `amount`, zaokrúhlenie | unit | `tests/unit/Fx/FxConverterTest.php` | ❌ W0 | ⬜ pending |
| CUR-02 | NBP/ECB/ČNB providery na fixtúrach (URL, posledný záznam, sviatky, prázdny rozsah, kurz nie je float) | unit | `tests/unit/Fx/{Nbp,Ecb,Cnb}RateProviderTest.php` | ❌ W0 | ⬜ pending |
| CUR-02 | výpočet `bound_date` podľa `date_basis` a `day_rule` | unit | `tests/unit/Fx/FxBoundDateTest.php` | ❌ W0 | ⬜ pending |
| CUR-02 | `FxService` cache: hit bez HTTP, miss → insert, finálny riadok sa neprepisuje | integration | `tests/integration/Fx/FxServiceCacheTest.php` | ❌ W0 | ⬜ pending |
| CUR-02 | náhľad s kurzom, token sa mení pri zmene kurzu/dátumu, ručný prepis | integration | `tests/integration/Invoicing/InvoiceDraftFxTest.php`, `PreviewTokenScopeTest` | ❌ W0 | ⬜ pending |
| CUR-02 | vystavenie: `fx_*`, `total_entity_ccy`, snapshot `fx`, audit, 0 HTTP volaní; rovnaká mena → `fx` NULL | integration | `tests/integration/Invoicing/InvoiceIssuerFxTest.php`, prepísaný `CurrencyGuardTest` | ❌ W0 | ⬜ pending |
| CUR-02 | PDF obsahuje obe sumy a riadky kurzu (SK/PL/CS/EN), text ručného kurzu | integration | `MpdfInvoiceInv2RendererTest` | ✅ (rozšíriť) | ⬜ pending |
| CUR-03 | API zlyhá → ručné pole, vystavenie s ručným kurzom; bez kurzu `fx_missing` | integration/feature | `InvoiceDraftFxTest`, `tests/feature/Invoicing/FxManualEntryTest.php` | ❌ W0 | ⬜ pending |
| CUR-03 | validácia ručného kurzu (nula, záporný, 9 des., text, exponent) | unit | `tests/unit/Fx/FxInputTest.php` | ❌ W0 | ⬜ pending |
| CUR-03 | nemennosť: zmena cache a nastavenia krajiny nezmení vystavenú faktúru ani PDF (hash) | integration | `tests/integration/Invoicing/InvoiceFxImmutableTest.php` | ❌ W0 | ⬜ pending |
| CUR-05 | nastavenie krajiny: defaulty, zmena + audit + revision, validácia, owner-only | integration+feature | `FxSettingsServiceTest.php`, `tests/feature/Settings/FxSettingsTest.php` | ❌ W0 | ⬜ pending |
| CUR-05 | `date_basis = issue_date` mení `bound_date` a volanú URL | unit/integration | `FxBoundDateTest`, `InvoiceDraftFxTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/_support/FakeRateHttpClient.php` + `tests/_support/fixtures/fx/*` (NBP rozsah, NBP 400, ČNB daily, ECB csv, ECB prázdne)
- [ ] `tests/_support/FxFixtures.php` (PL subjekt PLN + klient EUR + bankový účet EUR, seed `fx_country_settings` a cache)
- [ ] `MasterFixtures::resetMasterTables` rozšírené o `exchange_rate_cache`, `fx_country_settings`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Živé API NBP/ECB/ČNB vrátia kurz v náhľade | CUR-02 | PHPUnit nikdy nevolá živé API | Na staging: PL subjekt + EUR klient, otvoriť náhľad, overiť kurz NBP a tabuľku; to isté pre SK (ECB) a CZ (ČNB) |
| Vzhľad riadku kurzu na PDF | CUR-02 | vizuálna kontrola | Vystaviť na staging, otvoriť PDF, porovnať s ukážkou v 08-CONTEXT D-15 |
| Ovládanie na iPhone (44 px, „Zadať ručne“) | CUR-03 | dotykové UX | Na iPhone PWA otvoriť náhľad, prepnúť na ručný kurz a vystaviť |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
