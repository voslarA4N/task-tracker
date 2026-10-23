# Task Tracker

Webová aplikácia (PWA) na evidenciu úloh, fakturáciu a výkazy práce. Vývoj beží v Docker Compose vo WSL2 na `https://work-tracker.loc`.

## Predpoklady

- Windows 11 s WSL2, Docker Engine + Compose v2, GNU Make.
- `work-tracker.loc` -> `127.0.0.1` v `/etc/hosts` vo WSL aj v `C:\Windows\System32\drivers\etc\hosts` vo Windows.
- `mkcert` nainštalovaný vo Windows (`winget install FiloSottile.mkcert`, potom `mkcert -install`).
- Koreňová CA z mkcert skopírovaná vo WSL do `/usr/local/share/ca-certificates/mkcert-rootCA.crt`.

Všetko skontroluje `make doctor`.

## Prvé spustenie

1. `cp .env.example .env` a nahraď hodnoty `change-me-*` vlastnými heslami.
2. `make certs` – vygeneruje certifikát (pozri nižšie).
3. `make up` – zostaví a spustí stack.
4. `make reset` – vytvorí a naplní vývojovú databázu.
5. Otvor https://work-tracker.loc.

Mailpit (zachytené e-maily): http://127.0.0.1:8025.

## Príkazy

```
make help
```

Najdôležitejšie: `up`, `down`, `ps`, `logs`, `shell` (root shell v kontajneri php, len na ladenie), `composer ARGS="..."`, `spark ARGS="..."`, `migrate`, `reset`, `test`, `test-unit`, `stan`, `cs`, `cs-fix`, `check`, `certs`, `smoke`.

Príkazy, ktoré spúšťajú kód aplikácie (spark, testy, stan), idú ako používateľ `www-data`, rovnako ako PHP-FPM, aby súbory v `writable/` nevlastnil root.

## Certifikáty a dôvera CA (Windows aj WSL)

1. Vo Windows: `winget install FiloSottile.mkcert`, potom v PowerShelli `mkcert -install` (CA sa pridá do Windows trust store, prehliadač ju bude dôverovať).
2. Vo WSL spusti `make certs`. Skript nájde `mkcert.exe` (premenná `MKCERT`, PATH alebo WinGet adresár) a vygeneruje `docker/certs/work-tracker.loc.pem` a `-key.pem` (adresár je v `.gitignore`).
3. Skript porovná koreňovú CA z Windows s `/usr/local/share/ca-certificates/mkcert-rootCA.crt`. Ak sa líšia, skopíruje ju a spustí `sudo update-ca-certificates` (alebo vypíše príkazy, ak sudo vyžaduje heslo).
4. Ak WSL interop zlyhá, skript vypíše PowerShell príkaz na ručné vygenerovanie do rovnakej cesty.
5. Overenie: `curl -I https://work-tracker.loc/` bez `-k` musí prejsť.

## Kontrola pred pushom

`make check` (stan + cs + test). Projekt zámerne nepoužíva git hooky, kvalitu stráži CI.

## Riešenie problémov

- **`work-tracker.loc` sa nepreloží vo WSL:** `/etc/wsl.conf` s `[network] generateHosts=false` a ručný záznam v `/etc/hosts`.
- **Skripty padajú na `\r`:** CRLF konce riadkov; repozitár vynucuje LF cez `.gitattributes`, skontroluj `git config core.autocrlf` (má byť `false` alebo `input`).
- **Pomalý Composer a cache:** bind mount z `/mnt/c` je pomalý (9p), preto `vendor/` a `writable/` bežia v named volumes. Zdrojáky sú najrýchlejšie, ak je repozitár priamo vo WSL súborovom systéme.
- **FPM nezapisuje do `writable/`:** `make perms`.
