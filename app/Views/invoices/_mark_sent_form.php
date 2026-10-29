<?php

/**
 * Formular rucneho oznacenia (spolocny pre sheet a no-JS stranku).
 *
 * @var array<string, mixed>  $invoice
 * @var string                $today
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var string|null           $alert
 */
$action   = '/invoices/' . (int) $invoice['id'] . '/mark-sent';
$field    = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-describedby="ms-' . $name . '-err"' : '';
?>
<?php if (! empty($alert)) : ?>
    <div class="alert alert-danger" role="alert"><?= esc($alert) ?></div>
<?php endif ?>
<form method="post" action="<?= esc($action, 'attr') ?>" hx-post="<?= esc($action, 'attr') ?>" hx-target="#sheet-body" hx-swap="innerHTML" data-submit-once novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="request_key" value="<?= esc($values['request_key'], 'attr') ?>">

    <div class="mb-3">
        <label class="form-label" for="ms-date"><?= esc(lang('Deliveries.labelSentDate')) ?></label>
        <input class="form-control<?= $field('date') ?>" type="date" id="ms-date" name="date" value="<?= esc($values['date'], 'attr') ?>"<?= ($invoice['issue_date'] ?? null) !== null ? ' min="' . esc((string) $invoice['issue_date'], 'attr') . '"' : '' ?> max="<?= esc($today, 'attr') ?>" required<?= $describe('date') ?>>
        <?php if (isset($errors['date'])) : ?><div class="invalid-feedback d-block" id="ms-date-err"><?= esc($errors['date']) ?></div><?php endif ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="ms-note"><?= esc(lang('Deliveries.labelMarkNote')) ?></label>
        <textarea class="form-control<?= $field('note') ?>" id="ms-note" name="note" rows="2" maxlength="200"<?= $describe('note') ?>><?= esc($values['note']) ?></textarea>
        <?php if (isset($errors['note'])) : ?><div class="invalid-feedback d-block" id="ms-note-err"><?= esc($errors['note']) ?></div><?php endif ?>
    </div>

    <div class="master-sticky-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Deliveries.cancel')) ?></button>
        <button type="submit" class="btn btn-primary btn-touch"><?= esc(lang('Deliveries.markSentCta')) ?></button>
    </div>
</form>
