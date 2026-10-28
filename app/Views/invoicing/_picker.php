<?php

/**
 * Vyber klienta, mesiaca a subjektu. Kazde pole obnovuje #inv-preview, bez JS funguje tlacidlo.
 *
 * @var list<array<string, mixed>> $clients
 * @var array<string, string>      $values
 * @var array<string, string>      $errors
 * @var array{min: string, max: string} $bounds
 */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');

$htmx = static fn (string $include): string => 'hx-get="/fragments/invoice-preview" hx-trigger="change" hx-target="#inv-preview" hx-swap="outerHTML" hx-push-url="true"'
    . ($include === '' ? '' : ' hx-include="' . $include . '"');

$invalid  = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="inv-' . $name . '-error"' : '';
$feedback = static fn (string $name): string => isset($errors[$name])
    ? '<div class="invalid-feedback d-block" id="inv-' . $name . '-error">' . esc($errors[$name]) . '</div>'
    : '';
?>
<form method="get" action="/invoices/new" id="inv-picker-form" class="app-card mb-3">
    <div class="inv-picker">
        <div>
            <label class="form-label" for="inv-client"><?= esc(lang('Invoicing.labelClient')) ?></label>
            <select class="form-select<?= $invalid('client') ?>" id="inv-client" name="client" <?= $htmx('#inv-payment-method, #inv-payment-note, #inv-payment-note-dirty') ?><?= $describe('client') ?>>
                <?php foreach ($clients as $c) : ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (string) $c['id'] === $v('client') ? ' selected' : '' ?>><?= esc((string) $c['name']) ?></option>
                <?php endforeach ?>
            </select>
            <?= $feedback('client') ?>
        </div>
        <div>
            <label class="form-label" for="inv-month"><?= esc(lang('Invoicing.labelMonth')) ?></label>
            <input class="form-control<?= $invalid('month') ?>" type="month" id="inv-month" name="month" value="<?= esc($v('month'), 'attr') ?>"
                   min="<?= esc($bounds['min'], 'attr') ?>" max="<?= esc($bounds['max'], 'attr') ?>"
                   <?= $htmx('#inv-client, #inv-entity, #inv-payment-method, #inv-payment-note, #inv-payment-note-dirty') ?><?= $describe('month') ?>>
            <?= $feedback('month') ?>
        </div>
        <?= view('invoicing/_entity_field', ['entities' => $entities ?? [], 'values' => $values, 'errors' => $errors, 'oob' => false]) ?>
    </div>
    <div class="px-3 pb-3">
        <button type="submit" class="btn btn-outline-secondary btn-touch"><?= esc(lang('Invoicing.showPreview')) ?></button>
    </div>
</form>
