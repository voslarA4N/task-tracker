<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

/**
 * Typ opravy dobropisu (D-10): pevny zoznam, ulozeny v invoices.correction_type a v snapshote.
 */
enum CorrectionType: string
{
    case DataError      = 'data_error';
    case AmountError    = 'amount_error';
    case FxError        = 'fx_error';
    case OrderCancelled = 'order_cancelled';
    case Other          = 'other';

    /**
     * Kluc popisku v InvoicePdf (ulozeny do snapshotu, D-18).
     */
    public function pdfLabelKey(): string
    {
        return match ($this) {
            self::DataError      => 'cnTypeDataError',
            self::AmountError    => 'cnTypeAmountError',
            self::FxError        => 'cnTypeFxError',
            self::OrderCancelled => 'cnTypeOrderCancelled',
            self::Other          => 'cnTypeOther',
        };
    }

    /**
     * Kluc UI textu (doplna plan 09-06).
     */
    public function uiLabelKey(): string
    {
        return match ($this) {
            self::DataError      => 'CreditNotes.typeDataError',
            self::AmountError    => 'CreditNotes.typeAmountError',
            self::FxError        => 'CreditNotes.typeFxError',
            self::OrderCancelled => 'CreditNotes.typeOrderCancelled',
            self::Other          => 'CreditNotes.typeOther',
        };
    }
}
