---
phase: quick-261028-w8p
verified: 2026-10-29T00:00:00Z
status: human_needed
score: 7/7 must-haves verified (static + cheap local checks); full selftest not re-run
overrides_applied: 0
human_verification:
  - test: "Run sh scripts/mail-selftest.sh (slow) if you want independent proof of the E2E claims"
    expected: "Prints 'Mail self-test hotovy', cleans up networks/containers"
    why_human: "Skipped by verifier (slow, pulls images); SUMMARY claim not independently reproduced"
  - test: "Runbook step 10c/10d and step 13 on the real server (PROXY/Cloudflare behaviour, real source IP in Received, outlook S3150)"
    expected: "As documented"
    why_human: "Outward-facing, cannot be tested locally"
---

# Quick 261028-w8p Verification

**Goal:** Mail stack (Stalwart 1.0.1 + Roundcube) behind shared edge, repo artifacts, local selftest, correct runbook.

| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | Pinned stack in repo, manual install, not CI | VERIFIED | compose.mail.yaml: stalwart v1.0.1, roundcube 1.7.5, nginx 1.30, certbot v5.8.0 profile, own unbound; `compose config -q` OK |
| 2 | Ports 25/465/587/993 on 0.0.0.0 only, 8080 on 127.0.0.1, 443 never published | VERIFIED | `compose config --format json`: exactly 4 x 0.0.0.0 + 8080 on 127.0.0.1, only stalwart publishes |
| 3 | Hosts route CF -> edge -> mail-web, only whitelisted paths | VERIFIED | edge nginx.conf diff = 4 map lines + comment only; `location /` returns 404 in mail-web nginx, only autoconfig/autodiscover/mta-sts paths proxied; variable upstreams with resolver 127.0.0.11 |
| 4 | Idempotent secret-free provisioning | VERIFIED (static) | plan.ndjson only has `<INFO_PASSWORD>` etc. placeholders; provision script renders into mktemp 0700 dir with trap cleanup; umask 077; no secret-like tracked files; .gitignore covers /secrets /tls /letsencrypt |
| 5 | Local E2E selftest | UNCERTAIN (not re-run) | script exists (338 lines), parses (`sh -n`); not executed by verifier; SUMMARY claims pass |
| 6 | edge-selftest green with 4 map lines | VERIFIED | Ran `sh scripts/edge-selftest.sh`: "Edge self-test hotovy", all OK |
| 7 | docs/ops/mail.md ordered runbook, DNS diff, rollback; server.md link | VERIFIED | See below |

## Runbook order checks (docs/ops/mail.md)
- Edge map + reload + routing checks (step 10) come before Cloudflare DNS (step 11); `nginx -t` before reload; backup `nginx.conf.pre-mail`. OK.
- MX is row 15, marked LAST, after steps 9/10 green; null MX deleted before new MX. OK.
- Rollback restores MX `0 .`, SPF `v=spf1 -all`, `_dmarc` to captured `p=reject` value, deletes records 1-12, reverts edge, `down` without `-v`. OK. DNS table lists old values `v=spf1 -all`, `0 .`, `v=DMARC1; p=reject...`.

## Findings
- WARNING (minor): original DMARC is given as `p=reject...` / "captured before change" rather than the literal `v=DMARC1; p=reject;`; the runbook mandates capturing it with dig first, so rollback is still sound. Consider writing the known literal value into the rollback.
- Not independently verified: scripts/mail-selftest.sh run, shellcheck (not installed locally).
- Edge change safe: stream map entries resolved at runtime through existing resolver (same pattern as wt-prod/stage); a down mail-web does not prevent edge start/reload.
- No secrets in git (grep of docker/mail and scripts/mail-* clean).

## Gaps
None blocking.
