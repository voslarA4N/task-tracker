# Phase 12: Lokalizácia a produkcia - Pattern Map

**Mapped:** 2026-11-04
**Files analyzed:** 27 (nové alebo menené)
**Analogs found:** 25 / 27 (2 bez analógu: restic skripty majú iba čiastočný analóg v shell skriptoch, `pull-backups.sh` a `measure-p95.sh` sú nové)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Config/App.php` (`supportedLocales`, r. 125) | config | request-response | sám seba | exact (1 riadok) |
| `app/Language/pl/*.php` (30 súborov) | config (preklady) | transform | `app/Language/sk/*.php` | exact |
| `app/Language/cs/*.php` (30 súborov) | config (preklady) | transform | `app/Language/sk/*.php` | exact |
| `app/Language/{sk,pl,cs,en}/Account.php` (+kľúče `language*`) | config (preklady) | transform | `app/Language/sk/Account.php` | exact |
| `app/Views/settings/_language.php` | component (view partial) | request-response | `app/Views/settings/_password.php` | exact |
| `app/Views/settings/security.php` (include) | component | request-response | sám seba | exact |
| `app/Controllers/Settings/Security.php` (`setLocale`) | controller | CRUD (UPDATE) | `Security::revokeDevice` / `changePassword` / `enableTotp` | exact |
| `app/Config/Routes/settings.php` (nová trasa) | route | request-response | r. 6 až 8 toho istého súboru | exact |
| `app/Libraries/Auth/AuthEvent.php` (nový case `LocaleChanged`) | model (enum) | event-driven | existujúce case `PasswordChanged` | exact |
| `tests/unit/I18n/LangKeysTest.php` (parita, placeholdery) | test | transform | sám seba (`testSkAndEnHaveSameFilesAndKeys`) | exact |
| `tests/feature/Settings/LocaleTest.php` | test | request-response | `tests/feature/Settings/SecurityPageTest.php` | exact |
| `tests/unit/Platform/MoneyFormatLocaleTest.php` | test | transform | `tests/unit/Platform/MoneyFormatTest.php` | exact |
| `scripts/backup-restic.sh` | utility (host cron skript) | batch | `scripts/backup.sh` + `scripts/mail-backup.sh` | role-match |
| `scripts/restore-test.sh` | utility (host cron skript) | batch | `scripts/backup.sh` (`pg_restore`) + docs/ops/backup.md obnova | role-match |
| `scripts/pull-backups.sh` | utility (WSL skript) | file-I/O | `scripts/mail-backup.sh` (štýl, umask, env) | partial |
| `scripts/archive-yearly.sh` | utility (cron wrapper) | batch | `scripts/mail-backup.sh` | role-match |
| `scripts/measure-p95.sh` | utility | request-response | `scripts/smoke-server.sh` | role-match |
| `app/Commands/ArchiveYear.php` | command (spark) | batch / file-I/O | `app/Commands/InvoicesLockHistorical.php` + `UserReset2fa.php` | role-match |
| `app/Libraries/Export/YearArchive.php` | service | file-I/O (ZIP + CSV) | `app/Libraries/Export/AccountantBundle.php` | exact |
| `tests/unit/Scripts/ResticBackupScriptTest.php` | test | batch | `tests/unit/Scripts/BackupScriptTest.php` | exact |
| `tests/integration/Database/ImmutableDocumentsTest.php` | test | CRUD (negatívny) | `tests/integration/DbRolesTest.php` | exact |
| `tests/integration/Export/YearArchiveTest.php` | test | file-I/O | `tests/integration/Export/AccountantBundleTest.php` | exact |
| nová migrácia (iba ak test nájde dieru, napr. REVOKE TRUNCATE) | migration | CRUD | `2026-10-27-000006_GuardInvoiceTruncate.php`, `2026-10-29-000005_CreatePayments.php` | exact |
| `docker/nginx/templates/default.conf.template` (gzip_types) | config | request-response | sám seba (r. 53 až 56) | exact |
| `scripts/smoke-server.sh` (rozšírenie) | utility | request-response | sám seba | exact |
| `docs/ops/backup.md` (prepis), `docs/ops/performance.md` (nový), `docs/ops/server.md` (cron, 2FA reset) | docs | n/a | `docs/ops/backup.md`, `docs/ops/server.md` §12 | exact |

---

## Pattern Assignments

### `app/Config/App.php` (config)

Zmena iba riadok 125 (overené `grep`): `public array $supportedLocales = ['sk', 'en'];` na `['sk', 'pl', 'cs', 'en']`. Poradie je zámerné (UI-SPEC: predvolený `sk` je prvý, `Config\App::$defaultLocale = 'sk'` r. 96). `LocaleNegotiation` ho číta z `config('App')->supportedLocales`, nič ďalšie sa nemení.

---

### `app/Language/pl/*.php` a `app/Language/cs/*.php` (preklady, transform)

**Analog:** `app/Language/sk/*.php` (32 súborov; `InvoicePdf.php` a `ReportPdf.php` v `pl`/`cs` už existujú a sa nemenia, treba doplniť 30 súborov). Zoznam:
`Account, Allocations, App, AuditLog, Bundle, Clients, CreditNotes, Currency, Dashboard, Deliveries, Demo, DocumentSettings, EmailTemplates, Entities, Export, FxSettings, Invoices, Invoicing, LegalTexts, Limits, Login, Notify, PasswordReset, Payments, Pwa, Reports, Tasks, TwoFactor, Users, Validation`.

**Formát súboru** (`app/Language/sk/Account.php` r. 1 až 9): `declare(strict_types=1); return [ 'kľúč' => 'text', ... ];`, zarovnané `=>`, UTF-8, jednoduché apostrofy, zástupné symboly `{0}`, `{1}`.
```php
<?php

declare(strict_types=1);

return [
    'moreTitle'                      => 'Viac',
    'securityTitle'                  => 'Zabezpečenie',
    'revokeConfirm'                  => 'Na zariadení {0} sa bude pri ďalšom prihlásení vyžadovať heslo aj kód 2FA. Odvolať?',
```
Postup: súbor po súbore skopírovať štruktúru kľúčov zo `sk` a preložiť hodnoty podľa terminológie v `12-UI-SPEC.md` sekcia B (Úloha PL Zadanie / CS Úkol, Dobropis PL Faktura korygująca atď.). Kľúče a poradie nemeniť. Tab bar `App.php`: jedno slovo (Przegląd/Zadania/Faktury/Więcej; Přehled/Úkoly/Faktury/Více). `Validation.php` preložiť celý. Endonymá `Account.languageSk|Pl|Cs|En` majú identickú hodnotu vo všetkých 4 jazykoch.
**Poznámka k `en`:** `en` už má 32 súborov, ale nové kľúče `Account.language*` treba pridať do všetkých štyroch jazykov v tom istom pláne (inak parita zlyhá).

---

### `app/Views/settings/_language.php` (view partial, request-response)

**Analog:** `app/Views/settings/_password.php` (celý súbor, 30 riadkov).

**Kostra karty + chybový alert + form so submit-once + tlačidlo so spinnerom** (`_password.php` r. 1 až 30):
```php
<?php $passwordError = $passwordError ?? null; ?>
<section class="app-card security-card" aria-labelledby="sec-password">
    <h2 class="h5" id="sec-password"><?= esc(lang('Account.passwordHeading')) ?></h2>

    <?php if (! empty($passwordError)) : ?>
        <div class="alert alert-danger" role="alert" id="password-error"><?= esc((string) $passwordError) ?></div>
    <?php endif ?>

    <form method="post" action="/settings/security/password" data-submit-once novalidate>
        <?= csrf_field() ?>
        ...
        <button type="submit" class="btn btn-primary btn-touch">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('Account.changePassword')) ?>
        </button>
    </form>
</section>
```
Pre `_language.php`: `sec-language`, `$languageError`, `action="/settings/security/locale"`, `fieldset` + `legend.visually-hidden` s 4 `.form-check` radio (`name="locale"`, hodnoty z `config('App')->supportedLocales`, `checked` podľa `$currentLocale`), 12px „Aktuálny“ pri uloženej hodnote, tlačidlo `w-100` na mobile a `w-auto` od lg (Bootstrap `w-100 w-lg-auto` neexistuje, použiť existujúci vzor tlačidiel v `_twofactor.php`; overiť pri implementácii). Bez inline `style`, bez `onclick` (CSP).

**Include** v `app/Views/settings/security.php` r. 8 až 11: pridať `<?= $this->include('settings/_language') ?>` **pred** `settings/_password` (UI-SPEC: karta ako prvá).

---

### `app/Controllers/Settings/Security.php` (controller, CRUD UPDATE)

**Analóg:** vo vnútri toho istého kontrolera: `changePassword` (validácia + `errorPage` 422), `revokeDevice` (flash `app_notice` + PRG redirect), `enableTotp` (`UPDATE user_security ... WHERE user_id`, `AuditLog->record`).

**Aktuálny používateľ** (`Security::revokeDevice` r. 41 až 43; v ostatných metódach `$this->currentUser()`):
```php
$user = $this->currentUser();
$userId = (int) $user->id;
```

**Zápis do `user_security`** (`enableTotp` r. 215 až 220): raw `$db->query('UPDATE user_security SET ... , updated_at = :now: WHERE user_id = :u:', [...])`. Pre locale: `UPDATE user_security SET locale = :l:, updated_at = :now: WHERE user_id = :u:` (stĺpec `locale` je mutable pre `app_rw`, bez migrácie; overiť, že `locale` je v zozname stĺpcov granted v migrácii `user_security`).
```php
$db->query(
    'UPDATE user_security SET totp_secret_enc = :enc:, totp_enabled_at = :now:, ... updated_at = :now: WHERE user_id = :u: AND totp_enabled_at IS NULL',
    ['enc' => ..., 'now' => $now->format('Y-m-d H:i:sP'), 'u' => $userId],
);
```

**Audit** (`enableTotp`): `(new AuditLog())->record(AuthEvent::TotpEnrolled, true, $userId, null, 'settings');` signatúra `AuditLog::record(AuthEvent $event, bool $success, ?int $userId, ?string $email = null, ?string $detail = null)`. Pridať case `LocaleChanged = 'locale_changed'` do `app/Libraries/Auth/AuthEvent.php` (vzor r. 9 až 31, `case PasswordChanged = 'password_changed';`), detail = nová hodnota locale. Skontrolovať, či `auth_events.event` nemá CHECK obmedzenie hodnôt (ak áno, treba migráciu) a či `Settings/Audit` zobrazenie a lang kľúče pre udalosti potrebujú nový kľúč (`AuditLog.php` / `Account` events).

**Flash + PRG** (`revokeDevice` r. 54 až 56, `changePassword` r. 87):
```php
session()->setFlashdata('app_notice', ['type' => 'info', 'message' => lang('Account.revoked')]);

return redirect()->to('/settings/security');
```
Pre locale: flash typ `success`, správa `lang('Account.languageSaved')` generovaná **až po** `service('language')->setLocale($new)` (aby bola v novom jazyku; alebo `lang('Account.languageSaved', [], $new)`, tretí argument `lang()` je locale a `LangKeysTest` ho už používa s `'sk'`).

**Validácia + chyba 422** (`changePassword` r. 70 až 72 a `errorPage`):
```php
if ($current === '' || $new === '' || mb_strlen($new) > 1024) {
    return $this->errorPage($user, 'passwordError', lang('Account.passwordRequired'), 422);
}
```
Pre locale: `if (! in_array($locale, config('App')->supportedLocales, true)) { return $this->errorPage($user, 'languageError', lang('Account.languageInvalid'), 422); }` (pozri `errorPage`/`pageData` v súbore, pridať `languageError` a `currentLocale` do `pageData`). `postString('locale')` je existujúci pomocník (r. 62 až 64). Bez zmeny hodnoty: rovnaký flash, bez `UPDATE` a bez auditu.

**Trasa** (`app/Config/Routes/settings.php` r. 6 až 8):
```php
$routes->post('settings/security/password', 'Settings\Security::changePassword');
```
pridať `$routes->post('settings/security/locale', 'Settings\Security::setLocale');`. CSRF filter je globálny (vzor `csrf_field()` vo formulári), nič ďalšie.

---

### `tests/feature/Settings/LocaleTest.php` (feature test)

**Analog:** `tests/feature/Settings/SecurityPageTest.php` r. 1 až 80.

**Kostra** (r. 1 až 36):
```php
#[Group('feature')]
final class SecurityPageTest extends AuthTestCase
{
    private function postTo(string $path, bool $csrf = true): TestResponse
    {
        $this->withSession();

        return $this->call('post', $path, $csrf ? [csrf_token() => csrf_hash()] : []);
    }
    ...
    $this->loginAs($this->createUser());
```
Pre locale poslať aj `'locale' => 'pl'` v poli; prípady: platná hodnota (302, `user_security.locale` zmenené cez `$this->owner()->query(...)`, audit riadok), neplatná (422, nezmenené), bez CSRF (odmietnuté), anonymný (302 na `/login`, vzor `testAnonymousIsRedirectedToLogin`), zmena na `cs` sa zmestí (varchar(10)), flash v novom jazyku.

---

### `tests/unit/I18n/LangKeysTest.php` (rozšírenie, transform)

**Analog:** sám seba. Existujúce helpery `load()` a `flatten()` (r. 66 až 99) nechať. `flatten()` vracia iba zoradený zoznam kľúčov bez hodnôt, preto pre kontrolu placeholderov treba pridať druhý helper vracajúci `[kľúč => hodnota]`.

**Test na rozšírenie** (r. 47 až 56), nahradiť dataproviderom po `['pl','cs','en']`:
```php
public function testSkAndEnHaveSameFilesAndKeys(): void
{
    $sk = $this->load(APPPATH . 'Language/sk');
    $en = $this->load(APPPATH . 'Language/en');

    $this->assertSame(array_keys($sk), array_keys($en), 'Rozdielne subory sk/en');

    foreach ($sk as $name => $keys) {
        $this->assertSame($keys, $en[$name], "Rozdielne kluce v {$name}");
    }
}
```
Pridať: `#[DataProvider]` pre `pl`, `cs`, `en` (súbory aj kľúče v oboch smeroch, `assertSame` robí oba smery), porovnanie `preg_match_all('/\{[^}]+\}/', ...)` medzi sk a locale pre každý kľúč, `assertNotSame('', $value)`, a výnimky (endonymá, `PDF`, `CSV`, `XLSX`, `2FA`) pre rovnosť PL/CS == SK. Test dĺžky >140 % iba ako `markTestIncomplete`/varovanie, nie chyba. Pozor: test parity sa pridáva až v pláne, ktorý dodá preklady (inak je červený).
Existujúci `testAllUsedKeysExistInSk` (r. 19 až 45) a `testValidationMessagesAreTranslated` ostávajú.

---

### `tests/unit/Platform/MoneyFormatLocaleTest.php` (unit test)

**Analog:** `tests/unit/Platform/MoneyFormatTest.php` r. 1 až 40.
**Normalizácia medzier** (r. 20, vzor):
```php
$this->assertSame('1080,00', preg_replace('/[\s\x{00A0}\x{202F}]/u', '', substr($out, 0, -strlen("\u{00A0}€"))));
```
Porovnávať po odstránení/normalizácii `U+00A0` a `U+202F` (RESEARCH Pitfall 5). Volanie: `MoneyFormat::format('1080.00', 'PLN', 'pl')` s explicitným locale (tretí argument), tabuľka očakávaní podľa UI-SPEC sekcia C (EN: `€1,080.00` prefix). Pridať `LimitMath::formatPercent` pre 4 jazyky (`app/Libraries/Reporting/LimitMath.php`) a mesiac cez `IntlDateFormatter` s `LLLL y` (`listopad`/`listopad` v pl a cs). Test bežať v kontajneri `php` (ICU dáta alpine, A3).

---

### `scripts/backup-restic.sh` (host cron skript, batch)

**Analóg 1:** `scripts/backup.sh` (dump + overenie + `.partial` + sha256). Znovu použiť volaním (`sh scripts/backup.sh "$DUMP_DIR"`) a premenovaním na **stabilný názov** (RESEARCH Pitfall 2: stabilná cesta kvôli skupinám snapshotov v restic).

**Hlavička skriptu a štýl** (`backup.sh` r. 1 až 20):
```sh
#!/bin/sh
# D-24: ... 
set -eu
umask 077
ROOT=$(cd "$(dirname "$0")/.." && pwd)
...
DB=${BACKUP_DB:-task_tracker}
DB_USER=${BACKUP_DB_USER:-app_owner}
NAME="task_tracker-$(date -u +%Y%m%dT%H%M%S).dump"
trap 'rm -f "$OUT/$NAME.partial"' EXIT

docker compose exec -T db pg_dump -U "$DB_USER" --format=custom --no-owner "$DB" > "$OUT/$NAME.partial"
docker compose exec -T db pg_restore -f /dev/null < "$OUT/$NAME.partial" > /dev/null
```
**Analóg 2:** `scripts/mail-backup.sh`: komentár s cron riadkom v hlavičke, premenné s `${VAR:-default}`, `install -d -m 0700`, `dc()` obal nad `docker compose`, `trap` cleanup, rotácia, `echo "OK: ..."`:
```sh
# cron (deploy): 30 3 * * * cd /opt/mail && sh scripts/mail-backup.sh >> /opt/mail/backup.log 2>&1
set -eu
umask 077
MAIL_DIR=${MAIL_DIR:-/opt/mail}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/mail}
COMPOSE_PROJECT=${COMPOSE_PROJECT:-mail}
COMPOSE_FILES=${COMPOSE_FILES:--f compose.mail.yaml}
...
install -d -m 0700 "$BACKUP_DIR"
# shellcheck disable=SC2086
dc() { docker compose -p "$COMPOSE_PROJECT" $COMPOSE_FILES "$@"; }
```
Pre server: `backup.sh` volá `docker compose exec` bez `-f`, na serveri treba `COMPOSE_FILE=compose.server.yaml` a `IMAGE_TAG=$(cat .last-good-tag)` (server.md §12 a RESEARCH Pitfall 1). `dc()` vzor nastaviť podobne. Kostra restic časti a forget parametre: RESEARCH.md r. 203 až 221 (`restic backup --tag db --host work-tracker`, `restic forget --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --keep-tag archive --prune`). Tajomstvá (restic heslo, `HC_URL`) zo samostatného root súboru `/root/.config/work-tracker-backup.env` (0600), nie `app.env` (zákaz `sed -i`, skupina `deploy:82`). Upozornenie: e-mail pri zlyhaní `curl --url smtp://` podľa UI-SPEC sekcia E (predmet `[work-tracker] Záloha zlyhala: {prostredie} {YYYY-MM-DD}`, slovenčina, bez hesiel).

---

### `scripts/restore-test.sh` (host cron, batch)

**Analóg:** `docs/ops/backup.md` r. 34 až 41 (obnova) a `backup.sh` (`docker compose exec -T db ...`):
```sh
docker compose exec -T db createdb -U postgres restore_check
docker compose exec -T db pg_restore -U postgres --clean --if-exists --no-owner -d restore_check < backups/task_tracker-<čas>.dump
```
Doplniť: `restic restore latest --tag db --target <tmp>`, porovnanie počtov riadkov a `SELECT max(number)` faktúr s produkčnou DB, `dropdb`, ping/mail ako v `backup-restic.sh`. `trap` cleanup z `backup.sh` r. 20.

---

### `scripts/pull-backups.sh` (WSL skript, file-I/O)

**Analóg:** žiadny presný (len štýl `mail-backup.sh`). Skopírovať konvencie (`set -eu`, `umask 077`, premenné s defaultmi, `echo "OK: ..."`). Príkaz z RESEARCH r. 225: `rsync -a --partial -e "ssh -i ~/.ssh/<kluc>" deploy@2.31.74.48:/var/backups/restic/work-tracker/ ~/backups/work-tracker-restic/`, **bez `--delete`** (Anti-Pattern). Otvorená otázka 1 (práva čítania repozitára root-only vs `deploy`) sa rieši v pláne.

---

### `scripts/archive-yearly.sh` (cron wrapper)

**Analóg:** `scripts/mail-backup.sh` (štýl) + volanie spark z `server.md` §12:
```
ssh -t deploy@2.31.74.48 'cd /opt/work-tracker && IMAGE_TAG=$(cat .last-good-tag) docker compose -f compose.server.yaml exec php php spark user:reset-2fa <e-mail>'
```
Pre skript: `docker compose -f compose.server.yaml exec -T php php spark archive:year [rok]`, potom `docker compose cp` na hostiteľa a `restic backup --tag archive --host work-tracker <ZIP>`, zmazať dočasný súbor, ping. Predvolený rok = predchádzajúci (cron 1. januára).

---

### `scripts/measure-p95.sh` (utility, request-response)

**Analóg:** `scripts/smoke-server.sh` r. 1 až 60: usage hlavička s voliteľnými env, `set -eu`, `D=${1:?Použitie: ...}`, `pass()/fail()/skip()` helpery, `mktemp -d` s `trap 'rm -rf "$TMP"' EXIT`, `curl -sS -o /dev/null -D "$TMP/root.h" -w '%{http_code} %{http_version}'`. Pre p95: `curl -w '%{time_total}'` slučka s cookie z premennej prostredia (cookie nikdy do súboru ani reportu, Pitfall 9), výpočet p95 cez `sort -n | awk`, `ORIGIN_IP` cez `--resolve` (už existuje v smoke skripte). Žiadny `ab/hey/wrk` nie je lokálne dostupný.

---

### `app/Commands/ArchiveYear.php` (spark command, batch)

**Analógy:** `app/Commands/InvoicesLockHistorical.php` (voľby, validácia, try/catch, návratové kódy) a `app/Commands/UserReset2fa.php` (minimálna kostra).

**Kostra** (`UserReset2fa.php` r. 15 až 35):
```php
/**
 * ...
 * @internal
 */
