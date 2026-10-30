<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use InvalidArgumentException;

/**
 * Kurz presne tak, ako ho zdroj publikuje (retazec, koncove nuly zachovane), so smerom a jednotkou (D-09).
 */
final readonly class RateQuote
{
    public function __construct(
        public string $source,
        public string $currency,
        public string $rate,
        public int $amount,
        public string $validFor,
        public ?string $tableNo,
        public string $direction,
    ) {
        if (! in_array($source, ['NBP', 'ECB', 'CNB'], true)) {
            throw new InvalidArgumentException('Invalid rate source.');
        }

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('Invalid currency.');
        }

        FxConverter::assertRate($rate, $amount, $direction);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $validFor) !== 1) {
            throw new InvalidArgumentException('Invalid validFor date.');
        }
    }
}
