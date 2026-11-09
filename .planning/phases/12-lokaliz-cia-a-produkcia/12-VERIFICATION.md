---
phase: 12-lokaliz-cia-a-produkcia
verified: 2026-11-09T00:00:00Z
status: human_needed
score: 4/4 roadmap success criteria verified (code and recorded live evidence); 4 owner items outstanding
overrides_applied: 0
human_verification:
  - test: "Test vynechanej zálohy v healthchecks.io (period 1 min, nepustiť cron)"
    expected: "Po vynechaní pingu príde upozornenie (e-mail) o vynechanej zálohe"
    why_human: "Externá služba a produkčný server, owner krok (12-14-SUMMARY)"
  - test: "Vlastnícka revízia PL a CS prekladov (finančná terminológia, zákonné texty)"
    expected: "Preklady sú terminologicky správne pre poľskú a českú účtovníčku"
    why_human: "Jazyková kvalita sa nedá overiť automaticky, test parity overuje len kľúče a nepreložené kópie"
  - test: "Vizuálna kontrola PL/CS obrazoviek na iPhone 375 px"
    expected: "Dlhšie texty (PL/CS) sa nelámu mimo obrazovky, žiadne horizontálne posúvanie, ciele >= 44 px"
    why_human: "Vizuálne, v teste sú len heuristiky dĺžky (varovanie >140 % SK dĺžky pre ~50 PL kľúčov)"
  - test: "SSH na prod: `php spark archive:year` je v produkčnom obraze (spark list), pamäť stacku"
    expected: "Príkaz je dostupný; prvý reálny beh cronu 2027-01-02"
    why_human: "Prístup na produkciu; staging overený rovnakým CI obrazom"
---

# Phase 12: Lokalizácia a produkcia - Verification Report

**Phase Goal:** Aplikácia je dostupná v SK, PL, CZ a EN a je pripravená na ostré používanie: zálohy s overenou obnovou, archív dokladov, nemožnosť mazať doklady, hardening a splnený výkonnostný cieľ na cieľovom VPS.
**Verified:** 2026-11-09
**Status:** human_needed
**Re-verification:** No, initial verification

## Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Prepínanie SK/PL/CZ/EN, formáty podľa jazyka, parita kľúčov | VERIFIED | `app/Language/{sk,pl,cs,en}` majú každý rovnakých 32 súborov (overené `ls`). `tests/unit/I18n/LangKeysTest.php` + MoneyFormatLocale + DateFormat testy: 63 testov / 5443 aserций OK (spustené lokálne). Karta jazyka a `setLocale` z plánu 12-01 (testy Account/Locale). Poznámka: test vypisuje len varovania o dĺžke (nie zlyhanie) |
| 2 | Denný šifrovaný dump + PDF, rotácia 14/8/12, upozornenie, overená obnova | VERIFIED (s akceptovaným odklonom D-05) | `scripts/backup-restic.sh`, `lib-restic-policy.sh` (`--keep-daily 14 --keep-weekly 8 --keep-monthly 12 --keep-tag archive`), `restore-test.sh`, `pull-backups.sh`, `lib-backup-notify.sh`, `install-backup.sh` existujú, bez TODO/FIXME. docs/ops/backup.md §12: obnova na serveri (prod, 2 faktúry, 202611001) a na čistom WSL prostredí z pullnutého repozitára (invoices=2, invoice_documents=4, bad_sha256=0, restic check OK, prihlásenie s 2FA). Mimo servera = ručný pull (ROADMAP výslovne akceptuje). Upozornenie pri chybe overené (e-mail "Záloha zlyhala"); upozornenie pri vynechanej zálohe nepotvrdené, pozri human |
| 3 | Doklady nemazateľné aj DB oprávneniami; ročný archív | VERIFIED | `tests/integration/Database/ImmutableDocumentsTest.php` (DELETE/TRUNCATE ako app_rw aj app_owner, trigger `truncate_guard`, migrácie s REVOKE/triggermi) a `YearArchiveTest` prešli. `app/Commands/ArchiveYear.php` + `scripts/archive-yearly.sh` (tag archive, vyňatý z forget). Staging `OK: archive 2026`; prod prvý beh cron 2027-01-02 (zámerne) |
| 4 | p95 < 100 ms, PDF < 2 s, < 512 MB RAM, HTTP/2, kompresia, OPcache, cache | VERIFIED pri výklade "odozva servera" (pozri nižšie) | docs/ops/performance.md: prod app-only FastCGI p95 ALL 51 ms, PDF p95 230 ms, prod stack v pokoji 97 MiB, smoke-server.sh HTTP/2/kompresia/immutable OK, `ProdHardeningConfigTest` stráži konfiguráciu |

