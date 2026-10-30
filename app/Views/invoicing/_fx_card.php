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
$state      = $fx['state'];
$fields     = $fx['fields'];
$fieldErrs  = $fx['errors'];
$fetched    = $fx['fetched'];
$today      = service('clock')->today();
$minDate    = service('clock')->parseDate($today)?->modify('-1 year')->format('Y-m-d') ?? $today;
$bound      = (string) $fx['bound'];
$dateValue  = $fields['fx_date'] !== '' ? $fields['fx_date'] : ($bound > $today ? $today : $bound);
$parts      = FxFormat::parts(['direction' => $fx['direction'], 'entity_currency' => $entityCcy, 'invoice_currency' => $fx['invoice_currency'], 'amount' => 1]);
$placeholder = is_array($fetched) ? FxFormat::rate((string) ($fetched['rate_display'] ?? $fetched['rate']), $locale) : lang('Currency.manualPlaceholder');
$errText    = static fn (string $code): string => match ($code) {
    'decimals'    => lang('Currency.errDecimals'),
    'date_invalid' => lang('Currency.errDate'),
    'date_future' => lang('Currency.errDateFuture'),
    'date_old'    => lang('Currency.errDateOld'),
    default       => lang('Currency.errRate'),
};
$include    = '#inv-client, #inv-month, #inv-entity, #inv-issue-date, #inv-delivery-date, #inv-due-date, #inv-payment-method, #inv-payment-note, #inv-payment-note-dirty';
$getAttrs   = 'hx-get="/fragments/invoice-preview" hx-target="#inv-preview" hx-swap="outerHTML" hx-push-url="false" hx-disabled-elt="this"';
$manualOpen = in_array($state, ['manual', 'invalid', 'unavailable', 'unsupported'], true);
?>
<section class="app-card fx-card mb-3" role="region" aria-labelledby="inv-fx-title">
    <h2 class="h5" id="inv-fx-title"><?= esc(lang('Currency.cardTitle')) ?></h2>
    <span class="small text-body-secondary htmx-indicator" id="inv-fx-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <?= esc(lang('Currency.loading')) ?></span>
<?php if ($fx['anchor_changed']) : ?>
    <div class="alert alert-secondary small py-2" role="status"><?= esc(lang('Currency.anchorChanged')) ?></div>
<?php endif ?>
<?php if (in_array($state, ['ok', 'manual'], true) && is_array($quote)) : ?>
    <?php
    $meta = [lang('Currency.metaFrom', [Invoicing::dateLabel((string) $quote['valid_for'])])];

    if (($quote['table_no'] ?? null) !== null && $quote['table_no'] !== '') {
        $meta[] = lang('Currency.metaTable', [(string) $quote['table_no']]);
    }

    $meta[] = $basis . ', ' . $rule;

    if ($state === 'manual') {
        $meta = [lang('Currency.manualMeta', [Invoicing::dateLabel((string) $quote['valid_for'])])];
    }

    $calc   = lang($fx['direction'] === 'divide' ? 'Currency.calcDivide' : 'Currency.calcMultiply', [MoneyFormat::format($draft->total, (string) $draft->currency)]);
    ?>
    <div class="fx-rate" aria-live="polite">
    <?php if ($state === 'manual') : ?>
        <?= esc(lang('Currency.manualRateLine', [FxFormat::expression($quote, $locale)])) ?>
        <span class="fx-badge badge text-bg-warning"><?= esc(lang('Currency.badgeManual')) ?></span>
    <?php else : ?>
        <?= esc(lang('Currency.rateLine', [$source, FxFormat::expression($quote, $locale)])) ?>
    <?php endif ?>
    </div>
    <div class="fx-meta"><?= esc(implode(' · ', $meta)) ?></div>
    <?php if ($state === 'manual' && is_array($quote['fetched'] ?? null)) : ?>
        <div class="fx-meta"><?= esc(lang('Currency.fetchedInfo', [FxFormat::sourceLabel((string) $quote['fetched']['source']), FxFormat::rate((string) $quote['fetched']['rate'], $locale), Invoicing::dateLabel((string) $quote['fetched']['valid_for'])])) ?></div>
    <?php endif ?>
    <div class="fx-total-wrap" aria-live="polite">
        <div class="fx-meta"><?= esc(lang('Currency.totalIn', [$entityCcy])) ?></div>
        <div class="fx-total"><?= esc(MoneyFormat::format((string) $quote['total'], $entityCcy)) ?></div>
        <div class="fx-meta"><?= esc($calc) ?></div>
    </div>
<?php elseif ($state === 'unavailable' || $state === 'unsupported') : ?>
    <div class="alert alert-warning mb-2" role="alert">
        <p class="h5 mb-1"><?= esc(lang('Currency.unavailableTitle')) ?></p>
    <?php if ($state === 'unsupported') : ?>
        <p class="small mb-0"><?= esc(lang('Currency.unsupportedBody', [$source, $entityCcy, $fx['invoice_currency']])) ?></p>
    <?php else : ?>
        <p class="small mb-0"><?= esc(lang('Currency.unavailableBody', [$source])) ?></p>
    <?php endif ?>
    </div>
