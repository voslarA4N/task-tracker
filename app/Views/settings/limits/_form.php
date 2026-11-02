<?php

/**
 * Formular limitu obratu (sheet aj stranka).
 *
 * @var array{id: int, revision: int}|null $limit
 * @var array<string, string>              $values
 * @var array<string, string>              $errors     pole => lang kluc
 * @var string|null                        $formError  lang kluc
 * @var bool                               $isSeed
 * @var string|null                        $currencyWarning
 * @var list<string>                       $currencies
 * @var list<string>                       $methods
 * @var bool                               $sheet
 */
$action  = '/settings/limits' . ($limit !== null ? '/' . (int) $limit['id'] : '');
$val     = static fn (string $k): string => esc($values[$k] ?? '', 'attr');
$cls     = static fn (string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
$err     = static function (string $k) use ($errors): string {
    return isset($errors[$k]) ? '<div class="invalid-feedback" id="lf-' . $k . '-err">' . esc(lang($errors[$k])) . '</div>' : '';
};
$desc    = static function (string $k, string $hint = '') use ($errors): string {
    $ids = trim($hint . (isset($errors[$k]) ? ' lf-' . $k . '-err' : ''));

    return $ids === '' ? '' : ' aria-describedby="' . $ids . '"';
};
$countries = ['SK' => 'FxSettings.countrySK', 'PL' => 'FxSettings.countryPL', 'CZ' => 'FxSettings.countryCZ'];
$mLabels   = ['calendar_year' => 'Limits.methodCalendar', 'rolling_12m' => 'Limits.methodRolling', 'pro_rata_first_year' => 'Limits.methodProRata'];
?>
<?php if (! empty($formError)) : ?>
    <div class="alert alert-danger" role="alert"><?= esc(lang($formError)) ?></div>
<?php elseif ($errors !== []) : ?>
    <div class="alert alert-danger" role="alert"><?= esc(lang('Limits.errorForm')) ?></div>
<?php endif ?>
<?php if ($isSeed) : ?>
    <div class="alert alert-secondary small" role="note"><?= esc(lang('Limits.seedEditNotice')) ?></div>
<?php endif ?>
<?php if (! empty($currencyWarning)) : ?>
    <div class="alert alert-warning small" role="note"><?= esc(lang('Limits.warnCurrency', [$currencyWarning])) ?></div>
<?php endif ?>
<form method="post" action="<?= esc($action, 'attr') ?>"<?php if ($sheet) : ?> hx-post="<?= esc($action, 'attr') ?>" hx-target="#sheet-body" hx-swap="innerHTML"<?php endif ?> data-submit-once novalidate>
    <?= csrf_field() ?>
    <?php if ($limit !== null) : ?>
        <input type="hidden" name="revision" value="<?= (int) $limit['revision'] ?>">
    <?php endif ?>

    <div class="mb-3">
        <label class="form-label" for="lf-country"><?= esc(lang('Limits.fieldCountry')) ?></label>
        <select class="form-select<?= $cls('country') ?>" id="lf-country" name="country"<?= $desc('country') ?>>
            <?php foreach ($countries as $code => $key) : ?>
                <option value="<?= $code ?>"<?= ($values['country'] ?? '') === $code ? ' selected' : '' ?>><?= esc(lang($key)) ?></option>
            <?php endforeach ?>
        </select>
        <?= $err('country') ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="lf-year"><?= esc(lang('Limits.fieldYear')) ?></label>
        <input class="form-control<?= $cls('year') ?>" type="number" inputmode="numeric" min="2000" max="2100" id="lf-year" name="year" value="<?= $val('year') ?>"<?= $desc('year') ?>>
        <?= $err('year') ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="lf-name"><?= esc(lang('Limits.fieldName')) ?></label>
        <input class="form-control<?= $cls('name') ?>" type="text" maxlength="120" id="lf-name" name="name" value="<?= $val('name') ?>" autocomplete="off"<?= $desc('name', 'lf-name-hint') ?>>
        <div class="form-text" id="lf-name-hint"><?= esc(lang('Limits.hintName')) ?></div>
        <?= $err('name') ?>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-7">
            <label class="form-label" for="lf-amount"><?= esc(lang('Limits.fieldAmount')) ?></label>
            <input class="form-control<?= $cls('amount') ?>" type="text" inputmode="decimal" id="lf-amount" name="amount" value="<?= $val('amount') ?>" autocomplete="off"<?= $desc('amount') ?>>
            <?= $err('amount') ?>
        </div>
        <div class="col-5">
            <label class="form-label" for="lf-currency"><?= esc(lang('Limits.fieldCurrency')) ?></label>
            <select class="form-select<?= $cls('currency') ?>" id="lf-currency" name="currency"<?= $desc('currency', 'lf-currency-hint') ?>>
                <?php foreach ($currencies as $code) : ?>
                    <option value="<?= esc($code, 'attr') ?>"<?= ($values['currency'] ?? '') === $code ? ' selected' : '' ?>><?= esc($code) ?></option>
                <?php endforeach ?>
            </select>
            <?= $err('currency') ?>
        </div>
        <div class="col-12"><div class="form-text" id="lf-currency-hint"><?= esc(lang('Limits.hintCurrency')) ?></div></div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="lf-calc_method"><?= esc(lang('Limits.fieldMethod')) ?></label>
        <select class="form-select<?= $cls('calc_method') ?>" id="lf-calc_method" name="calc_method"<?= $desc('calc_method', 'lf-method-help') ?>>
            <?php foreach ($methods as $m) : ?>
                <option value="<?= esc($m, 'attr') ?>"<?= ($values['calc_method'] ?? '') === $m ? ' selected' : '' ?>><?= esc(lang($mLabels[$m] ?? 'Limits.methodCalendar')) ?></option>
            <?php endforeach ?>
        </select>
        <?= $err('calc_method') ?>
        <dl class="small text-body-secondary mt-2 mb-0" id="lf-method-help">
            <dt><?= esc(lang('Limits.methodCalendar')) ?></dt><dd><?= esc(lang('Limits.explainCalendar')) ?></dd>
            <dt><?= esc(lang('Limits.methodRolling')) ?></dt><dd><?= esc(lang('Limits.explainRolling')) ?></dd>
            <dt><?= esc(lang('Limits.methodProRata')) ?></dt><dd class="mb-0"><?= esc(lang('Limits.explainProRata')) ?></dd>
        </dl>
    </div>

    <div class="mb-3">
        <label class="form-label" for="lf-thresholds"><?= esc(lang('Limits.fieldThresholds')) ?></label>
        <input class="form-control<?= $cls('thresholds') ?>" type="text" inputmode="numeric" id="lf-thresholds" name="thresholds" value="<?= $val('thresholds') ?>" autocomplete="off"<?= $desc('thresholds', 'lf-thresholds-hint') ?>>
        <div class="form-text" id="lf-thresholds-hint"><?= esc(lang('Limits.hintThresholds')) ?></div>
        <?= $err('thresholds') ?>
    </div>

    <div class="master-sticky-actions">
        <?php if ($sheet) : ?>
            <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Limits.cancel')) ?></button>
        <?php else : ?>
            <a class="btn btn-outline-secondary btn-touch" href="/settings/limits"><?= esc(lang('Limits.cancel')) ?></a>
        <?php endif ?>
        <button type="submit" class="btn btn-primary btn-touch"><?= esc(lang('Limits.save')) ?></button>
    </div>
</form>
