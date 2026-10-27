<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

final readonly class AllocatedNumber
{
    public function __construct(
        public string $number,
        public int $sequence,
        public string $periodKey,
    ) {
    }
}