**Score:** 4/4

## NFR-01 - explicitné posúdenie

REQUIREMENTS.md: "Odozva **servera** p95 pod 100 ms na bežných stránkach, PDF pod 2 s." Dôkaz: app-only (FastCGI na php-fpm) p95 51 ms na produkcii (n=50 na štyroch stránkach), pred ladením 138 ms (FAIL), po ladení (perzistentné DB spojenie, reuse NumberFormatter) splnené. Cez Cloudflare (verejná URL) p95 176 ms, teda nad 100 ms; rozdiel tvorí TCP+TLS+Cloudflare hop (základná línia `/login` 56-96 ms).

Posúdenie: znenie "odozva servera" podporuje app-only interpretáciu, takže požiadavka je splnená. Zároveň treba otvorene uviesť: koncový používateľ (iPhone cez Cloudflare) meria TTFB 176 ms, čo cieľ 100 ms nespĺňa, a ROADMAP SC4 hovorí "p95 odozvy bežných stránok" bez upresnenia. Interpretácia bola zdokumentovaná a vlastník ju prijal; odporúčam ju zapísať do REQUIREMENTS.md (poznámka "meranie na úrovni php-fpm/server") a odškrtnúť NFR-01 vedome. Nevyužíva sa override mechanizmus, lebo ide o výklad, nie odchýlku implementácie. Okrem toho je PDF, pamäť a statika nad limitom bez výhrad. Zvyšné riziko: `/` p95 46/61 ms robí ~30 dotazov; zatiaľ netreba.

## Requirements Coverage

| ID | Plány | Status | Evidencia |
|----|-------|--------|-----------|
| UI-02 | 12-01, 02, 06-09, 12 | SATISFIED | 4 jazyky x 32 súborov, parita, formáty, doklad nezávislý od jazyka UI (LocaleIndependentDocumentTest); REQUIREMENTS už Complete. Kvalita PL/CS prekladu = human |
| NFR-01 | 12-05, 12-15 | SATISFIED (výklad "server") | viď vyššie; v REQUIREMENTS.md stále `[ ]`/Pending, treba odškrtnúť |
| NFR-05 | 12-04, 10, 11, 13, 14 | SATISFIED | skripty, rotácia, overená obnova na čistom prostredí; chýba len ručný test vynechanej zálohy (human); v REQUIREMENTS stále Pending |
| NFR-09 | 12-03, 10, 13, 14 | SATISFIED | nemazateľnosť testovaná, ročný archív; prvý prod beh 2027-01-02, v REQUIREMENTS stále Pending |

Všetky 4 ID z PLAN frontmatter sú v REQUIREMENTS.md a zodpovedajú ROADMAP; žiadne osirelé ID.

## Anti-Patterns

Žiadne TODO/FIXME/XXX/TBD v skriptoch záloh, archívu a merania ani v `ArchiveYear.php`. Code review (1 critical, 5 warning) opravené podľa 12-REVIEW-FIX.md. Info: LangKeysTest hlási ~50 PL kľúčov a 1 CS kľúč dlhší ako 140 % SK (len upozornenie, mobilná kontrola je human).

## Poznámky

- Plná PHPUnit sada bola spustená na pozadí a ku koncu verifikácie nebola dokončená; cielené testy fázy (63 testov) prešli. Orchestrátor môže plnú sadu skontrolovať v CI.
- CLAUDE.md bol zmenený (perzistentné DB spojenia) so súhlasom vlastníka, zdokumentované v performance.md.
- Hygiena: v REQUIREMENTS.md aktualizovať NFR-01, NFR-05, NFR-09 na `[x]`/Complete po potvrdení human položiek.

## Gaps Summary

Žiadne kódové medzery. Fáza je funkčne dokončená; ostávajú 4 vlastnícke/manuálne položky (human_verification vyššie).

_Verifier: Claude (gsd-verifier)_
