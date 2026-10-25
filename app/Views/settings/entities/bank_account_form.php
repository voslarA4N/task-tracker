<?php
/**
 * @var array<string, mixed>       $entity
 * @var array<string, mixed>|null  $account
 * @var array<string, mixed>       $values
 * @var array<string, string>      $errors
 * @var bool                       $lockDefault
 * @var list<string>               $currencies
 */
$eid     = (int) $entity['id'];
$editing = $account !== null;
$action  = '/settings/entities/' . $eid . '/bank-accounts' . ($editing ? '/' . (int) $account['id'] : '');
$title   = lang($editing ? 'Entities.bankEditTitle' : 'Entities.bankNewTitle');
$isDef   = (bool) ($values['is_default'] ?? false) || $lockDefault;
$field   = static fn (string $k): string => esc((string) ($values[$k] ?? ''), 'attr');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc($title) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/settings/entities/<?= $eid ?>"><?= esc(lang('Entities.backToDetail')) ?></a></p>

<section class="app-card" aria-labelledby="bank-form-title">
    <h1 class="h4 mb-3" id="bank-form-title"><?= esc($title) ?></h1>

    <?php if ($errors !== []) : ?>
        <div class="alert alert-danger" role="alert"><?= esc(lang('Entities.errorForm')) ?></div>
    <?php endif ?>

    <form method="post" action="<?= $action ?>" data-submit-once novalidate>
        <?= csrf_field() ?>

        <div class="mb-3">
            <label class="form-label" for="f-label"><?= esc(lang('Entities.bankLabel')) ?></label>
            <input class="form-control<?= isset($errors['label']) ? ' is-invalid' : '' ?>" type="text" id="f-label" name="label" value="<?= $field('label') ?>"
                   placeholder="<?= esc(lang('Entities.bankLabelPlaceholder'), 'attr') ?>" autocomplete="off">
            <?php if (isset($errors['label'])) : ?><div class="invalid-feedback"><?= esc($errors['label']) ?></div><?php endif ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="f-iban"><?= esc(lang('Entities.bankIban')) ?></label>
            <input class="form-control master-iban<?= isset($errors['iban']) ? ' is-invalid' : '' ?>" type="text" id="f-iban" name="iban" value="<?= $field('iban') ?>"
                   autocomplete="off" spellcheck="false" autocapitalize="characters" inputmode="text">
            <?php if (isset($errors['iban'])) : ?><div class="invalid-feedback"><?= esc($errors['iban']) ?></div><?php endif ?>
            <?php if ($editing) : ?><div class="form-text"><?= esc(lang('Entities.bankIbanEditHint')) ?></div><?php endif ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="f-swift"><?= esc(lang('Entities.bankSwift')) ?></label>
            <input class="form-control<?= isset($errors['swift']) ? ' is-invalid' : '' ?>" type="text" id="f-swift" name="swift" value="<?= $field('swift') ?>"
                   autocomplete="off" spellcheck="false" autocapitalize="characters">
            <?php if (isset($errors['swift'])) : ?><div class="invalid-feedback"><?= esc($errors['swift']) ?></div><?php endif ?>
        </div>

        <div class="mb-3">
            <?php if ($editing) : ?>
                <div class="small text-body-secondary"><?= esc(lang('Entities.bankCurrency')) ?></div>
                <div class="master-fixed"><?= esc((string) $account['currency']) ?></div>
            <?php else : ?>
                <label class="form-label" for="f-currency"><?= esc(lang('Entities.bankCurrency')) ?></label>
                <select class="form-select<?= isset($errors['currency']) ? ' is-invalid' : '' ?>" id="f-currency" name="currency">
                    <?php foreach ($currencies as $c) : ?>
                        <option value="<?= esc($c, 'attr') ?>"<?= ($values['currency'] ?? '') === $c ? ' selected' : '' ?>><?= esc($c) ?></option>
                    <?php endforeach ?>
                </select>
                <?php if (isset($errors['currency'])) : ?><div class="invalid-feedback"><?= esc($errors['currency']) ?></div><?php endif ?>
            <?php endif ?>
        </div>

        <div class="form-check d-flex align-items-center gap-2 mb-3 ps-0">
            <input class="form-check-input m-0" type="checkbox" id="f-is_default" name="is_default" value="1"<?= $isDef ? ' checked' : '' ?><?= $lockDefault ? ' disabled' : '' ?>>
            <label class="form-check-label py-2" for="f-is_default"><?= esc(lang('Entities.bankDefaultAccount')) ?></label>
        </div>

        <div class="master-sticky-actions master-actions">
            <a class="btn btn-outline-secondary btn-touch" href="/settings/entities/<?= $eid ?>"><?= esc(lang('Entities.cancel')) ?></a>
            <button type="submit" class="btn btn-primary btn-touch"><?= esc(lang($editing ? 'Entities.bankSave' : 'Entities.bankAdd')) ?></button>
        </div>
    </form>
</section>
<?= $this->endSection() ?>
