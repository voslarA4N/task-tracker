<?php

/**
 * Pole Subjekt (iba subjekty aktivne k datumu vystavenia). V htmx odpovedi sa prenasa ako out-of-band,
 * aby sa zoznam obnovil spolu s datumom vystavenia.
 *
 * @var list<array{id: int, name_internal: string, country: string, currency: string}> $entities
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var bool                  $oob
 */
$selected  = (string) ($values['entity'] ?? '');
$htmxAttrs = 'hx-get="/fragments/invoice-preview" hx-trigger="change" hx-target="#inv-preview" hx-swap="outerHTML" hx-push-url="true" hx-include="#inv-client, #inv-month, #inv-issue-date, #inv-delivery-date, #inv-due-date, #inv-payment-method, #inv-payment-note, #inv-payment-note-dirty"';
?>
<div id="inv-entity-field"<?= $oob ? ' hx-swap-oob="true"' : '' ?>>
    <label class="form-label" for="inv-entity"><?= esc(lang('Invoicing.labelEntity')) ?></label>
    <select class="form-select<?= isset($errors['entity']) ? ' is-invalid' : '' ?>" id="inv-entity" name="entity" <?= $htmxAttrs ?>>
        <?php foreach ($entities as $e) : ?>
            <option value="<?= (int) $e['id'] ?>"<?= (string) $e['id'] === $selected ? ' selected' : '' ?>><?= esc($e['name_internal'] . ' (' . $e['currency'] . ')') ?></option>
        <?php endforeach ?>
    </select>
    <div class="form-text"><?= esc(lang('Invoicing.entityHint')) ?></div>
    <?php if (isset($errors['entity'])) : ?>
        <div class="invalid-feedback d-block"><?= esc($errors['entity']) ?></div>
    <?php endif ?>
</div>
