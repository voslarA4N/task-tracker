---
status: complete
phase: 12-lokaliz-cia-a-produkcia
source: [12-VERIFICATION.md]
started: 2026-11-09T15:56:44Z
updated: 2026-11-10T16:30:00+01:00
---

## Current Test

[testing complete]

## Tests

### 1. Upozornenie pri vynechanej zálohe (healthchecks.io)
expected: Na checku work-tracker-backup dočasne Period 1 min a Grace 1 min, bez pingu príde e-mail DOWN; potom vrátiť 1 deň / 2 h a ďalší nočný cron check vráti na up.
result: pass (2026-11-10)

### 2. Prezretie poľských a českých prekladov vlastníkom
expected: Texty na dokladoch a v e-mailoch (QR dôvody, platobná poznámka, banery dobropisu, PasswordReset, Notify, EmailTemplates) sú vecne správne; termíny z 12-07/12-09 SUMMARY (Wezwanie do zapłaty, symbol zmienny, účetní, dvoufázové) sú akceptované.
result: pass (2026-11-10)

### 3. iPhone 375 px, rozhranie v PL a CS
expected: Prehľad, Úlohy, Fakturácia, Faktúry, Prehľady, Zabezpečenie a Viac v PL aj CS bez horizontálneho posúvania, tlačidlá a štítky sa nelámu nečitateľne (cca 50 PL kľúčov je nad 140 % dĺžky SK).
result: pass (2026-11-10, iPhone)

### 4. archive:year v produkčnom obraze
expected: Na serveri (deploy alebo admin): cd /opt/work-tracker && docker compose -f compose.server.yaml exec -T php php spark list | grep archive:year vypíše príkaz.
result: pass (2026-11-10, produkcia)

## Summary

total: 4
passed: 4
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps
