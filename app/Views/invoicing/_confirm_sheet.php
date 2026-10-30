<?php

use App\Controllers\Invoicing;
use App\Libraries\Fx\FxFormat;
use App\Libraries\Invoicing\Draft;
use App\Libraries\Platform\MoneyFormat;

/**
 * Potvrdzovaci spodny panel vystavenia (D-06): rekapitulacia a skutocne odoslanie.
 *
 * @var Draft  $draft
 * @var string $clientName
 * @var string $entityName
 * @var string $monthLabel
 * @var string $total      uz sformatovana suma
 * @var bool   $needsConfirm
 */
$methodLabels = [
    'transfer' => lang('Invoicing.paymentTransfer'),
    'cash'     => lang('Invoicing.paymentCash'),
    'card'     => lang('Invoicing.paymentCard'),
    'other'    => lang('Invoicing.paymentOther'),
];
?>
<div class="offcanvas offcanvas-bottom work-sheet" tabindex="-1" id="inv-confirm" aria-labelledby="inv-confirm-title">
    <div class="offcanvas-header px-0 pt-0">
        <h2 class="offcanvas-title h5" id="inv-confirm-title"><?= esc(lang('Invoicing.confirmTitle', [$clientName])) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Invoicing.close'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0">
        <dl class="master-kv mb-2">
            <dt><?= esc(lang('Invoicing.labelEntity')) ?></dt>
            <dd><?= esc($entityName) ?></dd>
            <dt><?= esc(lang('Invoicing.labelMonth')) ?></dt>
            <dd><?= esc($monthLabel) ?></dd>
            <dt><?= esc(lang('Invoicing.labelIssueDate')) ?></dt>
            <dd><?= esc(Invoicing::dateLabel($draft->issueDate)) ?></dd>
            <dt><?= esc(lang('Invoicing.labelTotal')) ?></dt>
            <dd class="fw-semibold tabular-nums"><?= esc($total) ?></dd>
            <?php if (in_array($draft->fx['state'] ?? null, ['ok', 'manual'], true) && is_array($draft->fx['quote'])) : ?>
                <?php $q = $draft->fx['quote']; ?>
                <dt><?= esc(lang('Currency.confirmRate')) ?></dt>
                <dd>
                <?php if (($draft->fx['state'] ?? null) === 'manual') : ?>
                    <?= esc(FxFormat::expression($q, service('request')->getLocale()) . ' (' . lang('Currency.manualMeta', [Invoicing::dateLabel((string) $q['valid_for'])]) . ')') ?>
                    <span class="fx-badge badge text-bg-warning"><?= esc(lang('Currency.badgeManual')) ?></span>
                <?php else : ?>
                    <?= esc(lang('Currency.confirmRateSource', [FxFormat::expression($q, service('request')->getLocale()), FxFormat::sourceLabel((string) $q['source']), Invoicing::dateLabel((string) $q['valid_for'])])) ?>
                <?php endif ?></dd>
                <dt><?= esc(lang('Currency.totalIn', [$q['entity_currency']])) ?></dt>
                <dd class="fw-semibold tabular-nums"><?= esc(MoneyFormat::format((string) $q['total'], (string) $q['entity_currency'])) ?></dd>
            <?php endif ?>
            <dt><?= esc(lang('Invoicing.confirmPaymentMethod')) ?></dt>
            <dd x-text="methodLabel"><?= esc($methodLabels[$draft->paymentMethod]) ?></dd>
            <dt><?= esc(lang('Invoicing.confirmPaymentNote')) ?></dt>
            <dd class="text-truncate" title="<?= esc((string) $draft->paymentNote, 'attr') ?>" x-bind:title="note" x-text="note"><?= esc((string) $draft->paymentNote) ?></dd>
            <dt class="visually-hidden"><?= esc(lang('Invoicing.labelItems')) ?></dt>
            <dd><?= esc(lang('Invoicing.confirmItems', [$draft->lineCount])) ?></dd>
        </dl>
        <?php if ($needsConfirm) : ?>
            <div class="alert alert-warning" role="alert"><?= esc(lang('Invoicing.confirmBackdateNote', [Invoicing::dateLabel($draft->issueDate)])) ?></div>
        <?php endif ?>
        <p class="small text-body-secondary"><?= esc(lang('Invoicing.confirmHint')) ?></p>
        <div class="d-flex gap-2 justify-content-end">
            <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Invoicing.cancel')) ?></button>
            <button type="submit" form="inv-issue-form" class="btn btn-primary btn-touch"
                    hx-post="/invoices/issue" hx-include="#inv-issue-form" hx-target="#inv-preview" hx-swap="outerHTML" hx-disabled-elt="this" x-bind:disabled="disabled">
                <span class="spinner-border spinner-border-sm htmx-indicator" aria-hidden="true"></span>
                <?= esc(lang('Invoicing.issueCta')) ?>
            </button>
        </div>
    </div>
</div>
