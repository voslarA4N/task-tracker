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
|       |            |         |         |      |      |
