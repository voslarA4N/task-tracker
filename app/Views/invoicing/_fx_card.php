<?php

use App\Controllers\Invoicing;
use App\Libraries\Fx\FxFormat;
use App\Libraries\Invoicing\Draft;
use App\Libraries\Platform\MoneyFormat;

/**
 * Blok kurzu v nahlade (Phase 8, D-07): kresli sa iba pri cudzej mene. Kurz sa stahuje v requeste nahladu, nie tu.
 *
 * @var Draft  $draft
 * @var string $locale
 */
$fx = $draft->fx;

if ($fx === null) {
    return;
}

$entityCcy  = $fx['entity_currency'];
$source     = FxFormat::sourceLabel($fx['source']);
$basis      = lang($fx['date_basis'] === 'issue_date' ? 'Currency.basisIssue' : 'Currency.basisDelivery');
$rule       = lang($fx['day_rule'] === 'on_or_before' ? 'Currency.ruleOnOrBefore' : 'Currency.rulePrevious');
$quote      = $fx['quote'];
?>
<section class="app-card fx-card mb-3" role="region" aria-labelledby="inv-fx-title">
    <h2 class="h5" id="inv-fx-title"><?= esc(lang('Currency.cardTitle')) ?></h2>
    <span class="small text-body-secondary htmx-indicator" id="inv-fx-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <?= esc(lang('Currency.loading')) ?></span>
<?php if ($fx['state'] === 'ok' && is_array($quote)) : ?>
    <?php
    $meta = [lang('Currency.metaFrom', [Invoicing::dateLabel((string) $quote['valid_for'])])];

    if (($quote['table_no'] ?? null) !== null && $quote['table_no'] !== '') {
        $meta[] = lang('Currency.metaTable', [(string) $quote['table_no']]);
    }

    $meta[] = $basis . ', ' . $rule;
    $calc   = lang($fx['direction'] === 'divide' ? 'Currency.calcDivide' : 'Currency.calcMultiply', [MoneyFormat::format($draft->total, (string) $draft->currency)]);
    ?>
    <div class="fx-rate" aria-live="polite"><?= esc(lang('Currency.rateLine', [$source, FxFormat::expression($quote, $locale)])) ?></div>
    <div class="fx-meta"><?= esc(implode(' · ', $meta)) ?></div>
    <div class="fx-total-wrap" aria-live="polite">
        <div class="fx-meta"><?= esc(lang('Currency.totalIn', [$entityCcy])) ?></div>
        <div class="fx-total"><?= esc(MoneyFormat::format((string) $quote['total'], $entityCcy)) ?></div>
        <div class="fx-meta"><?= esc($calc) ?></div>
    </div>
<?php elseif ($fx['state'] === 'unavailable' || $fx['state'] === 'unsupported') : ?>
    <div class="alert alert-warning mb-2" role="alert">
        <p class="h5 mb-1"><?= esc(lang('Currency.unavailableTitle')) ?></p>
    <?php if ($fx['state'] === 'unsupported') : ?>
        <p class="small mb-0"><?= esc(lang('Currency.unsupportedBody', [$source, $entityCcy, $fx['invoice_currency']])) ?></p>
    <?php else : ?>
        <p class="small mb-0"><?= esc(lang('Currency.unavailableBody', [$source])) ?></p>
    <?php endif ?>
    </div>
<?php endif ?>
    <div class="fx-sub-link"><a class="d-inline-flex align-items-center py-2 small" href="/settings/fx"><?= esc(lang('Currency.settingsLink')) ?></a></div>
</section>
