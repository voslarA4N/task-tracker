# Phase 2: Identita a skoré nasadenie - Context

**Gathered:** 2026-10-24
**Status:** Ready for planning

<domain>
## Phase Boundary

Vlastník sa prihlási e-mailom a heslom (Argon2id) s povinnou TOTP 2FA, má 10 záložných kódov, môže si „zapamätať zariadenie“ na 30 dní, obnoví si heslo e-mailom bez obídenia TOTP a vidí denník prihlásení. Dátový model obsahuje roly `admin` a `accountant`. Aplikácia má kostru PWA (manifest, ikony, service worker, offline stránka) a beží na HTTPS stagingu `https://work-tracker.vostack.dev`, kde sa otestuje na reálnom iPhone ako nainštalovaná PWA.

Požiadavky: AUTH-01 až AUTH-09, PWA-01, NFR-02, NFR-03, NFR-08.

Mimo rozsahu sú doménové tabuľky (subjekty, klienti a ďalšie, Phase 3+), voľba produkčného SMTP (Phase 7), plné zálohy restic na Storage Box a finálny produkčný compose (Phase 12, WR-03/WR-04 z Phase 1) a UI pre rolu `accountant`.

</domain>

<decisions>
## Implementation Decisions

### Staging a nasadenie
- **D-01:** Staging beží na **`work-tracker.vostack.dev`**. Doména `vostack.dev` je registrovaná v Cloudflare (2026-10-24) a záznam smeruje na nový server. Doména je v konfigurácii premenná (`DOMAIN` v `.env`), nie natvrdo v kóde.
- **D-02:** **Cloudflare proxy je zapnutá (oranžový obláčik).** Dôsledky pre plán:
  - TLS režim Cloudflare **Full (strict)**. Na serveri je platný certifikát Let's Encrypt, ktorý sa vydá cez **DNS-01 s Cloudflare API tokenom** (token len s právom `Zone:DNS:Edit` pre `vostack.dev`, uložený mimo gitu). Alternatívou je Cloudflare Origin CA certifikát, planner zváži, čo je jednoduchšie a podľa NFR-03 prijateľné.
  - nginx berie skutočnú IP klienta z `CF-Connecting-IP` (`real_ip_header`, `set_real_ip_from`) **iba z oficiálnych rozsahov Cloudflare**. Denník prihlásení aj limit pokusov musia vidieť skutočnú IP, nie IP Cloudflare.
  - Firewall servera (Hetzner Cloud Firewall alebo ufw) povolí 80/443 iba z rozsahov Cloudflare a SSH iba s kľúčom.
  - Treba overiť, že Cloudflare nemení ani necachuje HTML a hlavičky (CSP, HSTS, `Cache-Control: no-store`, `Vary: HX-Request`). Vypnúť Rocket Loader, Email Obfuscation a Auto Minify, ktoré vkladajú inline skripty a rozbíjajú prísnu CSP. Overiť aj, že `sw.js` sa doručí s `no-cache`.
- **D-03:** Staging je **zároveň budúca produkcia** (jeden server). Po Phase 12 sa vyčistí DB a server sa prepne na ostrú prevádzku.
- **D-04:** Server je už objednaný: **Hetzner Cloud cx23** (x86), 2 vCPU, 4 GB RAM, 40 GB disk, IP `2.31.74.48`, privátna IP `10.0.0.2`, ID `#172176757`. Obrazy stačí stavať pre `linux/amd64`.
- **D-05:** Aj pri 4 GB RAM ostáva cieľ **512 MB v pokoji** (NFR-02). Ladenie PG/FPM sa drží CLAUDE.md, rezervu nechávame pre build, zálohy a prípadný menší server.
- **D-06:** Nasadzovanie ide cez **GitHub Actions + GHCR**. CI postaví produkčný obraz (bez dev závislostí, `--classmap-authoritative`) a pushne ho do `ghcr.io` (repo `voslarA4N/task-tracker`). Server obraz stiahne a spustí `docker compose up -d`.
- **D-07:** Nasadenie sa spúšťa **automaticky pri pushi do `main` po zelenom CI** (testy, PHPStan, cs). Pri jednom serveri, ktorý bude produkciou, musí byť deploy bezpečný sám o sebe, viď D-08.
- **D-08:** **Migrácie bežia automaticky v rámci deployu**, ale až po `pg_dump` zálohe na serveri (lokálny súbor s rotáciou, kým nie je restic z Phase 12). Spúšťajú sa ako `spark migrate -g owner`. Pri chybe migrácie sa deploy zastaví, starý kontajner beží ďalej a nový sa nespustí.

