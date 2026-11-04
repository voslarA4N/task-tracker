# Zálohy, obnova a ročný archív

## 1. Účel a akceptovaný odklon D-05

Záloha celej databázy (vrátane uložených PDF dokladov v `invoice_documents.content`) sa robí denne, šifruje sa nástrojom
restic (AES-256) a rotuje 14 denných / 8 týždenných / 12 mesačných snapshotov. Ročný archív dokladov sa ukladá do toho
istého repozitára s tagom `archive` a retenciou 10 rokov.

**Akceptovaný odklon D-05:** repozitár leží na serveri v `/var/backups/restic/work-tracker` (nie na platenom úložisku
mimo servera). Kópiu mimo servera robí vlastník ručným pullom na PC (`scripts/pull-backups.sh`, odsek 7). Strata servera
medzi dvoma pullmi znamená stratu záloh za toto obdobie. Neskorší prechod na Hetzner Storage Box sa urobí cez
`restic copy` alebo zmenou `RESTIC_REPOSITORY` bez prepisu skriptov.

## 2. Architektúra

```
cron (root, host)
  03:10 denne        backup-restic.sh    -> pg_dump (kontajner db) + manifest.txt -> restic backup --tag db
  04:30 prvého v mes. restore-test.sh    -> restic restore -> dočasná DB restore_check_<čas> -> porovnanie s manifestom
  04:00 2. januára   archive-yearly.sh   -> spark archive:year -> restic backup --tag archive
skripty:  /usr/local/lib/work-tracker-backup/scripts/  (vlastník root)
env:      /root/.config/work-tracker-backup.env  (0600, vlastník root, nikdy app.env)
repozitár: /var/backups/restic/work-tracker  (iba root)
```

Premenné v env súbore: `RESTIC_REPOSITORY`, `RESTIC_PASSWORD_FILE`, `HC_URL`, `HC_RESTORE_URL`, `HC_ARCHIVE_URL`
(healthchecks.io, voliteľné), `ALERT_TO`, `ALERT_FROM`, `ALERT_SMTP_URL`, `ALERT_SMTP_USER`, `ALERT_SMTP_PASS_FILE`,
`BACKUP_ENV_NAME`, `STACK_DIR`, `STAGE_DIR`. Skripty odmietnu env súbor, ktorý nepatrí používateľovi alebo nemá práva
600 alebo 400. Tajomstvá sa nikdy nevypisujú na výstup ani do e-mailu.

## 3. Denná záloha

`backup-restic.sh` urobí `pg_dump --format=custom` v kontajneri `db` (klient = server, PostgreSQL 18), overí archív
úplným prečítaním a vygeneruje `manifest.txt` s kontrolnými hodnotami: `rows.<tabuľka>` (počty riadkov),
`last_invoice_number`, `documents_sha256_digest`, `documents_bad_sha256` a `dump_sha256`. Ak sa niektorý uložený PDF
doklad nezhoduje s vlastným sha256, záloha skončí chybou. Dump, `.sha256` a manifest idú ako jeden snapshot s tagom `db`
a hostom `work-tracker`. Staging nemá cron záloh (DB sa pri nasadení reseeduje).

Spustenie ručne: `sudo /usr/local/lib/work-tracker-backup/scripts/backup-restic.sh`.

## 4. Rotácia a kontrola repozitára

```sh
restic forget --group-by host,tags --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --keep-tag archive --prune
```

Politika je na jednom mieste (`scripts/lib-restic-policy.sh`). `--group-by host,tags` je nutné, inak by sa snapshoty
zoskupovali podľa cesty. Snapshoty s tagom `archive` rotácia nikdy nezmaže. Raz týždenne (nedeľa) beží
`restic check --read-data-subset=5%`.

## 5. Upozornenia

- healthchecks.io (free plán): kontrola s periódou 1 deň a grace 2 hodiny, e-mailová integrácia na adresu vlastníka.
  Skript pri úspechu pingne URL, pri chybe pingne `/fail`. Ak ping nepríde, healthchecks pošle upozornenie sám
  (zachytí aj výpadok servera alebo cronu),
