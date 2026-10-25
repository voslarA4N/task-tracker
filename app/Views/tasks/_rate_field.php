<?php

/**
 * Sadzba a mena klienta; obnovuje sa cez htmx pri zmene klienta (D-02).
 *
 * @var array<string, string>                                                     $values
 * @var array<string, string>                                                     $errors
 * @var array<string, mixed>|null                                                 $client
 * @var array{symbol: string, decimal: string, group: string, symbolBefore: bool} $format
 */
$errors ??= [];
$currency = (string) ($client['currency'] ?? '');
$invalid  = isset($errors['rate']);
?>
<div id="rate-field" data-rate="<?= esc((string) ($values['rate'] ?? ''), 'attr') ?>" data-currency-symbol="<?= esc($format['symbol'], 'attr') ?>"
     data-decimal="<?= esc($format['decimal'], 'attr') ?>" data-group="<?= esc($format['group'], 'attr') ?>" data-symbol-before="<?= $format['symbolBefore'] ? '1' : '' ?>"
     x-init="syncRate()">
    <label class="form-label" for="f-rate"><?= esc(lang('Tasks.rate')) ?></label>
    <div class="input-group has-validation">
        <input class="form-control<?= $invalid ? ' is-invalid' : '' ?>" type="text" inputmode="decimal" id="f-rate" name="rate" value="<?= esc((string) ($values['rate'] ?? ''), 'attr') ?>" autocomplete="off"
               x-on:input="setRate"<?= $invalid ? ' aria-invalid="true" aria-describedby="f-rate-error"' : '' ?>>
        <span class="input-group-text small"><?= esc(lang('Tasks.perHourUnit', [$currency])) ?></span>
        <?php if ($invalid) : ?>
            <div class="invalid-feedback d-block" id="f-rate-error"><?= esc($errors['rate']) ?></div>
        <?php endif ?>
    </div>
</div>
