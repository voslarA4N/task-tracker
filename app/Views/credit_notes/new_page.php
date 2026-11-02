<?php

/**
 * Stranka nahladu dobropisu (UI-SPEC B): jeden formular, jedno primarne tlacidlo.
 *
 * @var int                       $id
 * @var array<string, mixed>|null $draft
 * @var array<string, string>     $values
 * @var array<string, string>     $errors
 * @var list<array<string, mixed>> $alerts
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('CreditNotes.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <p class="mb-2"><a class="d-inline-block py-2" href="/invoices/<?= (int) $id ?>"><?= esc(lang('CreditNotes.backToInvoice')) ?></a></p>
    <form id="cn-form" method="post" action="/invoices/<?= (int) $id ?>/credit-note/confirm" novalidate>
        <?= csrf_field() ?>
        <div id="cn-preview">
            <?= view('credit_notes/_preview', get_defined_vars()) ?>
        </div>
    </form>
</div>
<div id="sheet"></div>
<?= $this->endSection() ?>
