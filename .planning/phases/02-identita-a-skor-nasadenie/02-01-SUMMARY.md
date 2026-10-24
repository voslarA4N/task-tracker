---
phase: 02-identita-a-skor-nasadenie
plan: 01
subsystem: governance
tags: [supply-chain, decisions, gate]
requires: []
provides: [package-approval, owner-decisions-O1-O6]
affects: [02-02, 02-03, 02-04, 02-05, 02-07, 02-15, 02-16, 02-20]
key-files:
  created: [.planning/phases/02-identita-a-skor-nasadenie/02-01-SUMMARY.md]
  modified: [.planning/STATE.md]
key-decisions:
  - "O-1..O-6 všetky podľa odporúčania"
  - "Legitimitu balíkov overil Claude cez Packagist API na výslovný pokyn vlastníka"
duration: 10min
completed: 2026-10-24
---

# Phase 02 Plan 01: Brána vlastníka Summary

**Balíky identity schválené (overené cez Packagist API) a rozhodnutia O-1..O-6 zapísané podľa odporúčania.**

## Package legitimacy

Vlastník na otázku odpovedal „Verify them yourself“, overenie preto vykonal Claude 2026-10-24 cez `packagist.org/packages/{name}.json` (repozitár, maintainer, stiahnutia, stav abandoned, existencia pinnutej verzie).

| Balík | URL | Repozitár / maintainer | Stiahnutia (mesiac / celkom) | Verzia | Verdikt |
|-------|-----|------------------------|------------------------------|--------|---------|
| codeigniter4/shield | https://packagist.org/packages/codeigniter4/shield | github.com/codeigniter4/shield / lonnieezell | 63 833 / 611 040 | v1.4.1 (2026-08-18), req php ^8.2, settings ^2.1 | approved |
| codeigniter4/settings | https://packagist.org/packages/codeigniter4/settings | github.com/codeigniter4/settings / lonnieezell | 83 362 / 811 932 | v2.4.0 | approved |
| spomky-labs/otphp | https://packagist.org/packages/spomky-labs/otphp | github.com/Spomky-Labs/otphp / Spomky | 2,5 M / 57,8 M | 11.5.0 (2026-06-06) | approved |
| bacon/bacon-qr-code | https://packagist.org/packages/bacon/bacon-qr-code | github.com/Bacon/BaconQrCode / DASPRiD | 12,5 M / 222 M | v3.1.1 (2026-04-05), req ext-iconv, dasprid/enum ^1.0.3 | approved |
| psr/clock | https://packagist.org/packages/psr/clock | github.com/php-fig/clock / fig | 25 M / 456 M | 1.0.0 | approved |
| dasprid/enum (tranzitívne) | https://packagist.org/packages/dasprid/enum | github.com/DASPRiD/Enum / DASPRiD | 12,6 M / 202 M | 1.0.7 | approved |
| paragonie/constant_time_encoding (tranzitívne) | https://packagist.org/packages/paragonie/constant_time_encoding | github.com/paragonie/constant_time_encoding / paragonie-scott | 18,8 M / 421 M | v3.1.3 | approved |
| symfony/deprecation-contracts (tranzitívne cez otphp, nebolo v RESEARCH) | https://packagist.org/packages/symfony/deprecation-contracts | github.com/symfony/deprecation-contracts | rozšírený základný balík | ^3.2 | approved |

Žiadny balík nie je označený ako abandoned, názvy zodpovedajú oficiálnym repozitárom (bez typosquatu). 02-03 má porovnať diff `composer.lock` s touto tabuľkou.

## Owner decisions

O-1: revoke
O-2: 10
O-3: metadata
O-4: letsencrypt
O-5: yes
O-6: followup

## Deviations from Plan

- Task 1 (blocking-human): vlastník delegoval overenie balíkov na Claude („Verify them yourself“). Overenie prebehlo strojovo cez Packagist API, nie ručnou kontrolou vlastníkom.
- Pridaný tranzitívny balík `symfony/deprecation-contracts`, ktorý RESEARCH neuvádzal.

## Self-Check: PASSED
