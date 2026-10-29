---
phase: 07-doru-enie-hrady-a-upomienky
plan: 07
subsystem: delivery-sender
tags: [email, sender-identity, entities, audit, grants]
requires: [07-04]
provides:
  - entities.mail_from_name/mail_from_email/mail_reply_to/mail_bcc with column-level app_rw grant
  - SenderIdentity::resolve (entity settings with D-27 defaults) and defaults()
  - EntityInput::validateSender / senderFromPost, EntityService::updateBase with sender fields
  - "Odosielanie e-mailov" card in entity edit form
affects: [07-09]
key-files:
  created:
    - app/Database/Migrations/2026-10-29-000004_AddEntitiesMailSender.php
    - app/Views/settings/entities/_sender_fields.php
    - tests/integration/Delivery/EntitySenderTest.php
    - tests/feature/Master/EntitySenderFormTest.php
  modified:
    - app/Libraries/Delivery/SenderIdentity.php
    - app/Libraries/Master/EntityInput.php
    - app/Libraries/Master/EntityService.php
    - app/Controllers/Settings/Entities.php
    - app/Views/settings/entities/edit.php
    - app/Language/sk/Entities.php
    - app/Language/en/Entities.php
    - tests/unit/Delivery/SenderIdentityTest.php
key-decisions:
  - "NULL column = default (From from config, name = trade name, Reply-To and BCC = entity e-mail); mail_bcc '[]' = no BCC"
  - "Sender settings are only applied when the POST contains sender fields, so older forms/tests posting just base fields never reset them"
  - "Audit stores mail_bcc as a decoded list, in the same recordChange as the base fields"
requirements-completed: [MAIL-01, MAIL-03]
duration: 40min
completed: 2026-10-29
---

# Phase 7 Plan 07: Sender identity per entity Summary

Owner sets sender name/address, Reply-To and BCC archive per entity in the existing edit form; the address is checked against `email.allowedFromDomains` on save and again at send time, and InvoiceMailer uses the values resolved at send time (stored in invoice_sends, invoice snapshot untouched).

## Tasks

1. Migration, SenderIdentity defaults, EntityInput validation, EntityService.updateBase - 1a58f23
2. Sender card in entity edit form, controller wiring, sk/en language keys, feature tests - 9b01909

## Verification

Full PHPUnit: 1449 tests, 22783 assertions, OK. `composer stan` (both configs) OK. `composer cs` clean (0 of 520 to fix). Tests cover column grants (country still denied), defaults, configured values with empty BCC, single audit record and no audit without change, mailer using new From/BCC with unchanged snapshot, send-time `sender_invalid` when the domain allowlist changes, form defaults, 422 on foreign domain, persist and PRG, CSRF 403, no inline style/onclick.

## Deviations from Plan

**1. [Rule 1 - Bug avoidance] Sender fields optional in the POST**
- Treating a missing sender block as empty would have set `mail_bcc = []` (no BCC) for any client posting only base fields. `senderFromPost` returns an empty array when no sender key is present, and the controller then skips sender validation/saving.

**2. Test helper tweaks**
- Form test sets `config('Email')->fromEmail` directly instead of using DeliveryFixtures (which needs further fixture traits for phpstan).

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-07-27..31 mitigated as planned).

## Self-Check: PASSED

Commits 1a58f23 and 9b01909 exist; created files present.