### Prvý účet a obnova 2FA
- **D-09:** Prvý admin účet vzniká **iba spark príkazom na serveri** (napr. `spark user:create --admin`, heslo zadané interaktívne, nie ako argument). Na webe nie je žiadna registrácia ani úvodná stránka „vytvor admina“.
- **D-10:** Pri prvom prihlásení sa vynúti nastavenie TOTP: QR kód (inline SVG, generovaný lokálne) a tajomstvo na ručné zadanie. TOTP sa aktivuje až po overení prvého kódu.
- **D-11:** **10 záložných kódov** sa po aktivácii ukáže **raz na obrazovke** spolu s tlačidlom **„Stiahnuť .txt“**. Pokračovať sa dá až po potvrdení „Uložil som si ich“. Potom sa kódy už nikdy nezobrazia, dajú sa iba pregenerovať (staré tým prestanú platiť).
- **D-12:** Pri strate telefónu aj kódov existuje **iba núdzový reset cez spark na serveri** (napr. `spark user:reset-2fa <email>`). Zmaže TOTP tajomstvo, záložné kódy aj dôveryhodné zariadenia, zapíše udalosť do denníka a pri ďalšom prihlásení sa 2FA nastaví nanovo. Obnova 2FA cez web alebo e-mail **neexistuje**.
- **D-13:** E-mailové **upozornenia pri citlivých zmenách**: zmena hesla, reset hesla, reset 2FA, pregenerovanie záložných kódov, nové dôveryhodné zariadenie a dočasný zámok účtu (D-18). E-mail neobsahuje žiadne tajomstvá ani odkazy, ktoré by niečo menili.
- **D-14:** **SMTP na stagingu sa do Phase 7 nenastavuje.** Odosielanie (reset hesla, upozornenia) sa naprogramuje cez CI4 Email a otestuje cez Mailpit vo vývoji. Na stagingu sa e-maily iba logujú (napr. vlastný „log“ transport). Kým nie je SMTP, reset hesla na stagingu nahrádza spark príkaz (napr. `spark user:password <email>`). Obnova hesla e-mailom musí byť hotová a otestovaná (AUTH-06), len sa na stagingu nedoručí.

### Relácie a dôveryhodné zariadenia
- **D-15:** „Zapamätať zariadenie“ preskočí **heslo aj TOTP** na 30 dní. Technicky ide o kombináciu trvalej prihlasovacej cookie a dôkazu 2FA viazaného na zariadenie. Pasca 9 však platí: Shield remember-me sa nesmie stať obchádzkou TOTP. Prihlásenie z remember cookie je platné iba vtedy, ak je zároveň platný záznam dôveryhodného zariadenia (hash tokenu v DB, neodvolaný, neexpirovaný). Ako to presne poskladať (vlastný token vs Shield remember-me + väzba), rozhodne výskum alebo spike.
- **D-16:** Začiarknutie „zapamätať zariadenie“ je **predvolene zapnuté**.
- **D-17:** 30 dní sa počíta **pevne od zadania TOTP** (nie posuvne). Po uplynutí sa vyžaduje heslo aj TOTP, aj pri dennom používaní. Odvolanie zariadenia, zmena hesla a reset 2FA zneplatnia dôveru okamžite.
- **D-18:** Na **nedôveryhodnom** zariadení sa relácia ukončí po **2 hodinách nečinnosti**.
- **D-19:** Obmedzenie pokusov: **5 chybných pokusov** (heslo alebo TOTP/záložný kód) vedie k **dočasnému zámku na 15 minút**, počítanému **pre IP aj pre účet**. Účet sa nikdy nezamkne natrvalo. Pri zámku účtu príde e-mail (D-13). Každý pokus aj zámok sa zapíše do denníka prihlásení.
- **D-20:** **Nastavenia → Zabezpečenie** je jedna stránka: zmena hesla, stav 2FA a pregenerovanie záložných kódov, zoznam dôveryhodných zariadení (názov/UA, prvé a posledné použitie, expirácia) s tlačidlom **Odvolať** a **posledných 50 prihlásení** (čas, úspech/neúspech, dôvod, IP, zariadenie). Na mobile je prístupná cez spodnú lištu Viac → Zabezpečenie.

