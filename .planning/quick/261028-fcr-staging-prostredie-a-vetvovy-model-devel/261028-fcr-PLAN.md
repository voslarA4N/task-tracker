---
phase: quick-261028-fcr
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - docker/edge/nginx.conf
  - compose.edge.yaml
  - docker/nginx/Dockerfile
  - docker/nginx/templates/default.conf.template
  - docker/nginx/templates/snippets/security-headers.conf.template
  - docker/nginx/realip/cloudflare.conf (git rm, replaced by docker/nginx/realip/edge.conf)
  - docker/nginx/realip/edge.conf
  - docker/nginx/http.d/cf-peer.conf
  - scripts/cf-ips-check.sh
  - scripts/edge-selftest.sh
  - compose.yaml
  - compose.staging.yaml (git mv -> compose.server.yaml)
  - compose.server.yaml
  - scripts/deploy.sh
  - scripts/cert-renew.sh
  - scripts/smoke-staging.sh (git mv -> scripts/smoke-server.sh)
  - scripts/smoke-server.sh
  - scripts/server-bootstrap.sh
  - .github/workflows/ci.yml
  - tests/unit/Scripts/DeployGuardTest.php
  - tests/unit/Scripts/StagingSecretsLayoutTest.php
  - docs/ops/staging.md (git mv -> docs/ops/server.md)
  - docs/ops/server.md
  - docs/ops/iphone-uat.md
autonomous: true
requirements: [OPS-STAGING-BRANCHES]

must_haves:
  truths:
    - "D-01: staging stack serves https://work-tracker-stage.vostack.dev with its own Let's Encrypt cert (DNS-01), same headers/CSP as prod plus X-Robots-Tag noindex"
    - "D-02: one edge nginx owns host 80/443, routes by SNI (ssl_preread) to wt-prod-nginx or wt-stage-nginx with PROXY protocol, closes unknown/missing SNI, redirects port 80 to https"
    - "D-02: stack nginx logs the real client IP from CF-Connecting-IP only when the PROXY-protocol peer address is a Cloudflare range; otherwise the request is dropped (444)"
    - "D-02: HTTP/2 still negotiated end-to-end through the edge (TLS passthrough, ALPN at the stack)"
    - "D-03: push to main deploys /opt/work-tracker (project task-tracker, environment production with reviewer); push to develop deploys /opt/work-tracker-staging (project task-tracker-staging, environment staging); check job runs on every push/PR"
    - "Prod compose project name stays task-tracker, so existing volumes task-tracker_pgdata and task-tracker_writable are reused"
    - "Two stacks on one host never clobber each other: per-path cert-renew cron, per-stack BACKUP_DIR, per-stack DOCKER_CONFIG, guard against project/working-dir mismatch"
    - "D-04: executor changes only repo files; server, DNS, GitHub settings and pushes are listed in the runbook for the orchestrator"
  artifacts:
    - path: "docker/edge/nginx.conf"
      provides: "edge stream SNI router + port 80 redirect"
      contains: "ssl_preread on"
    - path: "compose.edge.yaml"
      provides: "edge stack (ports 80/443, external network edge)"
      contains: "external: true"
    - path: "compose.server.yaml"
      provides: "shared prod/staging stack definition, no published ports"
      contains: "COMPOSE_PROJECT_NAME"
    - path: "docker/nginx/http.d/cf-peer.conf"
      provides: "geo $proxy_protocol_addr -> $cf_peer from Cloudflare ranges"
      contains: "geo $proxy_protocol_addr $cf_peer"
    - path: "scripts/edge-selftest.sh"
      provides: "local end-to-end test edge -> stack nginx (PROXY protocol, realip, h2, SNI drop, 80 redirect, noindex)"
    - path: "docs/ops/server.md"
      provides: "production, staging, edge, branch model, cut-over runbook with rollback"
  key_links:
    - from: "docker/edge/nginx.conf"
      to: "compose.server.yaml nginx alias"
      via: "map $ssl_preread_server_name -> wt-prod-nginx:443 / wt-stage-nginx:443 resolved via 127.0.0.11"
      pattern: "wt-(prod|stage)-nginx:443"
    - from: "docker/nginx/realip/edge.conf"
      to: "edge network subnet 10.250.250.0/24"
      via: "set_real_ip_from must equal the subnet created by `docker network create --subnet 10.250.250.0/24 edge`"
      pattern: "10\\.250\\.250\\.0/24"
    - from: ".github/workflows/ci.yml deploy"
      to: "scripts/deploy.sh in TARGET_DIR"
      via: "branch -> (environment, TARGET_DIR, APP_DOMAIN, BRANCH_TAG, SMOKE_PROJECT) mapping"
      pattern: "work-tracker-staging"
---

