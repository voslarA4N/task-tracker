---
phase: 6
slug: dokumenty-fakt-ry-a-v-kazu
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-10-27
---

# Phase 6 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5 + CI4 `CIUnitTestCase` (`failOnDeprecation/Notice/Warning/Risky`); node --test (tests/js) |
| **Config file** | `phpunit.dist.xml` (suites `unit`, `integration`, `feature`) |
| **Quick run command** | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter <Trieda>` |
| **Full suite command** | `make test` (potom `composer stan`, `composer cs`, `node --test tests/js/`) |
| **Performance group** | `IssueTimingTest` je v `#[Group('performance')]`, ktorú `phpunit.dist.xml` vylučuje z predvolenej `make test`; spúšťa sa explicitne `docker compose exec -T -u www-data php vendor/bin/phpunit --group performance` (06-09 Task 2 a pred `/gsd-verify-work`) |
| **Estimated runtime** | ~6 s quick filter, full suite niekoľko minút (reálne mPDF testy) |

---

## Sampling Rate

- **After every task commit:** `phpunit --filter <dotknuté triedy>` (príkaz z `<verify>` úlohy) + `composer stan`
- **After every plan wave:** `make test` (po migrácii najprv `make test-db`)
- **Before `/gsd-verify-work`:** celá sada zelená + `composer stan` + `composer cs` + `node --test tests/js/`, plus `vendor/bin/phpunit --group performance` (IssueTimingTest) a ručný UAT z 06-09
- **Max feedback latency:** quick filter do ~10 s

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 6-01-01 | 01 | 1 | INV-05 | T-06-04 | snapshot schema 2 nesie všetko, čo PDF tlačí; token ignoruje QR | unit + integration | `phpunit --filter 'LegalProfilePrintTest\|SnapshotBuilderTest\|PreviewTokenScopeTest\|LangKeysTest'` | ❌ W0 (LegalProfilePrintTest), ✅ rozšíriť ostatné | ⬜ pending |
| 6-01-02 | 01 | 1 | INV-05 | T-06-01 | `esc()` všetkých hodnôt, HTML injection ako text, inv-1 nezmenené | integration + unit | `phpunit --filter 'MpdfInvoiceInv2RendererTest\|RendererRegistryTest\|MpdfInvoiceRendererTest\|ViewsCspTest'` | ❌ W0 | ⬜ pending |
| 6-01-03 | 01 | 1 | INV-05 | T-06-02, T-06-03 | renderer s inou verziou = rollback; staré inv-1 bajty a súbory nezmenené | integration (celá suita) | `make test` | ❌ W0 (LegacyInv1UnchangedTest) | ⬜ pending |
| 6-02-01 | 02 | 2 | REP-01, REP-02 | T-06-08 | odkaz na portál len http(s), „16 z 40 h“ zo snapshotu, fixtúry v tvare ostrého snapshotu | unit + integration | `phpunit --filter 'ReportRowFormatTest\|SnapshotBuilderTest\|PreviewTokenScopeTest\|LangKeysTest'` | ❌ W0 (ReportRowFormatTest) | ⬜ pending |
| 6-02-02 | 02 | 2 | REP-01, REP-02 | T-06-08 | `esc()` v šablóne, súčty len zo snapshotu, bez lang()/DB v šablóne | integration + unit | `phpunit --filter 'RendererRegistryTest\|MpdfReportRendererTest\|MpdfInvoiceInv2RendererTest\|ViewsCspTest' && make test` | ❌ W0 (MpdfReportRendererTest) | ⬜ pending |
| 6-03-01 | 03 | 3 | REP-01, REP-02 | T-06-06, T-06-07 | DB vynúti report pre schema >= 2; report insert-only; rollback pri zlyhaní; bajty a sha256 oboch PDF nezmenené po prepise prekladu (D-24) | integration | `make test-db && phpunit --filter 'ReportDocumentTest\|ReportTotalsMatchTest\|IssueRollbackTest\|InvoiceImmutabilityTest\|InvoiceImmutableAfterChangeTest\|InvoiceIssuerTest\|IssueIdempotencyTest\|InvoicingIssueTest'` | ❌ W0 (ReportDocumentTest, ReportTotalsMatchTest, tests/_support/OverridingLanguage.php) | ⬜ pending |
| 6-03-02 | 03 | 3 | REP-01 | T-06-09, T-06-10, T-06-11 | uložené bajty, `no-store`, `nosniff`, sanitizovaný filename, bez regenerácie, opätovné stiahnutie po prepise prekladu = rovnaké bajty | feature | `phpunit --filter 'InvoicePdfEndpointTest\|InvoiceDetailTest\|InvoiceQueriesTest\|LangKeysTest\|ViewsCspTest\|ViewsHardcodedTextTest' && make test` | ✅ rozšíriť | ⬜ pending |
| 6-04-01 | 04 | 4 | INV-12 | T-06-12, T-06-13, T-06-15 | whitelist formy, <= 500 znakov, poznámka escapovaná v PDF | unit + integration | `phpunit --filter 'IssueInputTest\|PaymentOptionsTest\|SnapshotBuilderTest\|PreviewTokenScopeTest\|LegalProfileTest\|InvoiceIssuerTest'` | ❌ W0 (PaymentOptionsTest) | ⬜ pending |
| 6-04-02 | 04 | 4 | INV-12 | T-06-14, T-06-16 | 422 bez zápisu, CSRF, CSP bez inline | feature + js | `phpunit --filter 'PreviewInputTest\|InvoicingIssueTest\|InvoicingPreviewTest\|InvoicingScreenTest\|LangKeysTest\|ViewsCspTest\|ViewsHardcodedTextTest' && node --test tests/js/` | ❌ W0 (tests/js/inv-pay.test.js) | ⬜ pending |
| 6-05-01 | 05 | 5 | INV-11 | T-06-17, T-06-18 | proc_open bez shellu, žiadna externá služba, round-trip dekódovanie | unit + integration | `phpunit --filter 'PayBySquareTest\|QrPaymentBuilderTest\|XzBinaryLzmaTest'` | ❌ W0 (+ tests/_support/PayBySquareDecoder.php) | ⬜ pending |
| 6-05-02 | 05 | 5 | INV-11 | T-06-19, T-06-20, T-06-21 | QR zo snapshotu po čísle, zlyhanie = rollback, xz v obraze | integration + feature | `phpunit --filter 'MpdfInvoiceInv2RendererTest\|SnapshotBuilderTest\|IssueRollbackTest\|PreviewTokenScopeTest\|InvoicingIssueTest\|LangKeysTest\|ViewsCspTest'` | ✅ rozšíriť | ⬜ pending |
| 6-06-01 | 06 | 6 | INV-11 | T-06-22, T-06-23, T-06-24 | escapovanie `*` a `|`, ZBP len PL+PLN+PL IBAN, bez float | unit | `phpunit --filter 'SpaydTest\|PolishZbpQrTest\|QrPaymentBuilderTest\|PayBySquareTest'` | ❌ W0 | ⬜ pending |
| 6-06-02 | 06 | 6 | INV-11 | T-06-25 | chýbajúci NIP = qr_failed; číslo > 32 znakov = bez QR ZBP (spresnenie D-19), nikdy ticho | integration | `phpunit --filter 'MpdfInvoiceInv2RendererTest\|QrIssueTest\|IssueRollbackTest'` | ❌ W0 (QrIssueTest) | ⬜ pending |
| 6-06-03 | 06 | 6 | INV-11 | T-06-25 | neblokujúce upozornenie v náhľade pri čísle > 32 znakov pre ZBP, náhľad nič nezapisuje | feature | `phpunit --filter 'InvoicingPreviewTest\|InvoicingScreenTest\|LangKeysTest\|ViewsCspTest\|ViewsHardcodedTextTest' && make test` | ✅ rozšíriť | ⬜ pending |
| 6-07-01 | 07 | 7 | SET-01 | T-06-26, T-06-27, T-06-28, T-06-30 | stĺpcový UPDATE bez DELETE, audit pred/po, revízia, nemennosť vystavených; SampleSnapshots s revision (tvar = resolver) | integration | `make test-db && phpunit --filter 'MasterRolesTest\|LegalProfileTest\|LegalTextServiceTest\|LegalTextVersioningTest\|DbRolesTest\|SnapshotBuilderTest'` | ❌ W0 (LegalTextServiceTest, LegalTextVersioningTest) | ⬜ pending |
| 6-07-02 | 07 | 7 | SET-01 | T-06-29, T-06-31, T-06-32 | CSRF, auth, 409 pri súbehu, esc() | feature | `phpunit --filter 'LegalTextsControllerTest\|LangKeysTest\|ViewsCspTest\|ViewsHardcodedTextTest'` | ❌ W0 (LegalTextsControllerTest) | ⬜ pending |
| 6-08-01 | 08 | 8 | SET-01 | T-06-33, T-06-34, T-06-35 | whitelist variantu, WR-11 bez regresie, audit create; SampleSnapshots s billing_mode (tvar = resolver) | integration | `phpunit --filter 'LegalProfileTest\|LegalTextServiceTest\|LegalTextVersioningTest\|SnapshotBuilderTest\|InvoiceIssuerTest'` | ✅ (z 06-07) | ⬜ pending |
| 6-08-02 | 08 | 8 | SET-01 | T-06-36, T-06-37 | reflektované query hodnoty escapované, CSRF | feature + suita | `phpunit --filter 'LegalTextsControllerTest\|LangKeysTest\|ViewsCspTest\|ViewsHardcodedTextTest' && make test` | ✅ (z 06-07) | ⬜ pending |
| 6-09-01 | 09 | 9 | INV-05, INV-11, REP-01 | T-06-38, T-06-39, T-06-40 | vzory bez DB zápisu, štítok VZOR, bez path traversal | integration | `phpunit --filter 'DocumentsSamplesCommandTest\|SampleSnapshotShapeTest\|MpdfInvoiceInv2RendererTest\|MpdfReportRendererTest'` | ❌ W0 | ⬜ pending |
| 6-09-02 | 09 | 9 | REP-02, NFR-01 | T-06-42 | každé PDF < 2 s, peak < 128 MB (skupina `performance`, mimo `make test`) | integration (performance) + suita | `phpunit --group performance && make test` | ❌ W0 (IssueTimingTest) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Testy sa v tejto fáze tvoria v tej istej úlohe ako kód (`tdd="true"`, najprv test, potom implementácia), samostatná Wave 0 nie je. Nové testovacie súbory a pomocníci podľa úlohy:

