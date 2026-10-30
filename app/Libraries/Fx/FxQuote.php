<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use InvalidArgumentException;

/**
 * Kurz + kontext pre snapshot `fx` (D-14). Ziadny cas stiahnutia: jadro musi byt deterministicke (D-08).
 */
final readonly class FxQuote
{
    /**
     * @param array{source: string, rate: string, amount: int, valid_for: string, table_no: ?string}|null $fetched
     */
    private function __construct(
        public string $source,
        public string $rate,
        public int $amount,
        public string $direction,
        public string $validFor,
        public ?string $tableNo,
        public string $dateBasis,
        public string $dayRule,
        public string $anchorDate,
        public string $entityCurrency,
        public string $invoiceCurrency,
        public bool $manual,
        public ?array $fetched,
        private ?RateQuote $original,
    ) {
    }

    public static function fromRate(
        RateQuote $q,
        string $dateBasis,
        string $dayRule,
        string $anchorDate,
        string $entityCurrency,
        string $invoiceCurrency,
    ): self {
        return new self(
            $q->source,
            $q->rate,
            $q->amount,
            $q->direction,
            $q->validFor,
            $q->tableNo,
            $dateBasis,
            $dayRule,
            $anchorDate,
            $entityCurrency,
            $invoiceCurrency,
            false,
            null,
            $q,
        );
    }

    public static function manual(
        string $rate,
        string $validFor,
        string $direction,
        string $dateBasis,
        string $dayRule,
        string $anchorDate,
        string $entityCurrency,
        string $invoiceCurrency,
        ?RateQuote $fetched,
    ): self {
        FxConverter::assertRate($rate, 1, $direction);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $validFor) !== 1) {
            throw new InvalidArgumentException('Invalid validFor date.');
        }

        return new self(
            'manual',
            $rate,
            1,
            $direction,
            $validFor,
            null,
            $dateBasis,
            $dayRule,
            $anchorDate,
            $entityCurrency,
            $invoiceCurrency,
            true,
            $fetched === null ? null : [
                'source'    => $fetched->source,
                'rate'      => $fetched->rate,
                'amount'    => $fetched->amount,
                'valid_for' => $fetched->validFor,
                'table_no'  => $fetched->tableNo,
            ],
            null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function core(): array
    {
        return [
            'source'           => $this->source,
            'manual'           => $this->manual,
            'rate'             => bcadd($this->rate, '0', 8),
            'rate_display'     => $this->rate,
            'amount'           => $this->amount,
            'direction'        => $this->direction,
            'valid_for'        => $this->validFor,
            'table_no'         => $this->tableNo,
            'date_basis'       => $this->dateBasis,
            'day_rule'         => $this->dayRule,
            'anchor_date'      => $this->anchorDate,
            'entity_currency'  => $this->entityCurrency,
            'invoice_currency' => $this->invoiceCurrency,
            'fetched'          => $this->fetched,
        ];
    }

    public function toRateQuote(): ?RateQuote
    {
        return $this->original;
    }
}
