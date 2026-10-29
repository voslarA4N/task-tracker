---
phase: 07-doru-enie-hrady-a-upomienky
plan: 03
subsystem: infra
tags: [docker-compose, deploy, smtp, stalwart, docs]
requires: []
provides:
  - compose overlay connecting production php to external network mail
  - deploy.sh MAIL_NETWORK guard (overlay + network preflight)
  - ops docs for production SMTP setup and extra From address
affects: [07-email-sending, ops]
tech-stack:
  added: []
  patterns: [conditional compose overlay selected by .env key]
key-files:
  created: [compose.server.mail.yaml]
  modified: [scripts/deploy.sh, .github/workflows/ci.yml, tests/unit/Scripts/DeployGuardTest.php, docs/ops/server.md, docs/ops/mail.md]
key-decisions:
  - "Overlay compose.server.mail.yaml added by deploy.sh only when MAIL_NETWORK is set; staging stays on log transport"
requirements-completed: [MAIL-01, MAIL-02, MAIL-03]
duration: 15min
completed: 2026-10-29
---

# Phase 7 Plan 03: Mail network overlay and ops docs Summary

Production php joins the external `mail` network via an overlay that `deploy.sh` adds only when `MAIL_NETWORK` is set in `.env`; a missing network or overlay file aborts the deploy before backup and migration. Staging is untouched.

## Tasks

1. Overlay, deploy.sh guard, CI copy, 4 new DeployGuardTest scenarios (30 tests pass) - ead6036
2. docs/ops/server.md section 20 (production setup, verification, rollback) and docs/ops/mail.md (overlay procedure, D-27 extra sender) - cf21f66

## Deviations from Plan

None - plan executed exactly as written.

## Notes

- composer cs and stan are clean.
- Owner action pending (end of phase): add `MAIL_NETWORK=mail` and the `email.*` block (with SMTPPass) on the server, deploy main, send a test invoice to Gmail and confirm SPF/DKIM/DMARC PASS.
- Stalwart From-header check behaviour (alias need) is unverified until the first production send.

## Self-Check: PASSED
