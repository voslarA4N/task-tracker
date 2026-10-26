<?php

/**
 * Panel zospodu pre fakturacnu cast (D-08, D-10). Obsah #sheet-body je formular alebo potvrdenie zmazania.
 *
 * @var array<string, mixed> $task
 * @var string               $mode
 * @var string               $inner
 */
$titleKey = $mode === 'add' ? 'Allocations.sheetAddTitle' : ($mode === 'edit' ? 'Allocations.sheetEditTitle' : 'Allocations.deleteTitle');
?>
<div class="offcanvas offcanvas-bottom work-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <h2 class="offcanvas-title h5" id="work-sheet-title"><?= esc(lang($titleKey)) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Allocations.close'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?= $this->include($inner) ?>
    </div>
</div>
