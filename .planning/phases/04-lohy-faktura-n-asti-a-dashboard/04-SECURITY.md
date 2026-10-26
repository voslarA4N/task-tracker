---
phase: 04
slug: lohy-faktura-n-asti-a-dashboard
status: verified
threats_open: 0
asvs_level: 1
created: 2026-10-26
---

# Phase 04 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Browser -> Controllers | formuláre úloh, častí, stavu, filtre, dashboard parametre | nedôveryhodný vstup (hodiny, sadzba, URL, q, month, client) |
| Controllers -> Services -> DB | atómové zápisy, DB trigger a CHECK ako posledná línia | peniaze numeric(12,2), hodiny numeric(7,2) |
| CLI import | jednorazový dev import XLSX | súbor od vlastníka (dev only) |
| Composer | require-dev phpoffice/phpspreadsheet | supply chain |

## Threat Register

| Threat ID | Category | Component | Disposition | Mitigation (evidence) | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-04-01 | Tampering | zamknutá časť | mitigate | trigger alloc_guard BEFORE UPDATE OR DELETE: app/Database/Migrations/2026-10-25-000008_CreateBillingAllocations.php:38-50 | CLOSED |
| T-04-02 | Elevation | app_rw stĺpce | mitigate | grantUpdateColumns bez invoice_id/task_id/currency: 000008:53 | CLOSED |
| T-04-03 | Tampering | suma časti | mitigate | CHECK amount = round(hours*rate,2): 000008:32 | CLOSED |
| T-04-04 | Tampering | zmazanie úlohy s časťami | mitigate | FK ON DELETE RESTRICT: 000008:23 | CLOSED |
| T-04-05 | Info disclosure | pohľad | mitigate | grep SECURITY DEFINER v app/: 0 zhôd; 000009 iba GRANT SELECT:38 | CLOSED |
| T-04-06 | Tampering | import_key/created_at | mitigate | tasks grantUpdateColumns bez import_key, created_at: 000007:38 | CLOSED |
| T-04-07 | Tampering | hodiny float | mitigate | app/Libraries/Platform/Hours.php:20-24 string + bcadd; grep float v Work/Hours: žiadne pretypovanie (ExcelReader:198 len vstupná konverzia na reťazec) | CLOSED |
| T-04-08 | Tampering | mesiac | mitigate | BillingMonth.php:21 regex /D, inRange:47, DB CHECK 000008:25 | CLOSED |
| T-04-09 | DoS | dĺžka hodín | mitigate | Hours.php:20 regex ^\d{1,5}(\.\d{1,2})?$ | CLOSED |
| T-04-SC | Tampering | phpspreadsheet | mitigate | composer.json:26 v require-dev; Dockerfile:24,26 composer install --no-dev; owner checkpoint 2026-10-26 | CLOSED |
| T-04-10 | Tampering | hyperlink schéma | mitigate | ExcelReader.php:161-168 http/https + host + max 2000 | CLOSED |
| T-04-11 | Tampering | súčty z Excelu | mitigate | ImportPlanner.php:47-50 sumy z sadzby klienta, Excel iba porovnanie | CLOSED |
| T-04-12 | Info disclosure | dáta klienta v gite | mitigate | *.xlsx v .gitignore:7, git ls-files: žiadny xlsx, /writable/ ignorovaný | CLOSED |
| T-04-14 | Elevation | mass assignment task | mitigate | TaskInput.php:18 FIELDS whitelist (bez amount/status/invoice_id/done_at/import_key) | CLOSED |
| T-04-15 | Tampering | delete úlohy | mitigate | TaskService.php:147-175 FOR UPDATE, DELETE ... NOT EXISTS locked, affectedRows, FK + trigger | CLOSED |
| T-04-16 | Tampering | zmena klienta úlohy | mitigate | TaskService.php:128-133 podmienka NOT EXISTS v tom istom UPDATE + affectedRows | CLOSED |
| T-04-17 | Tampering | URL | mitigate | TaskInput.php:125-133 http/https+host, max 2000, bez riadiacich znakov; show.php:21 druhá kontrola | CLOSED |
| T-04-18 | Tampering | zmena meny | mitigate | app/Libraries/Master/ClientService.php:68 FOR UPDATE, :81-90 ClientCurrencyLocked | CLOSED |
| T-04-19 | Repudiation | audit úlohy | mitigate | TaskService.php:74,137,175 AuditLogger v transakcii | CLOSED |
| T-04-20 | Tampering | SQLi služby | mitigate | TaskService/AllocationService: výhradne väzby :name: | CLOSED |
| T-04-21 | Tampering | zamknutá časť race | mitigate | AllocationService.php:116,140 AND invoice_id IS NULL + affectedRows :120,:142 + trigger | CLOSED |
| T-04-22 | Elevation | mass assignment časť | mitigate | AllocationInput.php:17 FIELDS = hours, billing_month, rate; amount vždy server | CLOSED |
| T-04-23 | Tampering | súbeh | mitigate | AllocationService.php:49,189 tasks FOR UPDATE ako prvý zámok | CLOSED |
| T-04-24 | Repudiation | audit častí | mitigate | AllocationService.php:83,124,146 audit v transakcii | CLOSED |
| T-04-25 | Tampering | mesiac časti | mitigate | AllocationInput + BillingMonth::parse/inRange, CHECK 000008:25 | CLOSED |
| T-04-26 | Tampering | SQLi filtre | mitigate | TaskFilter.php:49,58 whitelist; TaskQueries väzby :name:, pevné fragmenty :201,:204 whitelist skupín | CLOSED |
| T-04-27 | Tampering | ILIKE wildcard | mitigate | TaskQueries.php:80 ESCAPE '\', :267 escapuje \ % _ | CLOSED |
| T-04-28 | DoS | zoznam | mitigate | TaskQueries.php:51-92 jeden dotaz LIMIT limit+1 (200+1) | CLOSED |
| T-04-29 | Info disclosure | súčet mien | mitigate | TaskQueries.php:161,169 GROUP BY currency | CLOSED |
| T-04-30 | Tampering | stored XSS | mitigate | Views/tasks: esc() (202 použití), show.php:47 rel="noopener noreferrer", href len pri http/https; žiadne <script>/inline handler | CLOSED |
| T-04-31 | Tampering | javascript: href | mitigate | TaskInput.php:131 + show.php:21 linkOk | CLOSED |
| T-04-32 | Spoofing | CSRF | mitigate | Filters.php:84 globálny csrf (HtmxCsrf); csrf_field() vo všetkých formulároch Views/tasks; layouts/app.php:45 hx-headers | CLOSED |
| T-04-33 | Elevation | anonym | mitigate | Filters.php:85 globálny authgate; AuthGate.php:43-57 | CLOSED |
| T-04-34 | Tampering | suma z formulára | mitigate | FIELDS bez amount; AllocationService vypočíta amount | CLOSED |
| T-04-35 | Tampering | delete cez POST | mitigate | Tasks.php:176-179 TaskHasLockedAllocations -> 409, atómový DELETE v TaskService | CLOSED |
| T-04-36 | Elevation | CSP | mitigate | layouts/app.php:8 allowEval=false; grep inline <script>/onclick/style= vo Views/tasks, home: 0; tests/unit/Security/ViewsCspTest.php | CLOSED |
| T-04-37 | Tampering | dvojklik | mitigate | TaskService.php:212,216 podmienený UPDATE stavu + affectedRows | CLOSED |
| T-04-38 | Tampering | čiastočný zápis | mitigate | TaskService.php:202-226 markDone jedna transakcia | CLOSED |
| T-04-39 | Repudiation | audit stavu | mitigate | TaskService.php:226,261 mark_done/reopen | CLOSED |
| T-04-40 | Tampering | import hodnoty | mitigate | ImportRunner.php:72 TaskInput::validate, ExcelReader:161-168, Transaction::run rollback | CLOSED |
| T-04-41 | Tampering | duplicitný import | mitigate | unique index tasks_import_key_uq 000007:33; ImportRunner.php:54 kontrola | CLOSED |
| T-04-42 | Repudiation | audit importu | mitigate | AuditLogger.php:60 ip='cli' pri CLI; služby auditujú | CLOSED |
| T-04-43 | DoS | spark list | mitigate | ImportExcel.php:39 class_exists guard pred akýmkoľvek použitím; v príkaze žiadny typehint/inštancia PhpSpreadsheet. Poznámka: riadok 16 je len alias `use` (bez autoloadu), funkčne neškodné | CLOSED |
| T-04-44 | Info disclosure | reálne dáta | mitigate | *.xlsx ignorované, žiadny xlsx v gite | CLOSED |
| T-04-45 | Tampering | POST na zamknutú časť | mitigate | Allocations.php:82-83,104-105 AllocationLocked -> 409; atómový SQL + trigger | CLOSED |
| T-04-46 | Spoofing | CSRF alokácie | mitigate | globálny csrf Filters.php:84, csrf_field() _alloc_form:62,_alloc_delete:32, hx-headers | CLOSED |
| T-04-47 | Elevation | mass assignment | mitigate | AllocationInput::FIELDS | CLOSED |
| T-04-48 | Tampering | XSS panel | mitigate | esc() vo Views/tasks, ViewsCspTest | CLOSED |
| T-04-49 | Elevation | anonym | mitigate | AuthGate globálny | CLOSED |
| T-04-50 | Tampering | 409 swap | mitigate | layouts/app.php:17 swap 409; formuláre mierijú hx-target="#sheet-body" (_alloc_form:58, _alloc_delete:31, sheet_page:26) | CLOSED |
| T-04-51 | Tampering | dvojklik časť | mitigate | data-submit-once (_alloc_form:58, app.js:90) + AllocationService podmienený UPDATE/409 | CLOSED |
| T-04-52 | Tampering | sadzba z formulára | mitigate | TaskStatus.php:47 prepis rate sadzbou úlohy; AllocationService.php:49 berie t.rate | CLOSED |
| T-04-53 | Spoofing | CSRF stav | mitigate | csrf_field() _done_form:43, show.php:102,124; globálny csrf | CLOSED |
| T-04-54 | Repudiation | audit stavu | mitigate | TaskService audit mark_done/reopen | CLOSED |
| T-04-55 | Tampering | reflected XSS q | mitigate | Views/tasks/index.php:62 esc(...,'attr'); TaskFilter MAX_Q=100 | CLOSED |
| T-04-56 | Tampering | SQLi filtre | mitigate | TaskFilter whitelist + TaskQueries väzby | CLOSED |
| T-04-57 | DoS | veľký zoznam | mitigate | TaskQueries.php:51 limit 200, truncated flag | CLOSED |
| T-04-58 | Info disclosure | zoznam anonym | mitigate | AuthGate globálny | CLOSED |
| T-04-59 | Tampering | mesiac/klient URL | mitigate | DashboardFilter.php:21-36 BillingMonth::parse, regex ^\d{1,18}$, predvolené hodnoty | CLOSED |
| T-04-60 | Info disclosure | súčet mien | mitigate | TaskQueries.php:161,169 GROUP BY currency | CLOSED |
| T-04-61 | Tampering | XSS hx-get | mitigate | Views/home/_body.php:20,36 toQuery z validovaných hodnôt + htmlspecialchars | CLOSED |
| T-04-62 | Info disclosure | dashboard anonym | mitigate | AuthGate globálny; route fragments/dashboard bez výnimky | CLOSED |
| T-04-13 | DoS | veľký XLSX v dev CLI | accept | Accepted Risks Log AR-04-01 | CLOSED |

*Status: open · closed. Cesty migrácií sú relatívne k `app/Database/Migrations/` (000007 = 2026-10-25-000007_CreateTasks.php atď.), ostatné k `app/`, ak nie je uvedené inak.*

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-04-01 | T-04-13 | Jednorazový dev-only import CLI, súbor dodáva vlastník, cca 25 riadkov; v produkčnom obraze nie je PhpSpreadsheet | owner (plan 04-04) | 2026-10-26 |

## Unregistered Flags

None (SUMMARY.md threat flags: žiadne).

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-26 | 63 | 63 | 0 | gsd-security-auditor |

## Sign-Off

- [x] All threats have a disposition
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-10-26
