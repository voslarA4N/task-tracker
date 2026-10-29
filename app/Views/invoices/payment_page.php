<?php

/**
 * Uhrada alebo storno uhrady ako cela stranka (bez JS).
 *
 * @var array<string, mixed> $invoice
 * @var string               $backUrl
 */
$isVoid = ! empty($void);
$title  = lang($isVoid ? 'Payments.voidTitle' : 'Payments.sheetTitle');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc($title) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-narrow">
    <p class="mb-2"><a class="d-inline-block py-2" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>"><?= esc(lang('Invoices.backToList')) ?></a></p>
    <section class="app-card" aria-labelledby="sheet-page-title">
        <h1 class="h4 mb-1" id="sheet-page-title"><?= esc($title) ?></h1>
        <p class="small text-body-secondary mb-3">
            <?= $isVoid
                ? esc(lang('Payments.voidMeta', [$amount, $paidOn]))
                : esc(lang('Payments.sheetMeta', [(string) $invoice['number'], $clientName, $balance])) ?>
        </p>
        <div id="sheet-body">
            <?= view($isVoid ? 'invoices/_payment_void_form' : 'invoices/_payment_form', get_defined_vars()) ?>
        </div>
    </section>
</div>
<?= $this->endSection() ?>
