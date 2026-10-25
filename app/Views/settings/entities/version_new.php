<?php
use App\Libraries\Master\Country;

/**
 * @var array<string, mixed>  $entity
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var Country               $country
 */
$errors = $errors ?? [];
$id     = (int) $entity['id'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Entities.versionNewTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/settings/entities/<?= $id ?>"><?= esc(lang('Entities.detailTitle')) ?></a></p>

<section class="app-card" aria-labelledby="version-new-title">
    <h1 class="h4 mb-3" id="version-new-title"><?= esc(lang('Entities.versionNewTitle')) ?>: <?= esc((string) $entity['name_internal']) ?></h1>

    <?php if ($errors !== []) : ?>
        <div class="alert alert-danger" role="alert"><?= esc($errors['_form'] ?? $errors['valid_from'] ?? lang('Entities.errorForm')) ?></div>
    <?php endif ?>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
            <div class="small text-body-secondary"><?= esc(lang('Entities.country')) ?></div>
            <div class="master-fixed"><?= esc($country->label()) ?></div>
        </div>
        <div class="col-12 col-md-6">
            <div class="small text-body-secondary"><?= esc(lang('Entities.currency')) ?></div>
            <div class="master-fixed"><?= esc((string) $entity['currency']) ?></div>
        </div>
    </div>
    <div class="small text-body-secondary mb-3"><?= esc(lang('Entities.fixedHint')) ?></div>

    <form method="post" action="/settings/entities/<?= $id ?>/versions" data-submit-once novalidate>
        <?= csrf_field() ?>

        <div class="mb-3">
            <label class="form-label" for="f-valid_from"><?= esc(lang('Entities.validFrom')) ?></label>
            <input class="form-control<?= isset($errors['valid_from']) ? ' is-invalid' : '' ?>" type="date" id="f-valid_from" name="valid_from"
                   value="<?= esc($values['valid_from'] ?? '', 'attr') ?>" min="<?= esc($minDate, 'attr') ?>" max="<?= esc($maxDate, 'attr') ?>"
                   hx-get="/settings/entities/<?= $id ?>/versions/notice" hx-trigger="change" hx-target="#version-notice" hx-include="this"
                   aria-describedby="f-valid_from-hint<?= isset($errors['valid_from']) ? ' f-valid_from-error' : '' ?>">
            <div class="form-text" id="f-valid_from-hint"><?= esc(lang('Entities.validFromHint')) ?></div>
            <?php if (isset($errors['valid_from'])) : ?>
                <div class="invalid-feedback" id="f-valid_from-error"><?= esc($errors['valid_from']) ?></div>
            <?php endif ?>
        </div>
        <div id="version-notice">
            <?= $this->include('settings/entities/_version_notice') ?>
        </div>

        <?= $this->include('settings/entities/_regime_fields') ?>
        <?= $this->include('settings/entities/_identity_fields') ?>
        <div class="form-text mb-3"><?= esc(lang('Entities.typoHint')) ?></div>

        <div class="master-sticky-actions">
            <button type="submit" class="btn btn-primary btn-touch w-100">
                <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
                <?= esc(lang('Entities.saveVersion')) ?>
            </button>
            <a class="btn btn-link w-100 mt-2" href="/settings/entities/<?= $id ?>"><?= esc(lang('Entities.cancel')) ?></a>
        </div>
    </form>
</section>
<?= $this->endSection() ?>
