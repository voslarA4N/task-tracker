# Výkon: metodika merania a ladenie

## Účel a ciele

- odozva servera (TTFB) p95 pod 100 ms na bežných stránkach (NFR-01),
- generovanie PDF pod 2 s,
- produkčný stack projektu `task-tracker` do 512 MB v pokoji (NFR-02).

## Čo sa meria (D-12)

Do limitu 512 MB sa rátajú iba kontajnery projektu `task-tracker`: `php`, `nginx`, `db`. Staging, edge (reverzná proxy)
a mail server bežia vo vlastných stackoch, uvádzajú sa samostatne a do limitu sa nerátajú.

## Poistky konfigurácie

- `tests/unit/Ops/ProdHardeningConfigTest.php` zlyhá, ak z repozitára zmizne `http2 on`, `gzip`, `immutable` cache,
  `opcache.validate_timestamps=0`, `jit=off`, preload, `--classmap-authoritative`, alebo ak CSP dostane `unsafe-`.
- `scripts/smoke-server.sh <doména>` (sekcia 5b) overí `Cache-Control: public, max-age=31536000, immutable` a kompresiu
  (`gzip` alebo `br`) na CSS a JS v `/assets/vendor/`. Spúšťať po každom nasadení.

## Postup merania

1. Pamäť: počkať 10 minút bez prevádzky, potom `docker stats --no-stream` pre kontajnery projektu `task-tracker`
   (alebo `SSH_TARGET` a `SMOKE_PROJECT` v `smoke-server.sh`). Súčet musí byť pod 512 MiB.
2. Odozva: prihlásiť sa v prehliadači a skopírovať hodnotu cookie relácie **iba do premennej prostredia**, nikdy do súboru,
   skriptu ani do tejto dokumentácie:

   ```sh
   read -r P95_COOKIE; export P95_COOKIE
   sh scripts/measure-p95.sh https://<doména> / /tasks /invoices /reports/month
   ```

   Skript robí iba GET, jednu požiadavku naraz, `P95_N` (50) meraní na cestu po `P95_WARMUP` (3) nemeraných.
   Metrika je `time_starttransfer`. Exit 0 = p95 v limite, 1 = p95 nad `P95_LIMIT_MS` (100), 2 = status iný než 200
   (neplatná alebo expirovaná cookie). Voliteľne `P95_RESOLVE=host:443:ip` meria priamo origin bez Cloudflare.
3. Poradie: najprv staging. Produkcia iba čítacie GET a až po schválení vlastníkom.
4. PDF: `phpunit --group performance` a meranie `GET /invoices/{id}/pdf` rovnakým skriptom (cieľ pod 2 s, `P95_LIMIT_MS=2000`).

## Ladenie, ak cieľ nevyjde

- PostgreSQL: `shared_buffers` znížiť na 48MB (`compose.server.yaml`, `db` command).
- PHP-FPM: ak cold-start `pm = ondemand` pokazí p95, prejsť na `pm = dynamic` s `pm.start_servers=1`,
  `pm.min_spare_servers=1`, `pm.max_spare_servers=2`; pri nedostatku pamäte znížiť `pm.max_children` na 3.
- Obmedziť fonty mPDF, nepoužívať `useSubstitutions` ani `autoLangToFont`.

## Výsledky

Tabuľku vypĺňa plán 12-14 po meraní na VPS.

| Dátum | Prostredie | Metrika | Hodnota | Cieľ | Stav |
|-------|------------|---------|---------|------|------|
| 2026-11-09 | staging | p95 SK `/` | 249 ms (p50 209) | < 100 ms | FAIL |
| 2026-11-09 | staging | p95 SK `/tasks` | 222 ms (p50 183) | < 100 ms | FAIL |
| 2026-11-09 | staging | p95 SK `/invoices` | 242 ms (p50 202) | < 100 ms | FAIL |
| 2026-11-09 | staging | p95 SK `/reports/month` | 209 ms (p50 153) | < 100 ms | FAIL |
| 2026-11-09 | staging | p95 SK ALL (n=200) | 241 ms | < 100 ms | FAIL |
| 2026-11-09 | staging | p95 PL ALL (n=200) | 228 ms (`/` 245, `/tasks` 223, `/invoices` 232, `/reports/month` 186) | < 100 ms | FAIL |
| 2026-11-09 | staging | PDF faktúry `/invoices/50/pdf` (n=10) | p50 126 ms, p95 166 ms | < 2000 ms | OK |
| 2026-11-09 | lokálne | `phpunit --group performance` | vystavenie 353 ms (inv 224, rep 71), peak 66 MB | PDF < 2 s | OK |
| 2026-11-09 | staging | pamäť staging (nginx 5.0 + php 32.3 + db 69.6) | 106.9 MiB | informatívne | OK |
| 2026-11-09 | staging | pamäť prod stack task-tracker (nginx 5.6 + php 31.0 + db 46.1), len `docker stats` | 82.7 MiB | < 512 MiB | OK |
| 2026-11-09 | staging | pamäť edge / mail (stalwart 100.7, roundcube 30.2, unbound 9.4, web 3.9) | 2.6 / 144.3 MiB | mimo limitu | info |

### Poznámky k meraniu na stagingu (2026-11-09)

- Priamy prístup na origin (`P95_RESOLVE=...:127.0.0.1`) nejde: stack nginx vyžaduje PROXY protokol z rozsahu Cloudflare (`cf-peer.conf` vracia 444). Meranie preto prebehlo zo servera cez verejnú URL (cez Cloudflare), TTFB teda zahŕňa TCP+TLS (~30 ms) a hop Cloudflare -> origin.
- Základná línia cez Cloudflare: `/login` a `/manifest.webmanifest` TTFB 56 až 96 ms.
- Čisto aplikácia (FastCGI priamo na php-fpm, bez nginx a Cloudflare, 30 požiadaviek): `/` p50 124 / p95 150 ms, `/tasks` 90 / 109, `/invoices` 117 / 139, `/reports/month` 76 / 96. Aplikácia sama je teda na hranici cieľa 100 ms už bez siete.
- Hypotéza: cieľ nevyšiel kvôli času PHP aplikácie na 2 vCPU Skylake (CI4 + Shield + session + dotazy + šablóny, seed ~150 úloh a 40 faktúr), nie kvôli studenému štartu FPM (pm=ondemand, merania po zahriatí). Pred Task 3 profilovať počet a čas dotazov na `/` a `/invoices` a zvážiť `pm = dynamic` (CLAUDE.md).
- Testovací účet: staging seed admin (bez 2FA, cesta `/2fa/continue`); jazyk po meraní vrátený na `sk`.
