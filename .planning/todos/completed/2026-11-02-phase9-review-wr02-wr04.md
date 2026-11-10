---
title: Dobropisy, review WR-02 a WR-04 (úhrady pri storne)
created: 2026-11-02
area: receivables
source: .planning/phases/09-dobropisy/09-REVIEW.md
priority: medium
completed: 2026-11-10
resolved_by: quick-261110-kfy
---

Rozhodnutie vlastníka 2026-11-02: riešiť **na konci všetkých fáz** (po Phase 12, pred produkčným nasadením V1).
Zámerne bez `resolves_phase`, aby sa todo automaticky nezavrelo dokončením žiadnej fázy.

## WR-02: Úhradu zapísanú pred stornom už nejde stornovať

- `PaymentService::void()` odmieta úhrady na stornovanej faktúre (`PaymentRejected('cancelled')`) a panel úhrad skryje odkaz na storno úhrady.
- D-17 zakazuje po storne iba **nové** úhrady. Omylom zapísaná úhrada tak ostane navždy v `paid_amount`, `last_paid_on` a v prehľadoch.
- Návrh opravy: povoliť `void()` aj na stornovanej faktúre (pridá iba riadok do `payment_voids`) a `readOnly` použiť len na skrytie „Zapísať úhradu“.
- Súbory: `app/Libraries/Receivables/PaymentService.php:142-146`, `app/Views/invoices/show.php:200`.

## WR-04: Súbeh úhrady a vystavenia dobropisu

- `PaymentService` zamyká `hashtext('payments')`, `CreditNoteIssuer` zamyká `hashtext('credit_note')`. Pri súbehu môže vzniknúť úhrada na faktúre, ktorá bola medzitým stornovaná (porušenie D-15). Žiadny DB trigger na `payments` to nekryje.
- Upomienky (`InvoiceMailer`) kontrolujú stav bez zámku.
- Návrh opravy: v `CreditNoteIssuer::issueInTransaction()` zobrať aj zámok `payments` pre pôvodnú faktúru (poradie `payments` pred `credit_note`). Ideálne aj `BEFORE INSERT ON payments` trigger, ktorý odmietne úhradu, ak existuje dobropis s `corrected_invoice_id = NEW.invoice_id` (nová migrácia).
- Súbory: `app/Libraries/Invoicing/CreditNoteIssuer.php:100`, `app/Libraries/Receivables/PaymentService.php:233-236,275`.

Podrobnosti: sekcie WR-02 a WR-04 v `09-REVIEW.md`.
