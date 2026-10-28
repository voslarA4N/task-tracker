<?php
/**
 * @var array<string, mixed> $text
 * @var string               $body
 * @var bool                 $provisional
 * @var int                  $revision
 * @var string|null          $error
 * @var bool                 $stale
 */
$id       = (int) $text['id'];
$modeKeys = ['' => 'LegalTexts.modeGeneral', 'domestic' => 'LegalTexts.modeDomestic', 'eu_b2b' => 'LegalTexts.modeEuB2b', 'non_eu' => 'LegalTexts.modeNonEu'];
$noteKeys = ['vat_note' => 'LegalTexts.keyVatNote', 'invoice_note' => 'LegalTexts.keyInvoiceNote'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('LegalTexts.editTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/settings/legal-texts"><?= esc(lang('LegalTexts.backToList')) ?></a></p>

<section class="app-card" aria-labelledby="lt-title">
    <h1 class="h4 mb-3" id="lt-title"><?= esc(lang('LegalTexts.editTitle')) ?></h1>

    <dl class="master-kv small mb-3">
        <dt><?= esc(lang('LegalTexts.key')) ?></dt>
        <dd><?= esc(lang($noteKeys[$text['key']] ?? 'LegalTexts.body')) ?></dd>
        <dt><?= esc(lang('LegalTexts.language')) ?></dt>
        <dd><?= esc((string) $text['language']) ?></dd>
        <dt><?= esc(lang('LegalTexts.billingMode')) ?></dt>
        <dd><?= esc(lang($modeKeys[(string) $text['billing_mode']] ?? 'LegalTexts.modeGeneral')) ?></dd>
        <dt><?= esc(lang('LegalTexts.revision')) ?></dt>
        <dd><?= (int) $text['revision'] ?></dd>
        <dt><?= esc(lang('LegalTexts.updatedAt')) ?></dt>
        <dd><?= esc(substr((string) $text['updated_at'], 0, 16)) ?></dd>
    </dl>

    <?php if ($stale) : ?>
        <div class="alert alert-danger" role="alert"><?= esc(lang('LegalTexts.errorStale')) ?></div>
        <div class="mb-3">
            <div class="form-label" id="lt-current-label"><?= esc(lang('LegalTexts.currentBody')) ?></div>
            <div class="form-control bg-body-secondary text-break" aria-labelledby="lt-current-label" id="lt-current-body"><?= nl2br(esc((string) $text['body'])) ?></div>
        </div>
    <?php endif ?>

    <form method="post" action="/settings/legal-texts/<?= $id ?>" data-submit-once novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="revision" value="<?= (int) $revision ?>">

        <div class="mb-3">
            <label class="form-label" for="lt-body"><?= esc(lang('LegalTexts.body')) ?></label>
            <textarea class="form-control lt-body<?= $error !== null ? ' is-invalid' : '' ?>" id="lt-body" name="body" rows="8" maxlength="2000" required
                      aria-describedby="lt-body-hint<?= $error !== null ? ' lt-body-error' : '' ?>"><?= esc($body) ?></textarea>
            <?php if ($error !== null) : ?><div class="invalid-feedback" id="lt-body-error"><?= esc($error) ?></div><?php endif ?>
            <div class="form-text" id="lt-body-hint"><?= esc(lang('LegalTexts.bodyHint')) ?></div>
        </div>

        <label class="lt-check mb-1" for="lt-confirmed">
            <input class="form-check-input mt-0" type="checkbox" id="lt-confirmed" name="confirmed" value="1"<?= $provisional ? '' : ' checked' ?>>
            <span><?= esc(lang('LegalTexts.confirmedLabel')) ?></span>
        </label>
        <p class="small text-body-secondary mb-2"><?= esc(lang('LegalTexts.confirmedHint')) ?></p>
        <p class="small text-body-secondary"><?= esc(lang('LegalTexts.immutableHint')) ?></p>

        <div class="master-sticky-actions master-actions">
            <a class="btn btn-outline-secondary btn-touch" href="/settings/legal-texts"><?= esc(lang('LegalTexts.backToList')) ?></a>
            <button type="submit" class="btn btn-primary btn-touch" id="lt-save"><?= esc(lang('LegalTexts.save')) ?></button>
        </div>
    </form>
</section>
<?= $this->endSection() ?>
