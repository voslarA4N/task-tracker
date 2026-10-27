# Záloha databázy (scripts/backup.sh)

## Účel

Jedným príkazom vznikne overený dump celej databázy vrátane uložených PDF dokladov (D-24). Ide o lokálny základ,
šifrovanie, úložisko mimo servera, rotácia a pravidelný test obnovy prídu vo Phase 12.

## Spustenie

```sh
scripts/backup.sh [adresár]
```

Predvolený adresár je `backups/` (v `.gitignore`). Premenné prostredia: `BACKUP_DB` (predvolene `task_tracker`) a
`BACKUP_DB_USER` (predvolene `app_owner`). Výstup: `task_tracker-RRRRMMDDTHHMMSS.dump` a `.dump.sha256`, obe s právami
0600. Skript nevypisuje heslá ani obsah dumpu. Pri akejkoľvek chybe skončí nenulovým kódom a neostane neúplný súbor
(zápis ide do `.partial`, ten sa po chybe zmaže).

## Čo záloha obsahuje

Celú DB v tvare `pg_dump --format=custom`, teda aj tabuľku `invoice_documents` so stĺpcom `content` (`bytea`) a všetky
uložené PDF faktúr. Samostatná záloha súborov PDF nie je potrebná, lebo PDF nie sú na disku.

`pg_dump` beží v kontajneri `db`, takže verzia klienta je rovnaká ako verzia servera (PostgreSQL 18).

## Overenie

- skript sám po dumpe spustí `pg_restore --list` (čitateľnosť archívu),
- kontrolný súčet: `cd backups && sha256sum -c task_tracker-<čas>.dump.sha256`.

## Ručná obnova do dočasnej DB

```sh
docker compose exec -T db createdb -U postgres restore_check
docker compose exec -T db pg_restore -U postgres --clean --if-exists --no-owner -d restore_check < backups/task_tracker-<čas>.dump
```

Potom porovnať počty riadkov a posledné číslo faktúry a dočasnú DB zmazať (`dropdb`).

## Mimo rozsahu (Phase 12)

Šifrovanie (restic), úložisko mimo servera (Hetzner Storage Box), rotácia 14 / 8 / 12 a pravidelný test obnovy sú
Phase 12. Dovtedy je dump nešifrovaný a leží len lokálne, preto ho nekopírujte na zdieľané miesta.
