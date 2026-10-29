---
phase: quick-261028-w8p
plan: 01
subsystem: ops/mail
tags: [stalwart, roundcube, mail, docker-compose, nginx, unbound, runbook]
requires: [edge proxy (compose.edge.yaml), certbot/Cloudflare DNS-01 pattern]
provides: [compose.mail.yaml, docker/mail/*, scripts/mail-*.sh, docs/ops/mail.md, edge map lines]
affects: [docker/edge/nginx.conf, docs/ops/server.md, .gitignore]
key-files:
  created:
    - compose.mail.yaml
    - docker/mail/unbound/Dockerfile
    - docker/mail/unbound/unbound.conf
    - docker/mail/roundcube/config.inc.php
    - docker/mail/nginx/nginx.conf
    - docker/mail/stalwart/config.json
    - docker/mail/stalwart/plan.ndjson
    - docker/mail/compose.selftest.yaml
    - scripts/mail-provision.sh
    - scripts/mail-backup.sh
    - scripts/mail-cert-renew.sh
    - scripts/mail-selftest.sh
    - docs/ops/mail.md
  modified:
    - docker/edge/nginx.conf
    - docs/ops/server.md
    - .gitignore
decisions:
  - "Bootstrap without wizard: config.json seeded into volume + temporary STALWART_RECOVERY_ADMIN + stalwart-cli apply (worked end to end on Stalwart 1.0.1)"
  - "Admin principal stalwart-admin@vostack.dev (full address is the login), role Admin, not a published mailbox"
  - "Certificate plan entry uses matchOn [certificate] (matchOn '*' is rejected: no comparable properties)"
duration: ~1.5 h
completed: 2026-10-28
---

# Quick 261028-w8p: Own mail server (Stalwart 1.0.1 + Roundcube) Summary

Complete, pinned, locally verified mail stack for vostack.dev (Stalwart v1.0.1 RocksDB, own unbound, Roundcube 1.7.5, mail-web nginx behind the shared edge), idempotent secret-free provisioning via stalwart-cli, nightly stop/tar/start backup, cert renewal script and a Slovak runbook with the exact outward-facing steps, DNS diff and rollback. Nothing outward-facing was done (no ssh, push, DNS, GitHub).

## Commits

| Commit | Message |
|---|---|
| feed637 | feat: mail stack compose, mail-web nginx, unbound, Roundcube and edge map |
| ef7fab2 | feat: stalwart provisioning plan and script |
| 126174a | feat: mail backup, cert renew and local end-to-end selftest |
| 7d1bb65 | docs: mail server runbook, DNS diff and rollback |

## Verification (all green at the end)

- `sh scripts/mail-selftest.sh` -> "Mail self-test hotovy" (all checks OK, incl. open-relay refusal, 465 and 587 AUTH, alias delivery, IMAP, autoconfig/autodiscover/mta-sts through the edge, /admin /api /jmap /  -> 404, Roundcube login over verified TLS, real cf-peer.conf (CF-range source accepted, other source 444), mail-cert-renew.sh --copy-only AND default mode, backup 0600 + sha256, restore into fresh volumes). Spamhaus check was OK locally (own unbound answers 127.0.0.x).
- `sh scripts/edge-selftest.sh` -> "Edge self-test hotovy" (four new map lines).
- shellcheck clean for scripts/mail-*.sh; `docker compose config` port assertion (25/465/587/993 only on 0.0.0.0, 8080 only on 127.0.0.1, only stalwart publishes ports) also runs inside the selftest.
- plan.ndjson secret grep gate passes. No PHP touched, so make test/stan/cs not run. Self-test containers, networks and volumes are all removed (verified).

## Verified Stalwart 1.0.1 shapes (research A3/A4/A5)

- **A5 resolved.** `AllowedIp`: `address` (immutable, matchOn ["address"]), optional `reason`. `DnsResolver` singleton variant `Custom`: `servers` list of `{"address","port","protocol":"udp|tcp|tls"}` (list = object with keys "0","1"). `NetworkListener`: `name`, `protocol` (smtp/imap/http/pop3/manageSieve), `bind` set `{"[::]:587":true}`, `implicitTls`. `Certificate`: `{"certificate":{"@type":"File","filePath":...},"privateKey":{"@type":"File",...}}`; **upsert needs `matchOn:["certificate"]`**, `"*"` fails with "no comparable properties". `Account` variant `User`: `name`, `domainId` ("#ref"), `credentials` `{"0":{"@type":"Password","secret":"<plain, server hashes it>"}}`, `aliases` `{"0":{"name","domainId"}}`, `roles` `{"@type":"User"|"Admin"}`. `Domain`: defaults are already `certificateManagement Manual`, `dnsManagement Manual`, `dkimManagement Automatic` (Ed25519 + RSA); `reportAddressUri` settable. `SystemSettings` (singleton, `update`): `defaultHostname`, `defaultDomainId`, `mailExchangers`, `services` map keyed `imap`/`smtp`/... with `{"hostname","cleartext"}`. `MtaSts`: default mode is already `testing`. `Security`: `authBanPeriod` ms.
- **A4 (partly).** CLI with basic auth works on plain `http://stalwart:8080` in normal mode (also with the recovery admin). The login name must be the **full address** `stalwart-admin@vostack.dev`. WebUI sign-in via plain HTTP origin through the SSH tunnel was NOT verified (CLI is the verified path; documented).
- **A3 resolved.** Stalwart serves `/mail/config-v1.1.xml`, `/Autodiscover/Autodiscover.xml` (POST) and `/.well-known/mta-sts.txt` on plain 8080 for any Host; selftest confirmed through nginx + edge.
- stalwart-cli: `apply --file`, `query`, `get --fields dnsZoneFile`, `create Action/ReloadSettings --json '{}'` (needs `--json '{}'`, otherwise "no input provided"), `delete <Object> --ids`. The CLI container runs as nonroot, so a 0700 temp dir needs `--user "$(id -u):$(id -g)"`.
- Empty `STALWART_RECOVERY_ADMIN` (compose `${...:-}`) in steady state is accepted; the recovery admin is rejected after the recreate (selftest check).

## Bootstrap path that worked

`mail-provision.sh --init` (config.json `{"@type":"RocksDb","path":"/var/lib/stalwart/"}` into volume, chown 2000) -> start unbound + stalwart with temporary `STALWART_RECOVERY_ADMIN=admin:<random>` -> `mail-provision.sh` as that admin (creates stalwart-admin and everything else) -> `up -d` recreates stalwart without the recovery admin -> provisioning again as stalwart-admin. Default listeners (25/465/993/995/4190/443/8080) exist; plan adds 587. No wizard, no blocker; wizard fallback documented in mail.md.

## Measured memory (selftest, idle after run, docker stats)

stalwart ~199 MiB, roundcube ~24 MiB, web ~3 MiB, unbound ~8 MiB (total ~235 MiB); limits 1g/256m/64m/64m.

## Deviations from Plan

1. **[Rule 3 - Blocking] Added `roundcube-init` service** (alpine:3.24, `chown 33:33 /var/roundcube/db`, `network_mode: none`, roundcube depends_on it with service_completed_successfully). Without it the new named volume is root-owned, Roundcube (www-data) cannot create SQLite and serves "Internal Error" (found by selftest). Adds a fifth, short-lived service.
2. **cf-peer mount via variable** (`MAIL_CF_PEER`, default the real file) in `compose.mail.yaml` instead of an extra mount in compose.selftest.yaml, so the self-test can switch web between permissive and real cf-peer.conf without recreating other services. compose.selftest.yaml also carries the CA bundle and the certbot cloudflare.ini stand-in (variables `MAIL_SELFTEST_CA_BUNDLE`, `MAIL_SELFTEST_CF_INI`).
3. **Plan-checker notes handled:** (1) nginx:1.30-alpine has curl 8.22 (supports `--haproxy-clientip`); runbook 10(d) uses `docker run --entrypoint curl nginx:1.30-alpine ... --connect-to ::mail-web:443`, the exact command is exercised in the selftest (13f), plus `curlimages/curl:8.17.0` (also has the flag) for 13a-d. (2) runbook 10(c) wording: edge publishes `0.0.0.0:443` (+ `[::]:443`), so `127.0.0.1` works on the server; connection is closed (444) and the proof is the `host=webmail.vostack.dev ... 444` line, which required a `log_format` with `$host` in mail-web nginx (selftest 13e verifies the line). (3) `mail-cert-renew.sh` default and `--copy-only` modes are both exercised in the selftest.
4. **mail-provision.sh additions:** `--cli` mode (used by cert-renew, runbook, restore check) and `STALWART_URL` override. "Idempotent" means no creations/duplicates and identical object ids on re-apply; `apply` itself reports "updated" every run (upsert re-writes values, password re-hashed). Selftest compares query output (sorted) before/after.
5. Selftest differences: unauthenticated alias message from a bogus sender domain correctly lands in Junk Mail, so the IMAP check counts INBOX plus Junk Mail; a restore-into-fresh-volumes check (16) was added; `MAIL_SELFTEST_KEEP=1` debug switch.
6. mail-cert-renew.sh was committed with the Task 2 Step B commit (selftest needs it), the Task 3 commit holds docs.
7. Stalwart 1.0.1 requires a domain on every User, so the admin principal is `stalwart-admin@vostack.dev` (documented, never used for mail). Default DNS zone from `--print-dns` contains `_dmarc p=reject`, CNAMEs, TLSA and ua-auto-config lines: runbook step 8 says to take only DKIM and `_mta-sts` id from it.

## Known notes for the orchestrator

- Docs are the runbook source (`docs/ops/mail.md` sections 4, 5, 9, 10). Compose reads `/opt/mail/.env` (needs `ROUNDCUBE_DES_KEY`, written by step 5). The first `docker compose run certbot` creates a default network `mail_default` (harmless).
- Stalwart logs nothing to console by default in bootstrap-less mode (empty `docker logs`); use CLI/`query` for diagnostics.
- DNSSEC warning: `DnsResolver` reload reported "cannot validate DNSSEC, DANE disabled" in the throwaway test before unbound was wired in; irrelevant for plan (DANE/TLSA not published).
- Unverified until real deployment: research A1 (no `[::]` listeners with explicit 0.0.0.0 on Docker 29), A2 (DNAT keeps source IP), A7 (Cloudflare vs Outlook POST / mta-sts cache), WebUI sign-in over tunnel.

## Self-Check: PASSED

All created files exist, commits feed637, ef7fab2, 126174a, 7d1bb65 present, selftests green, no leftover containers/networks/volumes.
