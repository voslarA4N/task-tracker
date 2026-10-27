<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

final readonly class IssuedInvoice
{
    public function __construct(
        public int $id,
        public string $number,
        public string $total,
        public string $currency,
        public string $sha256,
    ) {
    }
}
