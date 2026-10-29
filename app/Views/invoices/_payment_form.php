<?php

/**
 * Formular uhrady (spolocny pre sheet a no-JS stranku).
 *
 * @var array<string, mixed>  $invoice
 * @var string                $balance
 * @var string                $today
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var string|null           $alert
 */
$id       = (int) $invoice['id'];
$action   = '/invoices/' . $id . '/payments';
$field    = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-describedby="pay-' . $name . '-err"' : '';
?>
<?php if (! empty($alert)) : ?>
    <div class="alert alert-danger" role="alert"><?= esc($alert) ?></div>
<?php endif ?>
<form method="post" action="<?= esc($action, 'attr') ?>" hx-post="<?= esc($action, 'attr') ?>" hx-target="#sheet-body" hx-swap="innerHTML" data-submit-once novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="request_key" value="<?= esc($values['request_key'], 'attr') ?>">
    <input type="hidden" name="return_to" value="<?= esc($values['return_to'], 'attr') ?>">

    <div class="mb-3">
        <label class="form-label" for="pay-paid_on"><?= esc(lang('Payments.labelDate')) ?></label>
        <input class="form-control<?= $field('paid_on') ?>" type="date" id="pay-paid_on" name="paid_on" value="<?= esc($values['paid_on'], 'attr') ?>"<?= ($invoice['issue_date'] ?? null) !== null ? ' min="' . esc((string) $invoice['issue_date'], 'attr') . '"' : '' ?> max="<?= esc($today, 'attr') ?>" required<?= $describe('paid_on') ?>>
        <?php if (isset($errors['paid_on'])) : ?><div class="invalid-feedback d-block" id="pay-paid_on-err"><?= esc($errors['paid_on']) ?></div><?php endif ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="pay-amount"><?= esc(lang('Payments.labelAmount')) ?></label>
        <input class="form-control<?= $field('amount') ?>" type="text" id="pay-amount" name="amount" value="<?= esc($values['amount'], 'attr') ?>" inputmode="decimal" autocomplete="off" placeholder="<?= esc($balance, 'attr') ?>"
               hx-post="<?= esc($action, 'attr') ?>/impact" hx-trigger="change" hx-target="#pay-impact" hx-swap="innerHTML" hx-include="this"<?= $describe('amount') ?>>
        <?php if (isset($errors['amount'])) : ?><div class="invalid-feedback d-block" id="pay-amount-err"><?= esc($errors['amount']) ?></div><?php endif ?>
        <div class="form-text"><?= esc(lang('Payments.amountHint', [$balance])) ?></div>
        <div class="form-text" id="pay-impact" role="status"></div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="pay-note"><?= esc(lang('Payments.labelNote')) ?></label>
        <input class="form-control<?= $field('note') ?>" type="text" id="pay-note" name="note" value="<?= esc($values['note'], 'attr') ?>" maxlength="200" autocomplete="off"<?= $describe('note') ?>>
        <?php if (isset($errors['note'])) : ?><div class="invalid-feedback d-block" id="pay-note-err"><?= esc($errors['note']) ?></div><?php endif ?>
    </div>

    <div class="master-sticky-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Payments.cancel')) ?></button>
        <button type="submit" class="btn btn-primary btn-touch">
            <span class="spinner-border spinner-border-sm htmx-indicator d-none" aria-hidden="true"></span>
            <?= esc(lang('Payments.cta')) ?>
        </button>
    </div>
</form>
