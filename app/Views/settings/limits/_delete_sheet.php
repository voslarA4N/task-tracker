<?php

/**
 * Spodny panel Zmazat limit.
 *
 * @var array{id: int, name: string, country: string, year: int, revision: int} $limit
 * @var string|null                                                             $error
 */
?>
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <h2 class="offcanvas-title h5 mb-1" id="work-sheet-title"><?= esc(lang('Limits.deleteTitle')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Limits.back'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?php if (! empty($error)) : ?>
            <div class="alert alert-danger" role="alert"><?= esc(lang($error)) ?></div>
        <?php endif ?>
        <p><?= esc(lang('Limits.deleteBody', [$limit['name'], $limit['country'], (string) $limit['year']])) ?></p>
        <form method="post" action="/settings/limits/<?= (int) $limit['id'] ?>/delete" hx-post="/settings/limits/<?= (int) $limit['id'] ?>/delete" hx-target="#sheet-body" hx-swap="innerHTML" data-submit-once>
            <?= csrf_field() ?>
            <input type="hidden" name="revision" value="<?= (int) $limit['revision'] ?>">
            <div class="master-sticky-actions">
                <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Limits.back')) ?></button>
                <button type="submit" class="btn btn-danger btn-touch"><?= esc(lang('Limits.deleteConfirm')) ?></button>
            </div>
        </form>
    </div>
</div>
