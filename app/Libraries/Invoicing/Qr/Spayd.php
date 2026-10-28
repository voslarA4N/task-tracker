<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing\Qr;

/**
 * SPAYD 1.0 (QR Platba, CZ). Sumy sa nikdy nepocitaju cez float, len retazcovo.
 */
final class Spayd
{
    /**
     * @param array<string, mixed> $snapshot
     *
     * @throws QrPaymentFailed
     */
    public function encode(array $snapshot): string
    {
        $bank   = (array) $snapshot['bank'];
        $labels = (array) $snapshot['labels'];
        $totals = (array) $snapshot['totals'];
        $entity = (array) $snapshot['entity'];
        $vs     = $snapshot['variable_symbol'] ?? null;
        $iban   = str_replace(' ', '', (string) $bank['iban']);
        $bic    = trim((string) ($bank['swift'] ?? ''));
        $acc    = $iban . ($bic !== '' ? '+' . $bic : '');
        $amount = (string) $totals['total'];

        if (preg_match('/^\d+\.\d{2}$/', $amount) !== 1 || strlen($amount) > 10) {
            throw new QrPaymentFailed('SPAYD amount invalid or longer than 10 characters.', QrPaymentFailed::AMOUNT_INVALID);
        }

        if (strlen($acc) > 46) {
            throw new QrPaymentFailed('SPAYD ACC longer than 46 characters.', QrPaymentFailed::IBAN_INVALID);
        }

        $msg = mb_strtoupper(PayBySquare::ascii(trim((string) ($labels['invoice'] ?? '') . ' ' . (string) $snapshot['number'])));
        $rn  = mb_strtoupper(PayBySquare::ascii((string) $entity['trade_name']));

        $fields = [
            'ACC'  => $acc,
            'AM'   => $amount,
            'CC'   => (string) $snapshot['currency'],
            'DT'   => str_replace('-', '', (string) $snapshot['due_date']),
            'X-VS' => is_string($vs) ? $vs : '',
            'MSG'  => substr($msg, 0, 60),
            'RN'   => substr($rn, 0, 35),
        ];

        $out = 'SPD*1.0*';

        foreach ($fields as $key => $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $out .= $key . ':' . str_replace('*', '%2A', $value) . '*';
        }

        return $out;
    }
}
