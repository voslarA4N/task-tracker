<?php

/**
 * Formular presunu casti do ineho mesiaca.
 *
 * @var array<string, mixed>  $allocation
 * @var string                $taskTitle
 * @var array<string, string> $selection
 * @var string                $choice
 * @var string                $other
 * @var array<string, string> $errors
 * @var bool                  $locked
 * @var string                $nextLabel
 * @var string                $min
 * @var string                $max
 * @var string                $backUrl
 */
$action = '/invoices/preview-move/' . (int) $allocation['id'];
?>
<p class="small text-body-secondary mb-3"><?= esc($taskTitle) ?></p>
<?php if ($locked) : ?>
    <div class="alert alert-danger" role="alert"><?= esc(lang('Invoicing.errorMoveLocked')) ?></div>
    <div class="master-actions">
        <a class="btn btn-outline-secondary btn-touch" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" data-bs-dismiss="offcanvas"><?= esc(lang('Invoicing.close')) ?></a>
    </div>
<?php else : ?>
<form method="post" action="<?= esc($action, 'attr') ?>" hx-post="<?= esc($action, 'attr') ?>" hx-target="#sheet-body" hx-swap="innerHTML" data-submit-once novalidate x-data="invMove" data-month-choice="<?= $choice === 'other' ? 'other' : 'next' ?>">
    <?= csrf_field() ?>
    <?php foreach ($selection as $name => $value) : ?>
        <?php if ($value !== '') : ?>
            <input type="hidden" name="<?= esc((string) $name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
        <?php endif ?>
    <?php endforeach ?>

    <fieldset class="mb-3">
        <legend class="form-label fs-6"><?= esc(lang('Invoicing.moveLegend')) ?></legend>
        <div class="work-month-pick" role="group">
            <input class="btn-check" type="radio" name="month_choice" id="m-month-next" value="next"<?= $choice === 'other' ? '' : ' checked' ?> x-on:change="setMonthChoice">
            <label class="btn btn-outline-secondary" for="m-month-next"><?= esc(lang('Invoicing.moveNext')) ?></label>
            <input class="btn-check" type="radio" name="month_choice" id="m-month-other" value="other"<?= $choice === 'other' ? ' checked' : '' ?> x-on:change="setMonthChoice">
            <label class="btn btn-outline-secondary" for="m-month-other"><?= esc(lang('Invoicing.moveOther')) ?></label>
        </div>
        <div class="form-text"><?= esc(lang('Invoicing.moveNextHint', [$nextLabel])) ?></div>
        <div class="mt-2" x-show="otherMonth">
            <label class="visually-hidden" for="m-month-other-input"><?= esc(lang('Invoicing.moveOther')) ?></label>
            <input class="form-control<?= isset($errors['billing_month']) ? ' is-invalid' : '' ?>" type="month" id="m-month-other-input" name="billing_month_other" value="<?= esc($other, 'attr') ?>"
                   min="<?= esc($min, 'attr') ?>" max="<?= esc($max, 'attr') ?>"<?= isset($errors['billing_month']) ? ' aria-invalid="true" aria-describedby="m-month-error"' : '' ?>>
        </div>
        <?php if (isset($errors['billing_month'])) : ?>
            <div class="invalid-feedback d-block" id="m-month-error"><?= esc($errors['billing_month']) ?></div>
        <?php endif ?>
    </fieldset>

    <div class="master-actions">
        <a class="btn btn-outline-secondary btn-touch" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" data-bs-dismiss="offcanvas"><?= esc(lang('Invoicing.cancel')) ?></a>
        <button type="submit" class="btn btn-primary btn-touch">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('Invoicing.moveSubmit')) ?>
        </button>
    </div>
</form>
<?php endif ?>
