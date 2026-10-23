---
phase: 01-z-klad-a-infra-trukt-ra
plan: 04
subsystem: ui
tags: [layout, bootstrap, htmx, alpine-csp, i18n, csp]
requires: ["01-01", "01-02"]
provides:
  - "layouts/app.php so htmx-config (responseHandling), CSRF hx-headers, tabbar Prehľad/Úlohy/Faktúry/Viac"
  - "lokalne vendor kniznice s verziou v nazve + SHA256SUMS + LICENSES, scripts/vendor-assets.sh"
  - "asset_url() helper, public/assets/css/app.css, public/assets/js/app.js"
  - "App.php jazykove subory sk/en, slovenske validacne hlasky (translations package funguje bez zasahu do Autoload)"
  - "testy LayoutTest, LangKeysTest, ViewsCspTest, ViewsHardcodedTextTest"
affects: [01-08]
tech-stack:
  added: [bootstrap 5.3.8, htmx 2.0.11, "@alpinejs/csp 3.17.5"]
  patterns: ["texty vo views iba cez lang()", "ziadny inline script/style/on*= vo views (automaticky test)", "chybove stranky bez layoutu a bez inline kodu"]
key-files:
  created: [scripts/vendor-assets.sh, app/Helpers/asset_helper.php, app/Views/layouts/app.php, app/Views/layouts/_nav_items.php, app/Views/home/index.php, app/Views/fragments/alert.php, app/Language/sk/App.php, app/Language/en/App.php, app/Language/sk/Validation.php, public/assets/css/app.css, public/assets/js/app.js, tests/feature/LayoutTest.php, tests/unit/I18n/LangKeysTest.php, tests/unit/I18n/ViewsHardcodedTextTest.php, tests/unit/Security/ViewsCspTest.php]
  modified: [app/Controllers/Home.php, app/Controllers/BaseController.php, app/Views/errors/html/error_404.php, app/Views/errors/html/error_400.php, app/Views/errors/html/production.php]
key-decisions:
  - "Autoload.php sa nemenil: codeigniter4/translations sa najde auto-discovery (predpoklad A1 potvrdeny)"
  - "BaseController::$helpers = ['asset','security'] (security kvoli csrf_header/csrf_hash)"
requirements-completed: [UI-01]
duration: ~25 min
completed: 2026-10-23
---

# Phase 1 Plan 04: Layout, vendor assets, i18n a CSP testy Summary

Responzivny layout podla makety s lokalnym Bootstrap 5.3.8, htmx 2.0.11 a Alpine CSP 3.17.5, so slovenskymi textami cez `lang()` a views bez inline kodu, strazenymi automatickymi testami.

## Tasks
1. RED testy: `021eda1`
2. vendor skript, app.css, app.js, asset_url: `321160b`
3. layout, home, jazyk, chybove stranky (GREEN): `78d0063`

Vysledok: 9 novych testov OK, `composer test:unit` 50 testov OK, `make smoke` = SMOKE OK (vendor-cache uz nie je SKIP), `curl /` obsahuje `lang="sk"`.

## Deviations from Plan
**1. [Rule 3] Alpine balik nema LICENSE subor** - `@alpinejs/csp` tarball neobsahuje LICENSE; skript zapise `LICENSES/alpinejs.txt` z `package.json` (MIT, Caleb Porzio).
**2. [Rule 3] 404 test** - `FeatureTestTrait` v testing prostredi prepusta `PageNotFoundException` namiesto vykreslenia stranky; test overuje vynimku (kod 404) a renderuje `errors/html/error_404` priamo. Skutocne vykreslenie overene `curl -H 'Accept: text/html'`.
**3. [Rule 2] `app/Language/sk/Validation.php`** - pridany (prazdny override), lebo test parity sk/en vyzaduje rovnake subory (en ho ma z appstarter-a).
- `$_SERVER['HTTPS']='on'` v setUp LayoutTest sa pouziva (poistka voci presmerovaniu na HTTPS).

## Notes for later plans
- `curl` bez `Accept: text/html` dostane JSON chybu (CI4 negociacia), prehliadac HTML.
- 01-08 doplni `htmx` helper do `BaseController::$helpers` a CSRF filter; `X-Requested-With` sa v htmx 2.0.11 nenachadza (0 vyskytov), CSRF filter nesmie na nom stavat.
- `/demo` odkaz a Uloh/Faktur/Viac polozky nav su zatial neaktivne `<span aria-disabled>`.
- Manualna kontrola 375/390 px (human-check z planu) ostava na 01-10.

## Known Stubs
Nav polozky Ulohy/Faktury/Viac su zamerne neaktivne (cielove obrazovky vo Phase 4, 5, 11).

## Self-Check: PASSED
Commity 021eda1, 321160b, 78d0063 existuju; testy a smoke zelene.
