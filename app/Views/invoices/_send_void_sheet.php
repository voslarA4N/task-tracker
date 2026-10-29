<?php

/**
 * Spodny panel Zrusit oznacenie (UI-SPEC B4, D-29).
 *
 * @var string $sentOn
 */
?>
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <div>
            <h2 class="offcanvas-title h5 mb-1" id="work-sheet-title"><?= esc(lang('Deliveries.voidMarkTitle')) ?></h2>
            <p class="small text-body-secondary mb-0"><?= esc(lang('Deliveries.voidMarkMeta', [$sentOn])) ?></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Deliveries.voidMarkBack'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?= view('invoices/_send_void_form', get_defined_vars()) ?>
    </div>
</div>
