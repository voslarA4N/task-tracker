<?php

/**
 * Zmazanie limitu ako samostatna stranka (bez JS).
 *
 * @var array{id: int, name: string, country: string, year: int, revision: int} $limit
 * @var string|null                                                             $error
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Limits.deleteTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <p class="mb-2"><a class="d-inline-block py-2" href="/settings/limits"><?= esc(lang('Limits.backToLimits')) ?></a></p>
    <section class="app-card" aria-labelledby="limit-delete-title">
        <h1 class="h4 mb-3" id="limit-delete-title"><?= esc(lang('Limits.deleteTitle')) ?></h1>
        <?php if (! empty($error)) : ?>
            <div class="alert alert-danger" role="alert"><?= esc(lang($error)) ?></div>
        <?php endif ?>
        <p><?= esc(lang('Limits.deleteBody', [$limit['name'], $limit['country'], (string) $limit['year']])) ?></p>
        <form method="post" action="/settings/limits/<?= (int) $limit['id'] ?>/delete" data-submit-once>
            <?= csrf_field() ?>
            <input type="hidden" name="revision" value="<?= (int) $limit['revision'] ?>">
            <div class="master-sticky-actions">
                <a class="btn btn-outline-secondary btn-touch" href="/settings/limits"><?= esc(lang('Limits.back')) ?></a>
                <button type="submit" class="btn btn-danger btn-touch"><?= esc(lang('Limits.deleteConfirm')) ?></button>
            </div>
        </form>
    </section>
</div>
<?= $this->endSection() ?>
