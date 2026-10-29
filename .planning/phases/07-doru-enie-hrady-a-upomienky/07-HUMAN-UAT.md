---
phase: 07-doru-enie-hrady-a-upomienky
status: complete
source: 07-VALIDATION.md (Manual-Only Verifications)
created: 2026-10-29
---

# Phase 7 — Human UAT

Postup a prostredia: `docs/ops/server.md`, sekcia 20, podsekcia „Ručné overenie fázy 7“.

## Testy

### UAT-01 Odoslanie znova z iPhonu najviac 2 klepnutiami
requirement: MAIL-02
prostredie: staging, inštalovaná PWA na iPhone (work-tracker-stage.vostack.dev)
kroky:
1. Otvor detail odoslanej faktúry.
2. Klepni na Odoslať znova, v sheete nastav vlastnú adresu a potvrď.
3. Skontroluj flash a históriu odoslaní.
očakávané: Odoslanie znova trvá najviac 2 klepnutia, flash hovorí „testovacie prostredie“, história ukazuje nový záznam s transportom log.
result: passed (2026-10-29, potvrdil vlastník na stagingu)

### UAT-02 Upomienka a úhrada z dashboardu Po splatnosti na iPhone
requirement: MAIL-03, PAY-01, PAY-02
prostredie: staging, inštalovaná PWA na iPhone
kroky:
1. Otvor dashboard, sekcia Po splatnosti je viditeľná.
2. Z riadku faktúry pošli upomienku (2 klepnutia).
3. Z riadku inej faktúry zapíš úhradu (plnú alebo čiastočnú).
očakávané: Upomienka sa zapíše do histórie (transport log), úhrada zmení zostatok a stav faktúry, riadok po plnej úhrade zmizne zo sekcie Po splatnosti.
result: passed (2026-10-29, potvrdil vlastník na stagingu)

### UAT-03 Ovládanie jednou rukou
requirement: UX (odoslanie, úhrady, upomienky)
prostredie: staging, inštalovaná PWA na iPhone
kroky:
1. Prejdi dashboard, zoznam faktúr, detail faktúry, sheet odoslania a sheet úhrady.
2. Sleduj posúvanie do strán a veľkosť ovládacích prvkov.
očakávané: Žiadne horizontálne posúvanie, dotykové ciele aspoň 44 px, všetko ovládateľné jednou rukou.
result: passed (2026-10-29, potvrdil vlastník na stagingu)

### UAT-04 Bajtová zhoda príloh v Mailpit a vlákno upomienky
requirement: MAIL-01, MAIL-03
prostredie: dev (Mailpit)
kroky:
1. Odošli faktúru, v Mailpit stiahni prílohu faktúry.
2. Porovnaj `sha256sum` prílohy so sha256 na detaile faktúry.
3. Pošli upomienku a skontroluj, že je v Mailpit v rovnakom vlákne.
očakávané: sha256 stiahnutej prílohy je zhodný s detailom faktúry, upomienka je v rovnakom vlákne ako pôvodné odoslanie.
result: passed (2026-10-29)
overenie: dev DB po `make reset-staging`, faktúra 202610001 (id 40); vlastník odoslal znova a poslal upomienku, kontrola cez Mailpit API.
- Faktúra v opätovnom odoslaní, faktúra v upomienke aj uložený doklad: sha256 `c38f7731383066d2301beaf90c985a9831f4359c41d5b30cd803e1395383e37a`. Výkaz v opätovnom odoslaní aj uložený doklad: `17966c242aedf3570e817b37c3024b3240ec84d6e10fa3a1b9193fd25f84f2ed`. Upomienka má iba PDF faktúry.
- Upomienka má `In-Reply-To` aj `References` = Message-ID pôvodného odoslania faktúry (`<887d8c85-a8dc-49ef-b6d9-799f060f9e24@work-tracker.loc>`, 2026-10-02). To pôvodné odoslanie vytvoril seed s transportom `log`, preto v Mailpit nie je a vlákno sa tam vizuálne nezobrazí; v schránke klienta sa upomienka zaradí do vlákna pôvodnej faktúry.
- BCC neuniká: jediný riadok `Bcc:` v surovom zdroji vkladá Mailpit z obálky (prvý riadok pred `Received`), aplikácia hlavičku Bcc neposiela.

### UAT-05 Doručiteľnosť v produkcii
requirement: MAIL-01
prostredie: produkcia po zapnutí SMTP podľa sekcie 20
kroky:
1. Vystav jednu skúšobnú faktúru a pošli ju iba na vlastnú adresu.
2. V Gmail otvor „Show original“ a skontroluj SPF, DKIM a DMARC.
3. Porovnaj `sha256sum` prílohy so sha256 na detaile faktúry.
očakávané: SPF, DKIM aj DMARC majú PASS, sha256 prílohy sa rovná detailu faktúry.
result: passed (2026-10-30, potvrdil vlastník v produkcii: SPF, DKIM, DMARC PASS, sha256 prílohy sedí)

## Súhrn

total: 5
passed: 5
pending: 0
issues: 0

## Zistenia

