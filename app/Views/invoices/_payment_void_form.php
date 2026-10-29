<?php

/**
 * Formular storna uhrady (UI-SPEC B4).
 *
 * @var array<string, mixed> $payment
 * @var string               $amount
 * @var string               $paidOn
 * @var string               $reason
 * @var string|null          $error
 * @var string|null          $alert
 */
$action = '/payments/' . (int) $payment['id'] . '/void';
?>
<?php if (! empty($alert)) : ?>
    <div class="alert alert-danger" role="alert"><?= esc($alert) ?></div>
<?php endif ?>
<div class="alert alert-warning" role="note"><?= esc(lang('Payments.voidWarn')) ?></div>
<form method="post" action="<?= esc($action, 'attr') ?>" hx-post="<?= esc($action, 'attr') ?>" hx-target="#sheet-body" hx-swap="innerHTML" data-submit-once novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="pay-reason"><?= esc(lang('Payments.labelReason')) ?></label>
        <input class="form-control<?= $error !== null ? ' is-invalid' : '' ?>" type="text" id="pay-reason" name="reason" value="<?= esc($reason, 'attr') ?>" maxlength="200" required autocomplete="off"<?= $error !== null ? ' aria-describedby="pay-reason-err"' : '' ?>>
        <?php if ($error !== null) : ?><div class="invalid-feedback d-block" id="pay-reason-err"><?= esc($error) ?></div><?php endif ?>
    </div>
    <div class="master-sticky-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Payments.back')) ?></button>
        <button type="submit" class="btn btn-danger btn-touch"><?= esc(lang('Payments.voidCta')) ?></button>
    </div>
</form>
