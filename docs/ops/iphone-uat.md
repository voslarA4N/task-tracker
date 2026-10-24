# UAT na iPhone: Work Tracker (staging)

Cieľ: `https://work-tracker.vostack.dev` ako nainštalovaná PWA na reálnom iPhone (kritérium úspechu 5, PWA-01, AUTH-05).

Do stĺpca „Výsledok“ zapíš `OK` alebo `FAIL`, do „Poznámka“ popis rozdielu. Každý `FAIL` sa zapíše ako medzera pre `/gsd-verify-work`.

Dátum UAT: ____________

| # | Krok | Očakávaný výsledok | Výsledok | Poznámka |
|---|------|--------------------|----------|----------|
| 1 | PC: vytvor admin účet podľa `docs/ops/staging.md`, sekcia 10: `ssh -t deploy@2.31.74.48 'cd /opt/work-tracker && IMAGE_TAG=$(cat .last-good-tag) docker compose -f compose.staging.yaml exec php php spark user:create <e-mail> --admin'` (heslo zadávaš ty, minimálne 12 znakov, bez časti e-mailu pred `@`) | Príkaz vypíše „Používateľ ... vytvorený“, heslo sa nikde nezobrazí | | |
| 2 | iPhone, Safari: otvor `https://work-tracker.vostack.dev` | Presmerovanie na prihlásenie, zobrazí sa inštalačný panel „Pridajte si Work Tracker na plochu“, po zatvorení (×) a znovunačítaní sa panel už nezobrazí | | |
| 3 | Prihlás sa heslom, naskenuj QR v Google/Microsoft Authenticator, zadaj kód, aktivuj 2FA | Zobrazí sa 10 záložných kódov; „Stiahnuť .txt“ uloží súbor; „Pokračovať“ je neaktívne, kým nezaškrtneš „Uložil som si ich“; zaškrtnuté „Zapamätať si toto zariadenie na 30 dní“ (O-5 = yes); po „Pokračovať“ domovská stránka | | |
| 4 | Zdieľať → Pridať na plochu | Ikona „Work Tracker“ (modrá, WT); spustenie z plochy je bez lišty Safari (standalone), inštalačný panel sa nezobrazí | | |
| 5 | V PWA: Viac → Zabezpečenie | 2FA „Aktívne“, 10 z 10 záložných kódov, zariadenie „iPhone, Safari“ s odznakom „Toto zariadenie“, prihlásenia v denníku so správnou verejnou IP (nie adresa Cloudflare) | | |
| 6 | Vynútene zatvor PWA (potiahnutie v prepínači aplikácií) a otvor znova; potom reštartuj telefón a otvor PWA | Oba razy bez prihlásenia (heslo ani TOTP sa nepýta) | | |
| 7 | Zapni režim lietadlo, v PWA ťukni na odkaz; potom vypni lietadlo | Zobrazí sa „Ste offline“; po zapnutí siete tlačidlo „Skúsiť znova“ načíta aplikáciu | | |
| 8 | PC: prihlás sa heslom + TOTP bez zapamätania; odhlás sa, znova heslo a ten istý kód v tom istom 30 s okne | Druhé použitie rovnakého kódu je odmietnuté | | |
| 9 | PC: Zabezpečenie → odvolať iPhone; na iPhone ďalšia navigácia v PWA | iPhone požaduje heslo aj TOTP | | |
| 10 | PC: odhlás sa, prihlás sa heslom + záložným kódom | Prihlásenie prejde, zostatok záložných kódov je 9 | | |
| 11 | iPhone: prihlás sa znova a nechaj zaškrtnuté zapamätanie zariadenia | Zariadenie je opäť v zozname dôveryhodných | | |
| 12 | Server: `ssh deploy@2.31.74.48 'cd /opt/work-tracker && IMAGE_TAG=$(cat .last-good-tag) docker compose -f compose.staging.yaml logs php \| grep mail.suppressed'` (alebo `writable/logs`) | Riadky `mail.suppressed kind=security.device_trusted` (a pri odvolaní zodpovedajúci druh) bez tela správy | | |

## Dlhodobé kontroly

Rozhodnutie O-6 = followup. Sledované úlohy sú v `.planning/STATE.md` (sekcia Phase 2 follow-up).

| Kontrola | Termín | Očakávanie | Výsledok |
|----------|--------|------------|----------|
| 7 dní | dátum UAT + 7 dní | PWA na iPhone sa za celé obdobie nepýtala na prihlásenie | |
| 30 dní | dátum UAT + 30 dní | Po vypršaní zapamätania sa vyžaduje heslo aj TOTP (D-17); predtým nebolo žiadne prihlásenie | |
