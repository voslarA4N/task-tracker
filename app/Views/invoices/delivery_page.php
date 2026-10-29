<?php

/**
 * Odoslanie faktury ako cela stranka (bez JS).
 *
 * @var array<string, mixed> $invoice
 * @var string               $clientName
 * @var string               $backUrl
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang(($kind ?? 'invoice') === 'resend' ? 'Deliveries.resendTitle' : 'Deliveries.sendTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-narrow">
    <p class="mb-2"><a class="d-inline-block py-2" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>"><?= esc(lang('Invoices.backToList')) ?></a></p>
    <section class="app-card" aria-labelledby="sheet-page-title">
        <h1 class="h4 mb-1" id="sheet-page-title"><?= esc(lang(($kind ?? 'invoice') === 'resend' ? 'Deliveries.resendTitle' : 'Deliveries.sendTitle')) ?></h1>
        <p class="small text-body-secondary mb-3"><?= esc(lang('Deliveries.sendMeta', [(string) $invoice['number'], $clientName, strtoupper((string) $draft['language'])])) ?></p>
        <div id="sheet-body">
            <?= view('invoices/_delivery_form', get_defined_vars()) ?>
        </div>
    </section>
</div>
<?= $this->endSection() ?>
