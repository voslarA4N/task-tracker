<?php

/**
 * Spodny panel Oznacit ako odoslanu (UI-SPEC B2, D-29).
 *
 * @var array<string, mixed> $invoice
 * @var string               $clientName
 */
?>
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <div>
            <h2 class="offcanvas-title h5 mb-1" id="work-sheet-title"><?= esc(lang('Deliveries.markSentTitle')) ?></h2>
            <p class="small text-body-secondary mb-0"><?= esc(lang('Deliveries.markSentHint')) ?></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Deliveries.cancel'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?= view('invoices/_mark_sent_form', get_defined_vars()) ?>
    </div>
</div>
