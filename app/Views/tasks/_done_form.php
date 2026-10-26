<?php

/**
 * Formular panela Zapracovane: mesiac, hodiny (predvolene zostatok), zivy prepocet sumy (D-11).
 *
 * @var array<string, mixed>                                                      $task
 * @var array<string, string>                                                     $values
 * @var array<string, string>                                                     $errors
 * @var string|null                                                               $conflict
 * @var bool                                                                      $zero
 * @var string                                                                    $remaining
 * @var string                                                                    $taskHours
 * @var array<string, string>                                                     $months
 * @var array{symbol: string, decimal: string, group: string, symbolBefore: bool} $format
 */
$taskId = (int) $task['id'];
$v      = static fn (string $k): string => (string) ($values[$k] ?? '');
$choice = $v('month_choice') === '' ? 'current' : $v('month_choice');

$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="d-' . esc($name, 'attr') . '-error"' : '';
$feedback = static fn (string $name): string => isset($errors[$name])
    ? '<div class="invalid-feedback d-block" id="d-' . esc($name, 'attr') . '-error">' . esc($errors[$name]) . '</div>'
    : '';
$invalid = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';

$options = [
    'current' => lang('Allocations.monthCurrent', [$months['currentLabel']]),
    'next'    => lang('Allocations.monthNext', [$months['nextLabel']]),
    'other'   => lang('Allocations.monthOther'),
];
?>
<p class="small text-body-secondary mb-3"><?= esc(lang('Allocations.doneMeta', [(string) $task['title'], $taskHours])) ?></p>
<?php if (($conflict ?? null) !== null) : ?>
    <div class="alert alert-danger" role="alert"><?= esc((string) $conflict) ?></div>
    <div class="master-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Allocations.close')) ?></button>
    </div>
<?php else : ?>
<form method="post" action="/tasks/<?= $taskId ?>/done" hx-post="/tasks/<?= $taskId ?>/done" hx-target="#sheet-body" hx-swap="innerHTML" data-submit-once novalidate
      x-data="allocForm" data-hours="<?= esc($v('hours'), 'attr') ?>" data-rate="<?= esc((string) $task['rate'], 'attr') ?>" data-month-choice="<?= esc($choice, 'attr') ?>"
      data-currency-symbol="<?= esc($format['symbol'], 'attr') ?>" data-decimal="<?= esc($format['decimal'], 'attr') ?>"
      data-group="<?= esc($format['group'], 'attr') ?>" data-symbol-before="<?= $format['symbolBefore'] ? '1' : '' ?>">
    <?= csrf_field() ?>

    <fieldset class="mb-3">
        <legend class="form-label fs-6"><?= esc(lang('Allocations.monthLegend')) ?></legend>
        <div class="work-month-pick" role="group">
            <?php foreach ($options as $key => $label) : ?>
                <input class="btn-check" type="radio" name="month_choice" id="d-month-<?= esc($key, 'attr') ?>" value="<?= esc($key, 'attr') ?>"<?= $choice === $key ? ' checked' : '' ?> x-on:change="setMonthChoice">
                <label class="btn btn-outline-secondary" for="d-month-<?= esc($key, 'attr') ?>"><?= esc($label) ?></label>
            <?php endforeach ?>
        </div>
        <div class="mt-2" x-show="otherMonth">
            <label class="visually-hidden" for="d-month-other-input"><?= esc(lang('Allocations.monthOther')) ?></label>
            <input class="form-control<?= $invalid('billing_month') ?>" type="month" id="d-month-other-input" name="billing_month_other" value="<?= esc($v('billing_month_other'), 'attr') ?>"
                   min="<?= esc($months['min'], 'attr') ?>" max="<?= esc($months['max'], 'attr') ?>"<?= $describe('billing_month') ?>>
        </div>
        <?= $feedback('billing_month') ?>
    </fieldset>

    <div class="mb-3">
        <label class="form-label" for="d-hours"><?= esc(lang('Allocations.fieldHours')) ?></label>
        <input class="form-control<?= $invalid('hours') ?>" type="text" inputmode="decimal" id="d-hours" name="hours" value="<?= esc($v('hours'), 'attr') ?>" autocomplete="off"
               x-on:input="setHours"<?= $describe('hours') ?>>
        <?= $feedback('hours') ?>
        <div class="form-text"><?= esc($zero ? lang('Allocations.doneHintZero') : lang('Allocations.doneHint', [$remaining])) ?></div>
    </div>

    <div class="master-fixed mb-3">
        <?= esc(lang('Allocations.fieldSum')) ?>: <strong class="tabular-nums" x-text="sumText"><?= esc(lang('Tasks.empty')) ?></strong>
    </div>

    <div class="master-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Allocations.cancel')) ?></button>
        <button type="submit" class="btn btn-primary btn-touch">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('Allocations.doneConfirm')) ?>
        </button>
    </div>
</form>
<?php endif ?>
