---
phase: quick-261027-elv
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Libraries/Work/Import/ImportSheet.php
  - app/Commands/ImportExcel.php
  - tests/unit/Work/Import/ImportSheetTest.php
  - tests/unit/Work/Import/ImportPlannerTest.php
  - tests/integration/Work/ImportExcelTest.php
  - docs/ops/import-excel.md
autonomous: true
requirements: [IMP-01]

must_haves:
  truths:
    - "`spark import:excel <path> --client <id> --month 2026-09` (dry run) prints the report, the per-month billed lines and the `POZOR:` line only for September 2026; October does not appear anywhere in the output"
    - "`--month 2026-09 --commit` creates only the September tasks and parts; no October task is created"
    - "Over docs/All4net-fakturovanie-prace.xlsx, `--month 2026-09 --commit` creates exactly 11 done tasks with 11 unlocked parts, 89.00 h, 2403.00 EUR, and 0 October tasks"
    - "A task imported with `--month` has the same `import_key` as when imported without the filter; a later unfiltered `--commit` reports the September rows as skipped and creates only the October rows"
    - "Without `--month` the command behaves exactly as before (all existing ImportExcelTest cases pass unchanged)"
    - "Invalid `--month` value (e.g. `2026-9`, `2026-13`, `sep`, missing value) or a month that has no block in the file ends with EXIT_ERROR and writes nothing (0 tasks, 0 billing_allocations, 0 task audit rows)"
  artifacts:
    - path: "app/Libraries/Work/Import/ImportSheet.php"
      provides: "forMonth(string): ImportSheet filter"
      contains: "function forMonth"
    - path: "app/Commands/ImportExcel.php"
      provides: "--month option wired before planning"
      contains: "forMonth"
    - path: "tests/unit/Work/Import/ImportSheetTest.php"
      provides: "unit tests of the filter"
    - path: "docs/ops/import-excel.md"
      provides: "--month option and single-month procedure"
      contains: "--month"
  key_links:
    - from: "app/Commands/ImportExcel.php"
      to: "ImportSheet::forMonth"
      via: "applied to ExcelReader result BEFORE ImportPlanner::plan, so report, billedParts, POZOR and runner all see only the filtered block"
      pattern: "forMonth\\("
---

<objective>
Add `--month RRRR-MM` to `spark import:excel`. The filter restricts the whole pipeline (dry run, commit, report, per-month billed lines and the `POZOR: ... Fakturované = ÁNO` line) to the block(s) of that month. Without the option behavior is unchanged. Invalid format or a month not present in the file fails without writing.

Purpose: the owner will run this from the dev container against the production DB (SSH tunnel) to import only 09/2026 and then lock it with `invoices:lock-historical` using the `--expect-*` values taken from the `POZOR:` line. Wrong filtering or wrong totals would either import October prematurely or lock wrong amounts, so correctness of the filtered report totals is critical (Core Value: totals must match everywhere).

