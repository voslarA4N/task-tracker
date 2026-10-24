---
phase: 2
slug: identita-a-skor-nasadenie
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-10-24
---

# Phase 2 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^11.5 (`CIUnitTestCase`, `FeatureTestTrait`, `Tests\Support\PgTestCase`) + `node --test` for JS |
| **Config file** | `phpunit.dist.xml` (failOnDeprecation/Warning/Risky/Notice on; suites `unit`, `integration`, `feature`) |
| **Quick run command** | `make test-unit` (single file: `docker compose exec -T -u www-data php vendor/bin/phpunit --filter <TestName>`) |
| **Full suite command** | `make test && composer check`; JS: `node --test tests/js/*.test.js` |
| **Estimated runtime** | ~60 seconds |

---

## Sampling Rate

- **After every task commit:** Run `make test-unit` plus the `--filter` test for the touched service
- **After every plan wave:** Run `make test && composer check`
- **Before `/gsd-verify-work`:** Full suite green + `scripts/smoke-staging.sh` green + iPhone UAT
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | — | — | AUTH-01 | pending-2FA / enumeration | generic error, Argon2id, dummy hash | feature+unit | `phpunit --filter LoginTest` | ❌ W0 | ⬜ pending |
| TBD | — | — | AUTH-02 | TOTP replay | atomic `last_step` claim, ±1 step, encrypted secret | unit+integration | `phpunit --filter TotpServiceTest` / `TotpReplayTest` | ❌ W0 | ⬜ pending |
| TBD | — | — | AUTH-03 | backup code reuse | hashed, single use, regenerate invalidates | integration | `phpunit --filter BackupCodeTest` | ❌ W0 | ⬜ pending |
| TBD | — | — | AUTH-04 | brute force | 5 fails → 15 min lock per IP + account | integration | `phpunit --filter LockoutTest` | ❌ W0 | ⬜ pending |
| TBD | — | — | AUTH-05 | stolen device cookie | `__Host-`, fixed 30 d, revocation, no sliding | feature+integration | `phpunit --filter TrustedDeviceTest` | ❌ W0 | ⬜ pending |
| TBD | — | — | AUTH-06 | reset bypassing 2FA | reset never logs in, revokes devices | feature | `phpunit --filter PasswordResetTest` | ❌ W0 | ⬜ pending |
| TBD | — | — | AUTH-07 | audit tampering | insert-only `auth_events`, no secrets | integration | `phpunit --filter AuditLogTest` | ❌ W0 | ⬜ pending |
| TBD | — | — | AUTH-08 | — | groups `admin`/`accountant` | integration | `phpunit --filter RolesTest` | ❌ W0 | ⬜ pending |
| TBD | — | — | AUTH-09 | pending treated as logged in | deny-by-default, route enumeration | feature | `phpunit --filter AuthGateTest` | ❌ W0 | ⬜ pending |
| TBD | — | — | PWA-01 | HTML caching in SW | network-only navigations, `/offline` only cached HTML | feature+js | `phpunit --filter PwaTest`; `node --test tests/js/*.test.js` | ❌ W0 | ⬜ pending |
| TBD | — | — | NFR-03 | weak crypto/cookies | Sodium driver, cookie flags | unit+smoke | `phpunit --filter EncryptionConfigTest`; `scripts/smoke-staging.sh` | ❌ W0 | ⬜ pending |
| TBD | — | — | NFR-02, NFR-08 | header misconfig | HTTPS/HTTP2, headers, memory < 512 MB | smoke | `scripts/smoke-staging.sh` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky* — Task IDs filled in by planner/executor.

---

## Wave 0 Requirements

- [ ] `tests/_support/AuthTestCase.php` — user with TOTP, FrozenClock injection, login-pending helper, device helper
- [ ] Shield + Settings migrations reachable via `composer test:migrate`; grants migration for `app_rw`
- [ ] Update `DemoPersistTest`, `LayoutTest`, `HtmxCsrfTest` to authenticate via helper
- [ ] Spike test `ShieldPendingStateTest` (pass criteria in 02-RESEARCH.md)
- [ ] Ensure `ViewsCspTest` / `ViewsHardcodedTextTest` scan new view subfolders

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Add to Home Screen, standalone launch, offline page | PWA-01 | real iPhone/WebKit needed | Safari → Share → Add to Home Screen; launch; airplane mode → offline page |
| No re-login after force-quit / 7+ days with trusted device | AUTH-05, PWA-01 | real device over time | force-quit, relaunch; re-check after ≥7 days; 30-day check tracked as follow-up |
| Device list + revoke from iPhone | AUTH-05 | real device | revoke from PC, iPhone must require TOTP |
| Cloudflare / Hetzner config, idle memory | NFR-02, NFR-08 | live infra | run `scripts/smoke-staging.sh`, `docker stats` at idle |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
