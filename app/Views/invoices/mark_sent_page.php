<?php

/**
 * Rucne oznacenie alebo jeho storno ako cela stranka (bez JS).
 *
 * @var array<string, mixed> $invoice
 * @var string               $backUrl
 */
$isVoid = ! empty($void);
$title  = lang($isVoid ? 'Deliveries.voidMarkTitle' : 'Deliveries.markSentTitle');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc($title) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-narrow">
    <p class="mb-2"><a class="d-inline-block py-2" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>"><?= esc(lang('Invoices.backToList')) ?></a></p>
    <section class="app-card" aria-labelledby="sheet-page-title">
        <h1 class="h4 mb-1" id="sheet-page-title"><?= esc($title) ?></h1>
        <p class="small text-body-secondary mb-3"><?= esc($isVoid ? lang('Deliveries.voidMarkMeta', [$sentOn]) : lang('Deliveries.markSentHint')) ?></p>
        <div id="sheet-body">
            <?= view($isVoid ? 'invoices/_send_void_form' : 'invoices/_mark_sent_form', get_defined_vars()) ?>
        </div>
    </section>
</div>
<?= $this->endSection() ?>
