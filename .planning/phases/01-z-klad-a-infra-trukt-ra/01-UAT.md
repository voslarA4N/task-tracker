---
status: diagnosed
phase: 01-z-klad-a-infra-trukt-ra
source: [01-01-SUMMARY.md, 01-02-SUMMARY.md, 01-03-SUMMARY.md, 01-04-SUMMARY.md, 01-05-SUMMARY.md, 01-06-SUMMARY.md, 01-07-SUMMARY.md, 01-08-SUMMARY.md, 01-09-SUMMARY.md, 01-10-SUMMARY.md, 01-REVIEW-FIX.md]
started: 2026-10-24T00:00:00Z
updated: 2026-10-24T01:00:00Z
---

## Current Test
<!-- OVERWRITE each test - shows where we are -->

[testing complete]

## Tests

### 1. Cold Start Smoke Test
expected: In WSL run `make down && make up`, then `make smoke`. All containers (nginx, php, db, mailpit) come up without errors and `make smoke` ends with `SMOKE OK`.
result: pass

### 2. HTTPS without certificate warning
expected: Open https://work-tracker.loc in Chrome or Edge on Windows. The page loads with the padlock, no certificate warning; http://work-tracker.loc redirects to https.
result: pass

### 3. Responsive home page (iPhone + PC)
expected: DevTools device mode iPhone SE (375 px) and iPhone 14 (390 px) on `/`: no horizontal scroll, bottom/top nav items, buttons and inputs at least 44 px high (Computed → height), all texts in Slovak. At desktop width the layout adapts (no stretched mobile view).
result: pass

### 4. Demo calculation without reload
expected: On https://work-tracker.loc/demo enter hours `1,25` and rate `27` and calculate. Result 33.75 appears without a full page reload (DevTools Network shows one XHR/fetch, no document load).
result: pass

### 5. Demo validation error in Slovak
expected: On `/demo` leave a field empty (or type `abc`) and submit. A Slovak error message appears next to the field without reload; nothing is saved.
result: pass

### 6. Demo save and total
expected: On `/demo` save 1,25 × 27 and then 2 × 10,50. Both rows appear in the list and the total shows 54.75 without reload. After a manual page refresh (F5) the rows and total are still there.
result: pass

### 7. CSRF rejection message is visible
expected: On `/demo`, in DevTools Elements delete the hidden CSRF input (or change its value) and submit. The request is refused and the alert box at the top shows the Slovak "session expired / CSRF" message (App.csrfFailed), not a generic server error.
result: pass

### 8. Strict CSP – clean console
expected: With DevTools Console open, browse `/` and `/demo` and run tests 4–7. No "Refused to … because it violates the following Content Security Policy directive" messages appear.
result: pass

### 9. Cookie flags
expected: DevTools → Application → Cookies → https://work-tracker.loc: the session and CSRF cookies have Secure ✓, HttpOnly ✓ (session), SameSite = Lax.
result: pass

### 10. Mailpit receives e-mail
expected: Open http://127.0.0.1:8025. The test e-mail sent by `make smoke` (spark mail:test) is in the inbox.
result: pass

### 11. mPDF spike PDF with diacritics
expected: Run `make spike-pdf` (prints 4 measurements, all under 2 s), then `docker compose cp php:/var/www/html/writable/mpdf/spike-output.pdf ./spike-output.pdf` and open it. The invoice-like table shows ł, ń, ě, ť, ž, ô correctly (no boxes or question marks). Delete the copied file afterwards.
result: pass

### 12. GitHub CI green
expected: After you push `main`, `gh run list --workflow ci.yml --limit 1` shows `completed  success` for the latest commit.
result: issue
reported: "gh run list: X (failure) for 'docs(01): add code review fix report', run 40035937482, 23s"
severity: blocker

## Summary

total: 12
passed: 11
issues: 1
pending: 0
skipped: 0
blocked: 0

## Gaps
- truth: "After you push `main`, `gh run list --workflow ci.yml --limit 1` shows `completed  success` for the latest commit."
  status: failed
  reason: "User reported: gh run list: X (failure) for 'docs(01): add code review fix report', run 40035937482, 23s"
  severity: blocker
  test: 12
  root_cause: "CI step 'JS testy (app.js, CR-01)' runs `node --test tests/js/`. On the runner's Node v22.23.3 a directory argument is not expanded to test files; it is loaded as a module and fails with MODULE_NOT_FOUND. Locally (WSL node v18.20.8) the directory form works, so make smoke passed. Same invocation exists in scripts/smoke.sh."
  artifacts:
    - path: ".github/workflows/ci.yml"
      issue: "line 41: node --test tests/js/ (directory arg) fails on Node 22"
    - path: "scripts/smoke.sh"
      issue: "line 103: same directory-form invocation"
  missing:
    - "Invoke the test files explicitly (e.g. node --test tests/js/*.test.js) in ci.yml and smoke.sh, and pin the Node version in CI (actions/setup-node) so local and CI match"
  debug_session: ""