- pri chybe skript pošle e-mail cez SMTP (Stalwart) s krokom, ktorý zlyhal, a posledných 20 riadkov chybového výstupu,
- staging nemá cron záloh.

## 6. Tajomstvá mimo servera

Do správcu hesiel vlastníka (nie na server, nie do gitu) uložiť:

- heslo repozitára (`RESTIC_PASSWORD_FILE`),
- `encryption.key` a ostatné tajomstvá zo `server.md` §7.

Bez hesla repozitára sa záloha neotvorí. Bez `encryption.key` sa po obnove nedá prihlásiť s 2FA, lebo TOTP tajomstvá sú
v databáze šifrované (obnova bez kľúča = stratené 2FA). Kópia hesla v rovnakom adresári ako repozitár je zbytočná.

## 7. Kópia na PC (pull)

Raz týždenne vo WSL:

```sh
scripts/pull-backups.sh
```

Premenné: `PULL_SSH_TARGET` (predvolene `backuppull@2.31.74.48`), `PULL_SSH_KEY` (predvolene
`~/.ssh/work-tracker-backup-pull`), `PULL_DEST` (predvolene `~/backups/work-tracker-restic`), voliteľne
`RESTIC_PASSWORD_FILE` (potom skript spustí `restic snapshots` a `restic check --no-lock` nad kópiou).

Zabezpečenie na serveri (nastavenie robí vlastník, podrobnosti v `server.md` §21 po nasadení 12-13): repozitár ostáva
čitateľný iba pre root. Pull ide cez vyhradeného používateľa `backuppull` s vlastným SSH kľúčom, ktorého
`authorized_keys` má

```
command="sudo -n /usr/bin/rrsync -ro /var/backups/restic/work-tracker",no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding,restrict ssh-ed25519 AAAA... backup-pull
```

a sudoers povoľuje iba presný príkaz `backuppull ALL=(root) NOPASSWD: /usr/bin/rrsync -ro /var/backups/restic/work-tracker`
s `Defaults!/usr/bin/rrsync env_keep += "SSH_ORIGINAL_COMMAND"`. Kľúč tak vie iba čítať tento adresár.

Skript nepoužíva `--delete`: ak sa repozitár na serveri stratí alebo zmaže, kópia na PC ostane. Balíky zmazané
rotáciou na serveri ostanú na PC a sú neškodné. Adresár `locks` sa nekopíruje. Kópia na PC je šifrovaná, heslo je iba v
správcovi hesiel. Raz ročne vedome premazať PC kópiu: nový pull do prázdneho adresára (`PULL_DEST=...`), po
úspešnom `restic check` zmazať starý.

## 8. Mesačný test obnovy

`restore-test.sh` (cron prvého v mesiaci, 04:30) obnoví posledný snapshot `db` do dočasnej DB `restore_check_<čas>`
a porovná s manifestom zo zálohy: počty riadkov všetkých tabuliek, posledné číslo faktúry, digest sha256 dokumentov a
kontrolu obsahu PDF (`documents_bad_sha256` = 0). Dočasnú DB a dočasné súbory vždy zmaže. Úspech vypíše
`OK: obnova <snapshot> <db> rows.invoices=<n> last_invoice_number=<číslo>`, rozdiel vypíše kľúč a obe hodnoty,
pošle e-mail a ping `/fail`. Na serveri je povolená iba DB `restore_check_*`, produkčná DB sa nikdy nedotkne.

```sh
sudo /usr/local/lib/work-tracker-backup/scripts/restore-test.sh
```

## 9. Obnova na čistom prostredí (D-15)

Vo WSL, v novom adresári a s novým compose projektom (prázdny volume), aby sa nedotkla vývojová DB:

