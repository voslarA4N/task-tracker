---
phase: 03
slug: master-d-ta-subjekty-a-klienti
status: verified
threats_open: 0
asvs_level: 1
created: 2026-10-25
---

# Phase 3 - Security

> Per-phase security contract: threat register, accepted risks, and audit trail.
> Verified against current code (after 03-REVIEW-FIX: WR-01..WR-11, IN-02). Test suite: 651 green.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| browser -> Settings\* controllers | untrusted POST/GET (entities, versions, clients, bank accounts, series) | personal and business data, e-mails, IBAN, rate, number format, portal URL template |
| app (app_rw) -> PostgreSQL | code bug or SQLi must not rewrite history, audit or immutable columns | master data, audit_log, entity_versions |
| migration (app_owner) -> legal_* | source of statutory texts on invoices | legal profiles and texts |
| stored values -> future output (Phase 4/5/7) | portal_url_template, cc_emails, series format, IBAN are rendered later as href, header, file name, invoice | URL, e-mail headers, file name |
| DevSeeder -> DB | must not run in production | sample data |

---

## Threat Register

Evidence is file:line in the current tree. Test column names the covering test.

| Threat ID | Category | Component | Disposition | Mitigation (verified) | Status |
|-----------|----------|-----------|-------------|-----------------------|--------|
| T-03-01 | Repudiation | audit_log | mitigate | No grant: `2026-10-25-000001_CreateAuditLog.php` (comment, no grantMutable/grantUpdateColumns); default privs SELECT,INSERT only (`docker/postgres/provision.sql:36`). Tests: `AuditLoggerTest::testAppRwCannotUpdateOrDelete`, `MasterRolesTest::testAuditLogIsInsertOnly` (UPDATE, DELETE, TRUNCATE) | closed |
| T-03-02 | Repudiation | AuditLogger in transaction | mitigate | `AuditLogger` takes the caller's connection (`AuditLogger.php` ctor, `$this->db->table('audit_log')->insert`); services pass `$this->db` and wrap in `Transaction::run` (`Platform/Transaction.php:30-62`). Tests: `AuditLoggerTest::testRollbackRemovesChangeAndAudit`, `EntityServiceTest::testRollbackWhenAuditFails`, `TransactionTest` | closed |
| T-03-03 | Info disclosure | AuditLogger::scrub | mitigate | `AuditLogger.php` SENSITIVE_PATTERN (password/secret/token segments, code_hash, hash) applied in `scrub()` to before and after in `record()`. Test: `AuditLoggerDiffTest::testScrubRemovesSensitiveKeys` | closed |
| T-03-04 | Tampering | grantUpdateColumns identifiers | mitigate | `GrantsPrivileges.php` regex `/^[a-z_][a-z0-9_]*$/` for table (grantMutable and grantUpdateColumns) and every column, plus `escapeIdentifiers`. Test: `DbRolesTest.php:120` (`'a; drop'` throws InvalidArgumentException) | closed |
| T-03-05 | Tampering | entity_type/action | mitigate | PHP `NAME_PATTERN` `/^[a-z_]{3,40}$/` in `AuditLogger::record`; DB CHECK `^[a-z_]{3,40}$` on both columns (migration 000001). Test: `AuditLoggerTest::testInvalidEntityTypeOrActionRejected` | closed |
| T-03-SC | Tampering | npm/composer installs | accept | See Accepted Risks Log (AR-01) | closed |
| T-03-06 | Tampering | country/currency (ENT-07) | mitigate | Column grant without country/currency (`000002_CreateEntities.php` grantUpdateColumns entities: name_internal, active_from, active_to, updated_at); whitelists `EntityInput::baseUpdateFromPost/versionFromPost` via `pick()`. Tests: `MasterRolesTest::testEntitiesCountryAndCurrencyCannotBeUpdated`, `EntityServiceTest::testColumnGrantsProtectCountryAndCurrency` | closed |
| T-03-07 | Tampering | entity_versions | mitigate | No UPDATE/DELETE grant (migration 000002, comment "Insert-only"). Test: `MasterRolesTest::testEntityVersionsAreInsertOnly` | closed |
| T-03-08 | Tampering | Entities::create CSRF | mitigate | Global `csrf` => `HtmxCsrf` (`Config/Filters.php:83`, `Filters/HtmxCsrf.php` verify, 403 on failure); `csrf_field()` in every POST form (counts match in `Views/settings/**`). Test: `EntitiesCreateTest.php:204` (POST without token => 403) | closed |
| T-03-09 | Tampering/Elevation | XSS via business name / register | mitigate | `esc()` on output (358 uses in `Views/settings`), `esc(..., 'attr')` in attributes (`series_form.php:12-13`, `clients/form.php` helpers); variables echoed raw are `(int)` casts or pre-escaped. Test: `ViewsCspTest` (no inline script/style/on*/javascript:) | closed |
| T-03-10 | Tampering | header injection via entity e-mail | mitigate | `EntityInput.php:249` rejects `[\r\n]` + `FILTER_VALIDATE_EMAIL` + length 254. Test: `EntityInputTest` | closed |
| T-03-11 | Elevation | access to /settings/entities | mitigate | Global `authgate` (`Filters.php:85`); `AuthGate::before` requires group admin, else 403; allowlist has no master route; routes in `Routes/entities.php` carry no exemption. Tests: `AuthGateTest::testEveryRegisteredRouteIsDeniedToAnonymous`, `testAccountantGets403` | closed |
| T-03-12 | Repudiation | entity creation | mitigate | `EntityService.php:59,71,83` audit entity, entity_version, invoice_series inside `Transaction::run` (line 48). Test: `EntityServiceTest::testRollbackWhenAuditFails` | closed |
| T-03-13 | Info disclosure | 404 for unknown id | accept | AR-02 | closed |
| T-03-14 | Tampering/Elevation | XSS via name_internal in list | mitigate | `esc()` in `Views/settings/entities/index.php`; `ViewsCspTest`. Test: `EntitiesListTest` | closed |
| T-03-15 | DoS | N+1 in list | mitigate | single query with `LEFT JOIN LATERAL` (`EntityDirectory.php:39`). Test: `EntityDirectoryTest` | closed |
| T-03-16 | Elevation | access to list | mitigate | global `authgate` (see T-03-11) | closed |
| T-03-17 | Info disclosure | ended entities in select | accept | AR-03 | closed |
| T-03-18 | Tampering | overwrite of version history | mitigate | insert-only (T-03-07); `Routes/entities.php` has only `versions/new`, `versions/notice`, POST `versions` (create). Test: `EntityVersionsTest::testNoEditOrDeleteRoutes` | closed |
| T-03-19 | Tampering | mass assignment in new version | mitigate | `EntityInput::versionFromPost` whitelist; `hasImmutableKeys` => 422 (`EntityVersions.php:89`); country read from DB (`EntityVersions.php:87`). Tests: `EntityImmutableTest::testNewVersionRejectsCurrency`, `EntityVersionsTest::testCountryCurrencyInPostRejected` | closed |
| T-03-20 | Elevation (IDOR) | `?from={versionId}` | mitigate | `EntityVersionResolver::find` uses `WHERE id = :v: AND entity_id = :e:` (line 76); controller 404 (`EntityVersions.php:30-33`, digits only). Test: `EntityVersionsTest::testFromOfOtherEntityIs404` | closed |
| T-03-21 | Tampering | duplicate version date race | mitigate | `UNIQUE (entity_id, valid_from)` (migration 000002); pre-check under row lock plus catch of 23505 outside the transaction mapped to `VersionRejected::duplicateDate` (`EntityService.php:111-118,158-163`) => 422. Test: `EntityVersionsTest::testDuplicateDateReturns422`, `EntityVersionTest` | closed |
| T-03-22 | Repudiation | new version | mitigate | `EntityService.php:155` audit `entity_version/create` with before/after diff in the same transaction. Test: `EntityVersionTest` | closed |
| T-03-23 | Tampering | XSS via version values | mitigate | `esc()` in `Views/settings/entities/*` history; `ViewsCspTest` | closed |
| T-03-24 | Tampering | header injection via cc_emails / invoice_email | mitigate | `CcEmails::parse` splits on `[,;\r\n]`, `validate` rejects control chars, `FILTER_VALIDATE_EMAIL`, max 5 (`CcEmails.php:20,41-52`); `ClientInput.php:197-199` for invoice_email. Tests: `CcEmailsTest::testHeaderInjectionDoesNotSurvive`, `testTooMany` | closed |
| T-03-25 | Tampering/DoS | client deletion | mitigate | no DELETE grant (`000003_CreateClients.php`), no delete route (`Routes/clients.php`). Tests: `MasterRolesTest::testClientsCannotBeDeletedButCanBeUpdated`, `ClientServiceTest::testRwCannotDelete` | closed |
| T-03-26 | Tampering | mass assignment (id, archived_at, created_at) | mitigate | `ClientInput::fromPost` iterates fixed FIELDS; column grant excludes id and created_at; `archived_at` only set by `ClientService::setArchived`. Test: `ClientServiceTest::testRwCannotUpdateCreatedAt` | closed |
| T-03-27 | Tampering | rate as float / rounding | mitigate | Regex `^\d{1,8}([.,]\d{1,2})?$` (`ClientInput.php:209`), kept as string; column now `numeric(12,2)` after IN-02 (`000006_WidenClientsHourlyRate.php`; plan said numeric(10,2), current state is stricter and project-conformant), CHECK `>= 0`. Tests: `ClientServiceTest.php:55` (assertIsString), `MasterRolesTest::testClientsHourlyRateIsMoneyNumericAndStillUpdatable` (precision 12/scale 2, grant survives ALTER) | closed |
| T-03-28 | Tampering/Elevation | XSS via client name/e-mails | mitigate | `esc()` in `Views/settings/clients/*`; no inline JS/eval, `ViewsCspTest`; Alpine CSP build (project stack). Test: `ClientsTest` | closed |
| T-03-29 | Tampering | CSRF on create/update | mitigate | global `HtmxCsrf`; `csrf_field()` in `clients/form.php` (3 forms, 3 fields) | closed |
| T-03-30 | Repudiation | client change | mitigate | `ClientService.php:50` (create), `:85` (`recordChange` of changed fields only), line 120 (archive), all inside `Transaction::run`. Test: `ClientServiceTest::testUpdateAuditsOnlyChangedFields` | closed |
| T-03-31 | Info disclosure | personal data in audit | accept | AR-04 | closed |
| T-03-32 | Tampering | forged country/currency POST | mitigate | `EntityInput::hasImmutableKeys` => 422 in `Entities.php:187,217` and `EntityVersions.php:89`; whitelist `baseUpdateFromPost`; column grant. Tests: `EntityImmutableTest::testUpdateRejectsForgedKey`, `testEndRejectsCountry`, `MasterRolesTest` | closed |
| T-03-33 | Tampering | SQLi/bug overwrites history/audit | mitigate | insert-only grants and no default TRUNCATE for app_rw (`provision.sql:35-36`); `MasterRolesTest` covers entity_versions/audit_log/entities/clients/series. Note: TRUNCATE is asserted only on audit_log here and on probe tables in `DbRolesTest.php:88,103` (default-privilege level), not per master table; acceptable because no grant path exists | closed |
| T-03-34 | Tampering | CSRF on entity end | mitigate | POST route + `csrf_field()` in `entities/_end_modal.php`; global `HtmxCsrf`. Test: `EntityEditTest` (end uses csrf token helper) | closed |
| T-03-35 | Repudiation | entity end/update | mitigate | `EntityService.php:201` (`recordChange` entity/update), `:223` (entity/end), same transaction (`:184`, `:212`) | closed |
| T-03-36 | DoS | ended entity vanishing from select | mitigate | `EntityDirectory::forSelect` has no filter on active_to (`:99-105`, `ended` flag only). Tests: `EntityEditTest.php:140,154`, `EntityDirectoryTest.php:66` | closed |
| T-03-37 | Tampering/Elevation | javascript:/data: in portal_url_template | mitigate | `PortalUrlTemplate::validate` (control/whitespace reject, `{id}` exactly once, scheme http/https with host, max 500; `PortalUrlTemplate.php:20-42`); `render` uses `rawurlencode` (`:46`); preview is text in `<code>` only (`_portal_preview.php`); DB CHECK length 500. Tests: `PortalUrlTemplateTest` (valid/invalid vectors, `testRenderEncodesId`), `ViewsCspTest` (no `javascript:` in views) | closed |
| T-03-38 | Tampering | CSRF on archive/unarchive | mitigate | POST routes (`Routes/clients.php`), `csrf_field()`, global `HtmxCsrf`. Test: `ClientsArchivePortalTest` | closed |
| T-03-39 | Repudiation | client archive | mitigate | `ClientService.php:120` audit `client/archive|unarchive` with real before value (WR-03). Test: `ClientArchiveTest` | closed |
| T-03-40 | DoS | loss of client data | mitigate | archive only sets `archived_at` (`ClientService.php:116-117`); no DELETE grant or route (see T-03-25) | closed |
| T-03-41 | Elevation (IDOR) | archive of foreign id | accept | AR-05 (unknown id => 404 is implemented: `Clients.php:118`) | closed |
| T-03-42 | Elevation (IDOR) | account of another entity | mitigate | every query carries `entity_id` (`BankAccountService.php:118,164,203,229,258`); controller 404 (`BankAccounts.php:166-169`). Tests: `BankAccountsTest::testAccountOfOtherEntityIs404`, `BankAccountTest` | closed |
| T-03-43 | Tampering | account currency change / delete | mitigate | column grant excludes currency, entity_id; no DELETE (`000004_CreateEntityBankAccounts.php`). Tests: `BankAccountTest::testCurrencyUpdateAndDeleteAreDenied`, `BankAccountsTest::testUpdateKeepsCurrencyEvenWhenForged` | closed |
| T-03-44 | Tampering | two default accounts race | mitigate | partial unique index `entity_bank_accounts_one_default` (migration 000004); two-step switch in transaction (`BankAccountService.php:243-258`), entity row locked first (`:216`, WR-02). Tests: `BankAccountTest::testSecondActiveDefaultViolatesUniqueIndex`, `testSetDefaultKeepsExactlyOneAndAudits`, `RowLockTest` | closed |
| T-03-45 | Tampering | invalid IBAN on invoice | mitigate | `Iban::isValid` format + country length + mod-97 (`Iban.php:20-45`); DB CHECK on iban and swift (migration 000004). Test: `IbanTest` | closed |
| T-03-46 | Repudiation | account change | mitigate | `BankAccountService.php:74,86,121,167,261` audit `bank_account/*` in same transaction. Tests: `BankAccountTest::testUpdateAuditsIbanChange`, `testAddWithDefaultAuditsDemotionOfPreviousDefault` | closed |
| T-03-47 | Tampering | CSRF on set_default/archive | mitigate | POST routes, `csrf_field()` in `_bank_accounts.php` (2 forms, 2 fields), global `HtmxCsrf` | closed |
| T-03-48 | Tampering | unsafe characters in number format | mitigate | `SeriesFormat.php` whitelist `[A-Za-z0-9\/_.-]` per literal char, output <= 30 (`MAX_OUTPUT`), DB CHECK length 1..40. Test: `SeriesFormatTest`. Carry-forward: `/` and `..` remain legal, so Phase 5 must sanitize the PDF file name as the plan states | closed |
| T-03-49 | Tampering | invoice / credit-note number collision | mitigate | Current code (post WR-05): `SeriesFormat::mayCollideWith` (conservative per-position shape comparison) used by `SeriesService::collides` under row lock, throws `SeriesRejected('same_as_other')` (`SeriesService.php:104-110,162-170`); falls back to case-insensitive string equality if a stored format no longer parses. Tests: `SeriesFormatTest::testMayCollideWith`, `SeriesServiceTest::testSameFormatAsOtherKindRejected`, `testFormatThatCanRenderSameNumberAsOtherKindRejected`. Limit: DB-level UNIQUE on document numbers belongs to Phase 5 (declared in 03-REVIEW-FIX) | closed |
| T-03-50 | Tampering | format change after issuing documents | mitigate | `SeriesService::update` rejects change of format or initial_number when `isFormatLocked` (`:117-121`, WR-07), under lock of both series rows. Integration point: `isFormatLocked()` returns false in Phase 3 by design (`:178-181`); Phase 5 must supply the real check. Tests: `SeriesServiceTest::testLockedSeriesRejectsFormatAndInitialNumberChange` (subclass), `testNotLockedInPhaseThree` | closed |
| T-03-51 | Elevation | `kind` outside whitelist | mitigate | `SeriesService::KINDS` whitelist in `find()` and `update()`; controller 404 (`Series.php:149-152`). Test: `SeriesTest::testUnknownKindIs404` | closed |
| T-03-52 | Repudiation | series change | mitigate | `SeriesService.php:136` audit `invoice_series/update` in same transaction. Test: `SeriesServiceTest::testUpdateWritesAndAudits` | closed |
| T-03-53 | Tampering | app changes legal texts | mitigate | `REVOKE INSERT ON legal_profiles, legal_texts FROM app_rw` (`000005_CreateLegalProfiles.php:49`), no UPDATE/DELETE grant. Test: `LegalProfileTest::testAppRwIsReadOnly` | closed |
| T-03-54 | Tampering | wrong legal text on invoice | mitigate | `provisional boolean NOT NULL DEFAULT true` (migration 000005), seeded true (`:88`); badge in `_legal_profile.php:21`; resolver throws `LegalTextMissing` instead of empty text (`LegalProfileResolver.php:53,90`); WR-11 pairs exemption code only with its own billing-mode text. Tests: `LegalProfileTest::testTextExactAndProvisional`, `testMissingKeyThrows`, `testMissingProfileThrows`, `testReverseChargeCodeNeverPairsWithGenericExemptionText` | closed |
| T-03-55 | Tampering | DevSeeder in production | mitigate | `DevSeeder::assertAllowed(ENVIRONMENT)` throws in production, called first in `run()` (`DevSeeder.php:23-33`). Test: `DevSeederGuardTest::testRefusesProduction` | closed |
| T-03-56 | Tampering/Elevation | XSS via legal text | mitigate | `esc()` for label, code, body in `_legal_profile.php:10-26`; `ViewsCspTest`. Test: `LegalProfileSectionTest` | closed |
| T-03-57 | Info disclosure | sample data with real details | mitigate | `.example`/`.test` domains (`DevSeeder.php:87,94,127`), public example IBANs (`:59,69,70`); marked "(ukážka)". Note: IČO 12345678, NIP 5260250274 and REGON 123456785 are well-known documentation/test values. Test: `DevSeederTest` | closed |

