<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Fx\FxConverter;
use App\Libraries\Fx\FxFormat;
use App\Libraries\Fx\FxQuote;
use App\Libraries\Invoicing\Qr\QrPaymentBuilder;
use App\Libraries\Invoicing\Qr\QrPaymentFailed;
use App\Libraries\Master\BankAccountSelector;
use App\Libraries\Master\EntityVersionResolver;
use App\Libraries\Master\LegalProfileResolver;
use App\Libraries\Master\LegalTextMissing;
use App\Libraries\Master\NoBankAccount;
use App\Libraries\Master\VersionNotFound;
use App\Libraries\Platform\DbBool;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use LogicException;
use OverflowException;

/**
 * Zostavi kopiu udajov faktury (schema 3) rovnakym kodom pre nahlad aj vystavenie (D-18).
 * Nic nezapisuje a netaha tabulky faktur.
 */
final class SnapshotBuilder
{
    public const LABEL_KEYS    = ['invoice', 'number', 'variableSymbol', 'supplier', 'customer', 'issueDate', 'deliveryDate', 'dueDate', 'paymentMethod', 'paymentTransfer', 'bankAccount', 'swift', 'item', 'hours', 'rate', 'amount', 'vatCode', 'total', 'companyId', 'taxId', 'vatId', 'regon', 'identifier', 'paymentCash', 'paymentCard', 'paymentOther', 'paymentTitle', 'unitHours', 'vatNoteHeading', 'note', 'page', 'nip', 'bankName', 'qrPayBySquare', 'qrSpayd', 'qrZbp', 'qrCaption', 'fxRate', 'fxFrom', 'fxTable', 'fxTotalIn', 'fxManual', 'fxManualFrom'];
    public const VS_MAX_DIGITS = 10;

