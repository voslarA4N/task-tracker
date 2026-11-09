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

Tabuľku vypĺňa plán 12-15 po meraní na VPS.

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
| 2026-11-09 | staging | app-only (FastCGI na php-fpm, n=60) PRED ladením: p50 / p95 `/` 129 / 152, `/tasks` 98 / 123, `/invoices` 121 / 148, `/reports/month` 82 / 108 ms | p95 max 152 ms | < 100 ms | FAIL |
| 2026-11-09 | staging | app-only (FastCGI, n=60) PO ladení (commity bbd31ca a be3dc26): `/` 55 / 76, `/tasks` 43 / 53, `/invoices` 54 / 66, `/reports/month` 28 / 34 ms (druhý beh: `/` 63 / 87, ostatné rovnako) | p95 max 87 ms | < 100 ms | OK |
| 2026-11-09 | staging | cez verejnú URL (Cloudflare, `measure-p95.sh`, SK, n=50) PO ladení: `/` 161 / 229, `/tasks` 131 / 178, `/invoices` 143 / 172, `/reports/month` 110 / 134 ms, ALL p95 191 ms (pred: 241) | p95 191 ms | < 100 ms | FAIL (sieť) |
| 2026-11-09 | prod | app-only (FastCGI na php-fpm, `fc-prod.php`, n=50) PRED ladením: `/` 125 / 146, `/tasks` 76 / 94, `/invoices` 89 / 109, `/reports/month` 75 / 104 ms | p95 ALL 138 ms | < 100 ms | FAIL |
| 2026-11-09 | prod | cez verejnú URL (Cloudflare, `measure-p95.sh`, n=50) PRED ladením: `/` 219 / 269, `/tasks` 180 / 220, `/invoices` 185 / 244, `/reports/month` 171 / 223 ms | p95 ALL 245 ms | < 100 ms | FAIL (sieť) |
| 2026-11-09 | prod | PDF faktúry `/invoices/4/pdf` cez Cloudflare (n=10) | p50 183 ms, p95 230 ms | < 2000 ms | OK |
| 2026-11-09 | prod | pamäť prod stack task-tracker v pokoji (nginx 5.6 + php 45.2 + db 46.5) | 97.3 MiB | < 512 MiB | OK |
| 2026-11-09 | prod | pamäť ostatné (staging 97.8, mail 151.3, edge 2.6), `free -m`: used 1087 z 3814 MiB | informatívne | mimo limitu | info |
| 2026-11-09 | prod | app-only (FastCGI, n=50) PO ladení (PR #14): `/` 46 / 61, `/tasks` 23 / 33, `/invoices` 31 / 38, `/reports/month` 24 / 30 ms | **p95 ALL 51 ms** | < 100 ms | **OK** |
| 2026-11-09 | prod | cez verejnú URL (Cloudflare, n=50) PO ladení: `/` 151 / 205, `/tasks` 123 / 166, `/invoices` 126 / 165, `/reports/month` 118 / 152 ms | p95 ALL 176 ms | < 100 ms | FAIL (sieť) |

### Poznámky k meraniu na stagingu (2026-11-09)

- Priamy prístup na origin (`P95_RESOLVE=...:127.0.0.1`) nejde: stack nginx vyžaduje PROXY protokol z rozsahu Cloudflare (`cf-peer.conf` vracia 444). Meranie preto prebehlo zo servera cez verejnú URL (cez Cloudflare), TTFB teda zahŕňa TCP+TLS (~30 ms) a hop Cloudflare -> origin.
- Základná línia cez Cloudflare: `/login` a `/manifest.webmanifest` TTFB 56 až 96 ms.
- Čisto aplikácia (FastCGI priamo na php-fpm, bez nginx a Cloudflare, 30 požiadaviek): `/` p50 124 / p95 150 ms, `/tasks` 90 / 109, `/invoices` 117 / 139, `/reports/month` 76 / 96. Aplikácia sama je teda na hranici cieľa 100 ms už bez siete.
- Hypotéza: cieľ nevyšiel kvôli času PHP aplikácie na 2 vCPU Skylake (CI4 + Shield + session + dotazy + šablóny, seed ~150 úloh a 40 faktúr), nie kvôli studenému štartu FPM (pm=ondemand, merania po zahriatí). Pred Task 3 profilovať počet a čas dotazov na `/` a `/invoices` a zvážiť `pm = dynamic` (CLAUDE.md).
- Testovací účet: staging seed admin (bez 2FA, cesta `/2fa/continue`); jazyk po meraní vrátený na `sk`.

### Ladenie (2026-11-09, plán 12-15)

Meranie "app-only": FastCGI priamo na php-fpm v kontajneri `php` na stagingu (bez nginx a Cloudflare), prihlásená relácia, 3 nemerané + 60 meraných GET na stránku, čas do posledného bajtu odpovede. Pred ladením vyšli rovnaké čísla ako v meraní 12-14 (tabuľka vyššie).

Čo sa overilo a vylúčilo (konfigurácia produkcie na stagingu je v poriadku): `CI_ENVIRONMENT=production`, OPcache `validate_timestamps=0`, hit rate 99,9 %, 18 z 64 MB použitých, žiadne restarty, interned strings 3,9 z 8 MB, preload aktívny, `--classmap-authoritative`, súborový session handler. Samotný štart CI4 + Shield s presmerovaním (`/login` pre prihláseného) stál na stagingu cca 49 ms, statická odpoveď `/offline` 7 ms, takže cena je v aplikácii a DB, nie v FPM ani v konfigurácii.

Profilovanie (prod obraz lokálne, rovnaký seed, excimer vzorkovač v jednorazovom kontajneri, nič sa nekomitovalo):

1. **Nové PostgreSQL spojenie na každú požiadavku (hlavná príčina).** 73 % času `/` bolo v `BaseConnection::query`: 22 % samotné pripojenie (fork backendu, SCRAM) a zvyšok dotazy bežiace v studenom backende (prázdne katalógové cache, plánovanie pohľadov). Rovnaký dotaz nad `invoice_status_v` trval v čerstvom spojení 5,8 až 8,2 ms, v zahriatom 1,4 ms. `/` robí cca 30 dotazov. Oprava: `pConnect` pre `PHP_SAPI === 'fpm-fcgi'` v `app/Config/Database.php` (CLI a testy ostávajú bez neho, `.env` to môže prebiť). Lokálne `/` 39 -> 19 ms, `/invoices` 39 -> 23, `/reports/month` 22 -> 9.
2. **`MoneyFormat::format` vytváral dva `NumberFormatter` (ICU) na každú sumu**, vo výpisoch stovky krát na požiadavku (po bode 1 to bolo 12,7 % `/invoices`). Oprava: formátovače sa držia v pamäti požiadavky podľa lokality a meny. Lokálne `/invoices` 23 -> 17, `/tasks` 19 -> 15.

Bezpečnosť trvalých spojení: aplikácia nepoužíva relačné `SET` (iba `SET LOCAL` a transakčné zámky); jediný session zámok `pg_advisory_lock` v `InvoiceMailer` sa uvoľňuje vo `finally` a pri páde procesu zaniká so spojením. PHP pri konci požiadavky vráti otvorenú transakciu späť. Po reštarte DB sa mŕtve trvalé spojenie obnovilo bez chyby (overené lokálne). Počet spojení je najviac `pm.max_children` (4) z `max_connections=20`. Pamäť stagingu po záťaži: db 77,7 MiB (predtým 69,6), php 29,3, nginx 5,2.

**Odchýlka od pôvodného CLAUDE.md** ("perzistentné DB spojenia: žiadny zisk, vyššia pamäť"): meranie to vyvrátilo, zisk je cca 2x a pamäťová cena je jednotky až desiatky MiB. Vlastník zmenu schválil 2026-11-09 a CLAUDE.md je upravený.

Výsledok: app-only p95 na stagingu 34 až 87 ms (cieľ < 100 ms splnený, najslabšia je `/`). Cez Cloudflare p95 ALL 191 ms (pred 241): rozdiel k app-only je sieť (TCP+TLS+Cloudflare hop, základná línia `/login` 56 až 96 ms), nie aplikácia, takže cieľ 100 ms sa dá pri tomto spôsobe merania splniť len mimo Cloudflare. Ďalší možný zisk: `/` robí cca 30 dotazov (dashboard, limity obratu `TurnoverCalculator::forActiveEntities` cca 12 dotazov); zatiaľ netreba.

### Produkcia (2026-11-09)

Meranie so súhlasom vlastníka, iba GET požiadavky s jeho session cookie zadanou na serveri (`read -rs`), mimo chatu a repozitára. App-only cez `fc-prod.php` (FastCGI na php-fpm v kontajneri, bez nginx a Cloudflare), verejná URL cez `measure-p95.sh`. Prvé meranie bežalo ešte na kóde bez ladenia (deploy PR #14 čakal na schválenie prostredia `production`), druhé po nasadení.

Záver (D-12, NFR-01): **odozva servera (app-only) p95 51 ms < 100 ms, splnené** na produkcii pre všetky štyri stránky. PDF 230 ms < 2 s. Prod stack v pokoji 97 MiB < 512 MiB. Cez Cloudflare je p95 176 ms; rozdiel tvorí sieť (TCP+TLS+Cloudflare hop, základná línia `/login` 56 až 96 ms), ktorú aplikácia neovplyvní, preto sa cieľ NFR-01 vzťahuje na odozvu servera (CLAUDE.md: "odozva servera p95 pod 100 ms").

D-13: HTTP/2, kompresia, `immutable` cache statiky a hlavičky overil `smoke-server.sh` na produkcii (SMOKE OK 2026-11-06), OPcache, preload a `--classmap-authoritative` overené na stagingu s rovnakým obrazom a strážené `ProdHardeningConfigTest`. `zz-www.conf` ani `compose.server.yaml` sa nemenili.
