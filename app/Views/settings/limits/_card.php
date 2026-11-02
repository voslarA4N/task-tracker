<?php
/**
 * Karta cerpania jedneho limitu. Cisla prichadzaju hotove z TurnoverCalculator, view iba formatuje.
 *
 * @var \App\Libraries\Reporting\LimitStatus $status
 * @var bool                                  $strictest
 * @var string|null                           $activeFrom
 * @var string                                $locale
 * @var string                                $entityCurrency
 */

use App\Libraries\Invoicing\PdfFormat;
use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Reporting\LimitMath;

$methods = ['calendar_year' => 'Limits.methodCalendar', 'rolling_12m' => 'Limits.methodRolling', 'pro_rata_first_year' => 'Limits.methodProRata'];
$method  = lang($methods[$status->method] ?? 'Limits.methodCalendar');
$money   = static fn (string $amount): string => MoneyFormat::format($amount, $status->currency, $locale);
$isError = $status->error !== null;
$mod     = $status->level === 'crit' ? ' lim-card--crit' : ($status->level === 'warn' ? ' lim-card--warn' : '');
$pct     = LimitMath::formatPercent($status->percent, $locale);
$barVal  = max(0, min(100, (int) explode('.', $status->percent)[0]));
$hasOpen = $status->openingTurnover !== '0.00' || $status->openingYears !== [];
?>
<article class="lim-card app-card<?= $isError ? '' : $mod ?>">
    <?php if ($strictest) : ?>
        <p class="small mb-1"><span class="badge text-bg-light border"><?= esc(lang('Limits.badgeStrictest')) ?></span></p>
    <?php endif ?>
    <div class="d-flex justify-content-between align-items-start gap-2">
        <h3 class="h5 fw-semibold mb-0"><?= esc($status->name) ?></h3>
        <?php if ($isError) : ?>
            <span class="badge text-bg-danger"><?= esc(lang('Limits.badgeConfigError')) ?></span>
        <?php elseif ($status->level === 'crit') : ?>
            <span class="badge text-bg-danger"><?= esc(lang('Limits.badgeCrit')) ?></span>
        <?php elseif ($status->level === 'warn') : ?>
            <span class="badge text-bg-warning"><?= esc(lang('Limits.badgeWarn')) ?></span>
        <?php else : ?>
            <span class="badge text-bg-light border"><?= esc(lang('Limits.badgeNormal')) ?></span>
        <?php endif ?>
    </div>
    <p class="small text-body-secondary mt-1 mb-2">
        <?= esc(lang('Limits.cardMeta', [$status->country, (string) $status->limitYear, $method])) ?>
        <?php if ($status->isSeed) : ?>
            <span class="badge text-bg-light border lim-draft"><?= esc(lang('Limits.badgeDraft')) ?></span>
        <?php endif ?>
        <?php if ($status->fallbackFromYear !== null) : ?>
            <span class="badge text-bg-warning"><?= esc(lang('Limits.badgeFallbackYear', [(string) $status->limitYear])) ?></span>
        <?php endif ?>
    </p>
    <?php if ($status->fallbackFromYear !== null) : ?>
        <div class="alert alert-warning small" role="alert"><?= esc(lang('Limits.alertFallbackYear', [(string) $status->refYear, (string) $status->limitYear])) ?></div>
    <?php endif ?>
    <?php if ($isError) : ?>
        <div class="alert alert-danger small mb-0" role="alert"><?= esc(lang('Limits.alertCurrencyMismatch', [$status->currency, (string) ($entityCurrency ?? '')])) ?></div>
    <?php else : ?>
        <div class="d-flex align-items-center gap-2 mb-2">
            <progress class="lim-bar flex-grow-1" value="<?= $barVal ?>" max="100" aria-label="<?= esc(lang('Limits.barLabel', [$status->name]), 'attr') ?>"></progress>
            <span class="lim-pct"><?= esc($pct) ?> %</span>
        </div>
        <p class="mb-0"><?= esc(lang('Limits.usageOf', [$money($status->total), $money($status->limit)])) ?></p>
        <p class="small text-body-secondary mb-2">
            <?php if ($status->exceededBy !== '0.00') : ?>
                <?= esc(lang('Limits.exceededBy', [$money($status->exceededBy)])) ?>
            <?php else : ?>
                <?= esc(lang('Limits.remaining', [$money($status->remaining)])) ?>
            <?php endif ?>
        </p>
        <div class="lim-formula">
            <div><?= esc(lang('Limits.formulaPeriod', [PdfFormat::date($status->periodFrom, $locale), PdfFormat::date($status->periodTo, $locale), $method])) ?></div>
            <div><?= esc(lang('Limits.formulaDocuments', [$money($status->documentTurnover), (string) $status->invoiceCount, (string) $status->creditNoteCount])) ?></div>
            <?php if ($hasOpen) : ?>
                <div><?= esc(lang('Limits.formulaOpening', [$money($status->openingTurnover)])) ?></div>
                <?php if ($status->method === 'rolling_12m' && $status->openingYears !== []) : ?>
                    <div><?= esc(lang('Limits.hintOpeningRolling', [implode(', ', $status->openingYears)])) ?></div>
                <?php endif ?>
            <?php endif ?>
            <div><?= esc(lang('Limits.formulaTotal', [$money($status->documentTurnover), $money($status->openingTurnover), $money($status->total)])) ?></div>
            <?php if ($status->proRataDays !== null) : ?>
                <div><?= esc(lang('Limits.formulaLimitProRata', [$money($status->baseLimit), (string) $status->proRataDays, $money($status->limit)])) ?></div>
                <div><?= esc(lang('Limits.hintProRataDays', [PdfFormat::date((string) $activeFrom, $locale)])) ?></div>
            <?php else : ?>
                <div><?= esc(lang('Limits.formulaLimit', [$money($status->limit)])) ?></div>
                <?php if ($status->proRataOutsideFirstYear) : ?>
                    <div><?= esc(lang('Limits.hintProRataOutside')) ?></div>
                <?php endif ?>
            <?php endif ?>
            <div><?= esc(lang('Limits.formulaUsage', [$money($status->total), $money($status->limit), $pct])) ?></div>
        </div>
        <p class="small text-body-secondary mt-2 mb-0"><?= esc(lang('Limits.docsMeta', [(string) $status->invoiceCount, (string) $status->creditNoteCount])) ?></p>
    <?php endif ?>
</article>
