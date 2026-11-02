<?php

use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Reporting\LimitMath;

/**
 * Predkontrola limitu obratu v nahlade (Phase 10, D-16): iba informacia, nikdy neblokuje vystavenie.
 *
 * @var array<string, mixed>|null $limitWarning
 * @var string                    $locale
 */
if ($limitWarning === null) {
    return;
}

$state = (string) $limitWarning['state'];
?>
<?php if ($state === 'failed') : ?>
    <div class="alert alert-secondary small" role="status"><?= esc(lang('Invoicing.limitFailed')) ?></div>
<?php elseif ($state === 'unavailable') : ?>
    <div class="alert alert-secondary small" role="status">
        <?= esc(lang('Invoicing.limitUnavailable', [lang($limitWarning['reason'] === 'currency' ? 'Invoicing.limitReasonCurrency' : 'Invoicing.limitReasonRate')])) ?>
        <a href="/settings/limits" target="_blank" rel="noopener"><?= esc(lang('Invoicing.limitLink')) ?></a>
    </div>
<?php elseif ($state === 'warning') : ?>
    <?php $crit = $limitWarning['level'] === 'crit'; ?>
    <div class="alert <?= $crit ? 'alert-danger' : 'alert-warning' ?>" role="alert">
        <p class="mb-2"><?= esc(lang($crit ? 'Invoicing.limitCritTitle' : 'Invoicing.limitWarnTitle')) ?></p>
        <?php foreach ($limitWarning['rows'] as $row) : ?>
            <?php $args = [(string) $row['name'], (string) $row['year'], LimitMath::formatPercent((string) $row['beforePercent'], $locale), LimitMath::formatPercent((string) $row['afterPercent'], $locale)]; ?>
            <p class="mb-0"><?= esc(lang($row['alreadyOver'] ? 'Invoicing.limitRowAlready' : 'Invoicing.limitRow', $args)) ?></p>
            <p class="small text-body-secondary mb-2"><?= esc(lang('Invoicing.limitRowSums', [
                MoneyFormat::format((string) $row['before'], (string) $row['currency'], $locale),
                MoneyFormat::format((string) $row['add'], (string) $row['currency'], $locale),
                MoneyFormat::format((string) $row['after'], (string) $row['currency'], $locale),
                MoneyFormat::format((string) $row['limit'], (string) $row['currency'], $locale),
            ])) ?></p>
        <?php endforeach ?>
        <?php if (! empty($limitWarning['fx'])) : ?>
            <p class="small text-body-secondary mb-1"><?= esc(lang('Invoicing.limitFxHint')) ?></p>
        <?php endif ?>
        <?php if (is_array($limitWarning['fallback'])) : ?>
            <p class="small text-body-secondary mb-1"><?= esc(lang('Invoicing.limitFallbackHint', [(string) $limitWarning['fallback']['refYear'], (string) $limitWarning['fallback']['usedYear']])) ?></p>
        <?php endif ?>
        <p class="small mb-0"><?= esc(lang('Invoicing.limitFooter')) ?>
            <a href="/settings/limits" target="_blank" rel="noopener"><?= esc(lang('Invoicing.limitLink')) ?></a></p>
    </div>
<?php endif ?>
