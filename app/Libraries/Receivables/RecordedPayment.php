<?php

declare(strict_types=1);

namespace App\Libraries\Receivables;

/**
 * Vysledok zapisu uhrady. $replay je true, ak sa pod rovnakym request_key zapis uz predtym uskutocnil.
 */
final readonly class RecordedPayment
{
    public function __construct(
        public int $id,
        public string $amount,
        public string $balanceAfter,
        public string $statusAfter,
        public bool $replay,
    ) {
    }
}
