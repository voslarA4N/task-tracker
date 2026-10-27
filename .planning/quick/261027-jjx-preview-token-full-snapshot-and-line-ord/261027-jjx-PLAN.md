---
phase: quick-261027-jjx
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Libraries/Invoicing/InvoiceCalculator.php
  - app/Libraries/Invoicing/InvoiceDraftService.php
  - app/Libraries/Invoicing/InvoiceIssuer.php
  - tests/_support/IssueFixtures.php
  - tests/_support/issue_worker.php
  - tests/unit/Invoicing/InvoiceCalculatorTest.php
  - tests/integration/Invoicing/PreviewTokenScopeTest.php
  - public/assets/js/app.js
  - app/Views/invoicing/_preview.php
  - app/Views/invoicing/_confirm_sheet.php
  - app/Language/sk/Invoicing.php
  - app/Language/en/Invoicing.php
  - tests/js/inv-issue.test.js
autonomous: true
requirements: [WR-01, WR-04]

must_haves:
  truths:
    - "Issuing after the preview is rejected as preview_stale (409, nothing issued, counter not consumed) when the submitted issue date, the entity bank account, the entity version/address or the client address differs from what the preview showed"
    - "An unchanged preview still issues successfully (totals parity 221.98, concurrency and idempotency tests green)"
    - "Line order on the issued invoice equals the preview line order, also for tasks created across the 2026-10-25 DST fall-back hour"
    - "The Vystavit button is disabled while an htmx preview refresh is in flight and whenever visible dates differ from the hidden dates in #inv-issue-form"
    - "A failed preview refresh shows a stale-preview message and keeps the button disabled"
  artifacts:
    - path: "app/Libraries/Invoicing/InvoiceCalculator.php"
      provides: "previewToken(array $snapshot): sha256 over canonical JSON of snapshot without number and variable_symbol"
      contains: "function previewToken(array $snapshot)"
    - path: "app/Libraries/Invoicing/InvoiceIssuer.php"
      provides: "separate lock statement + DB-ordered fetch (t.created_at, a.id), no usort"
    - path: "tests/integration/Invoicing/PreviewTokenScopeTest.php"
      provides: "stale scenarios + DST order test"
    - path: "public/assets/js/app.js"
      provides: "invIssue in-flight / dates mismatch / stale guard"
  key_links:
    - from: "app/Libraries/Invoicing/InvoiceDraftService.php"
      to: "InvoiceCalculator::previewToken"
      via: "token computed only from the built snapshot"
      pattern: "previewToken\\(\\$snapshot\\)"
    - from: "app/Libraries/Invoicing/InvoiceIssuer.php"
      to: "InvoiceCalculator::previewToken"
      via: "hash_equals against token of snapshot built from $cmd dates"
      pattern: "previewToken\\(\\$snapshot\\)"
---

<objective>
Fix WR-01 and WR-04 from `.planning/phases/05-faktura-n-jadro/05-REVIEW.md` (owner-approved, locked as requested changes 1-3 below).

1. WR-01: the preview token covers the whole snapshot the user confirms (dates, entity version, bank, legal, client header, lines in order), not only line amounts.
2. WR-01 UI: the "Vystaviť" button cannot submit stale hidden dates (in-flight refresh, mismatched dates, failed refresh).
3. WR-04: the database is the single source of line order for both preview and issue.

Purpose: Core Value of the project: an issued invoice must equal exactly what was previewed and can never change afterwards.
Output: changed issuer/draft/calculator, new integration test, UI guard with node test.
</objective>

