<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Samples;

use App\Libraries\Fx\FxQuote;
use App\Libraries\Fx\RateQuote;
use App\Libraries\Invoicing\LegalProfile;
use App\Libraries\Invoicing\Qr\QrPaymentBuilder;
use App\Libraries\Invoicing\ReportRows;
use App\Libraries\Invoicing\SnapshotBuilder;
use App\Libraries\Master\LegalProfileResolver;
use InvalidArgumentException;

/**
 * Fixturne snapshoty schema 3 pre kazdy pravny profil a jazyk (testy renderera, neskor spark prikaz).
 * Bez DB a bez zivych nastaveni.
 */
final class SampleSnapshots
{
    public const PROFILES   = ['sk', 'cz', 'pl', 'pl_eu_b2b', 'pl_non_eu'];
    private const VAT_NOTES = [
        'sk' => ['sk' => 'Nie som platiteľ DPH.', 'en' => 'Not a VAT payer.'],
        'cz' => ['cs' => 'Nejsem plátce DPH.', 'en' => 'Not a VAT payer.'],
        'pl' => [
            'pl' => 'Sprzedawca zwolniony podmiotowo z podatku VAT na podstawie art. 113 ust. 1 ustawy o VAT.',
            'en' => 'Seller exempt from VAT under Art. 113(1) of the Polish VAT Act.',
            'sk' => 'Predávajúci je oslobodený od DPH podľa čl. 113 ods. 1 poľského zákona o DPH.',
        ],
        'pl_eu_b2b' => ['pl' => 'Odwrotne obciążenie.', 'en' => 'Reverse charge.', 'sk' => 'Prenesenie daňovej povinnosti.'],
    ];

    /**
     * @param array<string, mixed>  $overrides vrchnourovnove kluce sa nahradia
     * @param ?LegalProfileResolver $texts     ak je zadany, legal.texts sa nacitaju z DB (len SELECT), inak seed znenia
     *
     * @return array<string, mixed>
     */
    public static function invoice(string $profile, ?string $language = null, array $overrides = [], ?LegalProfileResolver $texts = null): array
    {
        $cfg = match ($profile) {
            'sk'        => ['country' => 'SK', 'regime' => 'sk_non_vat', 'cur' => 'EUR', 'lang' => 'sk', 'mode' => 'domestic', 'client' => 'SK', 'iban' => 'SK3112000000198742637541', 'swift' => 'GIBASKBX', 'notes' => 'sk'],
            'cz'        => ['country' => 'CZ', 'regime' => 'cz_non_vat', 'cur' => 'CZK', 'lang' => 'cs', 'mode' => 'domestic', 'client' => 'CZ', 'iban' => 'CZ6508000000192000145399', 'swift' => 'GIBACZPX', 'notes' => 'cz'],
            'pl'        => ['country' => 'PL', 'regime' => 'pl_exempt_113_1', 'cur' => 'PLN', 'lang' => 'pl', 'mode' => 'domestic', 'client' => 'PL', 'iban' => 'PL61109010140000071219812874', 'swift' => 'WBKPPLPP', 'notes' => 'pl'],
            'pl_eu_b2b' => ['country' => 'PL', 'regime' => 'pl_exempt_113_1', 'cur' => 'PLN', 'lang' => 'sk', 'mode' => 'eu_b2b', 'client' => 'SK', 'iban' => 'PL61109010140000071219812874', 'swift' => 'WBKPPLPP', 'notes' => 'pl_eu_b2b'],
            'pl_non_eu' => ['country' => 'PL', 'regime' => 'pl_exempt_113_1', 'cur' => 'PLN', 'lang' => 'en', 'mode' => 'non_eu', 'client' => 'GB', 'iban' => 'PL61109010140000071219812874', 'swift' => 'WBKPPLPP', 'notes' => 'pl'],
            default     => throw new InvalidArgumentException('Unknown sample profile: ' . $profile),
        };

        $lang   = $language ?? $cfg['lang'];
        $labels = ['language' => $lang];

        foreach (SnapshotBuilder::LABEL_KEYS as $key) {
            $labels[$key] = lang('InvoicePdf.' . $key, [], $lang);
        }

        $reportLabels = ReportRows::labels($lang);
        $notes        = self::notes($cfg['notes']);
        $body         = $notes[$lang] ?? $notes['en'];
        $isPl         = $cfg['country'] === 'PL';
        $euB2b        = $cfg['mode'] === 'eu_b2b';

        $base = [
            'schema'                  => 3,
            'sample'                  => true,
            'origin'                  => 'issued',
            'type'                    => 'invoice',
            'template_version'        => 'inv-2',
            'report_template_version' => 'rep-1',
            'number'                  => '202610001',
            'variable_symbol'         => '202610001',
            'billing_month'           => '2026-10',
            'issue_date'              => '2026-10-26',
            'delivery_date'           => '2026-10-26',
            'due_date'                => '2026-11-09',
            'currency'                => $cfg['cur'],
            'doc_language'            => $lang,
            'entity'                  => [
                'id'            => 1, 'version_id' => 1, 'country' => $cfg['country'], 'currency' => $cfg['cur'],
                'trade_name'    => 'Tomáš Voslař – All4Net',
                'address_line1' => 'Hlavná 1', 'address_line2' => null, 'postal_code' => '01001', 'city' => 'Žilina',
                'company_id'    => '12345678', 'regon' => $isPl ? '123456789' : null, 'tax_id' => '1234567890',
                'vat_id'        => $euB2b ? 'PL1234567890' : null,
                'registry_note' => $isPl ? 'Wpis do CEIDG' : 'Zapísaný v živnostenskom registri OÚ Žilina',
                'email'         => 'fakturacia@example.sk', 'phone' => null,
            ],
            'client' => [
                'id'              => 1, 'name' => 'All4Net s.r.o.',
                'address_line1'   => 'Dlhá 5', 'address_line2' => null, 'postal_code' => '81101', 'city' => 'Bratislava', 'country' => $cfg['client'],
                'identifier_type' => 'ico', 'identifier_value' => '87654321', 'tax_id' => '2020123456',
                'vat_id'          => $euB2b ? 'SK2020123456' : null,
                'billing_mode'    => $cfg['mode'], 'portal_name' => 'Taskman', 'counts_toward_limit' => true,
            ],
            'legal' => [
                'vat_regime'     => $cfg['regime'], 'billing_mode' => $cfg['mode'],
                'exemption_code' => $isPl ? 'ART_113_1' : null, 'vat_code' => $isPl ? 'zw' : 'np',
                'print'          => LegalProfile::print($cfg['country'], $cfg['regime'], $cfg['mode']),
                'texts'          => $texts === null
                    ? [
                        'vat_note'     => ['key' => 'vat_note', 'body' => $body, 'language' => $lang, 'provisional' => true, 'revision' => 1, 'billing_mode' => $cfg['notes'] === 'pl_eu_b2b' ? 'eu_b2b' : null],
                        'invoice_note' => null,
                    ]
                    : self::dbTexts($texts, $cfg['country'], $cfg['regime'], $cfg['mode'], $lang),
            ],
            'bank'     => ['label' => 'Hlavný účet', 'iban' => $cfg['iban'], 'swift' => $cfg['swift'], 'currency' => $cfg['cur']],
            'payment'  => ['method' => 'transfer', 'note' => null, 'qr' => null],
            'fx'       => null,
            'rounding' => ['line' => 'half_up_2', 'total' => 'sum_of_rounded_lines'],
            'labels'   => $labels + ['report' => $reportLabels],
            'lines'    => [
                [
                    'position'        => 1, 'source_allocation_id' => 1, 'source_task_id' => 1,
                    'title'           => 'Zażółć gęślą jaźń – Ľščťžýáíé', 'task_ref' => 'TM-1', 'portal_url' => 'https://taskman.example/1',
                    'estimated_hours' => '50.00', 'hours' => '40.00', 'rate' => '27.00', 'amount' => '1080.00', 'vat_code' => $isPl ? 'zw' : 'np',
                ],
                [
                    'position'        => 2, 'source_allocation_id' => 2, 'source_task_id' => 2,
                    'title'           => 'ěřůďňť – druhá úloha', 'task_ref' => 'TM-2', 'portal_url' => 'https://taskman.example/2',
                    'estimated_hours' => null, 'hours' => '8.50', 'rate' => '27.00', 'amount' => '229.50', 'vat_code' => $isPl ? 'zw' : 'np',
                ],
            ],
            'totals' => ['total' => '1309.50', 'hours' => '48.50', 'line_count' => 2],
        ];

        $base['payment']['qr'] = (new QrPaymentBuilder())->build($base);
        $base['report']        = self::report($base['lines'], $reportLabels, $lang, $base['billing_month']);

        return array_replace($base, $overrides);
    }

