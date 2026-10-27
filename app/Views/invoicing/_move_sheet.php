<?php

/**
 * Spodny panel presunu casti (D-03).
 */
?>
<div class="offcanvas offcanvas-bottom work-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <h2 class="offcanvas-title h5" id="work-sheet-title"><?= esc(lang('Invoicing.moveTitle')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Invoicing.close'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?= view('invoicing/_move_form', get_defined_vars()) ?>
    </div>
</div>
