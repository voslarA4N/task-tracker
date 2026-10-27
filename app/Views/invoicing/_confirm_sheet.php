<?php

use App\Controllers\Invoicing;
use App\Libraries\Invoicing\Draft;

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
                    hx-post="/invoices/issue" hx-include="#inv-issue-form" hx-target="#inv-preview" hx-swap="outerHTML" hx-disabled-elt="this">
                <span class="spinner-border spinner-border-sm htmx-indicator" aria-hidden="true"></span>
                <?= esc(lang('Invoicing.issueCta')) ?>
            </button>
        </div>
    </div>
</div>
