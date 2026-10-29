<?php

/**
 * Formular storna rucneho oznacenia (sheet aj no-JS stranka).
 *
 * @var array<string, mixed> $send
 * @var string               $reason
 * @var string|null          $error
 * @var string|null          $alert
 */
$action = '/sends/' . (int) $send['id'] . '/void';
?>
<?php if (! empty($alert)) : ?>
    <div class="alert alert-danger" role="alert"><?= esc($alert) ?></div>
<?php endif ?>
<div class="alert alert-warning" role="note"><?= esc(lang('Deliveries.voidMarkWarn')) ?></div>
<form method="post" action="<?= esc($action, 'attr') ?>" hx-post="<?= esc($action, 'attr') ?>" hx-target="#sheet-body" hx-swap="innerHTML" data-submit-once novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="sv-reason"><?= esc(lang('Deliveries.voidMarkReason')) ?></label>
        <textarea class="form-control<?= $error !== null ? ' is-invalid' : '' ?>" id="sv-reason" name="reason" rows="2" maxlength="200" required<?= $error !== null ? ' aria-describedby="sv-reason-err"' : '' ?>><?= esc($reason) ?></textarea>
        <?php if ($error !== null) : ?><div class="invalid-feedback d-block" id="sv-reason-err"><?= esc($error) ?></div><?php endif ?>
    </div>
    <div class="master-sticky-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Deliveries.voidMarkBack')) ?></button>
        <button type="submit" class="btn btn-danger btn-touch"><?= esc(lang('Deliveries.voidMarkCta')) ?></button>
    </div>
</form>
