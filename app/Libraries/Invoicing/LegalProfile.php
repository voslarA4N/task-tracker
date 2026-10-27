<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use InvalidArgumentException;

/**
 * Pravidla tlacenych poli faktury podla krajiny subjektu, DPH rezimu a rezimu fakturacie klienta (D-07, D-11).
 * Cista trieda bez DB. Vystup sa kopiruje do snapshotu (`legal.print`) a sablona iteruje iba tieto zoznamy.
 *
 * Pravidla su predbezne: zavisia od potvrdenia uctovnicky (R1 a otvorene otazky 2, 3, 6 v 06-CONTEXT.md).
 */
final class LegalProfile
{
    private const META = ['issue_date', 'delivery_date', 'due_date', 'payment_method'];

    /**
     * @return array{supplier: list<string>, customer: list<string>, meta: list<string>, payment_reference: 'variable_symbol'|'invoice_number', line_unit: bool, line_vat_code: bool, vat_note: bool}
     */
    public static function print(string $country, string $vatRegime, string $clientBillingMode): array
    {
        $expected = match ($vatRegime) {
            'sk_non_vat'                        => 'SK',
            'cz_non_vat'                        => 'CZ',
            'pl_exempt_113_1', 'pl_exempt_113_9' => 'PL',
            default                             => throw new InvalidArgumentException('Unknown VAT regime: ' . $vatRegime),
        };

        if ($country !== $expected) {
            throw new InvalidArgumentException(sprintf('Country %s does not match VAT regime %s.', $country, $vatRegime));
        }

        $euB2b = $clientBillingMode === 'eu_b2b';

        if ($expected === 'PL') {
            $supplier = ['trade_name', 'address', 'tax_id'];
            $customer = ['name', 'address', 'tax_id'];

            if ($euB2b) {
                $supplier[] = 'vat_id';
                $customer[] = 'vat_id';
            }

            return [
                'supplier'          => $supplier,
                'customer'          => $customer,
                'meta'              => self::META,
                'payment_reference' => 'invoice_number',
                'line_unit'         => true,
                'line_vat_code'     => true,
                'vat_note'          => true,
            ];
        }

        $supplier = ['trade_name', 'address', 'company_id', 'tax_id'];
        $customer = ['name', 'address', 'identifier', 'tax_id'];

        if ($euB2b) {
            $supplier[] = 'vat_id';
            $customer[] = 'vat_id';
        }

        $supplier[] = 'registry_note';

        return [
            'supplier'          => $supplier,
            'customer'          => $customer,
            'meta'              => self::META,
            'payment_reference' => 'variable_symbol',
            'line_unit'         => false,
            'line_vat_code'     => false,
            'vat_note'          => true,
        ];
    }
}