- [ ] `tests/unit/Invoicing/LegalProfilePrintTest.php` (6-01-01) — pravidlá tlače pre každý profil
- [ ] `tests/_support/PdfText.php` (6-01-02) — pdftotext wrapper
- [ ] `app/Libraries/Invoicing/Samples/SampleSnapshots.php` + `SnapshotSample::issuedV2()` (6-01-02) — snapshoty schema 2 pre každý profil a jazyk
- [ ] `tests/integration/Invoicing/MpdfInvoiceInv2RendererTest.php`, `tests/unit/Invoicing/RendererRegistryTest.php` (6-01-02) — vrátane diakritiky a escapovania
- [ ] `tests/integration/Invoicing/LegacyInv1UnchangedTest.php` (6-01-03); úprava `FakePdfRenderer`, `IssueFixtures`, `issue_worker.php` na registry
- [ ] `tests/unit/Invoicing/ReportRowFormatTest.php` (6-02-01), `tests/integration/Invoicing/MpdfReportRendererTest.php` (6-02-02)
- [ ] `tests/integration/Invoicing/ReportDocumentTest.php`, `ReportTotalsMatchTest.php`, `tests/_support/OverridingLanguage.php` (6-03-01)
- [ ] `tests/integration/Invoicing/PaymentOptionsTest.php` (6-04-01), `tests/js/inv-pay.test.js` (6-04-02)
- [ ] `tests/_support/PayBySquareDecoder.php`, `tests/unit/Invoicing/Qr/PayBySquareTest.php`, `QrPaymentBuilderTest.php`, `tests/integration/Invoicing/Qr/XzBinaryLzmaTest.php` (6-05-01)
- [ ] `tests/unit/Invoicing/Qr/SpaydTest.php`, `PolishZbpQrTest.php` (6-06-01), `tests/integration/Invoicing/QrIssueTest.php` (6-06-02)
- [ ] `tests/integration/Master/LegalTextServiceTest.php`, `tests/integration/Invoicing/LegalTextVersioningTest.php` (6-07-01), `tests/feature/Settings/LegalTextsControllerTest.php` (6-07-02)
- [ ] `tests/integration/Invoicing/DocumentsSamplesCommandTest.php`, `SampleSnapshotShapeTest.php` (6-09-01), `IssueTimingTest.php` (6-09-02, skupina `performance`)
- Framework install: žiadny (existujúca infraštruktúra)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| QR kódy sa naskenujú reálnymi bankovými aplikáciami (SK PAY by square, CZ SPAYD, PL ZBP) | INV-11 | bez oficiálnych testovacích vektorov a reálnej aplikácie (výskum A1, A2, A4, A9) | 06-09 Task 2 `<human-check>` body 1-3 |
| Vizuálna kontrola a tlač vzorových PDF na A4, potvrdenie znení účtovníčkou | INV-05, REP-01 | právny obsah je predbežný (R1), vzhľad posudzuje človek | 06-09 Task 2 `<human-check>` bod 4, staging runbook |
| PDF faktúry a výkazu sa otvoria v novej karte v inštalovanej PWA na iPhone | INV-05, REP-01 (D-21) | iOS standalone správanie sa nedá automatizovať | 06-09 Task 2 `<human-check>` bod 5 |
| Úprava znenia na stagingu sa prejaví len na novej faktúre | SET-01 | potvrdenie end-to-end na reálnom prostredí (automaticky pokryté LegalTextVersioningTest) | 06-09 Task 2 `<human-check>` bod 6 |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references (testy vznikajú v úlohe, `tdd="true"`)
- [x] No watch-mode flags
- [ ] Feedback latency < 10s (overí sa počas vykonania; reálne mPDF testy sú pomalšie)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
