<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Platform\Money;

/**
 * Snapshot dobropisu (D-05, D-06, D-11, D-18): zaporna kopia ulozeneho riadka faktury, jej riadkov a jej snapshotu.
 * Nikdy nectie zive tabulky subjektu, klienta, banky ani pravnych textov, DB ani hodiny. Nahlad aj vystavenie volaju
 * ten isty builder, preto preview token sedi.
 */
final class CreditNoteSnapshotBuilder
{
    public const SCHEMA           = 4;
    public const TEMPLATE_VERSION = DocumentTemplates::CREDIT_NOTE;
    public const LABEL_KEYS       = ['cnTitle', 'cnCorrects', 'cnOfDate', 'cnType', 'cnReason', 'cnScopeFull', 'cnTypeDataError', 'cnTypeAmountError', 'cnTypeFxError', 'cnTypeOrderCancelled', 'cnTypeOther'];

    /**
     * @param array<string, mixed>       $original      riadok invoices (snapshot ako JSON retazec alebo pole)
     * @param list<array<string, mixed>> $originalLines riadky invoice_lines povodnej faktury ORDER BY position
     *
     * @return array<string, mixed>
     *
     * @throws CreditNoteRejected
     */
    public function build(array $original, array $originalLines, string $issueDate, string $reason, CorrectionType $type, ?string $number): array
    {
        $snap = $original['snapshot'];
        $orig = is_string($snap) ? json_decode($snap, true, 512, JSON_THROW_ON_ERROR) : $snap;

        $docLanguage = trim((string) ($original['doc_language'] ?? $orig['doc_language']));

        $snapLines = [];

        foreach ($orig['lines'] ?? [] as $l) {
            $snapLines[(int) $l['position']] = $l;
        }

        if (count($snapLines) !== count($originalLines)) {
            throw new CreditNoteRejected('parts_mismatch', ['cause' => 'line_count']);
        }

        $lines    = [];
        $amounts  = [];
        $hoursSum = Money::zero();

        foreach ($originalLines as $o) {
            $position = (int) $o['position'];
            $s        = $snapLines[$position] ?? null;

            if ($s === null || ! Money::of((string) $s['amount'])->equals(Money::of((string) $o['amount']))) {
                throw new CreditNoteRejected('parts_mismatch', ['position' => $position]);
            }

            $hours  = Money::of((string) $o['hours'])->negate()->toDecimalString(); // hodiny maju 2 desatinne miesta ako Money, Hours zaporne nepodporuje
            $rate   = Money::of((string) $o['rate'])->toDecimalString();
            $amount = Money::of((string) $o['amount'])->negate();

            if (! Money::of($rate)->timesHours($hours)->equals($amount)) {
                throw new CreditNoteRejected('parts_mismatch', ['position' => $position, 'cause' => 'amount_formula']);
            }

            $hoursSum  = $hoursSum->plus(Money::of($hours));
            $amounts[] = $amount;
            $lines[]   = [
                'position'             => $position,
                'source_allocation_id' => null,
                'source_task_id'       => $o['source_task_id'],
                'title'                => $o['title'],
                'task_ref'             => $o['task_ref'],
                'portal_url'           => $o['portal_url'],
                'estimated_hours'      => $o['estimated_hours'],
                'hours'                => $hours,
                'rate'                 => $rate,
                'amount'               => $amount->toDecimalString(),
                'vat_code'             => $o['vat_code'],
            ];
        }

        $total = Money::sum(...$amounts);

        if (! $total->equals(Money::of((string) $original['total'])->negate())) {
            throw new CreditNoteRejected('sum_mismatch', ['total' => $total->toDecimalString()]);
        }

        $labels = ['language' => $docLanguage];

        foreach ([...SnapshotBuilder::LABEL_KEYS, ...self::LABEL_KEYS] as $key) {
            $labels[$key] = lang('InvoicePdf.' . $key, [], $docLanguage);
        }

        $fx = $orig['fx'] ?? null;

        if ($fx !== null) {
            $negated                   = Money::of((string) $fx['total'])->negate()->toDecimalString();
            $fx['total']               = $negated;
            $fx['text']['total_value'] = PdfFormat::amount($negated, (string) ($fx['entity_currency'] ?? $orig['entity']['currency']), $docLanguage);
        }

        return [
            'schema'           => self::SCHEMA,
            'origin'           => 'issued',
            'type'             => 'credit_note',
            'template_version' => self::TEMPLATE_VERSION,
            'number'           => $number,
            'variable_symbol'  => $number === null ? null : SnapshotBuilder::variableSymbol($number),
            'billing_month'    => $orig['billing_month'] ?? null,
            'issue_date'       => $issueDate,
            'delivery_date'    => $orig['delivery_date'],
            'due_date'         => $issueDate,
            'currency'         => $orig['currency'],
            'doc_language'     => $docLanguage,
            'entity'           => $orig['entity'],
            'client'           => $orig['client'],
            'legal'            => $orig['legal'],
            'bank'             => $orig['bank'],
            'payment'          => ['method' => $orig['payment']['method'] ?? 'transfer', 'note' => $orig['payment']['note'] ?? null, 'qr' => null],
            'fx'               => $fx,
            'rounding'         => $orig['rounding'] ?? null,
            'correction'       => [
                'original_id'         => (int) $original['id'],
                'original_number'     => (string) $original['number'],
                'original_issue_date' => (string) $orig['issue_date'],
                'reason'              => $reason,
                'type'                => $type->value,
                'scope'               => 'full',
            ],
            'labels' => $labels,
            'lines'  => $lines,
            'totals' => ['total' => $total->toDecimalString(), 'hours' => $hoursSum->toDecimalString(), 'line_count' => count($lines)],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function previewToken(array $snapshot): string
    {
        return InvoiceCalculator::previewToken($snapshot);
    }
}