final class UserReset2fa extends BaseCommand
{
    protected $group       = 'Auth';
    protected $name        = 'user:reset-2fa';
    protected $description = '...';
    protected $usage       = 'user:reset-2fa <email> [--yes]';
    protected $arguments   = ['email' => 'E-mail používateľa'];
    protected $options     = ['--yes' => 'Bez potvrdzovacej otázky'];

    public function run(array $params): int
    {
        $email = $params[0] ?? null;
        if ($email === null) {
            CLI::error('Použitie: ' . $this->usage);
            return EXIT_ERROR;
        }
        ...
        CLI::write('2FA bola zrušená. ...', 'green');
        return EXIT_SUCCESS;
```
**Validácia vstupu regexom** (`InvoicesLockHistorical` r. 56 až 66): `preg_match('/^\d{1,18}$/D', $client) !== 1` → `CLI::error('Neplatný vstup. Použitie: ' . $this->usage); return EXIT_ERROR;`. Pre rok: `preg_match('/^\d{4}$/D', $params[0] ?? '')`, predvolene `service('clock')->now()->getYear() - 1`.
**Výstup** podľa UI-SPEC sekcia E: „Archív roka {rok}: {n} dokladov, {n} PDF, {veľkosť}“, pri chybe „Archív sa nevytvoril: {dôvod}“ a nenulový exit. Namespace `App\Commands`, `@internal`, slovenské hlášky v UTF-8 (CLI výstup nie je `lang()`).

---

### `app/Libraries/Export/YearArchive.php` (service, file-I/O)

**Analóg:** `app/Libraries/Export/AccountantBundle.php` (celý súbor).

**Importy a konštruktor** (r. 5 až 31):
```php
use App\Libraries\Invoicing\DocumentStore;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use ZipArchive;

final class AccountantBundle
{
    /** @var BaseConnection<mixed, mixed> */
    private readonly BaseConnection $db;

    /** @param BaseConnection<mixed, mixed>|null $db */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }
```
**Dočasný súbor + ZipArchive + cleanup `finally`** (r. 66 až 119):
```php
$dir = WRITEPATH . 'exports';
if (! is_dir($dir)) { mkdir($dir, 0700, true); }
$tmp = tempnam($dir, 'zip');
if ($tmp === false) { throw new RuntimeException('Cannot create temporary bundle file.'); }
try {
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot open zip archive.');
    }
    $store = new DocumentStore($this->db);
    $used  = [];
    foreach ($docs as $d) {
        $stored = $store->read($d['id'], 'invoice');
        ...
        $name = DocumentListExport::pdfName($d);
        if (isset($used[$name])) { $name = substr($name, 0, -4) . '-' . $d['id'] . '.pdf'; }
        $used[$name] = true;
        $zip->addFromString('doklady/' . $name, $stored->bytes);
    }
    $zip->addFromString('zoznam-dokladov.csv', CsvWriter::render(...));
    if (! $zip->close()) { throw new RuntimeException('Cannot finalize zip archive.'); }
    ...
} finally {
    if (is_file($tmp)) { unlink($tmp); }
}
```
**Rozdiely oproti analógu (nie kopírovať):**
- bez `MAX_BYTES` limitu; výstup nie je string v pamäti, ale **cesta k ZIP** v `WRITEPATH . 'archive'` (D-10),
- SELECT nie je z `BundleSelector::documents` (filtruje podľa entity a `turnover_date`), ale podľa roku `issue_date` cez `invoices` JOIN `invoice_documents` (**všetky verzie**, `kind IN ('invoice','report')`, `version`, `sha256`; stĺpce overené v migrácii `CreateInvoices` r. 129: `kind CHECK IN ('invoice','report')`, `UNIQUE (invoice_id, kind)` bolo neskôr rozšírené o `version` v migrácii `2026-11-04-000001_AddInvoiceDocumentVersions`). Pozor: `DocumentStore::read` vracia iba najvyššiu verziu; pre všetky verzie použiť `DocumentStore::readVersion($invoiceId, $kind, $version)` (r. 68 až 76 súboru `DocumentStore.php`, overuje sha256, vyhodí `DocumentIntegrityError`),
- bytea iba cez `DocumentStore` (docblok: „Binarne data iba cez decode(hex) a pg_unescape_bytea, nikdy Query Builder“), nenačítavať všetky PDF naraz,
- CSV: `CsvWriter::render(ExportTable)` (UTF-8 BOM, `;`, `FormulaGuard`), sumy ako string (`ExportCell::money`/`text`), hlavička podľa D-10 `číslo;typ;dátum;subjekt;klient;suma;mena;sha256;súbor`. Archív je ops artefakt, preto CSV hlavička môže byť pevná slovenčina namiesto `lang('Export.*')`. Názov súboru PDF cez `DocumentFilename::for($kind, $docLanguage, $number)` (`app/Libraries/Invoicing/DocumentFilename.php` r. 20) alebo `DocumentListExport::pdfName`.

---

### `tests/unit/Scripts/ResticBackupScriptTest.php` (unit test shell skriptu)

**Analóg:** `tests/unit/Scripts/BackupScriptTest.php` r. 1 až 120.

**Falošný `docker` na PATH, logovanie volaní, temp adresár** (r. 17 až 60):
```php
private const FAKE_DOCKER = <<<'SH'
    #!/bin/sh
    printf '%s\n' "$*" >> "$FAKE_LOG"
    case "$*" in
      *pg_dump*)
        [ -z "${FAKE_DUMP_FAIL-}" ] || exit 1
        printf 'PGDMP-fake-dump'
        ;;
      *"pg_restore -f /dev/null"*)
        cat > /dev/null
        [ -z "${FAKE_LIST_FAIL-}" ] || exit 1
        ;;
    esac
    exit 0
    SH;
