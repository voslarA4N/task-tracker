<?php

/**
 * Spodny panel Pridat/Upravit limit (UI-SPEC C).
 *
 * @var array{id: int}|null $limit
 */
$title = lang($limit === null ? 'Limits.formNewTitle' : 'Limits.formEditTitle');
?>
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <h2 class="offcanvas-title h5 mb-1" id="work-sheet-title"><?= esc($title) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Limits.cancel'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?= view('settings/limits/_form', get_defined_vars()) ?>
    </div>
</div>
