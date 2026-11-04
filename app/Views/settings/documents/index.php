<?php
/**
 * @var string $hours
 * @var int    $current
 * @var int    $revision
 * @var bool   $stale
 * @var bool   $invalid
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('DocumentSettings.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-narrow">
<p class="mb-2"><a href="/more"><?= esc(lang('DocumentSettings.back')) ?></a></p>
<h1 class="h4 mb-1"><?= esc(lang('DocumentSettings.title')) ?></h1>
<p class="small text-body-secondary"><?= esc(lang('DocumentSettings.intro')) ?></p>

<?php if ($stale) : ?>
    <div class="alert alert-warning" role="alert"><?= esc(lang('DocumentSettings.errorStale', [$current])) ?></div>
<?php endif ?>

<form method="post" action="/settings/documents" data-submit-once novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="revision" value="<?= (int) $revision ?>">
    <div class="mb-3">
        <label class="form-label" for="regen_invoice_hours"><?= esc(lang('DocumentSettings.fieldLabel')) ?></label>
        <div class="input-group">
            <input class="form-control btn-touch<?= $invalid ? ' is-invalid' : '' ?>" type="number" inputmode="numeric" min="0" max="8760" step="1" name="regen_invoice_hours" id="regen_invoice_hours" value="<?= esc($hours) ?>" aria-describedby="regen_invoice_hours-hint">
            <span class="input-group-text"><?= esc(lang('DocumentSettings.fieldUnit')) ?></span>
            <?php if ($invalid) : ?><div class="invalid-feedback"><?= esc(lang('DocumentSettings.errorInvalid')) ?></div><?php endif ?>
        </div>
        <div class="form-text" id="regen_invoice_hours-hint"><?= esc(lang('DocumentSettings.fieldHint')) ?></div>
    </div>

    <div class="master-sticky-actions master-actions">
        <a class="btn btn-outline-secondary btn-touch" href="/more"><?= esc(lang('DocumentSettings.back')) ?></a>
        <button type="submit" class="btn btn-primary btn-touch" id="documents-save"><?= esc(lang('DocumentSettings.save')) ?></button>
    </div>
</form>
</div>
<?= $this->endSection() ?>
