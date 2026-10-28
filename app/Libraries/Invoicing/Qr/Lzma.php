<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Qr;

/**
 * Raw LZMA1 kompresia pre PAY by square.
 */
interface Lzma
{
    /**
     * @throws QrPaymentFailed
     */
    public function compress(string $raw): string;
}