### PWA identita
- **D-21:** Názov aplikácie je **„Work Tracker“** (`name` aj `short_name` v manifeste, `apple-mobile-web-app-title`).
- **D-22:** Ikona je **jednoduchá SVG ikona** (písmená alebo symbol na modrom podklade `#0d6efd`). Z nej sa vygenerujú PNG 180 (`apple-touch-icon`), 192, 512 a maskable variant. Zdrojové SVG je v repozitári, aby sa ikona dala neskôr vymeniť.
- **D-23:** Jazyk prihlasovacej stránky (pred prihlásením) sa vyberie podľa **`Accept-Language`** z podporovaných jazykov, predvolená je **slovenčina**. Po prihlásení platí jazyk z nastavení používateľa. V `app/Language` sú dnes len `sk` a `en`, PL a CZ pribudnú neskôr (E12). Výber musí fungovať aj s jazykmi, ktoré ešte nemajú preklad.
- **D-24:** Návod na inštaláciu sa zobrazí ako **zatvárateľný panel** („Zdieľať → Pridať na plochu“), **iba v mobilnom Safari mimo standalone režimu**. V nainštalovanej PWA sa nezobrazí. Zatvorenie sa pamätá lokálne (localStorage je tu v poriadku, ide len o pohodlie).

### Claude's Discretion
- Či sa TOTP zavesí do Shield ako akcia (`ConditionalActionInterface`/gateway) alebo do vlastného filtra. Rozhodne spike podľa poznámky v ROADMAP. Pevná požiadavka: stav „čaká na 2FA“ nie je prihlásenie (AUTH-09) a existuje integračný test bypassu.
- Presný mechanizmus remember-me a dôveryhodného zariadenia (D-15). Musí dodržať pascu 9 a pascu 10: `__Host-` cookie, `Secure`, `HttpOnly`, `SameSite=Lax`, `Max-Age`, rotácia tokenu pri použití.
- Session handler (súbory vs DB), dĺžka `Session::$expiration` a cookie `Max-Age` tak, aby platilo D-17/D-18 a iOS PWA sa neodhlasovala.
- Názvy spark príkazov, štruktúra GHA workflow (jeden `ci.yml` s jobom `deploy` alebo samostatný `deploy.yml`), spôsob prístupu servera ku GHCR (deploy token, SSH kľúč v GitHub Secrets) a kde na serveri ležia `.env` a tajomstvá.
- Podoba offline stránky a dizajn ikony (v rámci D-22).
- Rola `accountant`: iba v dátovom modeli a seede rolí. UI pre ňu sa nerobí (AUTH-08 ho uvádza ako voliteľné).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Rozsah a požiadavky
- `.planning/ROADMAP.md` § Phase 2: cieľ, 5 kritérií úspechu, poznámky (spike Shield + TOTP s fallbackom, SW bez HTML, `Vary: HX-Request`, CSRF bez regenerácie)
- `.planning/REQUIREMENTS.md`: AUTH-01 až AUTH-09, PWA-01, NFR-02, NFR-03, NFR-08
- `.planning/PROJECT.md`: obmedzenia, Key Decisions (CSP iba v nginx, HtmxCsrf, DB roly `app_owner`/`app_rw`, `grantMutable()`)
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` § 4 (anti-goal: stále odhlasovanie v PWA), § 5 (Používatelia a prístup, Prihlásenie), § 7 PWA, § 8 Technológie/Prostredie. **Pri rozpore platí spec.**
- `docs/PRD.md`: E1 (prihlásenie) a PWA požiadavky s kritériami prijatia

### Bezpečnosť a autentifikácia
- `CLAUDE.md` (Technology Stack): otvorené body 1 a 2 (otphp + bacon SVG, overenie s leeway 1 a `last_used_timestep`, `hash_equals`, šifrovanie cez CI4 Encryption/Sodium), nginx hlavičky a CSP, Docker obrazy, certbot
- `.planning/research/PITFALLS.md`: **Pasca 9** (TOTP nad Shieldom: pending stav, remember-me vs dôveryhodné zariadenie, reset hesla, fixácia relácie), **Pasca 10** (iOS PWA: trvalá cookie, oddelené úložisko, SW necachuje HTML, safe area, ikony), Pasca 11 (htmx + CSRF), Pasca 18 (denník bez tajomstiev)
- `.planning/research/ARCHITECTURE.md`: štruktúra `app/` (Filters, Libraries/Platform), DB roly
- `.planning/research/STACK.md`: zdôvodnenie otphp, bacon, Shield 1.4.1
- Shield docs: `https://raw.githubusercontent.com/codeigniter4/shield/develop/docs/references/authentication/auth_actions.md` (Actions, `ConditionalActionInterface`)

### Phase 1 (nadväznosť)
- `.planning/phases/01-z-klad-a-infra-trukt-ra/01-CONTEXT.md`: D-01 Makefile, D-04 HTTPS a secure cookies vo vývoji, D-05 CI, D-08 testovacia DB
- `.planning/phases/01-z-klad-a-infra-trukt-ra/01-VERIFICATION.md`: odložené WR-03 (prod compose je iba báza) a WR-04 (heslá DB rolí v env)

