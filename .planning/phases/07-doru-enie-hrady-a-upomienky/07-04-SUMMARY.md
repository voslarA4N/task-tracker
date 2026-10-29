---
phase: 07-doru-enie-hrady-a-upomienky
plan: 04
subsystem: delivery
tags: [invoice-sends, insert-only, mail, idempotency, postgres-view]
requires: [07-01, 07-02]
provides:
  - invoice_sends and invoice_send_voids insert-only tables (immutable_guard and truncate_guard)
  - invoice_status_v extended with sent_at, send_count, last_reminder_at (status sent derived, D-29)
  - InvoiceMailer draft/send/history, SenderIdentity, TemplateContext, SendCommand/SendResult/SendRejected
affects: [07-05, 07-09, 07-11]
tech-stack:
  added: []
  patterns: [SMTP outside transaction, record after success, request_key unique violation maps to replay, derived status via view]
key-files:
  created:
    - app/Database/Migrations/2026-10-29-000002_CreateInvoiceSends.php
    - app/Libraries/Delivery/InvoiceMailer.php
    - app/Libraries/Delivery/SenderIdentity.php
    - app/Libraries/Delivery/SenderRejected.php
    - app/Libraries/Delivery/TemplateContext.php
    - app/Libraries/Delivery/SendCommand.php
    - app/Libraries/Delivery/SendResult.php
    - app/Libraries/Delivery/SendRejected.php
    - tests/_support/DeliveryFixtures.php
    - tests/integration/Delivery/DeliveryRolesTest.php
    - tests/integration/Delivery/InvoiceStatusViewColumnsTest.php
    - tests/integration/Delivery/InvoiceMailerTest.php
    - tests/integration/Delivery/InvoiceMailerFailureTest.php
    - tests/integration/Delivery/InvoiceMailerIdempotencyTest.php
    - tests/unit/Delivery/SenderIdentityTest.php
  modified:
    - tests/_support/MasterFixtures.php
    - app/Libraries/Invoicing/DocumentStore.php
key-decisions:
  - "counts_as_sent computed and stored at write time (kind invoice/resend and To/CC intersects client invoice_email + cc_emails, case-insensitive)"
  - "Only body_sha256 stored, never the body"
  - "Manual mark is kind manual with transport none; void is a separate insert-only row"
requirements-completed: [MAIL-01, INV-06]
duration: 75min
completed: 2026-10-29
---

# Phase 7 Plan 04: Invoice sends, derived Sent status, InvoiceMailer Summary

InvoiceMailer sends the exact stored PDF bytes of invoice and report (from invoice_documents, never re-rendered) using the template in the document language, runs SMTP outside any transaction and writes invoice_sends plus audit only after success; a repeated request_key sends nothing and returns the previous result.

## Tasks

1. Insert-only invoice_sends / invoice_send_voids, invoice_status_v extension (16 original columns kept, 3 appended), DeliveryRolesTest and InvoiceStatusViewColumnsTest - 48cc4e9
2. InvoiceMailer, SenderIdentity (From domain allowlist), TemplateContext, DTOs, tests for bytes equality, SMTP failure, idempotency, unique-violation replay, record_failed, log transport, validation - 830af7c
3. php-cs-fixer pass on a test file - 1c43e54

## Verification

Full suite (vendor/bin/phpunit): 1425 tests, 22681 assertions, OK. composer stan clean, composer cs clean.

## Deviations from Plan

**1. [Rule 3 - Blocking] View migration merged into CreateInvoiceSends**
- **Found during:** Task 1 (`spark migrate` ran only 2026-10-29-000003 and failed with "relation invoices does not exist").
- **Issue:** In this dev environment (WSL2 9p bind mount + Alpine musl) `RecursiveDirectoryIterator`/`get_filenames` returns a single entry once `app/Database/Migrations` holds more than 30 files, so CI4 sees only the newest migration. Reproduced with a trivial extra file. Not a code defect and not present on a Linux production host.
- **Fix:** The invoice_status_v extension lives in `2026-10-29-000002_CreateInvoiceSends.php` (public methods `extendStatusView()` and `restoreOriginalStatusView()`, used by up/down). The planned separate file `2026-10-29-000003_ExtendInvoiceStatusViewSends.php` does not exist.
- **Impact on later plans:** the migrations directory is now at the limit (30 files). Any further migration file will break `spark migrate` in this dev/test environment until the listing problem is solved (for example by moving to a named volume or a native ext4 checkout, or by consolidating migrations). Later plans in this phase that add migrations must account for this.
- **Files modified:** app/Database/Migrations/2026-10-29-000002_CreateInvoiceSends.php, tests/integration/Delivery/InvoiceStatusViewColumnsTest.php
- **Commit:** 48cc4e9

**2. [Rule 3 - Blocking] DocumentStore no longer final**
- **Issue:** the plan requires a test double subclass of DocumentStore that throws DocumentIntegrityError; the class was final.
- **Fix:** removed `final`.
- **Commit:** 830af7c

**3. [Rule 3 - Blocking] Added tests/_support/DeliveryFixtures.php**
- Shared fixtures for the three mailer test classes (not listed in plan files).

Acceptance greps for the second migration file (`CREATE OR REPLACE VIEW`, no CURRENT_DATE/now) are satisfied in the merged file.

## Known Stubs

None. `balance` placeholder equals `total` by plan (07-09 replaces it with the outstanding balance).

## Threat Flags

None beyond the plan threat model.

## Self-Check: PASSED

Files and commits 48cc4e9, 830af7c, 1c43e54 exist.
