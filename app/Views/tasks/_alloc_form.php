<?php

use App\Libraries\Platform\Money;
use App\Libraries\Platform\MoneyFormat;

/**
 * Formular fakturacnej casti: pridanie, uprava, presun (D-08, D-09, D-15).
 *
 * @var array<string, mixed>                                                      $task
 * @var array<string, mixed>|null                                                 $allocation
 * @var array<string, string>                                                     $values
 * @var array<string, string>                                                     $errors
 * @var string                                                                    $mode
 * @var bool                                                                      $lockedError
 * @var array<string, string>                                                     $months
 * @var array{symbol: string, decimal: string, group: string, symbolBefore: bool} $format
 */
$taskId = (int) $task['id'];
$locked = $lockedError ?? false;

$edit   = $mode === 'edit' && $allocation !== null;
$action = $edit ? '/allocations/' . (int) $allocation['id'] : '/tasks/' . $taskId . '/allocations';
$v      = static fn (string $k): string => (string) ($values[$k] ?? '');
$choice = $v('month_choice') === '' ? 'current' : $v('month_choice');

$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="a-' . esc($name, 'attr') . '-error"' : '';
$feedback = static fn (string $name): string => isset($errors[$name])
    ? '<div class="invalid-feedback d-block" id="a-' . esc($name, 'attr') . '-error">' . esc($errors[$name]) . '</div>'
    : '';
$invalid = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';

$sum = '';

if (preg_match('/^\d{1,5}([.,]\d{1,2})?$/D', str_replace(["\u{00A0}", ' '], '', $v('hours'))) === 1
    && preg_match('/^\d{1,8}([.,]\d{1,2})?$/D', str_replace(["\u{00A0}", ' '], '', $v('rate'))) === 1) {
    $sum = MoneyFormat::format(
        Money::of(str_replace(',', '.', str_replace(["\u{00A0}", ' '], '', $v('rate'))))
            ->timesHours(str_replace(',', '.', str_replace(["\u{00A0}", ' '], '', $v('hours'))))
            ->toDecimalString(),
        (string) $task['currency'],
    );
}

$options = [
    'current' => lang('Allocations.monthCurrent', [$months['currentLabel']]),
    'next'    => lang('Allocations.monthNext', [$months['nextLabel']]),
    'other'   => lang('Allocations.monthOther'),
];
?>
<?php if ($locked) : ?>
    <div class="alert alert-danger" role="alert"><?= esc(lang('Allocations.errorLocked')) ?></div>
    <div class="master-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Allocations.close')) ?></button>
    </div>
<?php else : ?>
<form method="post" action="<?= esc($action, 'attr') ?>" hx-post="<?= esc($action, 'attr') ?>" hx-target="#sheet-body" hx-swap="innerHTML" data-submit-once novalidate
      x-data="allocForm" data-hours="<?= esc($v('hours'), 'attr') ?>" data-rate="<?= esc($v('rate'), 'attr') ?>" data-month-choice="<?= esc($choice, 'attr') ?>"
      data-currency-symbol="<?= esc($format['symbol'], 'attr') ?>" data-decimal="<?= esc($format['decimal'], 'attr') ?>"
      data-group="<?= esc($format['group'], 'attr') ?>" data-symbol-before="<?= $format['symbolBefore'] ? '1' : '' ?>">
    <?= csrf_field() ?>

    <?php if ($edit) : ?>
        <p class="small text-body-secondary mb-3"><?= esc(lang('Allocations.editHint')) ?></p>
    <?php endif ?>

    <fieldset class="mb-3">
        <legend class="form-label fs-6"><?= esc(lang('Allocations.monthLegend')) ?></legend>
        <div class="work-month-pick" role="group">
            <?php foreach ($options as $key => $label) : ?>
                <input class="btn-check" type="radio" name="month_choice" id="a-month-<?= esc($key, 'attr') ?>" value="<?= esc($key, 'attr') ?>"<?= $choice === $key ? ' checked' : '' ?> x-on:change="setMonthChoice">
                <label class="btn btn-outline-secondary" for="a-month-<?= esc($key, 'attr') ?>"><?= esc($label) ?></label>
            <?php endforeach ?>
        </div>
        <div class="mt-2" x-show="otherMonth">
            <label class="visually-hidden" for="a-month-other-input"><?= esc(lang('Allocations.monthOther')) ?></label>
            <input class="form-control<?= $invalid('billing_month') ?>" type="month" id="a-month-other-input" name="billing_month_other" value="<?= esc($v('billing_month_other'), 'attr') ?>"
                   min="<?= esc($months['min'], 'attr') ?>" max="<?= esc($months['max'], 'attr') ?>"<?= $describe('billing_month') ?>>
        </div>
        <?= $feedback('billing_month') ?>
    </fieldset>

    <div class="row g-3 mb-3">
        <div class="col-6">
            <label class="form-label" for="a-hours"><?= esc(lang('Allocations.fieldHours')) ?></label>
            <input class="form-control<?= $invalid('hours') ?>" type="text" inputmode="decimal" id="a-hours" name="hours" value="<?= esc($v('hours'), 'attr') ?>" autocomplete="off"
                   x-on:input="setHours"<?= $describe('hours') ?>>
            <?= $feedback('hours') ?>
        </div>
        <div class="col-6">
            <label class="form-label" for="a-rate"><?= esc(lang('Allocations.fieldRate')) ?></label>
            <input class="form-control<?= $invalid('rate') ?>" type="text" inputmode="decimal" id="a-rate" name="rate" value="<?= esc($v('rate'), 'attr') ?>" autocomplete="off"
                   x-on:input="setRate"<?= $describe('rate') ?>>
            <?= $feedback('rate') ?>
        </div>
    </div>

    <div class="master-fixed mb-1">
        <?= esc(lang('Allocations.fieldSum')) ?>: <strong class="tabular-nums" x-text="sumText"><?= $sum === '' ? esc(lang('Tasks.empty')) : esc($sum) ?></strong>
    </div>
    <div class="form-text mb-3"><?= esc(lang('Tasks.sumHint')) ?> <?= esc(lang('Tasks.currencyHint', [(string) $task['currency']])) ?></div>

    <div class="master-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Allocations.cancel')) ?></button>
        <?php if ($edit) : ?>
            <button type="button" class="btn btn-outline-danger btn-touch" hx-get="/allocations/<?= (int) $allocation['id'] ?>/delete" hx-target="#sheet-body" hx-swap="innerHTML"><?= esc(lang('Allocations.delete')) ?></button>
        <?php endif ?>
        <button type="submit" class="btn btn-primary btn-touch">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang($edit ? 'Allocations.save' : 'Allocations.add')) ?>
        </button>
    </div>
</form>
<?php endif ?>
