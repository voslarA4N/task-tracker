---
phase: 07-doru-enie-hrady-a-upomienky
plan: 08
subsystem: delivery-ui
tags: [email-templates, settings, alpine-csp, audit]
requires: [07-02, 07-06]
provides:
  - EmailTemplateService::update (revision, FOR UPDATE, placeholder whitelist, audit in one transaction)
  - Settings > E-mail templates screens (list, edit, preview) at /settings/email-templates
  - Alpine CSP component etVars (insert variable at cursor)
  - TemplateRenderer::sampleValues
affects: [07-11]
key-files:
  created:
    - app/Libraries/Delivery/EmailTemplateRejected.php
    - app/Controllers/Settings/EmailTemplates.php
    - app/Views/settings/email_templates/index.php
    - app/Views/settings/email_templates/edit.php
    - app/Views/settings/email_templates/_preview.php
    - app/Language/sk/EmailTemplates.php
    - app/Language/en/EmailTemplates.php
    - tests/integration/Delivery/EmailTemplateServiceTest.php
    - tests/feature/Settings/EmailTemplatesControllerTest.php
    - tests/js/et-vars.test.js
  modified:
    - app/Libraries/Delivery/EmailTemplateService.php
    - app/Libraries/Delivery/TemplateRenderer.php
    - app/Config/Routes/settings.php
    - app/Views/more/index.php
    - public/assets/js/app.js
    - public/assets/css/app.css
key-decisions:
  - "Preview and sampleValues use the same TemplateRenderer::render as live sending; unknown variables are rejected in preview too"
  - "Edit form is a plain PRG POST (as LegalTexts); controller still answers htmx with HX-Location"
  - "On 409 the form is re-rendered with the current DB revision, draft kept in the fields"
requirements-completed: [MAIL-04]
completed: 2026-10-29
---

# Phase 7 Plan 08: E-mail template editing Summary

Owner edits subject and text of all 8 e-mail templates (invoice, reminder x sk, pl, cs, en) with variable insertion, sample-value preview and revision plus before/after audit; unknown `{{x}}` placeholders are refused with 422, stale revisions with 409.

## Tasks

1. Service update, EmailTemplateRejected, sampleValues, integration tests - 4e9b51e
2. Controller, routes, views, menu item, etVars, language files, CSS, JS and feature tests - 4e61ab9 (also includes a PHPStan docblock fix in the task 1 test)

## Verification

Full PHPUnit: 1508 tests, 23044 assertions, OK. `node --test tests/js/`: 58 pass. `composer stan` and `composer cs` clean (run inside the php container; not on host). Manual browser check not done.

## Deviations from Plan

**1. [Rule 3] Dynamic lang keys.** `LangKeysTest` cannot see concatenated keys, so views use key maps for languages and variables.

**2. [Rule 3] Error message formatting.** `{{{0}}}` breaks the ICU MessageFormatter; the placeholder is passed as `{{name}}` into `{0}`.

**3.** `sampleValues` is static (called as `TemplateRenderer::sampleValues`); `billing_month` sample uses the `MM/YYYY` format produced by `TemplateContext`.

## Known Stubs

None.

## Self-Check: PASSED

Commits 4e9b51e and 4e61ab9 and created files exist.
