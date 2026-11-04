<?php

/**
 * @var list<array{id: int, name_internal: string, country: string, currency: string, ended: bool}> $entities
 * @var ?int                                                                                        $entityId
 * @var string                                                                                      $from
 * @var string                                                                                      $to
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Bundle.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <p class="mb-2"><a class="d-inline-block py-2" href="/more"><?= esc(lang('Bundle.backToMore')) ?></a></p>
    <h1 class="h4 mb-1"><?= esc(lang('Bundle.title')) ?></h1>
    <p class="small text-body-secondary"><?= esc(lang('Bundle.intro')) ?></p>
    <form method="get" action="/reports/accountant/download" hx-get="/reports/accountant/preview" hx-trigger="change" hx-target="#bundle-preview" hx-swap="outerHTML" data-submit-once data-submit-reset="10000">
        <div class="mb-3">
            <label class="form-label small mb-1" for="bundle-entity"><?= esc(lang('Bundle.entityLabel')) ?></label>
            <select name="entity" class="form-select btn-touch" id="bundle-entity">
                <?php foreach ($entities as $e) : ?>
                    <option value="<?= esc((string) $e['id'], 'attr') ?>"<?= $e['id'] === $entityId ? ' selected' : '' ?>><?= esc(lang('Reports.entityOption', [$e['name_internal'], $e['currency']])) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="row g-2 mb-1">
            <div class="col-12 col-sm-6">
                <label class="form-label small mb-1" for="bundle-from"><?= esc(lang('Bundle.fromLabel')) ?></label>
                <input type="month" class="form-control btn-touch" id="bundle-from" name="from" value="<?= esc($from, 'attr') ?>" required>
            </div>
            <div class="col-12 col-sm-6">
                <label class="form-label small mb-1" for="bundle-to"><?= esc(lang('Bundle.toLabel')) ?></label>
                <input type="month" class="form-control btn-touch" id="bundle-to" name="to" value="<?= esc($to, 'attr') ?>" required>
            </div>
        </div>
        <p class="small text-body-secondary mb-3"><?= esc(lang('Bundle.rangeHint')) ?></p>
        <?= view('reports/accountant/_preview', get_defined_vars()) ?>
    </form>
</div>
<?= $this->endSection() ?>
