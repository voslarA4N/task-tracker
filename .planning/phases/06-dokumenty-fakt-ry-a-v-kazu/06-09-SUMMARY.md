---
phase: 06-dokumenty-fakt-ry-a-v-kazu
plan: 09
subsystem: documents
tags: [samples, pdf, performance, staging, qr]
requires: [06-06, 06-08]
provides:
  - spark documents:samples (sample invoice and report PDFs per profile and language, labelled VZOR, DB read-only)
  - SampleSnapshots with sample flag and optional LegalProfileResolver texts
  - IssueTimingTest (performance group)
  - staging runbook section 16 (phase 6 deploy, samples, accountant review, QR UAT)
affects: []
key-files:
  created:
    - app/Commands/DocumentsSamples.php
    - tests/integration/Invoicing/DocumentsSamplesCommandTest.php
    - tests/integration/Invoicing/SampleSnapshotShapeTest.php
    - tests/integration/Invoicing/IssueTimingTest.php
  modified:
    - app/Libraries/Invoicing/Samples/SampleSnapshots.php
    - app/Libraries/Invoicing/MpdfInvoiceInv2Renderer.php
    - app/Libraries/Invoicing/MpdfReportRenderer.php
    - phpunit.dist.xml
    - docs/ops/staging.md
key-decisions:
  - "Seed branch of SampleSnapshots also emits legal.texts.invoice_note = null so the key set equals SnapshotBuilder output"
  - "VZOR header is a constant (not translated), rendered through SetHTMLHeader only when snapshot.sample === true"
  - "Command falls back to seed wording with a warning when DB or a wording is unavailable"
  - "hrtime in IssueTimingTest uses phpstan-ignore app.forbiddenTime (measurement, not business time)"
requirements-completed: [INV-05, INV-11, REP-01, REP-02]
completed: 2026-10-28
---

# Phase 6 Plan 09: Samples command, timing and staging runbook Summary

`spark documents:samples` writes VZOR-labelled invoice and report PDFs for all five legal profiles (profile language plus `en`, 18 files) using current legal wording from the DB without any write, shape tests prove samples match issued snapshots, and issuing with both real PDFs is measured well inside limits.

## Tasks

1. **documents:samples and shape parity** (commit 4dcfcb7): command with whitelisted `--profile`, `--language`, `--out` (`^[a-z0-9_-]{1,40}$/D`, output only under `writable/samples/`), no INSERT/UPDATE/DELETE/DocumentStore/InvoiceIssuer (grep gate 0). `SampleSnapshots::invoice(..., ?LegalProfileResolver)` adds `sample => true`; seed and resolver branches produce the same key set as an issued SK and PL snapshot (tests compare recursive key paths). Tests: all profiles generated, row counts of invoices/invoice_documents/invoice_counters/audit_log unchanged, PDFs contain VZOR, issued-style PDF (no `sample`) does not, invalid input rejected.
2. **Timing, performance group, runbook** (commit b40620f): `IssueTimingTest` (20 parts, real inv-2 + rep-1 + PAY by square QR), group `performance` excluded in `phpunit.dist.xml`; `docs/ops/staging.md` section 16.

## Measurements (performance group, run in docker, WSL2)

- inv-2 render: 333-360 ms
- rep-1 render: 175-187 ms
- full issue (both PDFs, DB, QR): 565-607 ms
- peak memory: 50 MB (limit 128 MB); each PDF limit 2000 ms

## Verification

- `make test`: OK (1257 tests, 8087 assertions); `make stan` OK; `make cs` clean; `node --test tests/js/`: 51 pass, 0 fail
- `phpunit --group performance`: OK (1 test, 5 assertions)
- `spark documents:samples` in the php container: 18 PDF files written

## Deviations from Plan

None in behaviour. Minor: `invoice_note => null` added to the seed branch (needed for key parity with SnapshotBuilder); the PL shape test sets a valid NIP on the entity version (as QrIssueTest does) so ZBP QR issuance succeeds.

## Pending human check (end-of-phase, owner)

Not executed by the agent, to be done on staging per `docs/ops/staging.md` section 16 and the plan human-check:
1. Run `php spark documents:samples`, open `sk-sk-faktura.pdf`, `sk-sk-vykaz.pdf`, `pl-pl-faktura.pdf`, `pl_eu_b2b-sk-faktura.pdf`, `cz-cs-faktura.pdf`.
2. Scan SK QR with at least two Slovak bank apps (IBAN, amount, VS, due date); record `PayBySquare::VERSION` accepted (Open Question 3, A1, A2).
3. Scan CZ QR (Czech app) and PL QR (Polish app); issue a PL test invoice above 9 999,99 PLN and check ZBP QR acceptance (Open Question 2).
4. Print SK invoice and report on A4 (readable, totals match, VZOR visible).
5. On iPhone PWA issue a test invoice, open "Faktúra PDF" and "Výkaz PDF" in a new tab.
6. Edit a legal wording, issue another invoice, confirm the first keeps old wording.
Record results (app, version, OK/error) in 06-HUMAN-UAT.md; failures become gaps for `/gsd-plan-phase 6 --gaps`.

## Known Stubs

None.

## Threat Flags

None (T-06-38..T-06-42 mitigated as planned: path whitelist, VZOR label, no DB writes, performance test).

## Self-Check: PASSED

Files and commits 4dcfcb7, b40620f verified present.
