# Milestones

## v1.0 MVP (Shipped: 2026-11-10)

**Delivered:** PWA, ktorá nahrádza ručný Excel. Eviduje úlohy a ich časti, vystavuje nemenné faktúry, výkazy a dobropisy v PDF (SK, PL, CZ), posiela ich e-mailom, sleduje úhrady, kurzy a limity obratu. Beží na produkcii https://work-tracker.vostack.dev so zálohami a overenou obnovou.

**Phases completed:** 14 fáz (1–12 a vložené 11.1, 12.1), 155 plánov, všetky so SUMMARY
**Requirements:** 90/90 v1 požiadaviek splnených (P0 + P1 + P2, epiky E1–E12)
**Timeline:** 2026-10-23 → 2026-11-10 (19 dní), približne 980 commitov
**Code:** približne 55 tis. riadkov PHP v `app/`, 106 tis. riadkov PHP spolu s testami, 2701 PHPUnit testov
**Git range:** `defbf4c` (first commit) → tag `v1.0`

**Key accomplishments:**

1. **Nemenné doklady:** atómové vystavenie za klienta a mesiac, kópia údajov v JSONB, číslovanie `RRRRMM###` bez medzier a bezpečné voči súbehu, uložené PDF so sha256, insert-only tabuľky chránené DB rolami (`app_rw`) a triggermi.
2. **Doklady podľa právneho profilu:** faktúra `inv-2`, výkaz práce `rep-1/rep-2`, dobropis `cn-1` pre SK, CZ, PL (art. 113) a EÚ B2B s QR platbou (PAY by square, SPAYD, ZBP), editovateľné zákonné texty a pregenerovanie výkazu z kópie údajov s históriou verzií.
3. **Pohľadávky:** odoslanie z uložených PDF cez vlastný SMTP (Stalwart), úhrady a ich storno, po splatnosti, upomienky, dobropisy ako celé storno s uvoľnením častí. Súbeh úhrady a dobropisu je ošetrený zámkom aj DB triggerom.
4. **Meny, kurzy a limity:** kurz podľa krajiny subjektu (NBP, ECB, ČNB) s ručným prepisom, uložený do kópie údajov; limity obratu nastaviteľné v aplikácii s upozorneniami a predkontrolou pri vystavení.
5. **Prehľady a exporty:** mesačné a ročné prehľady, CSV/XLSX, ZIP balík pre účtovníčku, prehliadač denníka zmien. Všetky čítacie cesty berú meno klienta z kópie vystaveného dokladu.
6. **Prevádzka:** prihlásenie s voliteľnou TOTP 2FA, PWA na iPhone, štyri jazyky (SK, PL, CZ, EN), staging a produkcia cez GitHub Actions, denné šifrované restic zálohy s testom obnovy a ročným archívom, p95 51 ms, PDF 230 ms, produkčný stack 97 MiB.

**Audit:** `milestones/v1.0-MILESTONE-AUDIT.md` (2026-11-09) mal stav `gaps_found` (RPT-02, RPT-03, W1–W4). Všetky medzery uzavrela vložená fáza 12.1 (overenie `passed`, UAT 7/7). Review nálezy Phase 9 WR-02 a WR-04 opravil quick 261110-kfy pred uzavretím.

**Human UAT:** všetky `*-HUMAN-UAT.md` sú `complete` bez otvorených problémov (posledné: 11.1 5/5 a 12 4/4 dňa 2026-11-10).

**Known deferred items at close:** 0. Otvorené úlohy vlastníka (nie sú to chyby v kóde) sú v STATE.md: kontrola 30-dňového zapamätania zariadenia na iPhone (2026-11-24), zamknutie importovaných „Fakturované = ÁNO“ častí pred importom reálnych dát (Phase 5 WR-07 C), potvrdenie zákonných textov účtovníčkou.

---
