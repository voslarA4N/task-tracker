<?php
/**
 * @var array<string, array{source: string, date_basis: string, day_rule: string, revision: int}> $rows
 * @var bool                                                                                      $stale
 * @var list<string>                                                                              $invalid
 * @var string|null                                                                               $error
 */
$sources = ['NBP' => 'FxSettings.sourceNbp', 'ECB' => 'FxSettings.sourceEcb', 'CNB' => 'FxSettings.sourceCnb'];
$bases   = ['delivery_date' => 'FxSettings.basisDelivery', 'issue_date' => 'FxSettings.basisIssue'];
$rules   = ['previous_business_day' => 'FxSettings.rulePrevious', 'on_or_before' => 'FxSettings.ruleOnOrBefore'];
$countries = ['SK' => 'FxSettings.countrySK', 'PL' => 'FxSettings.countryPL', 'CZ' => 'FxSettings.countryCZ'];
$fields  = [
    'source'     => ['FxSettings.fieldSource', $sources, 'FxSettings.hintSource'],
    'date_basis' => ['FxSettings.fieldDateBasis', $bases, 'FxSettings.hintDateBasis'],
    'day_rule'   => ['FxSettings.fieldDayRule', $rules, 'FxSettings.hintDayRule'],
];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('FxSettings.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/more"><?= esc(lang('FxSettings.backToMore')) ?></a></p>
<h1 class="h4 mb-1"><?= esc(lang('FxSettings.title')) ?></h1>
<p class="small text-body-secondary"><?= esc(lang('FxSettings.intro')) ?></p>
<div class="alert alert-secondary small" role="status"><?= esc(lang('FxSettings.provisional')) ?></div>

<?php if ($stale) : ?>
    <div class="alert alert-warning" role="alert"><?= esc(lang('FxSettings.errorStale')) ?></div>
<?php endif ?>
<?php if ($error !== null) : ?>
    <div class="alert alert-danger" role="alert"><?= esc($error) ?></div>
<?php endif ?>

<form method="post" action="/settings/fx" data-submit-once novalidate>
    <?= csrf_field() ?>
    <?php foreach ($rows as $country => $row) : ?>
        <fieldset class="app-card mb-4">
            <legend class="h5 fw-semibold"><?= esc(lang($countries[$country])) ?></legend>
            <input type="hidden" name="<?= esc($country) ?>[revision]" value="<?= (int) $row['revision'] ?>">
            <?php foreach ($fields as $name => [$labelKey, $options, $hintKey]) : ?>
                <?php
                $id  = 'fx-' . strtolower($country) . '-' . str_replace('_', '-', $name);
                $bad = in_array($country . '.' . $name, $invalid, true);
                ?>
                <div class="mb-3">
                    <label class="form-label" for="<?= esc($id) ?>"><?= esc(lang($labelKey)) ?></label>
                    <select class="form-select btn-touch<?= $bad ? ' is-invalid' : '' ?>" id="<?= esc($id) ?>" name="<?= esc($country) ?>[<?= esc($name) ?>]" aria-describedby="<?= esc($id) ?>-hint">
                        <?php foreach ($options as $value => $textKey) : ?>
                            <option value="<?= esc($value) ?>"<?= $row[$name] === $value ? ' selected' : '' ?>><?= esc(lang($textKey)) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?php if ($bad) : ?><div class="invalid-feedback"><?= esc(lang('FxSettings.errorInvalid')) ?></div><?php endif ?>
                    <div class="form-text" id="<?= esc($id) ?>-hint"><?= esc(lang($hintKey)) ?></div>
                    <?php if ($name === 'source') : ?>
                        <div class="alert alert-warning small mt-2 mb-0" role="note"><?= esc(lang('FxSettings.unsupportedWarning')) ?></div>
                    <?php endif ?>
                </div>
            <?php endforeach ?>
        </fieldset>
    <?php endforeach ?>

    <p class="small text-body-secondary mb-1"><?= esc(lang('FxSettings.pairs')) ?></p>
    <p class="small text-body-secondary"><?= esc(lang('FxSettings.defaults')) ?></p>

    <div class="master-sticky-actions master-actions">
        <a class="btn btn-outline-secondary btn-touch" href="/more"><?= esc(lang('FxSettings.back')) ?></a>
        <button type="submit" class="btn btn-primary btn-touch" id="fx-save"><?= esc(lang('FxSettings.save')) ?></button>
    </div>
</form>
<?= $this->endSection() ?>
