---
status: partial
phase: 06-dokumenty-fakt-ry-a-v-kazu
source: [06-VERIFICATION.md]
started: 2026-10-28T00:29:23Z
updated: 2026-10-28T00:29:23Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Vzorové PDF pre účtovníčku
Na stagingu `php spark documents:samples`, poslať 5 vzorov (sk-sk faktúra/výkaz, pl-pl, pl_eu_b2b-sk, cz-cs) účtovníčke, prejsť otvorené otázky 1-7 z 06-CONTEXT.md, potvrdené znenia upraviť v Nastavenia → Zákonné texty.
expected: účtovníčka potvrdí zákonné náležitosti a znenia
result: [pending]

### 2. QR PAY by square (SK)
Naskenovať QR na SK vzore dvoma slovenskými bankovými aplikáciami.
expected: suma, IBAN, VS a správa sedia; zapísať akceptovanú `PayBySquare::VERSION` a EOPM
result: issue → opravené (2026-10-28). ČSOB SK: hlavička 1.1.0 aj 1.2.0 nerozpoznaná, rozpozná iba 1.0.0; pri vyplnenej ulici/meste príjemcu hlási chybu adresného riadku, s prázdnymi poliami alebo iba s menom bez chyby. Obsah 1.1.0 bol bajtovo zhodný s referenčnou knižnicou bysquare 4.1.0 a QR z PDF čitateľný (zbar), problém bol iba vo verzii a adrese. Oprava: `PayBySquare::VERSION = 0x00`, ulica a mesto prázdne, meno ostáva; zlatý vektor v PayBySquareTest. EOPM (xz raw) aplikácia akceptuje. Druhá slovenská aplikácia: [pending]

### 3. QR SPAYD (CZ) a ZBP (PL), vrátane sumy nad 9 999,99 PLN
Naskenovať CZ vzor českou a PL vzor poľskou aplikáciou; vystaviť testovaciu PL faktúru (PLN, PL IBAN) nad 9 999,99 PLN.
expected: aplikácie prijmú QR; ak poľská odmietne veľkú sumu → gap CR-01 (obmedziť v PolishZbpQr)
result: [pending]

### 4. Tlač A4
Vytlačiť SK faktúru a výkaz na A4.
expected: čitateľné, nič neorezané, QR skenovateľný z papiera
result: [pending]

### 5. iPhone PWA
Na stagingu vystaviť faktúru a z detailu otvoriť „Faktúra PDF“ a „Výkaz PDF“.
expected: oba sa otvoria v novej karte
result: [pending]

### 6. Nemennosť po zmene zákonného textu
Upraviť zákonný text, vystaviť novú faktúru.
expected: staršia faktúra má pôvodné znenie a nezmenené PDF, nová má nové znenie
result: [pending]

## Summary

total: 6
passed: 0
issues: 0
pending: 6
skipped: 0
blocked: 0

## Gaps