<?php endif ?>
<?php if ($state === 'unavailable') : ?>
    <button type="button" class="btn btn-outline-secondary btn-touch w-100 mb-2" <?= $getAttrs ?> hx-vals='{"fx_retry":"1"}' hx-include="<?= esc($include, 'attr') ?>"><span class="spinner-border spinner-border-sm htmx-indicator" aria-hidden="true"></span> <?= esc(lang('Currency.retry')) ?></button>
<?php endif ?>
    <details class="fx-manual"<?= $manualOpen ? ' open' : '' ?>>
        <summary class="btn btn-outline-secondary btn-touch w-100"><?= esc(lang('Currency.manualSummary')) ?></summary>
        <div class="mt-3">
            <label class="form-label" for="fx-manual-rate"><?= esc(lang('Currency.manualLabel', [$parts['amount'], $parts['base']])) ?></label>
            <div class="input-group mb-1">
                <input class="form-control<?= isset($fieldErrs['fx_rate']) ? ' is-invalid' : '' ?>" type="text" inputmode="decimal" autocomplete="off" name="fx_rate" id="fx-manual-rate" value="<?= esc($fields['fx_rate'], 'attr') ?>" placeholder="<?= esc($placeholder, 'attr') ?>" aria-describedby="fx-manual-hint<?= isset($fieldErrs['fx_rate']) ? ' fx-manual-rate-error' : '' ?>">
                <span class="input-group-text"><?= esc($parts['quote']) ?></span>
            </div>
    <?php if (isset($fieldErrs['fx_rate'])) : ?>
            <div class="invalid-feedback d-block mb-1" id="fx-manual-rate-error"><?= esc($errText($fieldErrs['fx_rate'])) ?></div>
    <?php endif ?>
            <div class="fx-meta mb-3" id="fx-manual-hint"><?= esc($state === 'unsupported' ? lang('Currency.manualHintNoSource', [$parts['amount'], $parts['base'], $parts['quote']]) : lang('Currency.manualHint', [$source, $parts['amount'], $parts['base'], $parts['quote']])) ?></div>
            <label class="form-label" for="fx-manual-date"><?= esc(lang('Currency.manualDateLabel')) ?></label>
            <input class="form-control mb-1<?= isset($fieldErrs['fx_date']) ? ' is-invalid' : '' ?>" type="date" name="fx_date" id="fx-manual-date" value="<?= esc($dateValue, 'attr') ?>" min="<?= esc($minDate, 'attr') ?>" max="<?= esc($today, 'attr') ?>"<?= isset($fieldErrs['fx_date']) ? ' aria-describedby="fx-manual-date-error"' : '' ?>>
    <?php if (isset($fieldErrs['fx_date'])) : ?>
            <div class="invalid-feedback d-block mb-1" id="fx-manual-date-error"><?= esc($errText($fieldErrs['fx_date'])) ?></div>
    <?php endif ?>
            <input type="hidden" name="fx_mode" id="fx-manual-mode" value="manual">
            <input type="hidden" name="fx_anchor" id="fx-manual-anchor" value="<?= esc((string) $fx['anchor'], 'attr') ?>">
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary btn-touch" <?= $getAttrs ?> hx-include="#fx-manual-rate, #fx-manual-date, #fx-manual-mode, #fx-manual-anchor, <?= esc($include, 'attr') ?>"><?= esc(lang('Currency.manualApply')) ?></button>
    <?php if ($fields['fx_mode'] === 'manual') : ?>
                <button type="button" class="btn btn-link btn-touch" <?= $getAttrs ?> hx-include="<?= esc($include, 'attr') ?>"><?= esc(lang('Currency.manualDiscard')) ?></button>
    <?php endif ?>
            </div>
        </div>
    </details>
    <input type="hidden" id="inv-fx-mode" name="fx_mode" form="inv-issue-form" value="<?= esc($fields['fx_mode'], 'attr') ?>">
    <input type="hidden" id="inv-fx-rate" name="fx_rate" form="inv-issue-form" value="<?= esc($fields['fx_rate'], 'attr') ?>">
    <input type="hidden" id="inv-fx-date" name="fx_date" form="inv-issue-form" value="<?= esc($fields['fx_date'], 'attr') ?>">
    <input type="hidden" id="inv-fx-anchor" name="fx_anchor" form="inv-issue-form" value="<?= esc($fields['fx_anchor'], 'attr') ?>">
    <div class="fx-sub-link"><a class="d-inline-flex align-items-center py-2 small" href="/settings/fx"><?= esc(lang('Currency.settingsLink')) ?></a></div>
</section>
