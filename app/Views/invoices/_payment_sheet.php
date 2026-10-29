<?php

/**
 * Spodny panel Zapisat uhradu (UI-SPEC C).
 *
 * @var array<string, mixed> $invoice
 * @var string               $clientName
 * @var string               $balance
 */
?>
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <div>
            <h2 class="offcanvas-title h5 mb-1" id="work-sheet-title"><?= esc(lang('Payments.sheetTitle')) ?></h2>
            <p class="small text-body-secondary mb-0"><?= esc(lang('Payments.sheetMeta', [(string) $invoice['number'], $clientName, $balance])) ?></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Payments.cancel'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?= view('invoices/_payment_form', get_defined_vars()) ?>
    </div>
</div>
