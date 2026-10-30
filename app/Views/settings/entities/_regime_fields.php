<?php
use App\Libraries\Master\CurrencyRepository;

$errors   = $errors ?? [];
$currencies = $currencies ?? (new CurrencyRepository())->codes();
$selected = $values['vat_regime'] ?? '';
$versionMode = $versionMode ?? false;
?>
<?php if (! $versionMode) : ?>
<div class="mb-3">
    <label class="form-label" for="f-currency"><?= esc(lang('Entities.currency')) ?></label>
    <select class="form-select<?= isset($errors['currency']) ? ' is-invalid' : '' ?>" id="f-currency" name="currency"<?= isset($errors['currency']) ? ' aria-invalid="true" aria-describedby="f-currency-error"' : '' ?>>
        <?php foreach ($currencies as $currency) : ?>
            <option value="<?= esc($currency, 'attr') ?>"<?= ($values['currency'] ?? '') === $currency ? ' selected' : '' ?>><?= esc($currency) ?></option>
        <?php endforeach ?>
    </select>
    <?php if (isset($errors['currency'])) : ?>
        <div class="invalid-feedback" id="f-currency-error"><?= esc($errors['currency']) ?></div>
    <?php endif ?>
</div>
<?php endif ?>
<div class="mb-3">
    <label class="form-label" for="f-vat_regime"><?= esc(lang('Entities.vatRegime')) ?></label>
    <select class="form-select<?= isset($errors['vat_regime']) ? ' is-invalid' : '' ?>" id="f-vat_regime" name="vat_regime"<?= isset($errors['vat_regime']) ? ' aria-invalid="true" aria-describedby="f-vat_regime-error"' : '' ?>>
        <?php foreach ($country->vatRegimes() as $regime) : ?>
            <option value="<?= esc($regime->value, 'attr') ?>"<?= $selected === $regime->value ? ' selected' : '' ?>><?= esc($regime->label()) ?></option>
        <?php endforeach ?>
        <?php if ($selected !== '' && ! in_array($selected, array_map(static fn ($r) => $r->value, $country->vatRegimes()), true)) : ?>
            <option value="<?= esc($selected, 'attr') ?>" selected><?= esc($selected) ?></option>
        <?php endif ?>
    </select>
    <?php if (isset($errors['vat_regime'])) : ?>
        <div class="invalid-feedback" id="f-vat_regime-error"><?= esc($errors['vat_regime']) ?></div>
    <?php endif ?>
</div>
