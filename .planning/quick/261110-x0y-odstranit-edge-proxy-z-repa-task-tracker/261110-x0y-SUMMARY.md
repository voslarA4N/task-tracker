---
phase: quick-261110-x0y
plan: 01
status: complete
completed: 2026-11-10
key-files:
  deleted: [compose.edge.yaml, docker/edge/nginx.conf, scripts/edge-selftest.sh]
  created: [docs/ops/edge.md]
  modified: [docs/ops/server.md, docs/ops/mail.md, compose.server.yaml, scripts/server-bootstrap.sh]
---

# Quick 261110-x0y: edge proxy odstraneny z repa

Edge stack (compose.edge.yaml, docker/edge, edge-selftest) odstraneny z repa, historia zachovana lokalnym anotovanym tagom `edge-final` (na 39ed8c4, obsahuje cde96a8). Tag nepushnuty.

## Zmeny
- Tag `edge-final`, `git rm` troch edge suborov.
- Nova poznamka docs/ops/edge.md (klientska zmluva, odkazy na tag).
- server.md: sekcia 17 skratena (zostal odsek o realip/cf-peer), sekcia 18 jedna veta, hlavicka sekcie 1 upravena.
- mail.md, compose.server.yaml a server-bootstrap.sh: iba komentare/odkazy.
- deploy.sh, ci.yml, docker/nginx, testy nezmenene. Server nedotknuty.

## Commit
- d9f7b96: docs(quick-261110-x0y): odstranenie edge proxy z repa (jediny commit pre tasky 1 a 2; task 3 bez zmien suborov)

## Overenie
- DeployGuardTest: OK (30 tests, 165 assertions); StagingSecretsLayoutTest: OK (8 tests, 45 assertions), v dev kontajneri.
- grep na compose.edge/docker/edge/edge-selftest mimo .planning a edge.md: bez vyskytov.

## Deviations
Ziadne okrem toho, ze tasky 1 a 2 su v jednom commite.
