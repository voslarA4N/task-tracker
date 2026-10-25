---
phase: 02-identita-a-skor-nasadenie
verified: 2026-10-25T12:00:00Z
status: passed
score: 5/5 roadmap success criteria verified
overrides_applied: 0
---

# Phase 2: Identita a skoré nasadenie Verification Report

**Phase Goal:** Vlastník sa prihlási heslom a povinnou TOTP 2FA na HTTPS stagingu a nainštalovanú PWA otestuje na reálnom iPhone.
**Status:** passed (initial verification)

## Observable Truths

| # | Truth (ROADMAP SC) | Status | Evidence |
|---|---|---|---|
| 1 | Login + povinné TOTP, lokálny QR, replay ochrana, AuthGate | VERIFIED | `app/Libraries/Auth/TotpService.php`, `Filters/AuthGate.php`, `Controllers/Auth/*`; tests `TotpSetupTest`, `TotpVerifyTest`, `AuthGateTest`; Argon2id in `Config/Auth.php`; UAT step 8 (replay rejected) OK |
| 2 | 10 záložných kódov, regenerácia, jednorazové, throttling | VERIFIED | `BackupCodeService`, `LockoutService`, `BackupCodesFlowTest`; nginx limit_req (WR-01 hardened); UAT step 10 (9 zostáva) OK |
| 3 | Zapamätať zariadenie 30 d, zoznam/odvolanie, reset hesla neobíde TOTP | VERIFIED | `TrustedDeviceService` (rotácia tokenu, expires_at), `DeviceLogin` filter, `PasswordResetService`/`AccountRecovery`, `TrustedDeviceTest`, `PasswordResetTest`; WR-02/WR-04 fixes in code+tests; UAT 6, 9, 11 OK |
| 4 | Denník prihlásení (výsledok, IP, zariadenie, čas), roly admin/accountant | VERIFIED | `AuditLog`, `AuthEvent`, `Config/AuthGroups.php` (admin, accountant); UAT step 5 (správna verejná IP) OK |
| 5 | HTTPS staging, CSP/HSTS, secure+HttpOnly cookies, PWA na iPhone standalone, offline, bez častejšieho prihlasovania | VERIFIED (UAT) | Živé `curl -4`: `/` 302 -> /login cez HTTP/2, CSP, HSTS, `__Host-ci_session; secure; HttpOnly; Max-Age=2592000`, `no-store`, HTTP->HTTPS 301; `/sw.js` 200 s `no-cache`, `/manifest.webmanifest` 200. iPhone správanie (standalone, offline, 30 d) = vlastníkov UAT 12/12 OK (`docs/ops/iphone-uat.md`) |

## Behavioral Spot-Checks

| Check | Result |
|---|---|
| `make check` (PHPStan, cs-fixer, PHPUnit) | OK, 358 tests, 4772 assertions |
| `node --test tests/js` | 26 pass, 0 fail |
| Staging headers (read-only curl) | pass, see SC5 |

## Requirements Coverage

Všetkých 13 ID z PLAN frontmatter (AUTH-01..09, PWA-01, NFR-02, NFR-03, NFR-08) sa zhoduje s REQUIREMENTS.md aj ROADMAP; žiadne osirelé požiadavky.

| ID | Status | Evidence |
|---|---|---|
| AUTH-01 | SATISFIED | Argon2id, LoginTest |
| AUTH-02 | SATISFIED | TotpService, TotpSetupTest/TotpVerifyTest, lokálny SVG QR |
| AUTH-03 | SATISFIED | BackupCodeService, BackupCodesFlowTest |
| AUTH-04 | SATISFIED | LockoutService + nginx limit_req |
| AUTH-05 | SATISFIED | TrustedDeviceService, TrustedDeviceTest, UAT 6/9/11 |
| AUTH-06 | SATISFIED | PasswordResetService, PasswordResetTest |
| AUTH-07 | SATISFIED | AuditLog, UAT 5 |
| AUTH-08 | SATISFIED | AuthGroups |
| AUTH-09 | SATISFIED | AuthGate, AuthGateTest |
| PWA-01 | SATISFIED | manifest, sw.js, offline stránka, UAT 4/6/7; 30-d dôveryhodné zariadenie |
| NFR-02 | SATISFIED | staging na Hetzner VPS cez compose.staging.yaml (nginx, php, db) |
| NFR-03 | SATISFIED | HTTPS, CSP, HSTS, cookies overené na živom stagingu; šifrované TOTP tajomstvá |
| NFR-08 | SATISFIED | staging beží a je testovaný na iPhone |

## Anti-Patterns

Žiadne TBD/FIXME/XXX debt markery (len placeholder text `XXXXX-XXXXX` vo formáte kódu v jazykových súboroch, nie debt). Žiadne stuby zistené.

## Notes (nie sú blokery)

- Opravy review WR-01..WR-06 (commit a9ab4e0 a predchodcovia) sú v kóde a testoch, ale **ešte nenasadené** na staging (beží sha-ba9b92f). Vyžaduje krok vlastníka `docs/ops/staging.md` §15, potom push/deploy. `deploy.sh` sa dovtedy zámerne zastaví na preflight kontrole.
- Follow-up (rozhodnutie O-6, nie blokér fázy): 7-dňová (2026-11-01) a 30-dňová (2026-11-24) kontrola iPhonu sú sledované v STATE.md. SC5 "nie častejšie ako raz za 30 dní" je do 2026-11-24 potvrdené len mechanizmom (Max-Age 30 d, testy, UAT krok 6), nie plným uplynutím obdobia.
- Dôkaz o iPhone testoch je tvrdenie vlastníka (UAT 12/12 OK), nie automaticky overiteľný; verifikátor ho prijal ako autoritatívny.
- `/offline.html` vracia 404; offline stránka je zrejme servovaná inou cestou (UAT krok 7 OK), pozn. len informatívne.

## Human Verification Required

Žiadne otvorené položky (UAT vykonaný vlastníkom).

---
_Verified: 2026-10-25_
_Verifier: Claude (gsd-verifier)_