...
protected function setUp(): void
{
    $this->dir = sys_get_temp_dir() . '/backup-script-' . bin2hex(random_bytes(6));
    foreach (['scripts', 'bin', 'out'] as $sub) { mkdir($this->dir . '/' . $sub, 0o700, true); }
    copy(ROOTPATH . 'scripts/backup.sh', $this->dir . '/scripts/backup.sh');
    file_put_contents($this->dir . '/bin/docker', self::FAKE_DOCKER . "\n");
    chmod($this->dir . '/bin/docker', 0o700);
}
```
Pridať falošné `restic` a `curl` (logujú argumenty): overiť `restic backup --tag db`, parametre `forget` (`--keep-daily 14 --keep-weekly 8 --keep-monthly 12 --keep-tag archive --prune`), ping OK pri úspechu a `/fail` pri chybe, dočasný dump po behu zmazaný, heslo ani `HC_URL` sa nevypíše do stdout/stderr. Asserty vzoru: `assertSame(0, $code, $stderr)`, `assertStringContainsString(..., $log)`, práva `fileperms & 0o777`. `run2()` helper je v súbore (posledná časť, treba ho skopírovať). Voliteľný `ResticRetention` test s reálnym restic v tmp repozitári (preskočiť, ak chýba).

---

### `tests/integration/Database/ImmutableDocumentsTest.php` (integration, negatívne CRUD)

**Analóg:** `tests/integration/DbRolesTest.php` r. 1 až 120, základ `tests/_support/PgTestCase.php`.

**Kostra a asercie** (DbRolesTest r. 11 až 25, 90 až 95; PgTestCase r. 60 až 71):
```php
#[Group('integration')]
final class DbRolesTest extends PgTestCase
{
    public function testRwCannotUpdateDeleteTruncateInsertOnly(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE _probe_insert_only SET note='b'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM _probe_insert_only'));
        $this->assertPermissionDenied(fn () => $this->rw()->query('TRUNCATE _probe_insert_only'));
    }
```
```php
protected function assertPermissionDenied(callable $fn): void
{
    try { $fn(); } catch (DatabaseException $e) {
        $this->assertMatchesRegularExpression('/permission denied|must be owner/i', $e->getMessage());
        return;
    }
    $this->fail('Expected DatabaseException (permission denied), none thrown.');
}
```
`$this->rw()` = `app_rw`, `$this->owner()` = `app_owner`. **Chýba helper `assertDbError`** (RESEARCH ho spomína, v `PgTestCase` nie je). Pre `app_owner` (trigger `integrity_constraint_violation`, hláška „... is immutable“) treba buď pridať helper do `PgTestCase`, alebo lokálny `try/catch (DatabaseException)` s `assertStringContainsString('immutable', ...)`.
**Tabuľky (overené v migráciách):** `invoices, invoice_lines, invoice_documents` (immutable_guard `CreateInvoices` r. 189 až 191, truncate_guard `GuardInvoiceTruncate::TABLES`), `invoice_counters` (**counter_guard**: DELETE zlyhá, UPDATE o +1 je povolené, `GuardInvoiceTruncate::TABLES`), `invoice_sends, invoice_send_voids` (`CreateInvoiceSends` r. 62 až 66), `payments, payment_voids` (`CreatePayments` r. 41 až 44), `invoice_regen_windows` (`2026-11-04-000002`). RESEARCH A7 spomína názvy neoverené, tu sú overené. **Dobropisy sú v `invoices`** (`type = 'credit_note'`). Doplnkový test: zoznam tabuliek sa overí dotazom na `pg_trigger` a poistka cez FK na `invoices`/`invoice_documents` (RESEARCH r. 239).
**Pitfall 7:** `truncate_guard` povolí TRUNCATE prázdnej tabuľky, test musí tabuľku najprv naplniť (fixtures `InvoiceFixtures`, `IssueFixtures`, `CreditNoteFixtures` z `tests/_support`, použité v `AccountantBundleTest`). Pre `app_rw` zlyhá TRUNCATE na GRANT aj nad prázdnou tabuľkou (`assertPermissionDenied`).

---

### `tests/integration/Export/YearArchiveTest.php` (integration, file-I/O)

**Analóg:** `tests/integration/Export/AccountantBundleTest.php` r. 1 až 70.

**Hlavička, traits, setUp s fixtures** (r. 23 až 71):
```php
#[Group('integration')]
final class AccountantBundleTest extends PgTestCase
{
    use MasterFixtures;
    use InvoiceFixtures;
    use CreditNoteFixtures;
    use IssueFixtures;
    use TurnoverFixtures;
    use ReportFixtures;
    ...
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        $this->entity = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $this->client = $this->createClient(['name' => '=1+1']);
        $a  = $this->reportInvoice($this->entity, $this->client, '2026-10-05', '1000.00');
        $cn = $this->turnoverCreditNote($b, '2026-10-20');
        $this->insertExternalInvoice($this->client, '2026-10', [$alloc]);
```
Ďalej v súbore (čítať pri implementácii) vzor otvorenia ZIP cez `ZipArchive` a porovnania sha256. Prípady: ZIP obsahuje všetky PDF roka a všetky verzie, `sha256` v CSV sedí s obsahom, prázdny rok, rok mimo, externé faktúry (bez PDF, `origin = 'external'`) sa vynechajú z PDF ale prípadne zostanú v CSV (rozhodnúť; Open Question 5), iný rok sa nezahrnie. Test príkazu: `ArchiveYear` cez `CLI` runner alebo priamo `YearArchive::build`.

---

### Nová migrácia (podmienená: doplnenie ochrany)

**Analóg:** `app/Database/Migrations/2026-10-27-000006_GuardInvoiceTruncate.php` r. 15 až 45 (statement trigger + `public const TABLES`) a `2026-10-29-000005_CreatePayments.php` r. 41 až 44 (`CREATE TRIGGER ... BEFORE TRUNCATE ... FOR EACH STATEMENT EXECUTE FUNCTION truncate_guard()` a `GRANT SELECT, INSERT ON TABLE {$t} TO app_rw`).
```php
foreach (self::TABLES as $t) {
    $this->db->query("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION truncate_guard()");
}
```
`down()` ruší trigger `DROP TRIGGER IF EXISTS`. Podľa overenia v migráciách všetky doklady už majú `immutable_guard` + `truncate_guard`, takže migrácia je pravdepodobne zbytočná; vytvoriť ju iba ak test nájde chýbajúcu tabuľku. Názov migrácie v tvare `2026-11-DD-00000N_*.php` (po `2026-11-04-000002`).

---

### `docker/nginx/templates/default.conf.template` (gzip, overenie)

Existujúce (r. 52 až 56 pri `gzip on`):
```nginx
gzip on;
gzip_vary on;
gzip_min_length 256;
gzip_types text/css application/javascript image/svg+xml application/json text/plain;
```
a `location ^~ /assets/ { ... add_header Cache-Control "public, max-age=31536000, immutable"; ... }`, `http2 on;`. HTTP/2, immutable a CSP snippet už existujú. Možná zmena: pridať `text/javascript application/manifest+json` do `gzip_types` ak `curl -sI -H 'Accept-Encoding: gzip'` na `.js` ukáže bez gzipu (A5). `text/html` je v nginx vždy gzipovaný. OPcache (`docker/php/conf.d/opcache-prod.ini`): `validate_timestamps=0`, `jit=off`, `preload=/var/www/html/preload.php`, `memory_consumption=64`, už splnené.

---

### `scripts/smoke-server.sh` (rozšírenie)

**Analóg:** sám seba, hlavne r. 25 až 45 (`R=$(curl -sS -o /dev/null -D "$TMP/root.h" -w ...)`, `pass`/`fail`, kontrola hlavičiek cez `has()`). Pridať kontroly: `Cache-Control: public, max-age=31536000, immutable` na jednom `/assets/vendor/*` súbore a `Content-Encoding: gzip` pri `Accept-Encoding: gzip`. Vzor:
```sh
hdr=$(tr -d '\r' < "$TMP/login.h")
has() { echo "$hdr" | grep -i "$1" >/dev/null; }
ok=1
has '^strict-transport-security:.*max-age=' || ok=0
if [ "$ok" = 1 ]; then pass "hlavičky $BASE/login"; else fail "hlavičky $BASE/login"; fi
```

---

### Dokumentácia `docs/ops/*`

- `docs/ops/backup.md`: zachovať sekcie „Spustenie“, „Čo záloha obsahuje“, „Overenie“, „Ručná obnova do dočasnej DB“ (r. 8 až 41); **prepísať** sekciu „Mimo rozsahu (Phase 12)“ (r. 43 až 45) na restic (repozitár, heslo mimo servera, `encryption.key`, forget 14/8/12, healthchecks, pull na PC, akceptovaný odklon D-05, mesačný restore test, ročný archív). Štýl: slovenčina, krátke odseky, code bloky s `sh`.
- `docs/ops/server.md`: §12 „Správa používateľa“ už dokumentuje `user:reset-2fa` (príkaz cez `ssh -t deploy@... 'cd /opt/work-tracker && IMAGE_TAG=$(cat .last-good-tag) docker compose -f compose.server.yaml exec php php spark user:reset-2fa <e-mail>'`); D-14 hovorí doplniť do runbooku, stačí odkaz a poznámka (v §12 to už je, overiť úplnosť). Nová sekcia „21. Nasadenie fázy 12“ (číslovanie pokračuje po §20) s cron riadkami (záloha, ročný archív, mesačný restore-test) v štýle existujúcich cron riadkov `cert-renew.sh` a `mail-backup.sh`.
- `docs/ops/performance.md` (nový): výsledky `docker stats` a p95 (D-12); súčasné namerané: nginx 4,4 MiB, php 21,5 MiB, db 31,2 MiB, spolu ~57 MiB (server.md §13).

---

## Shared Patterns

### Shell skripty (host/cron)
**Source:** `scripts/backup.sh` r. 1 až 20 a `scripts/mail-backup.sh` r. 7 až 28
**Apply to:** `backup-restic.sh`, `restore-test.sh`, `archive-yearly.sh`, `pull-backups.sh`, `measure-p95.sh`
```sh
set -eu
umask 077
... ${VAR:-default}
install -d -m 0700 "$BACKUP_DIR"
trap '...cleanup...' EXIT
echo "OK: $OUT"
```
ASCII komentáre v skriptoch (existujúce skripty nepoužívajú diakritiku v komentároch; správy v e-mailoch sú podľa UI-SPEC slovensky s diakritikou). Žiadne tajomstvo na `stdout`/`stderr`. Skripty sa testujú falošnými príkazmi na PATH (`BackupScriptTest`).

### Compose na serveri
**Source:** `docs/ops/server.md` §12 a RESEARCH Pitfall 1
**Apply to:** všetky skripty volajúce `docker compose` na serveri
`COMPOSE_FILE=compose.server.yaml`, `IMAGE_TAG=$(cat /opt/work-tracker/.last-good-tag)`; staging je samostatný stack `/opt/work-tracker-staging` s vlastným `BACKUP_DIR`.

### Tajomstvá
**Source:** CLAUDE.md, memory (server env), `server.md` §6, §15
**Apply to:** restic heslo, `HC_URL`, SMTP pre alert
Nový root súbor `/root/.config/work-tracker-backup.env` (0600), nikdy `app.env` (musí zostať `deploy:82`, bez `sed -i`), nikdy v gite.

### Flash + PRG v kontroleroch Settings
**Source:** `app/Controllers/Settings/Security.php` r. 54 až 56
**Apply to:** `Security::setLocale`
```php
session()->setFlashdata('app_notice', ['type' => 'info', 'message' => lang('Account.revoked')]);
return redirect()->to('/settings/security');
```

### Audit
**Source:** `app/Libraries/Auth/AuditLog.php` r. 29 (`record(AuthEvent $event, bool $success, ?int $userId, ?string $email = null, ?string $detail = null)`), `AuthEvent` enum
**Apply to:** `Security::setLocale`

### Lang v UI
**Source:** `ViewsHardcodedTextTest`, `LangKeysTest::testAllUsedKeysExistInSk` (regex `lang('Súbor.kľúč'`)
**Apply to:** všetky nové texty vo views a kontroleroch; kľúče iba cez `lang('File.key')` s literálom (regex testu), pridané vo všetkých 4 jazykoch.

### DB testy
**Source:** `tests/_support/PgTestCase.php` (`owner()`, `rw()`, `assertPermissionDenied`), fixtures traits v `tests/_support/`
**Apply to:** `ImmutableDocumentsTest`, `YearArchiveTest`

### Peniaze a bytea
**Source:** CLAUDE.md, `DocumentStore` docblok, `AccountantBundle`
**Apply to:** `YearArchive`
Sumy ako string z DB, nikdy float; PDF iba cez `DocumentStore`.

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `scripts/pull-backups.sh` | utility (WSL) | file-I/O (rsync cez SSH) | Žiadny existujúci skript neťahá dáta zo servera; použiť štýl `mail-backup.sh` a príkaz z RESEARCH r. 225 |
| `restic` časť `backup-restic.sh` (forget, tagy, check, healthchecks ping, SMTP alert cez curl) | utility | batch | Zatiaľ žiadne použitie restic, healthchecks ani odosielania e-mailu z hostiteľa; použiť RESEARCH r. 203 až 221 a overiť `restic <cmd> --help` na stagingu |
| helper `assertDbError` pre `app_owner` | test support | n/a | V `PgTestCase` neexistuje (RESEARCH ho predpokladá); pridať alebo lokálny try/catch |

## Metadata

**Analog search scope:** `app/Controllers/Settings`, `app/Views/settings`, `app/Config`, `app/Commands`, `app/Libraries/{Export,Invoicing,Auth,Platform,Reporting}`, `app/Language`, `app/Database/Migrations`, `scripts`, `docker/{nginx,php}`, `tests/{unit,feature,integration,_support}`, `docs/ops`
**Files scanned:** ~45
**Pattern extraction date:** 2026-11-04

**Zistenia, ktoré treba predať plánovačovi:**
1. UI voľby jazyka v kóde neexistuje (potvrdené: `settings.php` routes nemá locale trasu, `security.php` includuje iba password/twofactor/devices/events), ide o novú kartu + POST + test.
2. `AccountantBundle` vracia ZIP ako string a číta iba najvyššiu verziu cez `DocumentStore::read`; ročný archív potrebuje `readVersion` pre všetky verzie a výstup na disk.
3. `PgTestCase` nemá `assertDbError`; všetky tabuľky dokladov už majú `immutable_guard` + `truncate_guard` (`invoice_counters` má `counter_guard`), takže migrácia je pravdepodobne zbytočná.
4. `AuthEvent` treba rozšíriť o `LocaleChanged` (overiť, či `auth_events.event` nemá CHECK a či zobrazenie auditu potrebuje lang kľúč).
5. Parita test sa musí pridať spolu s prekladmi v tom istom pláne (inak je červený).
