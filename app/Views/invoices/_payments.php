<?php

use App\Libraries\Platform\MoneyFormat;

/**
 * Karta Uhrady na detaile faktury (PAY-01, PAY-03, D-28).
 *
 * @var array<string, mixed>       $invoice
 * @var array<string, mixed>       $payState
 * @var list<array<string, mixed>> $payments
 * @var string                     $locale
 * @var array<string, string>      $dates
 */
$currency = (string) $invoice['currency'];
$dateFmt  = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'Europe/Bratislava');
$stampFmt = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, 'Europe/Bratislava');
$day      = static function (mixed $v) use ($dateFmt): string {
    $d = is_string($v) && $v !== '' ? service('clock')->parseDate(substr($v, 0, 10)) : null;

    return $d === null ? '' : (string) $dateFmt->format($d);
};
$stamp = static function (mixed $v) use ($stampFmt): string {
    if (! is_string($v) || $v === '') {
        return '';
    }

    return (string) $stampFmt->format(service('clock')->now()->modify($v));
};
?>
<section class="app-card mb-4" aria-labelledby="inv-pay-title">
    <h2 class="h5 mb-2" id="inv-pay-title"><?= esc(lang('Payments.cardTitle')) ?></h2>
    <div class="pay-balance">
        <div class="pay-balance-total"><?= esc(lang('Payments.balance', [MoneyFormat::format((string) $payState['balance'], $currency)])) ?></div>
        <div class="small text-body-secondary"><?= esc(lang('Payments.cardMeta', [MoneyFormat::format((string) $payState['total'], $currency), MoneyFormat::format((string) $payState['paid_amount'], $currency), $dates['due_date']])) ?></div>
    </div>
    <?php if ($payments === []) : ?>
        <p class="fw-semibold mb-1"><?= esc(lang('Payments.emptyTitle')) ?></p>
        <p class="small text-body-secondary mb-0"><?= esc(lang('Payments.emptyBody')) ?></p>
    <?php else : ?>
        <ul class="pay-list">
            <?php foreach ($payments as $p) : ?>
                <li<?= $p['voided'] ? ' class="pay-void"' : '' ?>>
                    <div class="d-flex justify-content-between gap-2">
                        <div class="fw-semibold"><span class="pay-amount tabular-nums"><?= esc(MoneyFormat::format((string) $p['amount'], $currency)) ?></span> · <span class="pay-date"><?= esc($day($p['paid_on'])) ?></span></div>
                        <?php if (! $p['voided']) : ?>
                            <a class="btn btn-link btn-touch p-0" href="/payments/<?= (int) $p['id'] ?>/void" hx-get="/payments/<?= (int) $p['id'] ?>/void" hx-target="#sheet" hx-swap="innerHTML"><?= esc(lang('Payments.voidLink')) ?></a>
                        <?php endif ?>
                    </div>
                    <div class="small text-body-secondary"><?= esc(lang('Payments.recordedAt', [$stamp($p['created_at'])])) ?><?= $p['note'] !== null ? ' · ' . esc((string) $p['note']) : '' ?></div>
                    <?php if ($p['voided']) : ?>
                        <div class="small text-body-secondary"><?= esc(lang('Payments.voidedAt', [$day($p['voided_at']), (string) $p['void_reason']])) ?></div>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>