<execution_context>
@/mnt/c/personal-projects/task-tracker/.claude/get-shit-done/workflows/execute-plan.md
@/mnt/c/personal-projects/task-tracker/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@./CLAUDE.md
@.planning/phases/05-faktura-n-jadro/05-REVIEW.md (sections WR-01, WR-04 only)
@app/Libraries/Invoicing/InvoiceCalculator.php
@app/Libraries/Invoicing/InvoiceIssuer.php
@app/Libraries/Invoicing/InvoiceDraftService.php
@app/Libraries/Invoicing/SnapshotBuilder.php
@app/Libraries/Invoicing/InvoiceParts.php
@tests/_support/IssueFixtures.php
@tests/_support/issue_worker.php
@app/Views/invoicing/_preview.php
@app/Views/invoicing/_confirm_sheet.php
@tests/js/inv-issue.test.js

Facts verified during planning (do not re-derive):
- `SnapshotBuilder::build()` output has no time-dependent or volatile field. All keys come from DB rows (client, entity, entity_versions via `valid_from <= issueDate`, legal profile/text, bank via `ORDER BY is_default DESC, id ASC LIMIT 1`, which is deterministic), from the input dates, from constant literals (`schema`, `origin`, `type`, `template_version`, `payment`, `fx`, `rounding`) and from `lang()` labels for a fixed `doc_language`. The only fields unknown before issuance are `number` and `variable_symbol` (both `null` in preview and in issuer step 6). Exclude exactly these two and record this in a docblock on `previewToken()`.
- `lines` are emitted by `InvoiceCalculator::compute()` in input-parts order, so the new token is order-sensitive. That is intended: it makes the token also guard line order. It also means the WR-04 fix (identical ORDER BY in preview and issue) is REQUIRED for the token to match.
- Preview parts: `InvoiceParts::unlocked()` uses `ORDER BY t.created_at, a.id`. Issuer step 4 currently uses `ORDER BY a.id FOR UPDATE OF a` + PHP `usort` on created_at text (InvoiceIssuer ~96-116).
- Token callers: `InvoiceDraftService.php:129`, `InvoiceIssuer.php:136`, `tests/_support/IssueFixtures.php:123` (`previewTokenFor`), `tests/_support/issue_worker.php:47`, `tests/unit/Invoicing/InvoiceCalculatorTest.php:110-133` (`testPreviewToken`).
- `invIssue` Alpine component: `public/assets/js/app.js` ~222-235. x-data div at `_preview.php:102` is a sibling below the date inputs (`#inv-issue-date`, `#inv-delivery-date`, `#inv-due-date`, lines 68-85), all inside `#inv-preview`, which is replaced by outerHTML on a successful refresh (the component then re-inits with fresh state). `_confirm_sheet.php` is rendered inside the x-data div. The global handlers at app.js ~34-43 (`htmx:responseError`, `htmx:sendError` on body, #app-alert) stay untouched.
- Invoicing lang files exist only for `sk` and `en` (pl/cs have only InvoicePdf.php). Add the new key to sk and en only.
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Full-snapshot preview token (WR-01) and DB-only line order (WR-04)</name>
  <files>app/Libraries/Invoicing/InvoiceCalculator.php, app/Libraries/Invoicing/InvoiceDraftService.php, app/Libraries/Invoicing/InvoiceIssuer.php, tests/_support/IssueFixtures.php, tests/_support/issue_worker.php, tests/unit/Invoicing/InvoiceCalculatorTest.php</files>
  <behavior>
    - previewToken(snapshot) returns 64-hex sha256; identical for a snapshot that differs only in number/variable_symbol (null vs 'FA-1')
    - token differs when issue_date, delivery_date, due_date, entity.version_id, entity.address_line1, bank.iban, legal.vat_code, legal.texts.vat_note, client.address_line1, a line hours/rate, or line order changes
  </behavior>
  <action>
Per requested change 1 (WR-01):
- `InvoiceCalculator::previewToken(array $snapshot): string`: replace the old signature (clientId, month, entityId, lines). Unset `number` and `variable_symbol`, then `hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))`. Do NOT ksort or reorder lines: key order is the builder's literal order, line order is the DB order (the token guards order too). Docblock: why only those two keys are excluded (the volatility audit from the context section) and that the input must be the unmodified `SnapshotBuilder::build()` output.
- `InvoiceDraftService` (~line 127-136): drop the `if ($selected !== null) $token = ...` block. After `$snapshot = $this->builder->build(...)` succeeds, set `$token = InvoiceCalculator::previewToken($snapshot)`. The token exists only when the snapshot exists. Add `&& $token !== null` to the `canIssue` expression (~line 171), so the button is never enabled without a token.
- `InvoiceIssuer::issueInTransaction` step 6 (~136): `hash_equals($cmd->previewToken, InvoiceCalculator::previewToken($snapshot))`, where `$snapshot` is the one already built from `$cmd->issueDate/deliveryDate/dueDate` with number null (call it before `withNumber`, as now). Mismatch throws the existing `IssueRejected('preview_stale')`. The controller already maps it to 409 + refreshed preview. Keep the token check before the series/counter lock (step 7), so a rejection never consumes the counter.

Per requested change 3 (WR-04), in issuer step 4:
- Run a separate lock statement first: `SELECT a.id FROM billing_allocations a JOIN tasks t ON t.id = a.task_id WHERE a.id = ANY(CAST(:ids: AS bigint[])) AND a.invoice_id IS NULL AND a.billing_month = :m: AND t.client_id = :c: ORDER BY a.id FOR UPDATE OF a`. This keeps the id lock order for deadlock safety.
- Then fetch the rows with the same column list and the same WHERE, but with `ORDER BY t.created_at, a.id` (identical to `InvoiceParts::unlocked`) and without FOR UPDATE.
- Keep the `count($rows) !== count($cmd->partIds)` → parts_changed branch exactly as is, applied to the fetched rows.
- Delete the `usort(...)` line.
- Keep the step comments in Slovak without diacritics, as in the existing style.

Update callers and tests:
- `IssueFixtures::previewTokenFor`: make it protected, with signature (clientId, month, entityId, partIds, issueDate, deliveryDate, dueDate). It loads the client and entity rows via `$this->rw()` (`SELECT *`), filters `InvoiceParts::unlocked(..., client portal_url_template or null)` to partIds, builds the snapshot with `new SnapshotBuilder($this->rw())`, and returns `InvoiceCalculator::previewToken($snapshot)`. Return 64 zeros on empty parts, null rows or `IssueRejected`.
- `issueCommand()`: resolve the three dates (same defaults '2026-10-27', '2026-10-31', '2026-11-10') BEFORE computing the default token, and pass them to `previewTokenFor`.
- `issue_worker.php`: build the snapshot the same way, with dates `$issueDate`, '2026-10-31', '2026-11-10', and the client/entity rows from `$db`.
- `InvoiceCalculatorTest::testPreviewToken`: rewrite against a hand-built minimal snapshot array covering the behavior cases above, including the order-sensitivity case (reversed lines give a different token, which replaces the old "reversed equals" assertion).
- Grep `previewToken(` across app/ and tests/ afterwards: no old-signature call may remain.
  </action>
  <verify>
    <automated>docker exec task-tracker-php-1 vendor/bin/phpunit tests/unit/Invoicing/InvoiceCalculatorTest.php && docker exec task-tracker-php-1 vendor/bin/phpunit tests/integration/Invoicing && docker exec task-tracker-php-1 sh -c "grep -rn 'previewToken(\$client\|previewToken(1,' app tests | wc -l" | grep -qx 0</automated>
  </verify>
  <done>New token signature used everywhere. Every existing Invoicing integration test passes, including totals parity 221.98, IssueConcurrencyTest and IssueIdempotencyTest. The issuer has no usort and locks in a separate statement.</done>
</task>

<task type="auto">
  <name>Task 2: Integration tests for stale scenarios and DST line order</name>
  <files>tests/integration/Invoicing/PreviewTokenScopeTest.php</files>
  <action>
Create `PreviewTokenScopeTest`, using the same base class, traits and `IssueFixtures` as `IssueIdempotencyTest` (copy its setUp pattern). For each stale case:
- Compute the token with `previewTokenFor(...)` and the default dates (the "preview").
- Apply the change.
- Call `issuer()->issue(issueCommand(..., ['previewToken' => $token, ...]))`.
- Assert `IssueRejected` with reason `preview_stale`.
- Assert no row in `invoices` for the client, and no `invoice_counters` row for the series (or `last_value` unchanged if a row pre-exists in the fixture).

Cases:
- (a) submitted issueDate '2026-10-28' with the token computed for '2026-10-27';
- (b) UPDATE the entity's bank account iban to another valid IBAN in `entity_bank_accounts`;
- (c) entity version/address: UPDATE `entity_versions.address_line1` for the version valid at the issue date (if an immutability trigger forbids UPDATE, INSERT a new version with valid_from <= '2026-10-27' instead, and say which in a comment);
- (d) UPDATE `clients.address_line1`.

Use the owner/rw connection that the fixtures already use for writes.

Add an unchanged-preview positive case: the token is computed with the same dates as submitted and the issue succeeds.

DST case:
- Create two tasks and allocations for the same client/month via the fixture.
- Set `tasks.created_at` explicitly: the first-created task (lower id) to '2026-10-25 02:30:00+02', the second to '2026-10-25 02:10:00+01'. The first is earlier as an instant, but later as text in Europe/Bratislava.
- Build the draft via `InvoiceDraftService` (or `InvoiceParts::unlocked`) and record the line order as a list of `source_allocation_id`.
- Issue with the fixture token. Assert it is not rejected.
- Assert that the issued `invoice_lines` ordered by their position column (check the migration for the column name) have the same `source_allocation_id` order as the preview.
- In a comment, note that the old PHP text sort reversed these.
  </action>
  <verify>
    <automated>docker exec task-tracker-php-1 vendor/bin/phpunit tests/integration/Invoicing/PreviewTokenScopeTest.php</automated>
  </verify>
  <done>All four stale cases are rejected with nothing issued and the counter not consumed. The unchanged case issues. The DST case issues, with identical preview and invoice order.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Vystavit guard in invIssue (in-flight, date mismatch, failed refresh)</name>
  <files>public/assets/js/app.js, app/Views/invoicing/_preview.php, app/Views/invoicing/_confirm_sheet.php, app/Language/sk/Invoicing.php, app/Language/en/Invoicing.php, tests/js/inv-issue.test.js</files>
  <behavior>
    - sameDates({issue_date,delivery_date,due_date}, same) is true, and false if any one of the three differs
    - onBeforeRequest with a GET targeting #inv-preview sets inFlight and disabled becomes true. onAfterRequest clears it. A POST (the issue request) does not set inFlight
    - onRefreshError for a preview GET sets stale and disabled stays true
    - datesMatch=false makes disabled true even when canIssue and confirmed are true
    - existing backdate/canIssue tests keep passing
  </behavior>
  <action>
Per requested change 2, extend the `invIssue` component in `public/assets/js/app.js`. Plain ES5 style as in the file, Alpine CSP build: no inline expressions beyond property/method names.
- Add state `inFlight: false`, `stale: false`, `datesMatch: true`.
- Add methods:
  - `isPreviewRefresh(e)`: true when `e.detail.requestConfig.verb` is 'get' and `e.detail.target` has id 'inv-preview'; guard against missing properties.
  - `onBeforeRequest(e)` / `onAfterRequest(e)` toggle `inFlight` for preview refreshes only.
  - `onRefreshError(e)` sets `stale = true` and `inFlight = false` for preview refreshes.
  - `sameDates(visible, hidden)`: pure comparison of the three keys.
  - `syncDates()`: reads `document.getElementById('inv-issue-date'|'inv-delivery-date'|'inv-due-date').value` and the same-named `elements` of `document.getElementById('inv-issue-form')`, then sets `datesMatch`. If any element is missing it leaves `datesMatch` true.
- `init()`: keep the dataset reads. Bind handlers to `this` and register them on `document.body`: `htmx:beforeRequest`, `htmx:afterRequest`, `htmx:responseError`, `htmx:sendError` (the latter two go to onRefreshError). Register `input` and `change` on `document` → syncDates. Call `syncDates()` once.
- `destroy()`: remove all those listeners. Alpine 3 calls destroy when outerHTML swaps the component away, so there is no leak and no stale component reacting.
- `get disabled()`: `!canIssue || inFlight || stale || !datesMatch || (needsConfirm && !confirmed)`.
- The test stub (`document.body.addEventListener` noop, `getElementById` returns null) must keep working. Add a `removeEventListener` noop to the stub in the test if destroy is exercised.

Views:
- In `_preview.php`, inside the x-data div just above `.master-sticky-actions`, add `<div class="alert alert-warning" role="alert" hidden x-bind:hidden="!stale">` with the escaped new lang text. No inline style or JS (strict CSP; `hidden` attribute, not x-show).
- The sticky "Vystaviť" button already has `x-bind:disabled="disabled"`, so keep it.
- In `_confirm_sheet.php`, add `x-bind:disabled="disabled"` to the real submit button as well (keep `hx-disabled-elt="this"`).

Lang: add `'previewStaleRefresh'` to both files:
- sk: 'Náhľad sa nepodarilo obnoviť, nemusí zodpovedať zadaným dátumom. Obnovte náhľad a až potom vystavte faktúru.'
- en: 'The preview could not be refreshed and may not match the entered dates. Refresh the preview before issuing.'

If a lang key-parity test exists, keep it green.

If `asset_url()` versions by content or mtime, nothing else is needed. Otherwise bump the version in the way the helper expects (check `asset_url` implementation once).

Extend `tests/js/inv-issue.test.js` with the behavior cases above, calling the component methods directly with fake event objects.
  </action>
  <verify>
    <automated>node --test tests/js/ && docker exec task-tracker-php-1 vendor/bin/phpunit tests/integration/Invoicing/InvoiceDraftServiceTest.php</automated>
  </verify>
  <done>Node tests are green. The button is disabled during preview refresh, on date mismatch and after a failed refresh, with a visible SK message. There is no inline script or style in the views.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser→POST /invoices/issue | hidden dates, part_ids and preview_token are user-controlled |
| other tab / concurrent edit→issuer | master data (entity version, bank, client) can change between preview and issue |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-jjx-01 | Tampering | InvoiceIssuer token check | mitigate | token = sha256 of the full snapshot rebuilt server-side from submitted dates and locked rows. Any change to dates/master data gives preview_stale before the counter lock |
| T-jjx-02 | Tampering | line order on immutable invoice | mitigate | single SQL ORDER BY t.created_at, a.id in preview and issue. The token is order-sensitive |
| T-jjx-03 | Repudiation | client-side guard bypass | accept | the JS guard is UX only. The server token check (T-jjx-01) is authoritative |
| T-jjx-04 | Denial of service | separate lock + fetch statements | accept | the lock stays in a.id order (deadlock-safe), and the second SELECT runs within the same transaction holding the locks |
</threat_model>

<verification>
Run inside container task-tracker-php-1 (shared test DB; `make test-db` first if needed). Sequential on the main tree, no worktree.
- `make test`
- `composer stan`
- `composer cs`
- `node --test tests/js/`
</verification>

<success_criteria>
- Old previewToken signature is gone. The token covers the whole snapshot minus number/variable_symbol.
- Stale scenarios (date, bank, entity version/address, client address) give preview_stale, with no invoice and no counter consumed.
- The unchanged preview issues. The 221.98 parity, concurrency and idempotency tests are green.
- The DST case has identical preview/invoice line order and is not rejected.
- The UI guard works with no inline JS/CSS. The SK and EN lang keys are added.
- All four gates are green.
</success_criteria>

<output>
Create `.planning/quick/261027-jjx-preview-token-full-snapshot-and-line-ord/261027-jjx-SUMMARY.md` when done
</output>
