<?php

/**
 * Formular poctoveho obratu (sheet aj stranka). Mena sa len zobrazuje z meny subjektu (D-07).
 *
 * @var array<int, array{id: int, name_internal: string, currency: string}> $entities
 * @var array<string, string>                                               $values
 * @var array<string, string>                                               $errors       pole => lang kluc
 * @var string|null                                                         $formError    lang kluc
 * @var bool                                                                $lockedEntity
 * @var int                                                                 $selected
 * @var string                                                              $currency
 * @var string                                                              $locale
 * @var bool                                                                $sheet
 */

use App\Libraries\Platform\MoneyFormat;

$val = static fn (string $k): string => esc($values[$k] ?? '', 'attr');
$cls = static fn (string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="of-' . $k . '-err">' . esc(lang($errors[$k])) . '</div>' : '';
$desc = static function (string $k, string $hint = '') use ($errors): string {
    $ids = trim($hint . (isset($errors[$k]) ? ' of-' . $k . '-err' : ''));

    return $ids === '' ? '' : ' aria-describedby="' . $ids . '"';
};
?>
<?php if (! empty($formError)) : ?>
    <div class="alert alert-danger" role="alert"><?= esc(lang($formError)) ?></div>
<?php elseif ($errors !== []) : ?>
    <div class="alert alert-danger" role="alert"><?= esc(lang('Limits.errorForm')) ?></div>
<?php endif ?>
<form method="post" action="/settings/limits/opening"<?php if ($sheet) : ?> hx-post="/settings/limits/opening" hx-target="#sheet-body" hx-swap="innerHTML"<?php endif ?> data-submit-once novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="revision" value="<?= $val('revision') ?>">

    <div class="mb-3">
        <label class="form-label" for="of-entity"><?= esc(lang('Limits.fieldEntity')) ?></label>
        <?php if ($lockedEntity) : ?>
            <input type="hidden" name="entity_id" value="<?= (int) $selected ?>">
        <?php endif ?>
        <select class="form-select<?= $cls('entity_id') ?>" id="of-entity"<?= $lockedEntity ? ' disabled' : ' name="entity_id"' ?><?= $desc('entity_id') ?>>
            <?php foreach ($entities as $entity) : ?>
                <option value="<?= (int) $entity['id'] ?>"<?= $selected === $entity['id'] ? ' selected' : '' ?>><?= esc($entity['name_internal']) ?></option>
            <?php endforeach ?>
        </select>
        <?= $err('entity_id') ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="of-year"><?= esc(lang('Limits.fieldYear')) ?></label>
        <input class="form-control<?= $cls('year') ?>" type="number" inputmode="numeric" min="2000" max="2100" id="of-year" name="year" value="<?= $val('year') ?>"<?= $desc('year') ?>>
        <?= $err('year') ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="of-amount"><?= esc(lang('Limits.fieldOpeningAmount')) ?></label>
        <div class="input-group">
            <input class="form-control<?= $cls('amount') ?>" type="text" inputmode="decimal" id="of-amount" name="amount" value="<?= $val('amount') ?>" autocomplete="off"<?= $desc('amount', 'of-amount-hint') ?>>
            <span class="input-group-text"><?= esc(MoneyFormat::symbol($currency, $locale)) ?></span>
        </div>
        <?= $err('amount') ?>
        <div class="form-text" id="of-amount-hint"><?= esc(lang('Limits.hintOpeningAmount', [$values['year'] ?? '', $currency])) ?> <?= esc(lang('Limits.hintOpeningEmpty')) ?></div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="of-note"><?= esc(lang('Limits.fieldNote')) ?></label>
        <textarea class="form-control<?= $cls('note') ?>" id="of-note" name="note" rows="2" maxlength="255"<?= $desc('note', 'of-note-hint') ?>><?= esc($values['note'] ?? '') ?></textarea>
        <?= $err('note') ?>
        <div class="form-text" id="of-note-hint"><?= esc(lang('Limits.hintOpeningNote')) ?></div>
    </div>

    <div class="master-sticky-actions">
        <?php if ($sheet) : ?>
            <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Limits.cancel')) ?></button>
        <?php else : ?>
            <a class="btn btn-outline-secondary btn-touch" href="/settings/limits"><?= esc(lang('Limits.cancel')) ?></a>
        <?php endif ?>
        <button type="submit" class="btn btn-primary btn-touch"><?= esc(lang('Limits.save')) ?></button>
    </div>
</form>
