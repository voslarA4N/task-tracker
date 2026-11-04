<?php

/**
 * Spodný panel detailu záznamu.
 *
 * @var array<string, mixed> $row
 */
?>
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <h2 class="offcanvas-title h5 mb-0" id="work-sheet-title"><?= esc(lang('AuditLog.detailTitle', [(int) $row['id']])) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('AuditLog.close'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0">
        <?= view('settings/audit/_detail', get_defined_vars()) ?>
    </div>
</div>
