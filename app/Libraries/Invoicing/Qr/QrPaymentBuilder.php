<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Qr;

use InvalidArgumentException;

/**
 * Vyber standardu QR platby podla krajiny klienta, meny a formy uhrady (D-18).
 */
final class QrPaymentBuilder
{
    private readonly PayBySquare $payBySquare;

    public function __construct(?PayBySquare $pbs = null)
    {
        $this->payBySquare = $pbs ?? new PayBySquare();
    }

    /**
     * Vyhodnotene vynimky enkodera sa nechytaju (D-19).
     *
     * @param array<string, mixed> $snapshot
     *
     * @return array{standard: string, payload: string}|null
     */
    public function build(array $snapshot): ?array
    {
        $number = $snapshot['number'] ?? null;

        if (! is_string($number) || $number === '') {
            throw new InvalidArgumentException('Snapshot has no invoice number.');
        }

        if ((string) ((array) $snapshot['payment'])['method'] !== 'transfer') {
            return null;
        }

        $country = (string) ((array) $snapshot['client'])['country'];

        if ($country === 'SK') {
            return ['standard' => 'pay_by_square', 'payload' => $this->payBySquare->encode($snapshot)];
        }

        return null;
    }
}