    private readonly EntityVersionResolver $versions;
    private readonly LegalProfileResolver $legal;
    private readonly BankAccountSelector $banks;
    private readonly QrPaymentBuilder $qr;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?EntityVersionResolver $versions = null, ?LegalProfileResolver $legal = null, ?BankAccountSelector $banks = null, ?QrPaymentBuilder $qr = null)
    {
        $db ??= Database::connect();
        $this->versions = $versions ?? new EntityVersionResolver($db);
        $this->legal    = $legal ?? new LegalProfileResolver($db);
        $this->banks    = $banks ?? new BankAccountSelector($db);
        $this->qr       = $qr ?? new QrPaymentBuilder();
    }

    /**
     * @param array<string, mixed>                                                                                                                                                                $client riadok clients
     * @param array<string, mixed>                                                                                                                                                                $entity riadok entities
     * @param list<array{allocation_id:int, task_id:int, title:string, task_ref:?string, portal_url:?string, estimated_hours:?string, hours:string, rate:string, amount:string, currency:string}> $parts
     *
     * @return array<string, mixed>
     *
     * @throws IssueRejected
     */
    public function build(array $client, array $entity, array $parts, string $month, string $issueDate, string $deliveryDate, string $dueDate, ?string $number, string $paymentMethod = 'transfer', ?string $paymentNote = null, ?FxQuote $fx = null): array
    {
        $entityId = (int) $entity['id'];

        try {
            $version = $this->versions->atDate($entityId, $issueDate);
        } catch (VersionNotFound) {
            throw new IssueRejected('version_not_found');
        }

        $docLanguage = ($client['doc_language'] ?? null) !== null ? (string) $client['doc_language'] : (string) $version['doc_language'];
        $country     = (string) $entity['country'];
        $regime      = (string) $version['vat_regime'];
        $mode        = (string) $client['billing_mode'];

        try {
            $profile = $this->legal->profile($country, $regime, $mode);

            if ($profile['vat_code'] === null) {
                throw new IssueRejected('vat_code_missing');
            }

            $text = $this->legal->text($country, $regime, $mode, $docLanguage, 'vat_note');
        } catch (LegalTextMissing) {
            throw new IssueRejected('legal_text_missing', ['language' => $docLanguage]);
        }

        $note = $this->legal->optionalText($country, $regime, $mode, $docLanguage, 'invoice_note');

        $calc = (new InvoiceCalculator())->compute($parts, $profile['vat_code']);

        try {
            $bank = $this->banks->forCurrency($entityId, $calc['currency']);
        } catch (NoBankAccount) {
            throw new IssueRejected('no_bank_account', ['currency' => $calc['currency']]);
        }

        if ($bank['currency_match'] !== true) {
            throw new IssueRejected('no_bank_account', ['currency' => $calc['currency']]);
        }

        $labels = ['language' => $docLanguage];

        foreach (self::LABEL_KEYS as $key) {
            $labels[$key] = lang('InvoicePdf.' . $key, [], $docLanguage);
        }

        $reportLabels = ReportRows::labels($docLanguage);
        $fxBlock      = $fx === null ? null : self::fxBlock($fx, $calc['total'], $calc['currency'], trim((string) $entity['currency']), $docLanguage, $labels);

        return [
            'schema'                  => 3,
            'origin'                  => 'issued',
            'type'                    => 'invoice',
            'template_version'        => 'inv-2',
            'report_template_version' => 'rep-1',
            'number'                  => $number,
            'variable_symbol'         => $number === null ? null : self::variableSymbol($number),
            'billing_month'           => $month,
            'issue_date'              => $issueDate,
            'delivery_date'           => $deliveryDate,
            'due_date'                => $dueDate,
            'currency'                => $calc['currency'],
            'doc_language'            => $docLanguage,
            'entity'                  => [
                'id'            => $entityId,
                'version_id'    => (int) $version['id'],
                'country'       => $country,
                'currency'      => trim((string) $entity['currency']),
                'trade_name'    => (string) $version['trade_name'],
                'address_line1' => (string) $version['address_line1'],
                'address_line2' => $this->nullable($version['address_line2']),
                'postal_code'   => (string) $version['postal_code'],
                'city'          => (string) $version['city'],
                'company_id'    => $this->nullable($version['company_id']),
                'regon'         => $this->nullable($version['regon']),
                'tax_id'        => $this->nullable($version['tax_id']),
                'vat_id'        => $this->nullable($version['vat_id']),
                'registry_note' => $this->nullable($version['registry_note']),
                'email'         => (string) $version['email'],
                'phone'         => $this->nullable($version['phone']),
            ],
            'client' => [
                'id'                  => (int) $client['id'],
                'name'                => (string) $client['name'],
                'address_line1'       => (string) $client['address_line1'],
                'address_line2'       => $this->nullable($client['address_line2']),
                'postal_code'         => (string) $client['postal_code'],
                'city'                => (string) $client['city'],
                'country'             => trim((string) $client['country']),
                'identifier_type'     => (string) $client['identifier_type'],
                'identifier_value'    => $this->nullable($client['identifier_value']),
                'tax_id'              => $this->nullable($client['tax_id']),
                'vat_id'              => $this->nullable($client['vat_id']),
                'billing_mode'        => $mode,
                'portal_name'         => $this->nullable($client['portal_name'] ?? null),
                'counts_toward_limit' => DbBool::of($client['counts_toward_limit']),
            ],
            'legal' => [
                'vat_regime'     => $regime,
                'billing_mode'   => $mode,
                'exemption_code' => $profile['exemption_code'],
                'vat_code'       => $profile['vat_code'],
                'print'          => LegalProfile::print($country, $regime, $mode),
                'texts'          => [
                    'vat_note'     => ['key' => 'vat_note'] + $text,
                    'invoice_note' => $note === null ? null : ['key' => 'invoice_note'] + $note,
                ],
            ],
            'bank' => [
                'label'    => (string) $bank['label'],
                'iban'     => (string) $bank['iban'],
                'swift'    => $this->nullable($bank['swift']),
                'currency' => $bank['currency'],
            ],
            'payment'  => ['method' => $paymentMethod, 'note' => $paymentNote, 'qr' => null],
            'fx'       => $fxBlock,
            'rounding' => ['line' => 'half_up_2', 'total' => 'sum_of_rounded_lines'] + ($fxBlock === null ? [] : ['fx' => 'invoice_total_half_up_2']),
            'labels'   => $labels + ['report' => $reportLabels],
            'report'   => $this->report($calc['lines'], $reportLabels, $docLanguage, $month),
            'lines'    => $calc['lines'],
            'totals'   => ['total' => $calc['total'], 'hours' => $calc['hours'], 'line_count' => $calc['line_count']],
        ];
    }

    /**
     * Blok fx kopie udajov (D-14): jadro kurzu, sucet v mene subjektu (D-10) a predformatovany text v jazyku dokladu (D-15).
     *
     * @param array<string, mixed> $labels
     *
     * @return array<string, mixed>
     *
     * @throws IssueRejected
     */
    public static function fxBlock(FxQuote $fx, string $total, string $invoiceCurrency, string $entityCurrency, string $lang, array $labels): array
    {
        if ($fx->invoiceCurrency !== $invoiceCurrency || $fx->entityCurrency !== $entityCurrency) {
            throw new LogicException('FxQuote currencies do not match the invoice.');
        }

        try {
            $converted = FxConverter::convert($total, $fx->rate, $fx->amount, $fx->direction);
        } catch (OverflowException) {
            throw new IssueRejected('fx_overflow', ['entity_currency' => $entityCurrency]);
        }

        $core       = $fx->core();
        $expression = FxFormat::expression($core, $lang);
        $date       = PdfFormat::date($fx->validFor, $lang);

        if ($fx->manual) {
            $rateLine = $labels['fxManual'] . ' ' . $expression;
            $dateLine = $labels['fxManualFrom'] . ' ' . $date;
        } else {
            $rateLine = $labels['fxRate'] . ' ' . FxFormat::sourceLabel($fx->source) . ' ' . $expression;
            $dateLine = $labels['fxFrom'] . ' ' . $date . ($fx->tableNo !== null && $fx->tableNo !== '' ? ', ' . $labels['fxTable'] . ' ' . $fx->tableNo : '');
        }

        return $core + [
            'total' => $converted,
            'text'  => [
                'rate_line'   => $rateLine,
                'date_line'   => $dateLine,
                'total_label' => $labels['fxTotalIn'] . ' ' . $entityCurrency,
                'total_value' => PdfFormat::amount($converted, $entityCurrency, $lang),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     *
     * @throws QrPaymentFailed
     */
    public function withNumber(array $snapshot, string $number): array
    {
        $snapshot['number']          = $number;
        $snapshot['variable_symbol'] = self::variableSymbol($number);
        $snapshot['payment']['qr']   = $this->qr->build($snapshot);

        return $snapshot;
    }

    /**
     * Variabilny symbol (SK/CZ): iba cislice, najviac 10. Z cisla dokladu sa vyberu cislice a ponecha
     * poslednych 10 (poradove cislo je na konci). Format cisla faktury sa nemeni. Bez cislic null.
     */
    public static function variableSymbol(string $number): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $number);

        if ($digits === '') {
            return null;
        }

        return strlen($digits) > self::VS_MAX_DIGITS ? substr($digits, -self::VS_MAX_DIGITS) : $digits;
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, string>      $reportLabels
     *
     * @return array<string, mixed>
     */
    private function report(array $lines, array $reportLabels, string $language, string $month): array
    {
        $built = ReportRows::build($lines, $reportLabels, $language);

        return ['period' => ReportRows::period($reportLabels, $month), 'rows' => $built['rows'], 'uniform_rate' => $built['uniform_rate']];
    }
}