<objective>
Add a staging environment `work-tracker-stage.vostack.dev` on the same Hetzner server as production, behind a shared SNI edge proxy, and switch CI to the branch model develop -> staging, main -> production (feature/* only runs `check`).

Locked owner decisions (2026-10-28), cited as D-01..D-04 below:
- D-01: staging domain `work-tracker-stage.vostack.dev` (1st-level, Cloudflare proxied like prod).
- D-02: same server 2.31.74.48; edge nginx (own compose in /opt/edge) owns 80/443, `stream` + `ssl_preread` by SNI to prod/staging nginx over shared external docker network; each stack keeps own TLS (LE DNS-01 via secrets/cloudflare.ini), headers, CSP. Port 80 -> 301 https. Unknown SNI -> close. Short prod downtime at cut-over accepted.
- D-03: prod in /opt/work-tracker deploys on push to `main` (GitHub environment `production`, required reviewer = owner); staging in /opt/work-tracker-staging deploys on push to `develop` (environment `staging`). `check` job on every push/PR.
- D-04: prod is LIVE. Executor tasks must not touch the server, DNS, GitHub settings, and must not push. Outward-facing steps are in the section "Outward-facing runbook" at the end and are executed by the orchestrator after owner confirmation.

Output: repo changes (edge config, shared server compose, hardened deploy scripts, CI split, tests, local self-test, docs with cut-over runbook and rollback).
</objective>

<execution_context>
@/mnt/c/personal-projects/task-tracker/.claude/get-shit-done/workflows/execute-plan.md
@/mnt/c/personal-projects/task-tracker/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@./CLAUDE.md
@.planning/STATE.md
@.github/workflows/ci.yml
@compose.staging.yaml
@compose.yaml
@scripts/deploy.sh
@scripts/cert-renew.sh
@scripts/smoke-staging.sh
@scripts/server-bootstrap.sh
@scripts/cf-ips-check.sh
@docker/nginx/Dockerfile
@docker/nginx/templates/default.conf.template
@docker/nginx/templates/snippets/security-headers.conf.template
@docker/nginx/realip/cloudflare.conf
@tests/unit/Scripts/DeployGuardTest.php
@tests/unit/Scripts/StagingSecretsLayoutTest.php
@docs/ops/staging.md

<design>
Fixed names (use exactly these everywhere, docs and runbook included):
- Docker network: `edge`, external to every compose file, created once with `docker network create --driver bridge --subnet 10.250.250.0/24 edge` (subnet is outside Docker default pools 172.17-31/16 and 192.168/20; realip trusts exactly this subnet).
- Stack nginx aliases on `edge`: prod `wt-prod-nginx`, staging `wt-stage-nginx` (value of `EDGE_ALIAS` in each server `.env`).
- Compose projects: prod `task-tracker` (UNCHANGED, keeps volumes), staging `task-tracker-staging` (value of `COMPOSE_PROJECT_NAME` in each server `.env`).
- Dirs: prod `/opt/work-tracker`, staging `/opt/work-tracker-staging`, edge `/opt/edge`. Backups: `/var/backups/work-tracker`, `/var/backups/work-tracker-staging`.
- Image tags: `sha-<commit>` shared by both; moving tags `main` (prod) and `develop` (staging).

Real client IP chain (D-02 pitfall): Cloudflare -> (TCP, firewall allows only CF ranges) edge nginx `stream` with `proxy_protocol on` -> stack nginx `listen 443 ssl proxy_protocol`. In the stack: `$proxy_protocol_addr` is the Cloudflare peer; `$remote_addr` (connection peer) is the edge container in 10.250.250.0/24. Stack realip: `set_real_ip_from 10.250.250.0/24; real_ip_header CF-Connecting-IP; real_ip_recursive off;` so `$remote_addr` becomes the real client and `$binary_remote_addr` keeps working for `limit_req` (AUTH-04). Spoof guard: http-level `geo $proxy_protocol_addr $cf_peer { default 0; <CF ranges> 1; }` and in the 443 server `if ($cf_peer = 0) { return 444; }` - CF-Connecting-IP is honored only if the PROXY-protocol source is Cloudflare (same trust as before, where set_real_ip_from listed CF ranges). Both files live only in the server nginx image (dev bind-mounts templates into plain nginx and has neither dir; nginx glob includes with no match are fine, the existing realip.d include already relies on this).

Shared template stays dev-compatible through two env vars rendered by the nginx entrypoint envsubst: `NGINX_PROXY_PROTOCOL` (server: `proxy_protocol`, dev: empty) used as `listen 443 ssl default_server ${NGINX_PROXY_PROTOCOL};`, and `NGINX_ROBOTS_TAG` (staging: `noindex, nofollow`, prod and dev: empty) used as `add_header X-Robots-Tag "${NGINX_ROBOTS_TAG}" always;` in the security-headers snippet (nginx does not emit add_header with an empty value). Both MUST be defined (possibly empty) in every compose file, otherwise envsubst leaves the literal `${...}` and nginx fails.
</design>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Edge proxy and PROXY-protocol aware stack nginx with local self-test (D-01, D-02)</name>
  <files>docker/edge/nginx.conf, compose.edge.yaml, docker/nginx/Dockerfile, docker/nginx/templates/default.conf.template, docker/nginx/templates/snippets/security-headers.conf.template, docker/nginx/realip/edge.conf, docker/nginx/realip/cloudflare.conf, docker/nginx/http.d/cf-peer.conf, scripts/cf-ips-check.sh, scripts/edge-selftest.sh, compose.yaml</files>
  <action>
Per D-02 create `docker/edge/nginx.conf` as a complete main config for `nginx:1.30-alpine` (replaces /etc/nginx/nginx.conf; the official image has the stream and ssl_preread modules compiled in, confirm with nginx -t): `worker_processes 1`, small `events`, error_log to stderr. `stream {}`: `resolver 127.0.0.11 valid=10s ipv6=off;` (Docker DNS, runtime resolution so a stopped stack does not break edge start), `map $ssl_preread_server_name $wt_upstream` with `work-tracker.vostack.dev wt-prod-nginx:443;`, `work-tracker-stage.vostack.dev wt-stage-nginx:443;`, `default 127.0.0.1:1;` (unknown or missing SNI gets connection refused upstream and is closed, D-02); one server `listen 443; ssl_preread on; proxy_pass $wt_upstream; proxy_protocol on; proxy_connect_timeout 5s;` plus a stream log_format with $remote_addr, $ssl_preread_server_name, $upstream_addr, $status to stdout. `http {}`: one server `listen 80 default_server; server_tokens off; return 301 https://$host$request_uri;`, access log off. Slovak comments in the style of the repo explaining why (SNI passthrough, PROXY protocol, drop).

Create `compose.edge.yaml` (`name: edge`, header comment: runs in /opt/edge, installed manually once per docs/ops/server.md, not shipped by CI): service `edge`, image `nginx:1.30-alpine`, `restart: unless-stopped`, ports `"80:80"` and `"443:443"`, volume `./nginx.conf:/etc/nginx/nginx.conf:ro`, network `edge`; top-level `networks: edge: external: true` (name edge). Comment that the network is created with the fixed subnet from docs.

Stack nginx: in `default.conf.template` change the 443 listen to `listen 443 ssl default_server ${NGINX_PROXY_PROTOCOL};` keeping `http2 on;`; add at the top (http context, before the map) `include /etc/nginx/http.d/*.conf;` with a comment (server image only, dev has no dir). Keep `include /etc/nginx/realip.d/*.conf;` in the 443 server and update its comment. In `snippets/security-headers.conf.template` add `add_header X-Robots-Tag "${NGINX_ROBOTS_TAG}" always;` with a comment (empty value = header not sent; staging sets noindex per D-01). `git rm docker/nginx/realip/cloudflare.conf` and create `docker/nginx/realip/edge.conf` (hand-written, static): `set_real_ip_from 10.250.250.0/24;`, `real_ip_header CF-Connecting-IP;`, `real_ip_recursive off;`, `if ($cf_peer = 0) { return 444; }`, with comments on the chain from the design block. Create `docker/nginx/http.d/cf-peer.conf` with `geo $proxy_protocol_addr $cf_peer { default 0; <each current CF v4/v6 range> 1; }` - take the ranges from the deleted cloudflare.conf (do not fetch new ones in this task) and a generated-by header. In `docker/nginx/Dockerfile` add `COPY docker/nginx/http.d /etc/nginx/http.d` and update the header comment (server image for prod and staging).

`scripts/cf-ips-check.sh`: TARGET becomes `docker/nginx/http.d/cf-peer.conf`; generate the geo block format (header lines, `geo $proxy_protocol_addr $cf_peer {`, `    default 0;`, sorted `    <range> 1;` lines, `}`); keep the `--write` / diff behaviour and the `# Generované` header-line exclusion. Must remain POSIX sh.

`compose.yaml` (dev): add to nginx environment `NGINX_PROXY_PROTOCOL: ""` and `NGINX_ROBOTS_TAG: ${NGINX_ROBOTS_TAG:-}` with a short comment (shared template, dev has no edge).

Create `scripts/edge-selftest.sh` (POSIX sh, `set -eu`, Slovak header comment, run from repo root, needs only docker, curl, openssl; prints OK/FAIL lines like smoke scripts, exit 1 on any FAIL; trap removes all its containers, the network and temp dir). Steps: build the server nginx image (`docker build -f docker/nginx/Dockerfile -t wt-nginx:selftest .`); self-signed cert for CN work-tracker-stage.vostack.dev into a temp dir (mode readable by nginx); `docker network create --subnet 10.250.250.0/24 wt-edge-selftest` (fail with a clear message if the subnet is taken, e.g. a real `edge` network exists); run the stack container with `--network wt-edge-selftest --network-alias wt-stage-nginx --add-host php:127.0.0.1`, env APP_DOMAIN, NGINX_HSTS_VALUE=max-age=300, SSL_CERT/SSL_KEY pointing to the mounted cert, NGINX_PROXY_PROTOCOL=proxy_protocol, NGINX_ROBOTS_TAG; run the edge container on the same network with `docker/edge/nginx.conf` mounted and ports `127.0.0.1:18443:443`, `127.0.0.1:18080:80`. Three stack runs (recreate only the stack container between them, wait until `nginx` answers): Run A with NGINX_ROBOTS_TAG="noindex, nofollow" and a permissive geo override mounted over /etc/nginx/http.d/cf-peer.conf (`geo $proxy_protocol_addr $cf_peer { default 1; }`): check (1) `curl -sk --http2 --resolve work-tracker-stage.vostack.dev:18443:127.0.0.1 -H 'CF-Connecting-IP: 203.0.113.9'` on `/robots.txt` returns `200 2` (code and http_version) with headers X-Robots-Tag noindex and Content-Security-Policy; (2) stack container logs contain `203.0.113.9` (realip from CF-Connecting-IP via trusted edge subnet); (3) same request with `--resolve evil.example:18443:127.0.0.1` to `https://evil.example:18443/` fails (curl exit non-zero, no HTTP status); (4) `curl -s -o /dev/null -D -` to `http://work-tracker-stage.vostack.dev:18080/x` (with --resolve) gives 301 and a Location starting with `https://work-tracker-stage.vostack.dev/x`. Run B with NGINX_ROBOTS_TAG empty and permissive geo: (5) `/robots.txt` 200 and no X-Robots-Tag header (prod behaviour). Run C with NGINX_ROBOTS_TAG empty and the real baked cf-peer.conf: (6) request from localhost (non-Cloudflare PROXY source) gets no HTTP response (444 closes; curl exit 52 or 56). Also (0) before runs: `nginx -t` of edge config in a throwaway container passes.

Do not touch the server; everything is local docker (D-04).
  </action>
  <verify>
    <automated>cd /mnt/c/personal-projects/task-tracker && sh scripts/edge-selftest.sh && docker run --rm --add-host php:127.0.0.1 -v "$PWD/docker/nginx/templates:/etc/nginx/templates:ro" -v "$PWD/docker/certs:/etc/nginx/certs:ro" -e APP_DOMAIN=work-tracker.loc -e NGINX_HSTS_VALUE=max-age=300 -e SSL_CERT=/etc/nginx/certs/work-tracker.loc.pem -e SSL_KEY=/etc/nginx/certs/work-tracker.loc-key.pem -e NGINX_PROXY_PROTOCOL= -e NGINX_ROBOTS_TAG= nginx:1.30-alpine nginx -t && docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:stable scripts/edge-selftest.sh scripts/cf-ips-check.sh</automated>
  </verify>
  <done>edge-selftest.sh prints only OK lines (h2 through edge, realip 203.0.113.9 in logs, unknown SNI closed, port 80 -> 301 https, noindex only when set, non-CF PROXY source dropped); dev template still passes nginx -t without proxy_protocol (if docker/certs lacks the dev cert, the executor generates a throwaway self-signed one in the scratchpad and mounts it instead); shellcheck clean.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Shared server compose, two-stack-safe deploy scripts, CI branch model (D-02, D-03)</name>
  <files>compose.staging.yaml -> compose.server.yaml, scripts/deploy.sh, scripts/cert-renew.sh, scripts/smoke-staging.sh -> scripts/smoke-server.sh, scripts/server-bootstrap.sh, .github/workflows/ci.yml, tests/unit/Scripts/DeployGuardTest.php, tests/unit/Scripts/StagingSecretsLayoutTest.php</files>
  <behavior>
    - deploy.sh fails before any docker call when .env lacks COMPOSE_PROJECT_NAME or EDGE_ALIAS
    - deploy.sh fails before `pull` when `docker network inspect edge` fails (fake docker FAKE_NO_EDGE=1)
    - deploy.sh fails before `pull` when containers of the same compose project have a working_dir label different from the current dir (fake docker FAKE_PS_WORKDIR=/other)
    - SKIP_PULL=1: no `pull` call, `image inspect` of both images instead; the rest of the order unchanged
    - letsencrypt mode: existing crontab line for another stack (/opt/other/cert-renew.sh) is preserved, exactly one line for <this dir>/cert-renew.sh is written
    - existing tests still pass (setUp .env gains COMPOSE_PROJECT_NAME = test-stack and EDGE_ALIAS = wt-test-nginx)
    - compose.server.yaml: nginx service has no `ports:`, joins external network edge with alias ${EDGE_ALIAS}, top-level name uses ${COMPOSE_PROJECT_NAME:?}; WR-06 assertions unchanged
  </behavior>
  <action>
`git mv compose.staging.yaml compose.server.yaml`. Header comment: used by prod (/opt/work-tracker, project task-tracker) and staging (/opt/work-tracker-staging, project task-tracker-staging); no host ports, the edge stack owns 80/443 (D-02). Set `name: ${COMPOSE_PROJECT_NAME:?Chýba COMPOSE_PROJECT_NAME v .env}` (prod value task-tracker keeps volumes; `:?` prevents a stack silently taking another project's name). nginx service: remove `ports`, add env `NGINX_PROXY_PROTOCOL: proxy_protocol` and `NGINX_ROBOTS_TAG: ${NGINX_ROBOTS_TAG:-}`, networks `default` plus `edge` with `aliases: ["${EDGE_ALIAS:?}"]`; top-level networks `default: {}` and `edge: external: true` (name edge). Other services stay on default only. Change the certbot comment to compose.server.yaml.

`scripts/deploy.sh`: default `COMPOSE_FILE_NAME=compose.server.yaml`; `BACKUP_DIR=${BACKUP_DIR:-/var/backups/$(basename "$(pwd -P)")}` (prod stays /var/backups/work-tracker); `DOCKER_CONFIG=${DOCKER_CONFIG:-$(pwd -P)/.docker}` exported so the GHCR login and the logout trap of one stack never affect the other's concurrent deploy. In preflight (no docker calls) require non-empty `COMPOSE_PROJECT_NAME` and `EDGE_ALIAS` in .env (read via env_get); export COMPOSE_PROJECT_NAME. New function `preflight_host` run after preflight_secrets and before pulling: `docker network inspect edge` must succeed (message: create per docs/ops/server.md); `docker ps -a --filter label=com.docker.compose.project=$COMPOSE_PROJECT_NAME --format '{{index .Labels "com.docker.compose.project.working_dir"}}'` sorted unique, every non-empty value must equal `pwd -P`, otherwise exit 1 with a message naming both paths. `SKIP_PULL=1` replaces `dc pull php nginx` with `docker image inspect "$IMAGE_PREFIX-php:$IMAGE_TAG" "$IMAGE_PREFIX-nginx:$IMAGE_TAG"` (IMAGE_PREFIX from env, else env_get IMAGE_PREFIX; needed for the prod cut-over that reuses images pulled by the staging deploy). Cron: `HERE=$(pwd -P)`, line `17 3 * * * $HERE/cert-renew.sh >> $HERE/cert-renew.log 2>&1`, filter only lines containing `$HERE/cert-renew.sh` (grep -vF), keep all others. Update comments and the docs reference (docs/ops/server.md). Keep everything else (backup before migrate D-08, rotation, rollback to .last-good-tag) byte-for-byte in behaviour.

`scripts/cert-renew.sh`: use compose.server.yaml; read COMPOSE_PROJECT_NAME is not needed (compose reads .env) but keep `cd "$(dirname "$0")"`. `scripts/backup.sh` is a dev/repo-root tool (not shipped to the server, no host path) - leave unchanged.

`git mv scripts/smoke-staging.sh scripts/smoke-server.sh`; header and usage text generic (prod or staging). Memory check: when `SMOKE_PROJECT` is set, sum only containers with label `com.docker.compose.project=$SMOKE_PROJECT` (docker ps -q filter, then docker stats on those IDs over SSH); label the line with the project; without SMOKE_PROJECT keep current behaviour. Add optional check: if `SMOKE_EXPECT_NOINDEX=1`, `/login` headers must contain `X-Robots-Tag: noindex`, else must NOT contain X-Robots-Tag (prod must stay indexable-neutral).

`scripts/server-bootstrap.sh` (for rebuild from scratch only, not run on live prod in this change): loop the existing dir creation over `/opt/work-tracker` and `/opt/work-tracker-staging` (secrets, certs with GID 82, letsencrypt, docker) and both backup dirs; create `/opt/edge` (0750 deploy); after Docker install create network `edge` with subnet 10.250.250.0/24 if `docker network inspect edge` fails. Final echo references docs/ops/server.md.

`.github/workflows/ci.yml` (D-03): keep `on: push, pull_request` and the `check` job unchanged. Replace the deploy job: `if: github.event_name == 'push' && (github.ref == 'refs/heads/main' || github.ref == 'refs/heads/develop')`; `environment: name: ${{ github.ref_name == 'main' && 'production' || 'staging' }}`, url to the matching domain; `concurrency: group: deploy-${{ github.ref_name }}, cancel-in-progress: false`. First step "Ciel nasadenia" writes to GITHUB_ENV by `case "$GITHUB_REF_NAME"`: main -> TARGET_DIR=/opt/work-tracker, APP_DOMAIN=work-tracker.vostack.dev, BRANCH_TAG=main, SMOKE_PROJECT=task-tracker, SMOKE_EXPECT_NOINDEX=0; develop -> /opt/work-tracker-staging, work-tracker-stage.vostack.dev, develop, task-tracker-staging, 1; other -> exit 1. Build/push use tags sha-$GITHUB_SHA and $BRANCH_TAG (replaces hardcoded main). Deploy step: scp compose.server.yaml, scripts/deploy.sh, scripts/cert-renew.sh to "$T:$TARGET_DIR/" and docker/postgres to "$T:$TARGET_DIR/docker/"; GHCR login over ssh with `mkdir -p -m 700 $TARGET_DIR/.docker && DOCKER_CONFIG=$TARGET_DIR/.docker docker login ghcr.io ...`; run `cd $TARGET_DIR && DOCKER_CONFIG=$TARGET_DIR/.docker IMAGE_PREFIX=... IMAGE_TAG=sha-... sh ./deploy.sh`. Smoke step named "Smoke" runs `sh scripts/smoke-server.sh "$APP_DOMAIN"` with SSH_TARGET, SMOKE_PROJECT, SMOKE_EXPECT_NOINDEX. Keep existing shellcheck disable comments and the secret names DEPLOY_SSH_KEY, DEPLOY_KNOWN_HOSTS, DEPLOY_HOST, DEPLOY_USER (they will exist in both environments).

Tests (RED first, then GREEN): extend FAKE_DOCKER in DeployGuardTest with cases for `network inspect` (exit 1 if FAKE_NO_EDGE set), `ps` (print FAKE_PS_WORKDIR if set) and `image inspect`; extend FAKE_CRONTAB so `-l` prints FAKE_CRONTAB_EXISTING and `-` appends stdin to FAKE_LOG; add the five behaviours above as tests; runDeploy keeps passing BACKUP_DIR. StagingSecretsLayoutTest: read compose.server.yaml, keep WR-06 assertions, add assertions for no `ports:` in the nginx block, `external: true`, `${COMPOSE_PROJECT_NAME:?` in name, `NGINX_PROXY_PROTOCOL: proxy_protocol`. Update docblocks that say "staging" to "server (prod aj staging)".
  </action>
  <verify>
    <automated>cd /mnt/c/personal-projects/task-tracker && docker compose exec -T -u www-data php vendor/bin/phpunit tests/unit/Scripts && docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:stable scripts/deploy.sh scripts/cert-renew.sh scripts/smoke-server.sh scripts/server-bootstrap.sh && docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest -color && S=/tmp/claude-1000/-mnt-c-personal-projects-task-tracker/aafc0561-56ff-41f0-958c-22aa0c225f74/scratchpad/fcr-compose && for p in task-tracker:wt-prod-nginx task-tracker-staging:wt-stage-nginx; do rm -rf "$S" && mkdir -p "$S/secrets" && cp compose.server.yaml "$S/" && touch "$S/app.env" "$S/migrate.env" "$S/secrets/db_superuser_password" "$S/secrets/db_owner_password" "$S/secrets/db_rw_password" "$S/secrets/cloudflare.ini" && printf 'COMPOSE_PROJECT_NAME=%s\nEDGE_ALIAS=%s\nIMAGE_PREFIX=ghcr.io/x/task-tracker\nIMAGE_TAG=sha-test\nAPP_DOMAIN=d.example\nNGINX_HSTS_VALUE=max-age=300\nSSL_CERT=/c.pem\nSSL_KEY=/k.pem\n' "${p%%:*}" "${p##*:}" > "$S/.env" && (cd "$S" && docker compose -f compose.server.yaml config --format json | grep -q "\"name\": \"${p%%:*}\"" && ! docker compose -f compose.server.yaml config | grep -qE 'published|443:443') || exit 1; done && echo COMPOSE_OK</automated>
  </verify>
  <done>All tests in tests/unit/Scripts pass (new and existing); shellcheck and actionlint clean; compose config renders project task-tracker for prod and task-tracker-staging for staging with no published ports and the edge alias; CI deploy job maps main -> production/opt/work-tracker and develop -> staging/opt/work-tracker-staging. If the dev stack is not running, start it with `make up` before phpunit.</done>
</task>

<task type="auto">
  <name>Task 3: docs/ops/server.md with prod, staging, edge, branch model, cut-over and rollback runbook (D-01..D-04)</name>
  <files>docs/ops/staging.md -> docs/ops/server.md, docs/ops/iphone-uat.md</files>
  <action>
`git mv docs/ops/staging.md docs/ops/server.md` and restructure (Slovak, same tone; keep existing historical sections 13-16 but fix wording). Title "Server runbook: produkcia a staging". Section 1 Prehľad: table of the two environments (domain, dir, compose project, edge alias, GitHub environment, branch, backup dir) plus edge (/opt/edge) and network `edge` 10.250.250.0/24; note D-03 from phase 2 ("staging je zároveň budúca produkcia") is superseded: /opt/work-tracker is production since go-live. New section "Vetvový model" (D-03): work on `feature/<nazov>` -> PR into `develop` (check job) -> merge deploys staging -> PR `develop` -> `main` -> deploy waits for owner approval in environment `production` -> production. Hotfix: `hotfix/<nazov>` from main, PR to main, then merge main back to develop. Cloudflare section: add `A work-tracker-stage -> 2.31.74.48 Proxied`; same settings apply to both hostnames (zone-level). Firewall section unchanged except a note that the edge container is the only publisher of 80/443. New section "Edge proxy": purpose, files (docker/edge/nginx.conf, compose.edge.yaml), one-time install commands, how to add a hostname (map line + reload `docker compose -f compose.edge.yaml exec edge nginx -s reload`), real-IP chain explanation (PROXY protocol, realip edge.conf, cf-peer.conf geo guard, cf-ips-check.sh now writes docker/nginx/http.d/cf-peer.conf), local test `sh scripts/edge-selftest.sh`. Section 6 (.env/app.env/migrate.env/secrets): parametrize the existing generator by DIR and DOMAIN, add the new .env keys `COMPOSE_PROJECT_NAME`, `EDGE_ALIAS`, `NGINX_ROBOTS_TAG` (staging `noindex, nofollow`, prod empty or absent), give the staging variant explicitly (fresh passwords, encryption key and pepper - never copy prod secrets; `email.transport = log`; `app.baseURL = 'https://work-tracker-stage.vostack.dev/'`; `secrets/cloudflare.ini` may be a copy of prod's zone-scoped token). Staging first admin: `spark user:create` command with compose.server.yaml in /opt/work-tracker-staging. New section "Prechod produkcie na edge (cut-over)" with exactly the steps of the runbook below (pre-checks, backups of compose/scripts/.env/.last-good-tag as `*.pre-edge`, `SKIP_PULL=1` deploy with the sha already pulled by staging, start edge immediately, expected downtime under 1 minute with Cloudflare 52x meanwhile, verification via smoke for both domains, and Rollback: stop edge, restore `*.pre-edge` scripts and tag, `docker compose -f compose.staging.yaml up -d --wait --remove-orphans` with the old tag so 80/443 are published again; do not push main until resolved; compose.staging.yaml on the server is kept until a week of stable operation, then deleted). Replace every server command `compose.staging.yaml` with `compose.server.yaml` outside the rollback text and historical notes; in section 16 replace "staging" with "produkcia" where it means the live server and add "first verify on staging" hints. In docs/ops/iphone-uat.md update references `docs/ops/staging.md` -> `docs/ops/server.md` and `compose.staging.yaml` -> `compose.server.yaml` in commands (results columns untouched). Grep the repo (excluding .planning/) for leftover `compose.staging.yaml`, `smoke-staging.sh`, `docs/ops/staging.md` and fix them except the intentional rollback mentions.
  </action>
  <verify>
    <automated>cd /mnt/c/personal-projects/task-tracker && test -f docs/ops/server.md && ! test -e docs/ops/staging.md && grep -q 'work-tracker-stage.vostack.dev' docs/ops/server.md && grep -q '10.250.250.0/24' docs/ops/server.md && grep -q 'SKIP_PULL=1' docs/ops/server.md && grep -q 'pre-edge' docs/ops/server.md && grep -qi 'feature/' docs/ops/server.md && test -z "$(git grep -lE 'smoke-staging\.sh|docs/ops/staging\.md' -- ':!.planning')" && test -z "$(git grep -l 'compose.staging.yaml' -- ':!.planning' ':!docs/ops/server.md')" && echo DOCS_OK</automated>
  </verify>
  <done>docs/ops/server.md documents both environments, edge, branch model, staging provisioning and the cut-over runbook with rollback; no stale references outside .planning and the intentional rollback text.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Internet -> Cloudflare -> edge:443 | only Cloudflare ranges pass the Hetzner firewall; SNI is attacker-controlled |
| edge -> stack nginx (docker network edge) | PROXY header and CF-Connecting-IP trusted only from 10.250.250.0/24 and only if the PROXY source is a CF range |
| GitHub Actions -> server (SSH) | deploy job with environment-scoped secrets; production gated by owner approval |
| staging stack <-> prod stack | same host and docker daemon; must not share project, volumes, secrets, cron, registry credentials |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-fcr-01 | Spoofing | stack realip (CF-Connecting-IP) | mitigate | realip trusts only edge subnet; geo $proxy_protocol_addr -> $cf_peer, `return 444` for non-CF PROXY source; tested in edge-selftest run C |
| T-fcr-02 | Denial of service | rate limit AUTH-04 | mitigate | $binary_remote_addr remains the real client after realip, so limit_req keys are per client, not per edge container; selftest checks realip in logs |
| T-fcr-03 | Tampering | staging compose hijacking prod project/volumes | mitigate | `name: ${COMPOSE_PROJECT_NAME:?}` plus deploy.sh working_dir label guard; tests in DeployGuardTest |
| T-fcr-04 | Elevation of privilege | unreviewed deploy to prod | mitigate | environment `production` with required reviewer, main-only branch policy (runbook) |
| T-fcr-05 | Information disclosure | staging secrets | mitigate | staging gets fresh DB passwords, encryption key and pepper; never copied from prod (docs section 6) |
| T-fcr-06 | Denial of service | edge single point / unknown SNI scanners | mitigate | unknown/missing SNI closed at edge; runtime DNS resolve so one stack down does not stop the other; rollback restores direct publishing |
| T-fcr-07 | Information disclosure | staging indexed by search engines | mitigate | X-Robots-Tag noindex on staging only (smoke check SMOKE_EXPECT_NOINDEX) |
| T-fcr-08 | Tampering | concurrent deploys sharing ~/.docker credentials or cron | mitigate | per-stack DOCKER_CONFIG, per-path cron line, per-stack BACKUP_DIR |
| T-fcr-SC | Tampering | package installs | accept | no npm/composer/pip packages added; only pinned official images (nginx:1.30-alpine) and CI-time linters (rhysd/actionlint, koalaman/shellcheck) not shipped |
</threat_model>

<verification>
- `sh scripts/edge-selftest.sh` all OK.
- `docker compose exec -T -u www-data php vendor/bin/phpunit tests/unit/Scripts` green.
- shellcheck on changed scripts and actionlint on ci.yml clean.
- `docker compose -f compose.server.yaml config` renders correct project names for prod and staging sample .env with no published ports.
- No commits pushed, no ssh to 2.31.74.48, no gh api calls (D-04).
</verification>

<success_criteria>
Repo contains everything needed for staging + edge + branch model; the orchestrator can perform the outward-facing runbook without further repo changes; prod volumes and project name are untouched by design.
</success_criteria>

<output>
Create `.planning/quick/261028-fcr-staging-prostredie-a-vetvovy-model-devel/261028-fcr-SUMMARY.md` when done (include the commit SHA that the runbook will deploy).
</output>

## Outward-facing runbook (orchestrator, after owner confirmation)

Not for the executor (D-04). Run in this order; each step needs the owner's explicit go-ahead. `X` = full SHA of the commit with this plan's changes.

1. **Cloudflare DNS**: in zone vostack.dev add `A work-tracker-stage -> 2.31.74.48`, Proxied (orange). SSL mode Full (strict) and the other zone settings already apply. Check `dig +short work-tracker-stage.vostack.dev` returns CF IPs.
2. **GitHub environments** (`gh api` on the repo):
   - Create `production`: required reviewer = owner, deployment branch policy = `main` only. Add secrets DEPLOY_SSH_KEY, DEPLOY_KNOWN_HOSTS, DEPLOY_HOST, DEPLOY_USER (same values as the current `staging` environment; values cannot be read back, so take the key from `~/.ssh/work_tracker_deploy`, known_hosts via `ssh-keyscan 2.31.74.48` verified against the recorded fingerprint, host 2.31.74.48, user deploy).
   - Existing `staging`: change deployment branch policy to `develop` only; secrets stay.
3. **Server, staging dirs and secrets** (as root, prod untouched): `install -d` for /opt/work-tracker-staging and its secrets (0700), certs (deploy:82, 0750), letsencrypt, docker subdirs; `/var/backups/work-tracker-staging` (0700 deploy); `/opt/edge` (0750 deploy). As deploy in /opt/work-tracker-staging: create `.env`, `app.env`, `migrate.env`, `secrets/db_*_password` with fresh values per docs/ops/server.md section 6 (staging variant: COMPOSE_PROJECT_NAME = task-tracker-staging, EDGE_ALIAS = wt-stage-nginx, NGINX_ROBOTS_TAG = noindex, nofollow, APP_DOMAIN and SSL paths for work-tracker-stage.vostack.dev, email.transport = log), copy `secrets/cloudflare.ini` from prod (0600). Store staging secrets copy off-server per section 7.
4. **Edge network and files**: `docker network create --driver bridge --subnet 10.250.250.0/24 edge` (check `docker network ls` / `ip route` for collisions first). scp `docker/edge/nginx.conf` and `compose.edge.yaml` to /opt/edge as `nginx.conf` and `compose.edge.yaml`. Do NOT start edge yet.
5. **Push develop**: `git push origin <local branch with X>:refs/heads/develop` (creates develop). CI: check -> deploy to staging (pulls images sha-X onto the server, issues LE cert for work-tracker-stage, starts the staging stack on network edge). The final "Smoke" step is expected to FAIL because edge is not running yet; that is fine.
6. **Prod cut-over** (downtime < 1 minute, Cloudflare shows 52x meanwhile), as deploy:
   - `cd /opt/work-tracker`; `cp compose.staging.yaml compose.staging.yaml.pre-edge; cp deploy.sh deploy.sh.pre-edge; cp cert-renew.sh cert-renew.sh.pre-edge; cp .env .env.pre-edge; cp .last-good-tag .last-good-tag.pre-edge`; keep compose.staging.yaml in place.
   - scp from the repo at X: `compose.server.yaml`, `scripts/deploy.sh`, `scripts/cert-renew.sh` into /opt/work-tracker.
   - Append to prod `.env`: `COMPOSE_PROJECT_NAME = task-tracker`, `EDGE_ALIAS = wt-prod-nginx` (no NGINX_ROBOTS_TAG). Verify: `IMAGE_TAG=sha-X docker compose -f compose.server.yaml config | grep -E '^name: task-tracker$'` and `docker volume ls | grep task-tracker_pgdata`.
   - `IMAGE_TAG=sha-X SKIP_PULL=1 sh ./deploy.sh` (verified backup, no-op migration, nginx recreated without host ports -> downtime starts) then immediately `cd /opt/edge && docker compose -f compose.edge.yaml up -d` (downtime ends).
   - Verify: `sh scripts/smoke-server.sh work-tracker.vostack.dev` with SMOKE_PROJECT=task-tracker, SSH_TARGET, ORIGIN_IP=2.31.74.48, and log in on prod; check `crontab -l` has one line per stack.
   - **Rollback** if anything fails: `cd /opt/edge && docker compose -f compose.edge.yaml down`; `cd /opt/work-tracker && cp deploy.sh.pre-edge deploy.sh && cp cert-renew.sh.pre-edge cert-renew.sh && cp .last-good-tag.pre-edge .last-good-tag && IMAGE_TAG=$(cat .last-good-tag) docker compose -f compose.staging.yaml up -d --wait --remove-orphans` (old project name task-tracker, ports 80/443 published again, same volumes). Restore cron line with the old deploy.sh behaviour if changed. Do not push main until fixed.
7. **Re-run staging deploy**: `gh run rerun <run-id-from-step-5> --failed`; smoke for work-tracker-stage must pass incl. noindex. Create the staging admin via `spark user:create` (owner enters the password interactively).
8. **Push main**: open PR develop -> main (or fast-forward push of X to main); CI deploy waits for owner approval in environment `production`, then deploys the same sha-X (re-pull, idempotent) and runs smoke against work-tracker.vostack.dev.
9. After ~1 week of stable operation: delete `*.pre-edge` files and `compose.staging.yaml` on the prod server.
