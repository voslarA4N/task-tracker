<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Master\BankAccountSelector;
use App\Libraries\Master\EntityVersionResolver;
use App\Libraries\Master\LegalProfileResolver;
use App\Libraries\Master\LegalTextMissing;
use App\Libraries\Master\NoBankAccount;
use App\Libraries\Master\VersionNotFound;
use App\Libraries\Platform\DbBool;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Zostavi kopiu udajov faktury (schema 1) rovnakym kodom pre nahlad aj vystavenie (D-18).
 * Nic nezapisuje a netaha tabulky faktur.
 */
final class SnapshotBuilder
{
    public const LABEL_KEYS = ['invoice', 'number', 'variableSymbol', 'supplier', 'customer', 'issueDate', 'deliveryDate', 'dueDate', 'paymentMethod', 'paymentTransfer', 'bankAccount', 'swift', 'item', 'hours', 'rate', 'amount', 'vatCode', 'total', 'companyId', 'taxId', 'vatId', 'regon', 'identifier'];

    private readonly EntityVersionResolver $versions;
    private readonly LegalProfileResolver $legal;
    private readonly BankAccountSelector $banks;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?EntityVersionResolver $versions = null, ?LegalProfileResolver $legal = null, ?BankAccountSelector $banks = null)
    {
        $db ??= Database::connect();
        $this->versions = $versions ?? new EntityVersionResolver($db);
        $this->legal    = $legal ?? new LegalProfileResolver($db);
        $this->banks    = $banks ?? new BankAccountSelector($db);
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
    public function build(array $client, array $entity, array $parts, string $month, string $issueDate, string $deliveryDate, string $dueDate, ?string $number): array
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

        return [
            'schema'           => 1,
            'origin'           => 'issued',
            'type'             => 'invoice',
            'template_version' => 'inv-1',
            'number'           => $number,
            'variable_symbol'  => $number,
            'billing_month'    => $month,
            'issue_date'       => $issueDate,
            'delivery_date'    => $deliveryDate,
            'due_date'         => $dueDate,
            'currency'         => $calc['currency'],
            'doc_language'     => $docLanguage,
            'entity'           => [
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
                'texts'          => ['vat_note' => $text],
            ],
            'bank' => [
                'label'    => (string) $bank['label'],
                'iban'     => (string) $bank['iban'],
                'swift'    => $this->nullable($bank['swift']),
                'currency' => $bank['currency'],
            ],
            'payment'  => ['method' => 'transfer', 'note' => null],
            'fx'       => null,
            'rounding' => ['line' => 'half_up_2', 'total' => 'sum_of_rounded_lines'],
            'labels'   => $labels,
            'lines'    => $calc['lines'],
            'totals'   => ['total' => $calc['total'], 'hours' => $calc['hours'], 'line_count' => $calc['line_count']],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    public function withNumber(array $snapshot, string $number): array
    {
        $snapshot['number']          = $number;
        $snapshot['variable_symbol'] = $number;

        return $snapshot;
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
