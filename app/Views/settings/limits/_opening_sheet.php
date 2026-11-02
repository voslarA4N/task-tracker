<?php

/**
 * Spodny panel Pociatocny obrat (UI-SPEC A6).
 *
 * @var array<int, array{id: int, name_internal: string, currency: string}> $entities
 * @var array<string, string>                                               $values
 * @var int                                                                 $selected
 */
?>
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <div>
            <h2 class="offcanvas-title h5 mb-1" id="work-sheet-title"><?= esc(lang('Limits.openingTitle')) ?></h2>
            <div class="small text-body-secondary"><?= esc(($entities[$selected]['name_internal'] ?? '') . ' · ' . ($values['year'] ?? '')) ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Limits.cancel'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?= view('settings/limits/_opening_form', get_defined_vars()) ?>
    </div>
</div>
