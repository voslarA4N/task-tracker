<?php
/**
 * @var array<string, mixed>                                           $values
 * @var list<array{value: string, country: string, regime: string}>    $profiles
 * @var string|null                                                    $error
 */
$modes = ['' => 'LegalTexts.modeGeneral', 'domestic' => 'LegalTexts.modeDomestic', 'eu_b2b' => 'LegalTexts.modeEuB2b', 'non_eu' => 'LegalTexts.modeNonEu'];
$keys  = ['vat_note' => 'LegalTexts.keyVatNote', 'invoice_note' => 'LegalTexts.keyInvoiceNote'];
$langs = ['sk', 'cs', 'pl', 'en'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('LegalTexts.addVariant')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/settings/legal-texts"><?= esc(lang('LegalTexts.backToList')) ?></a></p>

<section class="app-card" aria-labelledby="lt-new-title">
    <h1 class="h4 mb-3" id="lt-new-title"><?= esc(lang('LegalTexts.addVariant')) ?></h1>

    <form method="post" action="/settings/legal-texts" data-submit-once novalidate>
        <?= csrf_field() ?>
        <fieldset>
            <legend class="h6"><?= esc(lang('LegalTexts.variantLegend')) ?></legend>

            <div class="mb-3">
                <label class="form-label" for="lt-profile"><?= esc(lang('LegalTexts.profile')) ?></label>
                <select class="form-select" id="lt-profile" name="profile" required>
                    <?php foreach ($profiles as $p) : ?>
                        <option value="<?= esc($p['value'], 'attr') ?>"<?= $values['profile'] === $p['value'] ? ' selected' : '' ?>><?= esc($p['country'] . ' · ' . $p['regime']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="lt-key"><?= esc(lang('LegalTexts.key')) ?></label>
                <select class="form-select" id="lt-key" name="key" required>
                    <?php foreach ($keys as $k => $label) : ?>
                        <option value="<?= esc($k, 'attr') ?>"<?= $values['key'] === $k ? ' selected' : '' ?>><?= esc(lang($label)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="lt-language"><?= esc(lang('LegalTexts.language')) ?></label>
                <select class="form-select" id="lt-language" name="language" required>
                    <?php foreach ($langs as $l) : ?>
                        <option value="<?= esc($l, 'attr') ?>"<?= $values['language'] === $l ? ' selected' : '' ?>><?= esc($l) ?></option>
                    <?php endforeach ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="lt-mode"><?= esc(lang('LegalTexts.billingMode')) ?></label>
                <select class="form-select" id="lt-mode" name="billing_mode">
                    <?php foreach ($modes as $m => $label) : ?>
                        <option value="<?= esc($m, 'attr') ?>"<?= (string) $values['billing_mode'] === $m ? ' selected' : '' ?>><?= esc(lang($label)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </fieldset>

        <div class="mb-3">
            <label class="form-label" for="lt-body"><?= esc(lang('LegalTexts.body')) ?></label>
            <textarea class="form-control lt-body<?= $error !== null ? ' is-invalid' : '' ?>" id="lt-body" name="body" rows="8" maxlength="2000" required
                      aria-describedby="lt-body-hint<?= $error !== null ? ' lt-body-error' : '' ?>"><?= esc((string) $values['body']) ?></textarea>
            <?php if ($error !== null) : ?><div class="invalid-feedback" id="lt-body-error"><?= esc($error) ?></div><?php endif ?>
            <div class="form-text" id="lt-body-hint"><?= esc(lang('LegalTexts.bodyHint')) ?></div>
        </div>

        <label class="lt-check mb-1" for="lt-confirmed">
            <input class="form-check-input mt-0" type="checkbox" id="lt-confirmed" name="confirmed" value="1"<?= $values['confirmed'] === true ? ' checked' : '' ?>>
            <span><?= esc(lang('LegalTexts.confirmedLabel')) ?></span>
        </label>
        <p class="small text-body-secondary mb-2"><?= esc(lang('LegalTexts.confirmedHint')) ?></p>
        <p class="small text-body-secondary"><?= esc(lang('LegalTexts.immutableHint')) ?></p>

        <div class="master-sticky-actions master-actions">
            <a class="btn btn-outline-secondary btn-touch" href="/settings/legal-texts"><?= esc(lang('LegalTexts.backToList')) ?></a>
            <button type="submit" class="btn btn-primary btn-touch"><?= esc(lang('LegalTexts.addVariant')) ?></button>
        </div>
    </form>
</section>
<?= $this->endSection() ?>
