---
phase: 07-doru-enie-hrady-a-upomienky
plan: 01
subsystem: mail
tags: [ci4-email, smtp, mailpit, message-id, bcc]
requires: []
provides:
  - AppMailer::sendMessage(OutgoingMessage): SendOutcome (jedna cesta odosielania, D-03)
  - AppEmail (vlastný Message-ID, SMTP obálka, zachytenie odpovede)
  - Uuid::v4, OutgoingMessage, SendOutcome, CapturingEmail
  - Config\Email allowedFromDomains, envelopeFrom, SMTPTimeout 15
affects: [07-02, 07-04, 07-06, 07-07]
tech-stack:
  added: []
  patterns: [továreň AppEmail na každú správu, log transport len s metadátami]
key-files:
  created:
    - app/Libraries/Mail/AppEmail.php
    - app/Libraries/Mail/OutgoingMessage.php
    - app/Libraries/Mail/SendOutcome.php
    - app/Libraries/Platform/Uuid.php
    - tests/_support/CapturingEmail.php
    - tests/integration/Mail/AppEmailTest.php
    - tests/integration/Mail/AppMailerMessageTest.php
    - tests/integration/Mail/MailpitDeliveryTest.php
  modified:
    - app/Libraries/Mail/AppMailer.php
    - app/Config/Email.php
    - .env.example
key-decisions:
  - "CI4 Email stačí (bez symfony/mailer): override getMessageID/sendCommand/sendData/getSMTPData v 4.7.5 potvrdené testami aj reálnym Mailpitom"
  - "MAIL FROM = email.envelopeFrom (prázdne = fromEmail), Mailpit potvrdil Return-Path noreply@vostack.dev pri From faktury@vostack.dev"
requirements-completed: [MAIL-01, MAIL-02]
duration: ~40 min
completed: 2026-10-29
---

# Phase 7 Plan 01: Odosielanie e-mailov (spike) Summary

AppMailer::sendMessage odošle správu s PDF prílohami z bufferu, CC/BCC, Reply-To, vlastným Message-ID a In-Reply-To cez novú AppEmail na každú správu, s SMTP obálkou účtu aplikácie; overené aj reálnym doručením do Mailpitu.

## Tasks
1. AppEmail, DTO, Uuid, CapturingEmail, testy: commit 5b54ed9
2. AppMailer::sendMessage, konfigurácia, testy vrátane Mailpitu: commit 1aaa27f

## Deviations from Plan
- Mailpit vo svojich poliach/endpointoch (`Bcc` v `/message`, `/headers` a na začiatku `/raw`) zobrazuje obálku RCPT TO ako Bcc. Integračný test preto overuje, že ide o jediný výskyt adresy vložený Mailpitom ako prvý riadok, zatiaľ čo samotné hlavičky správy BCC neobsahujú (to dokazuje aj unit test nad `capturedHeaders`).
- `setCC`/`setBCC` v CI4 očakáva string (PHPStan), preto sa zoznamy posielajú cez `implode(',')`.
- Test hlavičky vlastnosti `readonly` rieši `ReflectionProperty` (PHPStan odmietol dead catch).
- Signatúry rodiča v 4.7.5 zodpovedali `<interfaces>`; žiadny rozdiel.

## Notes
- Žiadna nová závislosť. Dev stack: nginx sa nespustí (port 80 obsadený `global-proxy`), pre testy nie je potrebný.
- Pri behu phpunit v kontajneri trvá každý beh ~60 s (pred-existujúce, nesúvisí s plánom).
- composer cs a stan čisté; cieľové testy 30/30 zelené.

## Known Stubs
None.

## Self-Check: PASSED