*Status: open | closed*
*Disposition: mitigate (implementation required) | accept (documented risk) | transfer (third-party)*

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-01 | T-03-SC | Phase 3 installs no npm or composer packages (RESEARCH Package Legitimacy Audit: none); no supply-chain surface introduced. | plan-time decision (03-01-PLAN) | 2026-10-25 |
| AR-02 | T-03-13 | 404 for unknown or foreign id: single owner of all data, `PageNotFoundException` without details. | plan-time decision (03-02-PLAN) | 2026-10-25 |
| AR-03 | T-03-17 | Ended entities stay visible in selects: intentional (ENT-04), single owner. | plan-time decision (03-03-PLAN) | 2026-10-25 |
| AR-04 | T-03-31 | Client personal data in the internal audit log: log is internal, single owner; secrets are never stored (scrub, T-03-03). | plan-time decision (03-05-PLAN) | 2026-10-25 |
| AR-05 | T-03-41 | IDOR on client archive/unarchive: single data owner; unknown id returns 404. | plan-time decision (03-07-PLAN) | 2026-10-25 |

*Accepted risks do not resurface in future audit runs.*

---

## Unregistered Flags

None. All `## Threat Flags` sections in 03-02..03-10 SUMMARY files map to registered threats (03-01 has no such section).

## Carry-forward Notes (non-blocking, not open threats)

- T-03-50: Phase 5 must replace `SeriesService::isFormatLocked()` (currently always false) with a real check for issued documents.
- T-03-48 / T-03-49: Phase 5 must sanitize the PDF file name and add a DB-level UNIQUE on document numbers.
- T-03-33: per-table TRUNCATE denial for master tables is not asserted individually (only audit_log and probe tables); could be added to `MasterRolesTest` as a hardening test.
- Several controllers/transaction paths flagged "requires human verification" in 03-REVIEW-FIX (WR-01, WR-02, WR-05, WR-09, WR-11) are functional logic, not declared mitigations; the mitigations that depend on them (audit atomicity, row locks, collision rule) are covered by `TransactionTest`, `RowLockTest` and `SeriesServiceTest`.

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-25 | 58 | 58 | 0 | gsd-security-auditor (static verification of code and tests; suite reported 651 green, not re-run) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-10-25
