<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @internal
 */
#[Group('unit')]
final class LangKeysTest extends CIUnitTestCase
{
    /**
     * Hodnoty rovnake ako v sk, ktore su v danom jazyku spravne (endonyma, skratky, ID, rovnake slova).
     *
     * @var array<string, list<string>>
     */
    private const SAME_AS_SK_ALLOWED = [
        'pl' => [
            'Account.eventsColIp',
            'Account.languageSk',
            'Account.languagePl',
            'Account.languageCs',
            'Account.languageEn',
            'App.title',
            'AuditLog.filterFrom',
            'AuditLog.filterTo',
            'AuditLog.colField',
            'AuditLog.colAfter',
            'AuditLog.entity_client',
            'Clients.typeLocalId',
            'CreditNotes.labelClient',
            'Dashboard.clientLabel',
            'Dashboard.limitsRow',
            'Deliveries.labelFrom',
            'Deliveries.attachMeta',
            'Deliveries.badgeClient',
            'Deliveries.historyCc',
            'Deliveries.labelMessageId',
            'Deliveries.labelTransport',
            'EmailTemplates.var_iban',
            'Entities.companyIdSk',
            'Entities.companyIdPl',
            'Entities.regon',
            'Entities.taxId',
            'Entities.vatId',
            'Entities.registryHintPl',
            'Entities.email',
            'Entities.regimePlExempt1131',
            'Entities.regimePlExempt1139',
            'Entities.periodFrom',
            'Entities.bankIban',
            'Entities.bankSwift',
            'Entities.bankSwiftLine',
            'Export.csv',
            'Export.xlsx',
            'Export.colClient',
            'Export.sheetYear',
            'Export.filePrefixReport',
            'Export.filePrefixInvoices',
            'Export.filePrefixBundle',
            'InvoicePdf.swift',
            'InvoicePdf.regon',
            'InvoicePdf.paymentCard',
            'InvoicePdf.nip',
            'InvoicePdf.qrPayBySquare',
            'InvoicePdf.qrSpayd',
            'InvoicePdf.qrZbp',
            'Invoices.labelClient',
            'Invoices.labelSha',
            'Invoices.fxTable',
            'Invoices.colClient',
            'Invoices.filterClient',
            'Invoicing.labelClient',
            'Invoicing.paymentCard',
            'Invoicing.limitRowSums',
            'LegalTexts.profile',
            'Limits.usageOf',
            'Limits.formulaLimit',
            'Limits.formulaLimitProRata',
            'Limits.fieldYear',
            'Login.appName',
            'Login.email',
            'Notify.signature',
            'Pwa.appName',
            'Reports.colClient',
            'Reports.tabYear',
            'Tasks.client',
            'Tasks.colClient',
            'Tasks.filterClient',
            'TwoFactor.backupPlaceholder',
            'Users.colRole',
            'Users.colTwofa',
        ],
        'cs' => [
            'Account.passwordHeading',
            'Account.newPassword',
            'Account.eventsColTime',
            'Account.eventsColIp',
            'Account.event_password_reset_requested',
            'Account.event_password_reset_done',
            'Account.languageSk',
            'Account.languagePl',
            'Account.languageCs',
            'Account.languageEn',
            'Account.twofaInactive',
            'Allocations.fieldHours',
            'Allocations.hoursValue',
            'Allocations.doneMeta',
            'App.title',
            'App.error404Text',
            'AuditLog.filterEntity',
            'AuditLog.filterFrom',
            'AuditLog.filterTo',
            'AuditLog.colTime',
            'AuditLog.colEntity',
            'AuditLog.detail',
            'AuditLog.pageN',
            'AuditLog.changedCount',
            'AuditLog.userSystem',
            'AuditLog.detailTitle',
            'AuditLog.colField',
            'AuditLog.colAfter',
            'AuditLog.entity_client',
            'AuditLog.entity_entity',
            'AuditLog.entity_payment',
            'AuditLog.entity_payment_void',
            'AuditLog.entity_turnover_limit',
            'Bundle.entityLabel',
            'Bundle.previewCancelled',
            'Clients.navTitle',
            'Clients.listTitle',
            'Clients.newTitle',
            'Clients.groupEmails',
            'Clients.addressLine1',
            'Clients.postalCode',
            'Clients.taxId',
            'Clients.vatId',
            'Clients.typeLocalId',
            'Clients.modeDomestic',
            'Clients.perHour',
            'Clients.errorFormat',
            'Clients.filterArchived',
            'Clients.badgeArchived',
            'CreditNotes.labelType',
            'CreditNotes.typePlaceholder',
            'CreditNotes.typeFxError',
            'CreditNotes.cardTitle',
            'CreditNotes.labelNumber',
            'CreditNotes.labelClient',
            'CreditNotes.labelEntity',
            'CreditNotes.labelRate',
            'CreditNotes.linesTitle',
            'CreditNotes.lineMeta',
            'CreditNotes.errorType',
            'CreditNotes.scopeFull',
            'Currency.cardTitle',
            'Currency.rateLine',
            'Currency.metaFrom',
            'Currency.metaTable',
            'Currency.confirmRate',
            'Currency.confirmRateSource',
            'Currency.manualLabel',
            'Currency.manualRateLine',
            'Dashboard.clientLabel',
            'Dashboard.tileWip',
            'Dashboard.tileWipCurrency',
            'Dashboard.groupInProgress',
            'Dashboard.overdueTitle',
            'Dashboard.actionPayment',
            'Dashboard.limitsTitle',
            'Dashboard.limitsRow',
            'Deliveries.sendMeta',
            'Deliveries.labelTo',
            'Deliveries.labelFrom',
            'Deliveries.attachMeta',
            'Deliveries.historyTo',
            'Deliveries.badgeClient',
            'Deliveries.historyCc',
            'Deliveries.historyDetail',
            'Deliveries.labelMessageId',
            'Deliveries.labelTransport',
            'Deliveries.labelMarkNote',
            'Deliveries.historyNote',
            'Demo.title',
            'Demo.hours',
            'Demo.listHeading',
            'DocumentSettings.navTitle',
            'EmailTemplates.kindCreditNote',
            'EmailTemplates.lang_cs',
            'EmailTemplates.lang_en',
            'EmailTemplates.kind',
            'EmailTemplates.language',
            'EmailTemplates.sampleValues',
            'EmailTemplates.var_iban',
            'Entities.newTitle',
            'Entities.detailTitle',
            'Entities.groupIdentity',
            'Entities.addressLine1',
            'Entities.postalCode',
            'Entities.companyIdSk',
            'Entities.companyIdPl',
            'Entities.regon',
            'Entities.taxId',
            'Entities.vatId',
            'Entities.registryHintPl',
            'Entities.email',
            'Entities.validFrom',
            'Entities.countrySK',
            'Entities.countryCZ',
            'Entities.langCs',
            'Entities.langEn',
            'Entities.regimePlExempt1131',
            'Entities.regimePlExempt1139',
            'Entities.errorFormat',
            'Entities.validFromLine',
            'Entities.statusEnded',
            'Entities.navTitle',
            'Entities.listTitle',
            'Entities.periodFrom',
            'Entities.historyFrom',
            'Entities.badgeCurrent',
            'Entities.bankIban',
            'Entities.bankSwift',
            'Entities.bankSwiftLine',
            'Entities.badgeArchived',
            'Entities.seriesCreditNotes',
            'Entities.seriesFormat',
            'Entities.seriesResetYearly',
            'Entities.seriesTokensTitle',
            'Entities.legalCode',
            'Entities.senderEmailHint',
            'Export.csv',
            'Export.xlsx',
            'Export.colClient',
            'Export.sheetYear',
            'Export.sheetDocuments',
            'Export.colNumber',
            'Export.colType',
            'Export.colRate',
            'Export.colPaymentStatus',
            'Export.typeCreditNote',
            'Export.filePrefixReport',
            'Export.filePrefixInvoices',
            'Export.filePrefixBundle',
            'FxSettings.fieldSource',
            'FxSettings.countrySK',
            'FxSettings.countryCZ',
            'InvoicePdf.bankAccount',
            'InvoicePdf.swift',
            'InvoicePdf.item',
            'InvoicePdf.hours',
            'InvoicePdf.vatCode',
            'InvoicePdf.companyId',
            'InvoicePdf.taxId',
            'InvoicePdf.regon',
            'InvoicePdf.identifier',
            'InvoicePdf.paymentTitle',
            'InvoicePdf.unitHours',
            'InvoicePdf.vatNoteHeading',
            'InvoicePdf.note',
            'InvoicePdf.page',
            'InvoicePdf.nip',
            'InvoicePdf.bankName',
            'InvoicePdf.qrPayBySquare',
            'InvoicePdf.qrSpayd',
            'InvoicePdf.fxRate',
            'InvoicePdf.fxTable',
            'InvoicePdf.cnType',
            'InvoicePdf.cnTypeDataError',
            'InvoicePdf.cnTypeFxError',
            'Invoices.badgeIssued',
            'Invoices.labelEntity',
            'Invoices.labelClient',
            'Invoices.labelLegalText',
            'Invoices.labelSha',
            'Invoices.labelItems',
            'Invoices.labelDetails',
            'Invoices.lineMeta',
            'Invoices.reportPdf',
            'Invoices.statusIssued',
            'Invoices.filterStatus',
            'Invoices.filterOverdue',
            'Invoices.fxRate',
            'Invoices.fxSource',
            'Invoices.fxTable',
            'Invoices.statusCancelled',
            'Invoices.badgeCreditNote',
            'Invoices.statusCnIssued',
            'Invoices.creditNotePdf',
            'Invoices.labelCorrectionType',
            'Invoices.labelCorrectionScope',
            'Invoices.listCorrects',
            'Invoices.listCreditNote',
            'Invoices.filterCancelled',
            'Invoices.filterCreditNote',
            'Invoices.colNumber',
            'Invoices.colClient',
            'Invoices.colIssued',
            'Invoices.colStatus',
            'Invoices.filterEntity',
            'Invoices.filterClient',
            'Invoices.filterFrom',
            'Invoices.filterTo',
            'Invoices.versionsKindReport',
            'Invoices.versionsKindCreditNote',
            'Invoicing.labelClient',
            'Invoicing.labelEntity',
            'Invoicing.labelItems',
            'Invoicing.lineMeta',
            'Invoicing.lang_en',
            'Invoicing.lang_cs',
            'Invoicing.paymentHeading',
            'Invoicing.confirmPaymentNote',
            'Invoicing.limitRowSums',
            'LegalTexts.navTitle',
            'LegalTexts.title',
            'LegalTexts.vatCode',
            'LegalTexts.keyVatNote',
            'LegalTexts.language',
            'LegalTexts.modeLine',
            'LegalTexts.profile',
            'Limits.navTitle',
            'Limits.title',
            'Limits.usageOf',
            'Limits.formulaLimit',
            'Limits.formulaLimitProRata',
            'Limits.thresholdsList',
            'Limits.fieldYear',
            'Limits.errOpeningEntity',
            'Limits.fieldEntity',
            'Limits.fieldNote',
            'Login.appName',
            'Login.email',
            'Login.password',
            'Notify.subject_password_reset',
            'Notify.labelTime',
            'Notify.labelIp',
            'Notify.signature',
            'PasswordReset.title',
            'PasswordReset.newTitle',
            'PasswordReset.password',
            'PasswordReset.mailSubject',
            'Payments.labelNote',
            'Payments.cardTitle',
            'Pwa.appName',
            'ReportPdf.title',
            'ReportPdf.portal',
            'ReportPdf.hoursOf',
            'ReportPdf.hoursOnly',
            'ReportPdf.vatNoteHeading',
            'ReportPdf.page',
            'Reports.entityLabel',
            'Reports.summaryCounted',
            'Reports.colClient',
            'Reports.noEntityLink',
            'Reports.tabYear',
            'Tasks.client',
            'Tasks.note',
            'Tasks.status',
            'Tasks.stateInProgress',
            'Tasks.archivedBadge',
            'Tasks.perHourUnit',
            'Tasks.sectionData',
            'Tasks.previewLabel',
            'Tasks.noteLabel',
            'Tasks.colClient',
            'Tasks.colStatus',
            'Tasks.colHours',
            'Tasks.colAlloc',
            'Tasks.filterClient',
            'Tasks.filterState',
            'Tasks.hoursValue',
            'TwoFactor.backupPlaceholder',
            'Users.colTwofa',
            'Users.never',
            'Users.role_admin',
        ],
    ];

