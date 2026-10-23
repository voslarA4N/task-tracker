# Phase 1: Základ a infraštruktúra - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-23
**Phase:** 01-Základ a infraštruktúra
**Areas discussed:** Vývojový workflow

Ponúknuté oblasti: Layout a vzhľad, Vývojový workflow, Peniaze a čas, mPDF spike. Vybraný bol len Vývojový workflow.

---

## Vývojový workflow

### Spúšťanie príkazov
| Option | Description | Selected |
|--------|-------------|----------|
| Makefile | `make up/test/migrate/shell`, pod tým `docker compose exec` | ✓ |
| Shell skripty v bin/ | tenké obaly nad `docker compose exec` | |
| Len docker compose exec | žiadne obaly, príkazy v README | |

### Vývojová adresa
| Option | Description | Selected |
|--------|-------------|----------|
| http://localhost:8080 | najjednoduchšie, HTTPS až na stagingu | |
| HTTPS s mkcert | https://localhost:8443 | |
| Vlastná doména | napr. task-tracker.test cez hosts | |

**User's choice:** Other: „HTTPS cez mkcert + vlastna domena `work-tracker.loc` do hosts“

### CI
| Option | Description | Selected |
|--------|-------------|----------|
| Áno, GitHub Actions | build, PHPUnit proti PG 18, kontrola preload.php | ✓ |
| Nie, testy len lokálne | CI neskôr | |

### Nástroje kvality
| Option | Description | Selected |
|--------|-------------|----------|
| PHPStan + cs-fixer | level 6, pravidlo proti date()/time() mimo Clock, devkit cs-fixer | ✓ |
| Len PHPStan | | |
| Nič navyše | | |

### Testovacia DB
| Option | Description | Selected |
|--------|-------------|----------|
| Samostatná DB v tom istom kontajneri | `task_tracker_test`, rovnaké roly | ✓ |
| Samostatný PG kontajner pre testy | tmpfs, jeden kontajner navyše | |

### Dôvera v mkcert CA
| Option | Description | Selected |
|--------|-------------|----------|
| Windows prehliadač + WSL | CA aj vo WSL trust store, `make certs` | ✓ |
| Len Windows prehliadač | curl vo WSL s `-k` | |

### Git hooky
| Option | Description | Selected |
|--------|-------------|----------|
| Nie, stačí CI | `make check` lokálne, CI pri pushi | ✓ |
| Áno, pre-commit | cs-fixer + PHPStan na zmenených súboroch | |

### Dev dáta
| Option | Description | Selected |
|--------|-------------|----------|
| make reset + dev seed | reset DB a seeder len pre dev | ✓ |
| Len migrácie | dáta až z importu vo Phase 4 | |

---

## Claude's Discretion

- Layout a vzhľad (podľa makiet, tmavý režim voliteľný)
- Peniaze a čas (bcmath + bcround, immutable Money, Clock, Europe/Bratislava)
- mPDF spike (DejaVu Sans predvolene, meranie času a pamäte, zápis do PROJECT.md)
- Porty, štruktúra `docker/`, názvy cieľov v Makefile

## Deferred Ideas

None.
