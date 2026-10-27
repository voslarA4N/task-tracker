<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Invoicing\Samples\SampleSnapshots;

/**
 * Uplny snapshot faktury podla kontraktu schema 1 pre testy renderera a vystavenia.
 */
final class SnapshotSample
{
    /**
     * Snapshot schema 2 (inv-2), slovensky neplatitel.
     *
     * @param array<string, mixed> $overrides vrchnourovnove kluce sa nahradia
     *
     * @return array<string, mixed>
     */
    public static function issuedV2(array $overrides = []): array
    {
        return SampleSnapshots::invoice('sk', null, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides vrchnourovnove kluce sa nahradia
     *
     * @return array<string, mixed>
     */
    public static function issued(array $overrides = []): array
    {
        $keys   = ['invoice', 'number', 'variableSymbol', 'supplier', 'customer', 'issueDate', 'deliveryDate', 'dueDate', 'paymentMethod', 'paymentTransfer', 'bankAccount', 'swift', 'item', 'hours', 'rate', 'amount', 'vatCode', 'total', 'companyId', 'taxId', 'vatId', 'regon', 'identifier'];
        $labels = ['language' => 'sk'];

        foreach ($keys as $k) {
            $labels[$k] = lang('InvoicePdf.' . $k, [], 'sk');
        }

        $base = [
            'schema'           => 1,
            'origin'           => 'issued',
            'type'             => 'invoice',
            'template_version' => 'inv-1',
            'number'           => '202610001',
            'variable_symbol'  => '202610001',
            'billing_month'    => '2026-10',
            'issue_date'       => '2026-10-26',
            'delivery_date'    => '2026-10-26',
            'due_date'         => '2026-11-09',
            'currency'         => 'EUR',
            'doc_language'     => 'sk',
            'entity'           => [
                'id'            => 1, 'version_id' => 1, 'country' => 'SK', 'currency' => 'EUR',
                'trade_name'    => 'Tomáš Voslař – All4Net',
                'address_line1' => 'Hlavná 1', 'address_line2' => null, 'postal_code' => '01001', 'city' => 'Žilina',
                'company_id'    => '12345678', 'regon' => null, 'tax_id' => '1234567890', 'vat_id' => null,
                'registry_note' => 'Zapísaný v živnostenskom registri OÚ Žilina',
                'email'         => 'fakturacia@example.sk', 'phone' => null,
            ],
            'client' => [
                'id'              => 1, 'name' => 'All4Net s.r.o.',
                'address_line1'   => 'Dlhá 5', 'address_line2' => null, 'postal_code' => '81101', 'city' => 'Bratislava', 'country' => 'SK',
                'identifier_type' => 'ico', 'identifier_value' => '87654321', 'tax_id' => null, 'vat_id' => null,
                'billing_mode'    => 'monthly', 'portal_name' => 'Taskman', 'counts_toward_limit' => true,
            ],
            'legal' => [
                'vat_regime' => 'non_payer', 'billing_mode' => 'monthly', 'exemption_code' => null, 'vat_code' => 'np',
                'texts'      => ['vat_note' => ['body' => 'Nie som platiteľ DPH.', 'language' => 'sk', 'provisional' => true]],
            ],
            'bank'     => ['label' => 'Hlavný účet', 'iban' => 'SK3112000000198742637541', 'swift' => 'GIBASKBX', 'currency' => 'EUR'],
            'payment'  => ['method' => 'transfer', 'note' => null],
            'fx'       => null,
            'rounding' => ['line' => 'half_up_2', 'total' => 'sum_of_rounded_lines'],
            'labels'   => $labels,
            'lines'    => [
                [
                    'position'        => 1, 'source_allocation_id' => 1, 'source_task_id' => 1,
                    'title'           => 'Úloha – łńěťžô ČŠŽ', 'task_ref' => 'TM-1', 'portal_url' => 'https://taskman.example/1',
                    'estimated_hours' => '50.00', 'hours' => '40.00', 'rate' => '27.00', 'amount' => '1080.00', 'vat_code' => 'np',
                ],
                [
                    'position'        => 2, 'source_allocation_id' => 2, 'source_task_id' => 2,
                    'title'           => 'Druhá úloha', 'task_ref' => 'TM-2', 'portal_url' => 'https://taskman.example/2',
                    'estimated_hours' => null, 'hours' => '8.50', 'rate' => '27.00', 'amount' => '229.50', 'vat_code' => 'np',
                ],
            ],
            'totals' => ['total' => '1309.50', 'hours' => '48.50', 'line_count' => 2],
        ];

        return array_replace($base, $overrides);
    }
}
