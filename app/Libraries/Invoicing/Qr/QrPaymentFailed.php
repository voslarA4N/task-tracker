<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Qr;

use RuntimeException;

/**
 * Vypocet QR kodu platby zlyhal (D-19): vystavenie sa ma zrusit.
 *
 * $reason je kod priciny (jeden z REASONS), aby nahlad aj chybova sprava pri vystaveni
 * ukazali konkretne pole na opravu (WR-03).
 */
final class QrPaymentFailed extends RuntimeException
{
    public const NIP_INVALID        = 'nip_invalid';
    public const IBAN_INVALID       = 'iban_invalid';
    public const AMOUNT_INVALID     = 'amount_invalid';
    public const TITLE_TOO_LONG     = 'title_too_long';
    public const PAYLOAD_TOO_LONG   = 'payload_too_long';
    public const COMPRESSION_FAILED = 'compression_failed';
    public const ENCODE_FAILED      = 'encode_failed';
    public const REASONS            = [
        self::NIP_INVALID,
        self::IBAN_INVALID,
        self::AMOUNT_INVALID,
        self::TITLE_TOO_LONG,
        self::PAYLOAD_TOO_LONG,
        self::COMPRESSION_FAILED,
        self::ENCODE_FAILED,
    ];

    public readonly string $reason;

    public function __construct(string $message, string $reason = self::ENCODE_FAILED)
    {
        parent::__construct($message);
        $this->reason = in_array($reason, self::REASONS, true) ? $reason : self::ENCODE_FAILED;
    }
}
