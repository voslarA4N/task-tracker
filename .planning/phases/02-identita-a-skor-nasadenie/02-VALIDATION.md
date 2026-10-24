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
| 02-03-T1/T2 | 02-03 | 2 | AUTH-09, AUTH-08 | T-02-03-01 | Shield pending fail-closed, spike kritériá 1–6 | integration | `phpunit --filter ShieldPendingStateTest` | ❌ W0 (02-03-T1) | ⬜ pending |
| 02-03-T3 | 02-03 | 2 | AUTH-08, AUTH-01 | T-02-03-03 | CLI-only účet, Argon2id, skupiny | integration | `phpunit --filter RolesTest` | ❌ W0 (02-03-T1) | ⬜ pending |
| 02-04-T1/T2 | 02-04 | 2 | NFR-03, AUTH-04 | T-02-04-01/02 | realip iba CF, limit_req 429, sw.js no-cache | smoke | `nginx -t && make smoke` | ✅ | ⬜ pending |
| 02-05-T2 | 02-05 | 2 | AUTH-04, AUTH-06 | T-02-05-01 | metadata-only mail log, bez tajomstiev | integration | `phpunit --filter SecurityNotifierTest` | ❌ W0 (02-05-T1) | ⬜ pending |
| 02-06-T2 | 02-06 | 3 | AUTH-09 | T-02-06-01/02 | deny-by-default, enumerácia rout | feature | `phpunit --filter AuthGateTest` | ❌ W0 (02-06-T1) | ⬜ pending |
| 02-07-T1 | 02-07 | 3 | NFR-08 | T-02-07-02 | migrácia iba po overenom pg_dump | unit | `phpunit --filter DeployGuardTest` | ❌ W0 (02-07-T1) | ⬜ pending |
| 02-08-T2/T3 | 02-08 | 4 | AUTH-01 | T-02-08-01 | generická hláška, dummy hash, Accept-Language | feature+js | `phpunit --filter LoginTest`; `node --test tests/js/submit-once.test.js` | ❌ W0 (02-08-T1) | ⬜ pending |
| 02-09-T1/T2 | 02-09 | 5 | NFR-08, NFR-03, NFR-02 | T-02-09-01..05 | HTTPS, hlavičky, realip, origin, pamäť | smoke | `sh scripts/smoke-staging.sh work-tracker.vostack.dev` | ❌ (02-07-T2) | ⬜ pending |
| 02-10-T2/T3 | 02-10 | 5 | AUTH-04, AUTH-07 | T-02-10-01/03 | 5 → 15 min IP + účet, insert-only denník | integration+feature | `phpunit --filter 'LockoutTest|AuditLogTest|LoginTest'` | ❌ W0 (02-10-T1) | ⬜ pending |
| 02-11-T2/T3 | 02-11 | 5 | PWA-01 | T-02-11-01 | manifest, ikony, inštalačný panel | feature+js | `phpunit --filter PwaManifestTest`; `node --test tests/js/install-hint.test.js` | ❌ W0 (02-11-T1) | ⬜ pending |
| 02-12-T2/T3 | 02-12 | 6 | AUTH-02, NFR-03 | T-02-12-01..06 | ±1 krok, Sodium tajomstvo, QR lokálne | unit+feature | `phpunit --filter 'TotpServiceTest|EncryptionConfigTest|TotpSetupTest'` | ❌ W0 (02-12-T1) | ⬜ pending |
| 02-13-T2 | 02-13 | 6 | PWA-01 | T-02-13-01/02 | navigácie network-only, /offline bez cookies | feature+js | `phpunit --filter PwaServiceWorkerTest`; `node --test tests/js/sw.test.js` | ❌ W0 (02-13-T1) | ⬜ pending |
| 02-14-T2 | 02-14 | 7 | AUTH-02, AUTH-04 | T-02-14-01 | atomický claim kroku, replay odmietnutý | integration+feature | `phpunit --filter 'TotpReplayTest|TotpVerifyTest'` | ❌ W0 (02-14-T1) | ⬜ pending |
| 02-15-T2/T3 | 02-15 | 8 | AUTH-03 | T-02-15-01/02 | HMAC s pepperom, jednorazové, zobrazené raz | integration+feature | `phpunit --filter 'BackupCodeTest|BackupCodesFlowTest'` | ❌ W0 (02-15-T1) | ⬜ pending |
| 02-16-T2/T3 | 02-16 | 9 | AUTH-05, PWA-01 | T-02-16-01..06 | __Host- cookie, pevných 30 dní, odvolanie, 2 h nečinnosť | feature | `phpunit --filter 'TrustedDeviceTest|IdleTimeoutTest'` | ❌ W0 (02-16-T1) | ⬜ pending |
| 02-17-T2/T3 | 02-17 | 10 | AUTH-05, AUTH-07 | T-02-17-01/03 | zoznam/odvolanie, 50 záznamov bez tajomstiev | feature | `phpunit --filter SecurityPageTest` | ❌ W0 (02-17-T1) | ⬜ pending |
| 02-18-T2/T3 | 02-18 | 10 | AUTH-06 | T-02-18-01..04 | reset neprihlási, token 60 min jednorazový, spark recovery | integration+feature | `phpunit --filter 'RecoveryTest|PasswordResetTest'` | ❌ W0 (02-18-T1) | ⬜ pending |
| 02-19-T2/T3 | 02-19 | 11 | AUTH-03, AUTH-05 | T-02-19-01/02 | re-auth, epoch + revokeAll | feature | `phpunit --filter 'ChangePasswordTest|RegenerateCodesTest'` | ❌ W0 (02-19-T1) | ⬜ pending |
| 02-20-T1 | 02-20 | 12 | NFR-02, NFR-08 | T-02-20-01 | session sa mení po hesle, pamäť < 512 MB | smoke | `SMOKE_EMAIL=… SSH_TARGET=… sh scripts/smoke-staging.sh work-tracker.vostack.dev` | ❌ (02-07-T2) | ⬜ pending |
| 02-20-T3 | 02-20 | 12 | PWA-01, AUTH-05 | — | iPhone UAT | manual | `docs/ops/iphone-uat.md` | ❌ (02-20-T2) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky* — Task IDs filled in by planner/executor.

---

## Wave 0 Requirements

- [ ] `tests/_support/AuthTestCase.php` — vytvára 02-06-T1, rozširujú 02-12-T1 (enrollTotp, totpCode) a 02-16-T1 (trustDevice, withDeviceCookie)
- [ ] Shield + Settings migrations reachable via `composer test:migrate`; grants migration for `app_rw` — 02-03-T2
- [ ] Update `DemoPersistTest`, `LayoutTest`, `HtmxCsrfTest` to authenticate via helper — 02-06-T2
- [ ] Spike test `ShieldPendingStateTest` (pass criteria in 02-RESEARCH.md) — 02-03-T1
- [ ] Ensure `ViewsCspTest` / `ViewsHardcodedTextTest` scan new view subfolders — overené v `composer check` každého UI plánu (02-08, 02-11, 02-12, 02-15, 02-17)

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
