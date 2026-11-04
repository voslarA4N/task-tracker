<?php

/**
 * @var ?string $error     kluc lang pri neplatnom vstupe
 * @var int     $invoices
 * @var int     $creditNotes
 * @var int     $cancelled
 * @var ?string $turnover  formatovany obrat
 * @var ?string $alertText hotovy text chyby odmietnuteho balika
 */
$total = $invoices + $creditNotes;
$off   = $error !== null || $total === 0;
?>
<div id="bundle-preview">
    <?php if (! empty($alertText)) : ?>
        <div class="alert alert-danger" role="alert"><?= esc($alertText) ?></div>
    <?php endif ?>
    <div class="app-card mb-3" role="status">
        <?php if ($error !== null) : ?>
            <p class="invalid-feedback d-block mb-0"><?= esc(lang($error)) ?></p>
        <?php elseif ($total === 0) : ?>
            <p class="mb-0"><?= esc(lang('Bundle.empty')) ?></p>
        <?php else : ?>
            <p class="mb-1"><?= esc(lang('Bundle.previewCounts', [$total, $invoices, $creditNotes])) ?></p>
            <p class="mb-1 small text-body-secondary"><?= esc(lang('Bundle.previewCancelled', [$cancelled])) ?></p>
            <p class="mb-0 fw-semibold"><?= esc(lang('Bundle.previewTurnover', [(string) $turnover])) ?></p>
        <?php endif ?>
    </div>
    <p class="small text-body-secondary"><?= esc(lang('Bundle.externalNote')) ?></p>
    <button type="submit" class="btn btn-primary btn-touch w-100 w-lg-auto" data-submit-once<?= $off ? ' disabled' : '' ?>><?= esc(lang('Bundle.download')) ?></button>
</div>