    public function testAllUsedKeysExistInSk(): void
    {
        $missing = [];
        $it      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match_all("/lang\\(\\s*'([A-Z][A-Za-z]+)\\.([A-Za-z0-9_.]+)'/", $src, $m, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($m as $hit) {
                $key = $hit[1] . '.' . $hit[2];
                if (lang($key, [], 'sk') === $key) {
                    $missing[] = $key . ' (' . $file->getPathname() . ')';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Chybajuce prekladove kluce v sk');
    }

    public function testSkAndEnHaveSameFilesAndKeys(): void
    {
        $sk = $this->load(APPPATH . 'Language/sk');
        $en = $this->load(APPPATH . 'Language/en');

        $this->assertSame(array_keys($sk), array_keys($en), 'Rozdielne subory sk/en');

        foreach ($sk as $name => $keys) {
            $this->assertSame($keys, $en[$name], "Rozdielne kluce v {$name}");
        }
    }

    public function testValidationMessagesAreTranslated(): void
    {
        $sk = lang('Validation.required', ['field' => 'X'], 'sk');
        $en = lang('Validation.required', ['field' => 'X'], 'en');

        $this->assertNotSame($en, $sk);
        $this->assertStringContainsString('X', $sk);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['pl' => ['pl'], 'cs' => ['cs'], 'en' => ['en']];
    }

    #[DataProvider('locales')]
    public function testLocaleFilesHaveSameKeysAsSk(string $locale): void
    {
        $sk     = $this->load(APPPATH . 'Language/sk');
        $actual = $this->load(APPPATH . 'Language/' . $locale);

        foreach ($actual as $name => $keys) {
            $this->assertArrayHasKey($name, $sk, "Subor {$name} v {$locale} neexistuje v sk");
            $this->assertSame($sk[$name], $keys, "Rozdielne kluce v {$locale}/{$name}");
        }
    }

    #[DataProvider('locales')]
    public function testPlaceholdersMatchSk(string $locale): void
    {
        $sk     = $this->values(APPPATH . 'Language/sk');
        $actual = $this->values(APPPATH . 'Language/' . $locale);

        foreach ($actual as $name => $pairs) {
            foreach ($pairs as $key => $value) {
                $this->assertSame(
                    $this->placeholders($sk[$name][$key] ?? ''),
                    $this->placeholders($value),
                    "Zastupne symboly {$locale}/{$name}:{$key}",
                );
            }
        }
    }

    #[DataProvider('locales')]
    public function testNoEmptyValues(string $locale): void
    {
        $empty = [];

        foreach (['sk', $locale] as $dir) {
            foreach ($this->values(APPPATH . 'Language/' . $dir) as $name => $pairs) {
                foreach ($pairs as $key => $value) {
                    if (trim($value) === '') {
                        $empty[] = "{$dir}/{$name}:{$key}";
                    }
                }
            }
        }

        $this->assertSame([], $empty, 'Prazdne hodnoty');
    }

    public function testSupportedLocalesHaveLanguageDirectories(): void
    {
        $this->assertSame(['sk', 'pl', 'cs', 'en'], config('App')->supportedLocales);

        foreach (config('App')->supportedLocales as $locale) {
            $this->assertDirectoryExists(APPPATH . 'Language/' . $locale);
        }
    }

    #[DataProvider('locales')]
    public function testEveryLocaleHasAllSkFiles(string $locale): void
    {
        $names = static fn (string $l): array => array_map('basename', glob(APPPATH . 'Language/' . $l . '/*.php') ?: []);
        $sk    = $names('sk');
        $act   = $names($locale);
        sort($sk);
        sort($act);

        $this->assertCount(32, $sk);
        $this->assertSame($sk, $act, "Subory {$locale} sa lisia od sk");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function plCs(): array
    {
        return ['pl' => ['pl'], 'cs' => ['cs']];
    }

    #[DataProvider('plCs')]
    public function testTranslationsAreNotCopiesOfSk(string $locale): void
    {
        $sk      = $this->values(APPPATH . 'Language/sk');
        $actual  = $this->values(APPPATH . 'Language/' . $locale);
        $allowed = self::SAME_AS_SK_ALLOWED[$locale];
        $copies  = [];

        foreach ($actual as $name => $pairs) {
            foreach ($pairs as $key => $value) {
                $id = basename($name, '.php') . '.' . $key;
                if ($value === ($sk[$name][$key] ?? null) && preg_match('/\pL/u', $value) === 1 && ! in_array($id, $allowed, true)) {
                    $copies[] = $id;
                }
            }
        }

        $this->assertSame([], $copies, "Neprelozene kopie sk v {$locale}");
    }

    #[DataProvider('plCs')]
    public function testAllowListHasNoStaleEntries(string $locale): void
    {
        $sk     = $this->values(APPPATH . 'Language/sk');
        $actual = $this->values(APPPATH . 'Language/' . $locale);
        $stale  = [];

        foreach (self::SAME_AS_SK_ALLOWED[$locale] as $id) {
            [$file, $key] = explode('.', $id, 2);
            $name         = $file . '.php';
            if (! isset($actual[$name][$key]) || $actual[$name][$key] !== ($sk[$name][$key] ?? null)) {
                $stale[] = $id;
            }
        }

        $this->assertSame([], $stale, "Zastarane vynimky v {$locale}");
    }

    #[DataProvider('plCs')]
    public function testValidationMessagesAreLocalized(string $locale): void
    {
        $msg = lang('Validation.required', ['field' => 'X'], $locale);

        $this->assertNotSame(lang('Validation.required', ['field' => 'X'], 'en'), $msg);
        $this->assertStringContainsString('X', $msg);
    }

    #[DataProvider('plCs')]
    public function testLongTranslationsReport(string $locale): void
    {
        $sk     = $this->values(APPPATH . 'Language/sk');
        $actual = $this->values(APPPATH . 'Language/' . $locale);
        $long   = [];

        foreach ($actual as $name => $pairs) {
            foreach ($pairs as $key => $value) {
                $len = mb_strlen($value);
                if ($len > 20 && $len > 1.4 * mb_strlen($sk[$name][$key] ?? '')) {
                    $long[] = basename($name, '.php') . '.' . $key . " ({$len})";
                }
            }
        }

        if ($long !== []) {
            fwrite(STDERR, "\n[i18n {$locale}] dlhsie ako 140 % sk: " . implode(', ', $long) . "\n");
        }

        $this->assertTrue(true);
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        // ICU plural vetvy (one{...}, few{...}) sa medzi jazykmi lisia, porovnavaju sa iba nazvy argumentov.
        preg_match_all('/\{\s*(\d+|[A-Za-z_]\w*)\s*[,}]/', $value, $m);
        $found = $m[1];
        sort($found);

        return $found;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function values(string $dir): array
    {
        $out   = [];
        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        foreach ($files as $f) {
            $out[basename($f)] = $this->flattenValues(require $f);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $a
     *
     * @return array<string, string>
     */
    private function flattenValues(array $a, string $prefix = ''): array
    {
        $out = [];

        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $out += $this->flattenValues($v, $prefix . $k . '.');
            } else {
                $out[$prefix . $k] = (string) $v;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function load(string $dir): array
    {
        $out   = [];
        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        foreach ($files as $f) {
            $out[basename($f)] = $this->flatten(require $f);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $a
     *
     * @return list<string>
     */
    private function flatten(array $a, string $prefix = ''): array
    {
        $keys = [];

        foreach ($a as $k => $v) {
            $full = $prefix . $k;
            if (is_array($v)) {
                array_push($keys, ...$this->flatten($v, $full . '.'));
            } else {
                $keys[] = $full;
            }
        }
        sort($keys);

        return $keys;
    }
}
