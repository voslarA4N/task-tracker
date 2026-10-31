<?php

/**
 * Potvrdzovaci spodny panel vystavenia dobropisu (UI-SPEC C): nevratna akcia.
 *
 * @var int                   $id
 * @var array<string, mixed>  $draft
 * @var array<string, string> $values
 */
$sheet = true;
?>
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="cn-confirm" aria-labelledby="cn-confirm-title">
    <div class="offcanvas-header px-0 pt-0">
        <h2 class="offcanvas-title h5" id="cn-confirm-title"><?= esc(lang('CreditNotes.confirmTitle')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('CreditNotes.back'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?= view('credit_notes/_confirm_body', get_defined_vars()) ?>
    </div>
</div>
