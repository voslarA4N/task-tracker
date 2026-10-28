# Quick 261028-fcr: Staging environment, edge SNI proxy and develop/main branch model - Summary

**One-liner:** Shared edge nginx (stream + ssl_preread, PROXY protocol, Cloudflare-peer guard) fronting prod and a new staging stack, with a two-stack-safe deploy.sh, a shared compose.server.yaml and CI mapping develop -> staging, main -> production.

## Commits (code)
- 9fce21a feat: edge proxy, PROXY-protocol aware stack nginx, X-Robots-Tag, scripts/edge-selftest.sh
- a6ee56c feat: compose.server.yaml, deploy.sh guards, smoke-server.sh, CI split, tests
- ed05715 docs: docs/ops/server.md (runbook, branch model, edge, cut-over, rollback)

Commit the runbook should deploy: `ed05715` (full SHA: `git rev-parse ed05715`). Nothing pushed.

## What changed
- `docker/edge/nginx.conf`, `compose.edge.yaml`: SNI router to `wt-prod-nginx`/`wt-stage-nginx`, unknown SNI closed, port 80 -> 301.
- Stack nginx: `listen 443 ssl ... ${NGINX_PROXY_PROTOCOL}`, `http.d/cf-peer.conf` (geo $cf_peer), `realip/edge.conf` (trusts 10.250.250.0/24, 444 for non-CF PROXY source), `NGINX_ROBOTS_TAG` header. `cloudflare.conf` removed; `cf-ips-check.sh` now writes `cf-peer.conf`.
- `compose.staging.yaml` -> `compose.server.yaml`: no ports, external `edge` network with `${EDGE_ALIAS}`, `name: ${COMPOSE_PROJECT_NAME:?}`.
- `deploy.sh`: requires COMPOSE_PROJECT_NAME/EDGE_ALIAS, checks network `edge` and project working_dir, `SKIP_PULL=1`, per-stack BACKUP_DIR / DOCKER_CONFIG / cron line.
- `smoke-staging.sh` -> `smoke-server.sh` (SMOKE_PROJECT memory, SMOKE_EXPECT_NOINDEX); Makefile target `smoke-server`; `server-bootstrap.sh` creates both stacks, edge dir, network.
- CI: deploy job on main (environment production) and develop (environment staging).
- Tests: DeployGuardTest (+5 cases), StagingSecretsLayoutTest (edge/no-ports/project name).

## Verification
- `sh scripts/edge-selftest.sh`: all OK (h2 via edge, realip 203.0.113.9, unknown SNI closed, port 80 301, noindex only on staging, non-CF PROXY source dropped; non-CF case shows curl 92 / HTTP 000).
- PHPUnit tests/unit/Scripts 54/54; `make test`, `make stan`, `make cs` pass.
- shellcheck and actionlint clean; compose config renders `task-tracker` / `task-tracker-staging`, no published ports, correct alias.
- Dev nginx template still passes `nginx -t` with empty proxy var; dev stack verified on https://localhost:8443 (HTTP/2 200, no X-Robots-Tag).

## Deviations
1. [Rule 3] Stale zero-byte `.git/index.lock` (12 min old, no git process) removed to proceed.
2. [Rule 3] Dev nginx could not bind 127.0.0.1:80/443 (container `global-proxy` holds 80/443). I recreated dev nginx via `docker compose up -d nginx` (compose.yaml env changed), then started it with a scratchpad override mapping 127.0.0.1:8080/8443 (`.../scratchpad/dev-ports.yaml`). Dev nginx is running on 8443/8080 now.
3. [Rule 2] Added shellcheck `disable=SC2015,SC2016/SC2029` comments where info-level findings were intentional; Makefile target renamed `smoke-staging` -> `smoke-server`; `.env.example` doc reference updated.
4. Docs: sections 17 (Edge) and 18 (Cut-over) appended after existing numbered sections instead of renumbering historical 11-16.

## Notes for the orchestrator
- Runbook step 3 must put `COMPOSE_PROJECT_NAME`, `EDGE_ALIAS`, `NGINX_ROBOTS_TAG` into staging `.env`; section 6 of docs/ops/server.md has the parametrized generator.
- Prod `.env` needs `IMAGE_PREFIX` for `SKIP_PULL=1` (already present there per docs, or pass it in env).
- Intermediate-state risk: first deploy.sh run on prod after CI switch requires `.env` keys and network edge; deploying main before cut-over would fail the guards (by design, nothing deployed).

## Self-Check: PASSED
