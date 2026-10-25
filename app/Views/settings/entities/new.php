<?php
use App\Libraries\Master\Country;

$errors = $errors ?? [];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Entities.newTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/more"><?= esc(lang('Entities.backToMore')) ?></a></p>

<section class="app-card" aria-labelledby="entity-new-title">
    <h1 class="h4 mb-3" id="entity-new-title"><?= esc(lang('Entities.newTitle')) ?></h1>

    <?php if ($errors !== []) : ?>
        <div class="alert alert-danger" role="alert"><?= esc(lang('Entities.errorForm')) ?></div>
    <?php endif ?>

    <form method="post" action="/settings/entities" data-submit-once novalidate>
        <?= csrf_field() ?>

        <h2 class="h5 mb-3"><?= esc(lang('Entities.groupBase')) ?></h2>
        <div class="alert alert-info small" role="note"><?= esc(lang('Entities.immutableNotice')) ?></div>
        <div class="mb-3">
            <label class="form-label" for="f-country"><?= esc(lang('Entities.country')) ?></label>
            <select class="form-select<?= isset($errors['country']) ? ' is-invalid' : '' ?>" id="f-country" name="country"
                    hx-get="/settings/entities/regime-fields" hx-trigger="change" hx-target="#entity-regime-fields" hx-swap="innerHTML" hx-include="#f-country, #entity-identity-fields">
                <?php foreach (Country::cases() as $option) : ?>
                    <option value="<?= esc($option->value, 'attr') ?>"<?= $country === $option ? ' selected' : '' ?>><?= esc($option->label()) ?></option>
                <?php endforeach ?>
            </select>
            <?php if (isset($errors['country'])) : ?>
                <div class="invalid-feedback"><?= esc($errors['country']) ?></div>
            <?php endif ?>
        </div>
        <div id="entity-regime-fields">
            <?= $this->include('settings/entities/_regime_fields') ?>
        </div>

        <h2 class="h5 mt-4 mb-3"><?= esc(lang('Entities.groupIdentity')) ?></h2>
        <div class="mb-3">
            <label class="form-label" for="f-name_internal"><?= esc(lang('Entities.nameInternal')) ?></label>
            <input class="form-control<?= isset($errors['name_internal']) ? ' is-invalid' : '' ?>" type="text" id="f-name_internal" name="name_internal"
                   value="<?= esc($values['name_internal'] ?? '', 'attr') ?>" autocomplete="off"<?= isset($errors['name_internal']) ? ' aria-invalid="true" aria-describedby="f-name_internal-error"' : '' ?>>
            <?php if (isset($errors['name_internal'])) : ?>
                <div class="invalid-feedback" id="f-name_internal-error"><?= esc($errors['name_internal']) ?></div>
            <?php endif ?>
        </div>
        <div id="entity-identity-fields">
            <?= $this->include('settings/entities/_identity_fields') ?>
        </div>

        <h2 class="h5 mt-4 mb-3"><?= esc(lang('Entities.groupPeriod')) ?></h2>
        <div class="mb-3">
            <label class="form-label" for="f-active_from"><?= esc(lang('Entities.activeFrom')) ?></label>
            <input class="form-control<?= isset($errors['active_from']) ? ' is-invalid' : '' ?>" type="date" id="f-active_from" name="active_from"
                   value="<?= esc($values['active_from'] ?? '', 'attr') ?>" aria-describedby="f-active_from-hint<?= isset($errors['active_from']) ? ' f-active_from-error' : '' ?>">
            <div class="form-text" id="f-active_from-hint"><?= esc(lang('Entities.activeFromHint')) ?></div>
            <div class="form-text"><?= esc(lang('Entities.activeFromLaterHint')) ?></div>
            <?php if (isset($errors['active_from'])) : ?>
                <div class="invalid-feedback" id="f-active_from-error"><?= esc($errors['active_from']) ?></div>
            <?php endif ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-active_to"><?= esc(lang('Entities.activeTo')) ?></label>
            <input class="form-control<?= isset($errors['active_to']) ? ' is-invalid' : '' ?>" type="date" id="f-active_to" name="active_to"
                   value="<?= esc($values['active_to'] ?? '', 'attr') ?>"<?= isset($errors['active_to']) ? ' aria-invalid="true" aria-describedby="f-active_to-error"' : '' ?>>
            <?php if (isset($errors['active_to'])) : ?>
                <div class="invalid-feedback" id="f-active_to-error"><?= esc($errors['active_to']) ?></div>
            <?php endif ?>
        </div>

        <div class="master-sticky-actions">
            <button type="submit" class="btn btn-primary btn-touch w-100">
                <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
                <?= esc(lang('Entities.createSubmit')) ?>
            </button>
        </div>
    </form>
</section>
<?= $this->endSection() ?>