1. Stiahnuť restic 0.19.1 z GitHub releases spolu so `SHA256SUMS`, overiť `sha256sum -c`, nainštalovať do `~/bin`.
2. `scripts/pull-backups.sh` (alebo použiť existujúcu kópiu), heslo repozitára z správcu hesiel do súboru s právami 600.
3. Čistý klon repozitára do nového adresára (napr. `~/restore-check/task-tracker`), nový názov compose projektu.
4. `.env` s produkčným `encryption.key` (z správcu hesiel), ostatné tajomstvá nové.
5. `make up` (prázdny volume pre DB).
6. Obnova reálnych dát:

```sh
export RESTIC_REPOSITORY=~/backups/work-tracker-restic RESTIC_PASSWORD_FILE=~/.config/restic-pass
RESTORE_LOCAL=1 RESTORE_KEEP=1 RESTORE_TARGET_DB=task_tracker sh scripts/restore-test.sh
```

7. Prihlásiť sa (vrátane 2FA), otvoriť faktúru a výkaz, skontrolovať PDF a číslo poslednej faktúry.
8. **Upratanie (T-12-40):** `docker compose down -v` testovacieho projektu a zmazať `encryption.key` z lokálneho `.env`.
   Reálne dáta nesmú ostať vo WSL.

Lokálny režim nepracuje s env súborom ani s upozorneniami a `RESTORE_KEEP=1` najprv zmaže cieľovú DB, preto ho
nikdy nespúšťať v projekte s vývojovou DB, ktorú chcete zachovať.

## 10. Ročný archív

`archive-yearly.sh` (cron 2. januára, 04:00) zavolá `spark archive:year` a uloží ZIP všetkých PDF vystavených dokladov
roka (všetky verzie) spolu s `zoznam-dokladov.csv` (so sha256) do restic s tagom `archive`. Retencia je **10 rokov** pre
všetky subjekty (SK 10 rokov pokrýva aj PL). Skript nikdy nevolá `forget` a rotácia 14/8/12 snapshoty `archive`
nemaže (`--keep-tag archive`).

Snapshoty `archive` staršie ako 10 rokov sa mažú iba ručne, po kontrole:

```sh
restic snapshots --tag archive
restic forget <snapshot-id> --prune
```

Obnova archívu: `restic restore <snapshot-id> --target /cesta/archiv`, potom v ZIP skontrolovať `sha256` podľa CSV.
Ručné spustenie: `archive-yearly.sh [rok]` (bez argumentu predchádzajúci rok).

## 11. Skript backup.sh (lokálny dump)

```sh
scripts/backup.sh [adresár]
```

Predvolený adresár je `backups/` v koreni repozitára (v `.gitignore`), relatívna cesta sa berie voči aktuálnemu
adresáru volajúceho. Premenné: `BACKUP_DB` (predvolene `task_tracker`), `BACKUP_DB_USER` (predvolene `app_owner`),
`BACKUP_NAME` (pevný názov súboru namiesto časovej značky) a `BACKUP_COMPOSE_DIR` (adresár s compose). Výstup:
`task_tracker-RRRRMMDDTHHMMSS.dump` a `.dump.sha256`, obe 0600. Dump je overený `pg_restore -f /dev/null`. Pri chybe
neostane neúplný súbor. Dump je nešifrovaný, preto ho nekopírujte na zdieľané miesta (šifrovanie robí až restic).

Ručná obnova bez restic do dočasnej DB:

```sh
docker compose exec -T db createdb -U postgres -O app_owner restore_check
docker compose exec -T db pg_restore -U app_owner --no-owner --exit-on-error -d restore_check < backups/task_tracker-<čas>.dump
```

Potom porovnať počty riadkov a posledné číslo faktúry a dočasnú DB zmazať (`dropdb`).

## 12. Záznam testov obnovy

| Dátum | Prostredie | Snapshot | Výsledok | Poznámka |
|-------|------------|----------|----------|----------|
|       |            |          |          |          |

## 13. Mimo rozsahu

Úložisko mimo servera na platenej službe a ochrana pred útočníkom s prístupom na server (ktorý by mohol zmazať aj
repozitár) sú odložené (CONTEXT Deferred). Dočasne ich nahrádza ručný pull na PC.
