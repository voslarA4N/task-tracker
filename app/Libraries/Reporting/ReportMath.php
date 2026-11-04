<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Platform\Money;
use RoundingMode;

/**
 * Aritmetika uhrad v prehladoch nad bcmath retazcami (bez float, CUR-04).
 */
final class ReportMath
{
    /**
     * Kumulativny podiel uhrady v mene subjektu: round(min(cum, total) * totalEntity / total, 2),
     * half away from zero. Zaokruhluje sa raz na kumulacii, takze plne uhradena faktura da presne
     * total_entity_ccy a rozdiel dvoch kumulacii je suma jednej uhrady. Nulovy total vrati 0.00.
     */
    public static function paidShare(string $cumAmount, string $invoiceTotal, string $invoiceTotalEntity): string
    {
        $cum   = Money::of($cumAmount)->toDecimalString();
        $total = Money::of($invoiceTotal)->toDecimalString();
        $tec   = Money::of($invoiceTotalEntity)->toDecimalString();

        if (bccomp($total, '0', 2) === 0) {
            return '0.00';
        }

        if (bccomp($cum, $total, 2) > 0) {
            $cum = $total;
        }

        $share = bcround(bcdiv(bcmul($cum, $tec, 12), $total, 12), 2, RoundingMode::HalfAwayFromZero);

        return Money::of($share)->toDecimalString();
    }
}