### Externé (nasadenie)
- Cloudflare: zoznam IP rozsahov `https://www.cloudflare.com/ips-v4` a `ips-v6` (pre `set_real_ip_from` a firewall), dokumentácia SSL režimu Full (strict)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Filters/HtmxCsrf.php`, `app/Filters/HtmxVary.php`: CSRF v hlavičke a `Vary: HX-Request`. Prihlasovacie a 2FA formuláre ich musia použiť.
- `app/Libraries/Platform/Clock.php` a `FrozenClock.php`: všetky časy (expirácia zariadení 30 dní, zámky 15 min, TOTP časový krok, nečinnosť 2 h) idú cez `Clock`, v testoch zmrazený.
- `app/Views/layouts/app.php` a `_nav_items.php`: layout so spodnou lištou, kam patrí položka Viac → Zabezpečenie. Prihlasovacie stránky potrebujú jednoduchší layout bez navigácie.
- `app/Views/fragments/alert.php` a `#app-alert` v `public/assets/js/app.js`: zobrazenie chýb (403 CSRF, 422).
- `docker/nginx/templates/snippets/security-headers.conf.template`: CSP a hlavičky, rozšíriť o manifest, `sw.js` (`no-cache`, koreň) a real IP z Cloudflare.
- `scripts/smoke.sh`, `scripts/lib-env-guard.sh`: smoke testy a ochrana pred spustením v produkcii. Rozšíriť o staging smoke (HTTPS, hlavičky, presmerovanie na login).
- `.github/workflows/ci.yml`: existujúce CI, na ktoré nadviaže build/push do GHCR a deploy.

### Established Patterns
- Migrácie vždy `spark migrate -g owner|tests_owner`, aplikácia beží ako `app_rw`. Denník prihlásení je **insert-only** (bez UPDATE/DELETE pre `app_rw`). Mutovateľné tabuľky (zariadenia, kódy, zámky) dostanú práva cez `grantMutable()`.
- Texty iba cez `lang()` (`ViewsHardcodedTextTest`), žiadne inline skripty a štýly (`ViewsCspTest`). Platí aj pre login, 2FA, offline stránku a inštalačný panel.
- Session: `app/Config/Session.php` má dnes `FileHandler` a `expiration = 7200`. Cookie (`app/Config/Cookie.php`) má `secure = true`, `httponly = true`, `samesite = Lax`, `prefix = ''`. Treba zosúladiť s D-15 až D-18 a iOS (pasca 10).
- Shield ani otphp/bacon zatiaľ nie sú v `composer.json`, pridávajú sa v tejto fáze.

### Integration Points
- `compose.yaml` a `compose.override.yaml`: dev je override. Staging/prod potrebuje compose bez Mailpitu a bind mountov, s obrazom z GHCR a certifikátmi.
- `app/Config/Filters.php`: globálny auth filter, ktorý okrem login/2FA/reset/offline/manifest/`sw.js`/assets všetko presmeruje na prihlásenie (AUTH-09).
- GitHub repo `voslarA4N/task-tracker`: GHCR balík a Secrets (SSH kľúč na server, Cloudflare API token, prípadne deploy token).

</code_context>

<specifics>
## Specific Ideas

- Presná staging/produkčná adresa: **`https://work-tracker.vostack.dev`**.
- Server: Hetzner cx23, IP `2.31.74.48`, cez Cloudflare proxy.
- Názov PWA na ploche: **„Work Tracker“**, ikona na modrom `#0d6efd` (farba z makiet).
- Záložné kódy majú tlačidlo „Stiahnuť .txt“ a potvrdenie „Uložil som si ich“.
- Kritérium 5 z ROADMAP sa overí ručne na reálnom iPhone vlastníka: Pridať na plochu, standalone, offline stránka, žiadne prihlásenie počas 30 dní po zapamätaní zariadenia.

</specifics>

<deferred>
## Deferred Ideas

- Voľba produkčného SMTP a DNS záznamy SPF/DKIM/DMARC pre `vostack.dev`: Phase 7. Dovtedy sa e-maily na stagingu iba logujú.
- UI pre rolu `accountant`: neskôr, podľa potreby (model je pripravený vo Phase 2).
- Plné zálohy (restic na Storage Box, rotácia 14/8/12, test obnovy) a finálny produkčný compose: Phase 12. Phase 2 robí len lokálny `pg_dump` pred migráciou.

</deferred>

---

*Phase: 02-identita-a-skor-nasadenie*
*Context gathered: 2026-10-24*
