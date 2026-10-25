<?php
use App\Libraries\Master\DocLanguage;
use App\Libraries\Master\Country;

/**
 * Identita (verzované polia) bez DPH režimu. Znovupoužiteľné pre Novú verziu (03-04).
 *
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var Country               $country
 */
$errors = $errors ?? [];
$isPl   = $country === Country::PL;

$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="f-' . esc($name, 'attr') . '-error"' : '';
$feedback = static function (string $name) use ($errors): string {
    return isset($errors[$name]) ? '<div class="invalid-feedback" id="f-' . esc($name, 'attr') . '-error">' . esc($errors[$name]) . '</div>' : '';
};

/** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $fields name, label, type, inputmode, autocomplete */
$fields = [
    ['trade_name', 'Entities.tradeName', 'text', '', 'organization'],
    ['address_line1', 'Entities.addressLine1', 'text', '', 'street-address'],
    ['address_line2', 'Entities.addressLine2', 'text', '', 'off'],
    ['postal_code', 'Entities.postalCode', 'text', '', 'postal-code'],
    ['city', 'Entities.city', 'text', '', 'address-level2'],
    ['company_id', $isPl ? 'Entities.companyIdPl' : 'Entities.companyIdSk', 'text', 'numeric', 'off'],
];

if ($isPl) {
    $fields[] = ['regon', 'Entities.regon', 'text', 'numeric', 'off'];
}

$fields[] = ['tax_id', 'Entities.taxId', 'text', '', 'off'];
$fields[] = ['vat_id', 'Entities.vatId', 'text', '', 'off'];
?>
<?php foreach ($fields as [$name, $labelKey, $type, $mode, $auto]) : ?>
    <div class="mb-3">
        <label class="form-label" for="f-<?= esc($name, 'attr') ?>"><?= esc(lang($labelKey)) ?></label>
        <input class="form-control<?= isset($errors[$name]) ? ' is-invalid' : '' ?>" type="<?= esc($type, 'attr') ?>"
               id="f-<?= esc($name, 'attr') ?>" name="<?= esc($name, 'attr') ?>"
               value="<?= esc($values[$name] ?? '', 'attr') ?>"
               <?= $mode !== '' ? 'inputmode="' . esc($mode, 'attr') . '"' : '' ?>
               autocomplete="<?= esc($auto, 'attr') ?>"<?= $describe($name) ?>>
        <?= $feedback($name) ?>
    </div>
<?php endforeach ?>
<div class="mb-3">
    <label class="form-label" for="f-registry_note"><?= esc(lang('Entities.registryNote')) ?></label>
    <textarea class="form-control<?= isset($errors['registry_note']) ? ' is-invalid' : '' ?>" id="f-registry_note" name="registry_note" rows="3"
              autocomplete="off" aria-describedby="f-registry_note-hint<?= isset($errors['registry_note']) ? ' f-registry_note-error' : '' ?>"><?= esc($values['registry_note'] ?? '') ?></textarea>
    <div class="form-text" id="f-registry_note-hint"><?= esc($isPl ? lang('Entities.registryHintPl') : lang('Entities.registryHintSk')) ?></div>
    <?= $feedback('registry_note') ?>
</div>
<div class="mb-3">
    <label class="form-label" for="f-email"><?= esc(lang('Entities.email')) ?></label>
    <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" type="email" id="f-email" name="email"
           value="<?= esc($values['email'] ?? '', 'attr') ?>" autocomplete="email"<?= $describe('email') ?>>
    <?= $feedback('email') ?>
</div>
<div class="mb-3">
    <label class="form-label" for="f-phone"><?= esc(lang('Entities.phone')) ?></label>
    <input class="form-control<?= isset($errors['phone']) ? ' is-invalid' : '' ?>" type="tel" id="f-phone" name="phone"
           value="<?= esc($values['phone'] ?? '', 'attr') ?>" autocomplete="tel"<?= $describe('phone') ?>>
    <?= $feedback('phone') ?>
</div>
<div class="mb-3">
    <label class="form-label" for="f-doc_language"><?= esc(lang('Entities.docLanguage')) ?></label>
    <select class="form-select<?= isset($errors['doc_language']) ? ' is-invalid' : '' ?>" id="f-doc_language" name="doc_language"<?= $describe('doc_language') ?>>
        <?php foreach (DocLanguage::cases() as $language) : ?>
            <option value="<?= esc($language->value, 'attr') ?>"<?= ($values['doc_language'] ?? '') === $language->value ? ' selected' : '' ?>><?= esc($language->label()) ?></option>
        <?php endforeach ?>
    </select>
    <?= $feedback('doc_language') ?>
</div>
<div class="mb-3">
    <label class="form-label" for="f-due_days"><?= esc(lang('Entities.dueDays')) ?></label>
    <input class="form-control<?= isset($errors['due_days']) ? ' is-invalid' : '' ?>" type="text" inputmode="numeric" id="f-due_days" name="due_days"
           value="<?= esc($values['due_days'] ?? '', 'attr') ?>" autocomplete="off"<?= $describe('due_days') ?>>
    <?= $feedback('due_days') ?>
</div>
