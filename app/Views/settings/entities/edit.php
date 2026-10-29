<?php
use App\Libraries\Master\Country;

/**
 * @var array<string, mixed>  $entity
 * @var Country               $country
 * @var array<string, string> $values
 * @var array<string, string> $errors
 */
$id = (int) $entity['id'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Entities.editTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/settings/entities/<?= $id ?>"><?= esc(lang('Entities.backToDetail')) ?></a></p>

<section class="app-card" aria-labelledby="entity-edit-title">
    <h1 class="h4 mb-3" id="entity-edit-title"><?= esc(lang('Entities.editTitle')) ?></h1>

    <?php if ($errors !== []) : ?>
        <div class="alert alert-danger" role="alert"><?= esc($errors['_form'] ?? lang('Entities.errorForm')) ?></div>
    <?php endif ?>

    <form method="post" action="/settings/entities/<?= $id ?>" data-submit-once novalidate>
        <?= csrf_field() ?>

        <div class="row g-3 mb-1">
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

        <div class="mb-3">
            <label class="form-label" for="f-name_internal"><?= esc(lang('Entities.nameInternal')) ?></label>
            <input class="form-control<?= isset($errors['name_internal']) ? ' is-invalid' : '' ?>" type="text" id="f-name_internal" name="name_internal"
                   value="<?= esc($values['name_internal'] ?? '', 'attr') ?>" autocomplete="off">
            <?php if (isset($errors['name_internal'])) : ?>
                <div class="invalid-feedback"><?= esc($errors['name_internal']) ?></div>
            <?php endif ?>
        </div>
        <div class="small text-body-secondary mb-3"><?= esc(lang('Entities.editVersionedHint')) ?></div>

        <h2 class="h5 mt-4 mb-3"><?= esc(lang('Entities.groupPeriod')) ?></h2>
        <div class="mb-3">
            <label class="form-label" for="f-active_from"><?= esc(lang('Entities.activeFrom')) ?></label>
            <input class="form-control<?= isset($errors['active_from']) ? ' is-invalid' : '' ?>" type="date" id="f-active_from" name="active_from"
                   value="<?= esc($values['active_from'] ?? '', 'attr') ?>">
            <div class="form-text"><?= esc(lang('Entities.activeFromLaterHint')) ?></div>
            <?php if (isset($errors['active_from'])) : ?>
                <div class="invalid-feedback"><?= esc($errors['active_from']) ?></div>
            <?php endif ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-active_to"><?= esc(lang('Entities.activeTo')) ?></label>
            <input class="form-control<?= isset($errors['active_to']) ? ' is-invalid' : '' ?>" type="date" id="f-active_to" name="active_to"
                   value="<?= esc($values['active_to'] ?? '', 'attr') ?>">
            <?php if (isset($errors['active_to'])) : ?>
                <div class="invalid-feedback"><?= esc($errors['active_to']) ?></div>
            <?php endif ?>
        </div>

        <?= view('settings/entities/_sender_fields', ['values' => $values, 'errors' => $errors, 'allowedDomains' => $allowedDomains]) ?>

        <div class="master-actions">
            <a class="btn btn-outline-secondary btn-touch" href="/settings/entities/<?= $id ?>"><?= esc(lang('Entities.backToDetail')) ?></a>
            <button type="submit" class="btn btn-primary btn-touch"><?= esc(lang('Entities.saveEntity')) ?></button>
        </div>
    </form>
</section>
<?= $this->endSection() ?>
