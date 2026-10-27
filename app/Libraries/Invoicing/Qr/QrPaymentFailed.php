<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Qr;

use RuntimeException;

/**
 * Vypocet QR kodu platby zlyhal (D-19): vystavenie sa ma zrusit.
 */
final class QrPaymentFailed extends RuntimeException
{
}