    /**
     * PL subjekt (PLN) a faktura v EUR s blokom fx (NBP 4.4090 z 27. 10. 2026 alebo rucny kurz).
     *
     * @return array<string, mixed>
     */
    public static function fxInvoice(bool $manual = false, string $language = 'sk'): array
    {
        $base = self::invoice('pl', $language);
        $quote = $manual
            ? FxQuote::manual('4.3000', '2026-10-28', 'multiply', 'delivery_date', 'previous_business_day', '2026-10-26', 'PLN', 'EUR', null)
            : FxQuote::fromRate(new RateQuote('NBP', 'EUR', '4.4090', 1, '2026-10-27', '209/A/NBP/2026', 'multiply'), 'delivery_date', 'previous_business_day', '2026-10-26', 'PLN', 'EUR');

        $base['bank']['currency']    = 'EUR';
        $base['currency']            = 'EUR';
        $base['rounding']['fx']      = 'invoice_total_half_up_2';
        $base['fx']                  = SnapshotBuilder::fxBlock($quote, $base['totals']['total'], 'EUR', 'PLN', $language, $base['labels']);

        return $base;
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    private static function dbTexts(LegalProfileResolver $r, string $country, string $regime, string $mode, string $lang): array
    {
        $note = $r->optionalText($country, $regime, $mode, $lang, 'invoice_note');

        return [
            'vat_note'     => ['key' => 'vat_note'] + $r->text($country, $regime, $mode, $lang, 'vat_note'),
            'invoice_note' => $note === null ? null : ['key' => 'invoice_note'] + $note,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function notes(string $key): array
    {
        return self::VAT_NOTES[$key];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, string>      $reportLabels
     *
     * @return array<string, mixed>
     */
    public static function report(array $lines, array $reportLabels, string $language, string $month): array
    {
        $built = ReportRows::build($lines, $reportLabels, $language);

        return ['period' => ReportRows::period($reportLabels, $month), 'rows' => $built['rows'], 'uniform_rate' => $built['uniform_rate']];
    }
}