Design (Claude's discretion, no CONTEXT.md for quick task): filter at the sheet level, not in the planner or runner. `ImportPlanner::plan()` derives everything (items, months, rowIssues, genericLinkRows, billedParts) per block, and the import_key base already contains the row month and the duplicate ordinal counter `$seen` is keyed by that base, so dropping whole blocks of other months cannot change keys of the kept rows. ImportPlanner, ImportRunner and ImportReportFormatter stay untouched.

Output: ImportSheet::forMonth, `--month` in the command, unit + integration tests, updated ops doc.
</objective>

<execution_context>
@/mnt/c/personal-projects/task-tracker/.claude/get-shit-done/workflows/execute-plan.md
@/mnt/c/personal-projects/task-tracker/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@./CLAUDE.md
@app/Commands/ImportExcel.php
@app/Libraries/Work/Import/ImportSheet.php
@app/Libraries/Work/Import/ImportBlock.php
@app/Libraries/Work/Import/ImportPlanner.php
@app/Libraries/Work/Import/ImportReportFormatter.php
@app/Libraries/Work/BillingMonth.php
@app/Commands/InvoicesLockHistorical.php
@tests/unit/Work/Import/ImportPlannerTest.php
@tests/integration/Work/ImportExcelTest.php
@tests/_support/ImportFixture.php
@docs/ops/import-excel.md

Facts gathered during planning (do not re-derive):
- ImportSheet is `final readonly class ImportSheet(public ?string $rate, public array $blocks)` with `list<ImportBlock>`; ImportBlock has `public string $month` (format RRRR-MM).
- `BillingMonth::parse(string): ?string` (App\Libraries\Work\BillingMonth) validates `^\d{4}-(0[1-9]|1[0-2])$`. Reuse it, do not write a new regex.
- InvoicesLockHistorical has the option-reading idiom `$params[$name] ?? CLI::getOption($name)`, keep `is_string` check. Note: CI4 stores a valueless `--month` as null/true, so "option present but not a valid string" must be an error, not "no filter". Detect presence via `array_key_exists('month', $params) || array_key_exists('month', CLI::getOptions())`.
- ImportFixture (always available in tests, written to WRITEPATH tests/import-fixture.xlsx): September 2026 rows 4-8 = Úloha A-E (A-D done, E not done; all Fakturované NIE); October 2026 rows 14-18 = Úloha F-J (H, I, J done; F, G not done). Full commit = 10 tasks, 7 parts. Fixture has NO billed=ÁNO rows, so the POZOR line must be covered by a unit test with hand-built blocks and by the real-file test.
- Real file docs/All4net-fakturovanie-prace.xlsx exists locally and is visible in the `php` container (compose.override.yaml bind-mounts `.:/var/www/html`), but is git-ignored, so the real-file test must `markTestSkipped` when the file is absent. Path in test: `ROOTPATH . 'docs/All4net-fakturovanie-prace.xlsx'`.
- Real-file expectations (from task description and docs): 09/2026 = 11 tasks, all done with parts, 89 h, 2 403,00 € (also the verified lock-historical values 11 / 89 / 2403); 10/2026 = 1 done + 4 in progress.
- Tests run in docker: `make test-db`, then `docker compose exec php vendor/bin/phpunit ...` (or `make test`), `make stan` (composer stan), `make cs` (composer cs; fix with `make cs-fix`).
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: ImportSheet::forMonth filter with unit tests</name>
  <files>app/Libraries/Work/Import/ImportSheet.php, tests/unit/Work/Import/ImportSheetTest.php, tests/unit/Work/Import/ImportPlannerTest.php</files>
  <behavior>
    - forMonth('2026-09') on a sheet with blocks [2026-09, 2026-10] returns a new ImportSheet with the same `rate` and only the 2026-09 block (same ImportBlock instance, order preserved); original sheet unchanged.
    - If several blocks carry the same month, all of them are kept in original order.
    - forMonth('2026-11') (no such block) throws DomainException whose message names the month and lists the months found in the file (e.g. "Mesiac 2026-11 v súbore nie je (nájdené: 2026-09, 2026-10).").
    - forMonth('2026-9') / '2026-13' / '' throws InvalidArgumentException (format checked via BillingMonth::parse).
    - Planner test (add to ImportPlannerTest): build blocks 2026-09 (two done+billed rows, one done non-billed, one not done) and 2026-10 (one done+billed row, one not done). Plan of `forMonth('2026-09')`: `months` has one entry 2026-09 with the same `calc` as in the full plan; `billedParts` == only the 2026-09 entry of the full plan; `billedPartRows()` counts only September; the list of `importKey` values equals the September subset of the full-plan keys (same order); `genericLinkRows` counts only September rows. Use ImportReportFormatter::billedWarning on the filtered plan and assert it contains the September row count and no October label.
  </behavior>
  <action>
    Write the tests first (RED), run them, then implement (GREEN).
    In ImportSheet add public method `forMonth(string $month): self`. Validate with `BillingMonth::parse($month) === null` -> throw InvalidArgumentException ("Neplatný mesiac, očakávaný formát RRRR-MM: ..."). Filter `$this->blocks` with array_values(array_filter(...)) on `$block->month === $month`. If the result is empty, throw DomainException with the message described in behavior (months found = unique block months in file order). Return `new self($this->rate, $filtered)`. Keep the class readonly, add phpdoc `@return self` / list types so PHPStan stays clean. Do NOT change ImportPlanner, ImportRunner, ImportReportFormatter or the import_key formula: idempotency relies on the key being identical with and without the filter (base = month|url|title|hours plus per-base ordinal; the base contains the month, so removing other months cannot shift ordinals). Use Slovak messages with diacritics consistent with the command's existing CLI messages.
  </action>
  <verify>
    <automated>docker compose exec -T php vendor/bin/phpunit tests/unit/Work/Import/</automated>
  </verify>
  <done>New ImportSheetTest and the added ImportPlannerTest case pass; all pre-existing unit tests under tests/unit/Work/Import still pass.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Wire --month into import:excel with integration tests (fixture + real file)</name>
  <files>app/Commands/ImportExcel.php, tests/integration/Work/ImportExcelTest.php</files>
  <behavior>
    Fixture-based (always run):
    - Dry run `--month 2026-09`: output contains "september", does NOT contain "október"; does not contain "F14:F16" (October formula issue) and not "riadok 15" (October row issue); 0 tasks, 0 billing_allocations, 0 task audit rows.
    - `--month 2026-09 --commit`: "Vytvorené: 5"; 5 tasks (Úloha A-E), 4 parts all billing_month 2026-09, 0 tasks with titles F-J.
    - Idempotency: after `--month 2026-09 --commit`, compute the full unfiltered plan (`ImportPlanner::plan(ExcelReader::read(file), '27.00', 'EUR')`) and assert that the set of `tasks.import_key` in DB equals the importKeys of the full-plan items whose row month is 2026-09. Then run unfiltered `--commit`: output "Vytvorené: 5, preskočené (už importované): 5"; totals 10 tasks / 7 parts (same as the existing unfiltered test).
    - Running `--month 2026-09 --commit` twice: second prints "Vytvorené: 0".
    - Errors writing nothing: `--month 2026-9`, `--month 2026-13`, `--month sep`, valueless `--month` (placed before `--commit`), and `--month 2026-11` (not in file) each, with `--commit`, produce an error message (for 2026-11 the message contains "2026-11") and leave 0 tasks / 0 billing_allocations / 0 task audit rows.
    Real file (skip with markTestSkipped if ROOTPATH docs/All4net-fakturovanie-prace.xlsx is missing):
    - Dry run `--month 2026-09` contains "september", not "október", and a `POZOR:` line; 0 tasks.
    - `--month 2026-09 --commit`: 11 tasks, all status done; 11 billing_allocations all billing_month 2026-09 and invoice_id NULL; `sum(hours) = 89.00` and `sum(amount) = 2403.00` compared as DB strings (no float); 0 tasks without a part (i.e. the 4 in-progress October tasks and the October done task were not created).
    - Unfiltered run after that reports 11 skipped and creates only October (1 done + 4 in progress = 5 created, 1 new part).
  </behavior>
  <action>
    Extend `runCommand` in ImportExcelTest with an optional extra-args string (keep default behavior for existing tests; do not modify existing test assertions). Add a helper that runs the command against an arbitrary file path for the real-file test. Write tests first, run (RED).
    In ImportExcel: update `$usage` to `import:excel <path> --client <id> [--month <RRRR-MM>] [--commit]` and add `'--month' => 'Importovať len blok daného mesiaca RRRR-MM (voliteľné, hodnota za medzerou)'` to `$options`. Read the option like InvoicesLockHistorical (`$params['month'] ?? CLI::getOption('month')`). If the option is present (array_key_exists in $params or CLI::getOptions()) but the value is not a string accepted by `BillingMonth::parse`, print `CLI::error('Neplatný mesiac pre --month, očakávaný formát RRRR-MM.')` and return EXIT_ERROR before reading the file or the client. After `$sheet = (new ExcelReader())->read($path);` and BEFORE `ImportPlanner::plan`, apply `$sheet = $sheet->forMonth($month)` when a month was given; the DomainException for a missing month is already caught by the existing catch block and returns EXIT_ERROR, so nothing is written. When filtering, print one line before the report, e.g. `CLI::write('Filter mesiaca: ' . BillingMonth::label($month, 'sk'), 'yellow')` so the owner sees the filter is active (make sure the test for "not október" still holds, the label is the September one). Everything downstream (report lines, billedParts, POZOR in dry run via `lines()` and after commit via `billedWarning()`, ImportRunner) automatically sees only the filtered block. Without `--month` code path is identical to today.
    Note in a code comment that ExcelReader still parses the whole file, so an invalid row in another month still stops the import (intentional: the file must be valid as a whole).
  </action>
  <verify>
    <automated>make test-db && docker compose exec -T php vendor/bin/phpunit tests/integration/Work/ImportExcelTest.php tests/unit/Work/Import/</automated>
  </verify>
  <done>All new and existing ImportExcelTest cases pass (real-file case passes locally, skips only when the file is absent); option appears in `spark help import:excel`.</done>
</task>

<task type="auto">
  <name>Task 3: Document --month and the single-month procedure, run full gates</name>
  <files>docs/ops/import-excel.md</files>
  <action>
    In docs/ops/import-excel.md: (a) in "Postup" mention the optional `--month RRRR-MM` (value after a space, like `--client`; put `--commit` last). (b) Add a section "Import len jedného mesiaca (--month)" with the exact procedure for the production run: dry run `make spark ARGS="import:excel docs/All4net-fakturovanie-prace.xlsx --client <id> --month 2026-09"`, check that only 09/2026 is in the report and that the `POZOR:` line reads 11 riadkov (89 h / 2 403,00 €), then the same with `--commit`, then `invoices:lock-historical --client <id> --month 2026-09` dry run and the `--expect-parts 11 --expect-hours 89 --expect-amount 2403 ... --commit` run from the existing WR-07 section; later importing October with `--month 2026-10` (or without the filter) skips September rows because import_key is identical with and without the filter. State the error cases: invalid format or a month not in the file ends with an error and writes nothing; the whole file is still parsed, so an invalid row in another month also stops the run. Since the POZOR line is now per filtered month, update step 1 of the WR-07 procedure to recommend `--month` instead of manually splitting the multi-month POZOR total in Excel. Keep the existing table of expected values. Slovak with diacritics, match the existing style (no em-dashes).
    Then run the full gates: `make test` (full PHPUnit incl. test-db), `make stan`, `make cs` (fix with `make cs-fix` if needed and re-run).
  </action>
  <verify>
    <automated>grep -c -- '--month' docs/ops/import-excel.md && make test && make stan && make cs</automated>
  </verify>
  <done>Doc describes the option and the single-month procedure; full PHPUnit, PHPStan and php-cs-fixer are green.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| CLI args -> command | owner-supplied `--month`, `--client`, path (local dev container, but writing to the production DB via SSH tunnel) |
| XLSX file -> reader | untrusted spreadsheet content (already handled by ExcelReader / planner, unchanged) |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-elv-01 | Tampering | ImportExcel `--month` parsing | mitigate | validate with BillingMonth::parse; valueless or malformed option = EXIT_ERROR before any read or write, never silently "no filter" |
| T-elv-02 | Tampering | production data integrity (wrong month imported / duplicated) | mitigate | filter applied before planning so report, POZOR totals and runner use the same filtered plan; import_key unchanged (tested equal to unfiltered keys) so a later unfiltered run skips instead of duplicating; whole run stays one transaction |
| T-elv-03 | Repudiation | imported tasks | accept | existing audit (`ip = 'cli'`) via TaskService unchanged |
| T-elv-04 | Denial of Service | non-existent month | mitigate | DomainException caught, EXIT_ERROR, nothing written (integration test) |
</threat_model>

<verification>
- `spark import:excel ... --month 2026-09` dry run lists only September and its POZOR line.
- ImportExcelTest real-file case: 11 tasks / 11 parts / 89.00 h / 2403.00 EUR, no October tasks.
- import_key equality with/without filter proven by test.
- Existing unfiltered tests unchanged and green; `make test`, `make stan`, `make cs` green.
</verification>

<success_criteria>
- `--month` filters dry run, commit, report and POZOR line to one month; no option = old behavior.
- Invalid or absent month errors with zero writes.
- Idempotency/import_key unchanged.
- docs/ops/import-excel.md documents the option and the single-month production procedure.
</success_criteria>

<output>
Create `.planning/quick/261027-elv-import-excel-month-filter/261027-elv-SUMMARY.md` when done
</output>
