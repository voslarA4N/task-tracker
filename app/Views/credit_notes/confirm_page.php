<?php

/**
 * Potvrdenie vystavenia dobropisu ako cela stranka (bez JS).
 *
 * @var int                   $id
 * @var array<string, mixed>  $draft
 * @var array<string, string> $values
 */
$sheet = false;
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('CreditNotes.confirmTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-narrow">
    <p class="mb-2"><a class="d-inline-block py-2" href="/invoices/<?= (int) $id ?>"><?= esc(lang('CreditNotes.backToInvoice')) ?></a></p>
    <section class="app-card" aria-labelledby="cn-confirm-page-title">
        <h1 class="h4 mb-3" id="cn-confirm-page-title"><?= esc(lang('CreditNotes.confirmTitle')) ?></h1>
        <?= view('credit_notes/_confirm_body', get_defined_vars()) ?>
    </section>
</div>
<?= $this->endSection() ?>
