<?php
/**
 * @var list<array<string, mixed>> $groups
 * @var int                         $provisionalCount
 */
$groupKeys = [
    'sk_non_vat'      => 'LegalTexts.groupSkNonVat',
    'cz_non_vat'      => 'LegalTexts.groupCzNonVat',
    'pl_exempt_113_1' => 'LegalTexts.groupPlExempt1131',
    'pl_exempt_113_9' => 'LegalTexts.groupPlExempt1139',
];
$modeKeys = [
    ''         => 'LegalTexts.modeGeneral',
    'domestic' => 'LegalTexts.modeDomestic',
    'eu_b2b'   => 'LegalTexts.modeEuB2b',
    'non_eu'   => 'LegalTexts.modeNonEu',
];
$noteKeys = ['vat_note' => 'LegalTexts.keyVatNote', 'invoice_note' => 'LegalTexts.keyInvoiceNote'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('LegalTexts.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/more"><?= esc(lang('LegalTexts.backToMore')) ?></a></p>
<h1 class="h4 mb-1"><?= esc(lang('LegalTexts.title')) ?></h1>
<p class="small text-body-secondary"><?= esc(lang('LegalTexts.intro')) ?></p>
<p class="small"><a href="/settings/fx"><?= esc(lang('FxSettings.navTitle')) ?></a></p>
<p><a class="btn btn-primary btn-touch w-100 w-md-auto" href="/settings/legal-texts/new"><?= esc(lang('LegalTexts.addVariant')) ?></a></p>

<?php if ($provisionalCount > 0) : ?>
    <div class="alert alert-warning small" role="status"><?= esc(lang('LegalTexts.warnProvisional', [$provisionalCount])) ?></div>
<?php endif ?>

<?php if ($groups === []) : ?>
    <section class="app-card">
        <p class="fw-semibold mb-1"><?= esc(lang('LegalTexts.emptyHeading')) ?></p>
        <p class="text-muted mb-0"><?= esc(lang('LegalTexts.emptyBody')) ?></p>
    </section>
<?php endif ?>

<?php foreach ($groups as $group) : ?>
    <section class="app-card lt-group">
        <?php
        $country = (string) $group['country'];
        $regime  = (string) $group['vat_regime'];
        ?>
        <h2 class="h6"><?= esc(lang($groupKeys[$regime] ?? 'LegalTexts.title')) ?></h2>
        <dl class="master-kv small mb-1">
            <?php foreach ($group['profiles'] as $profile) : ?>
                <dt><?= esc(lang($modeKeys[(string) $profile['billing_mode']] ?? 'LegalTexts.modeGeneral')) ?></dt>
                <dd><?= esc(lang('LegalTexts.exemptionCode')) ?>: <span class="master-iban"><?= esc($profile['exemption_code'] ?? lang('LegalTexts.noExemption')) ?></span>,
                    <?= esc(lang('LegalTexts.vatCode')) ?>: <span class="master-iban"><?= esc($profile['vat_code'] ?? lang('LegalTexts.noExemption')) ?></span></dd>
            <?php endforeach ?>
        </dl>
        <p class="small text-body-secondary"><?= esc(lang('LegalTexts.codesHint')) ?></p>

        <?php foreach ($group['texts'] as $text) : ?>
            <a class="lt-row text-decoration-none text-reset" href="/settings/legal-texts/<?= (int) $text['id'] ?>/edit">
                <span class="lt-main">
                    <span class="d-block"><span class="fw-semibold"><?= esc(lang($noteKeys[$text['key']] ?? 'LegalTexts.body')) ?></span> <span class="text-body-secondary"><?= esc((string) $text['language']) ?></span></span>
                    <span class="d-block small text-body-secondary"><?= esc(lang('LegalTexts.modeLine')) ?>: <?= esc(lang($modeKeys[(string) $text['billing_mode']] ?? 'LegalTexts.modeGeneral')) ?> · <?= esc(lang('LegalTexts.revisionN', [(int) $text['revision']])) ?> · <?= esc(lang('LegalTexts.updatedOn', [substr((string) $text['updated_at'], 0, 10)])) ?></span>
                    <span class="d-none d-md-block small text-truncate"><?= esc((string) $text['body']) ?></span>
                </span>
                <?php if ($text['provisional'] === true) : ?>
                    <span class="badge lt-badge text-bg-warning"><?= esc(lang('LegalTexts.badgeProvisional')) ?></span>
                <?php else : ?>
                    <span class="badge lt-badge text-bg-light border"><?= esc(lang('LegalTexts.badgeConfirmed')) ?></span>
                <?php endif ?>
            </a>
        <?php endforeach ?>

        <?php if ($group['noteMissing'] === true) : ?>
            <p class="small text-body-secondary mt-2 mb-0"><?= esc(lang('LegalTexts.noDefaultNote')) ?></p>
        <?php endif ?>

        <?php foreach ($group['effective'] as $row) : ?>
            <div class="lt-effective mt-2">
                <?php if ($row['effective'] === null) : ?>
                    <span class="badge text-bg-danger"><?= esc(lang('LegalTexts.badgeMissing')) ?></span>
                    <?= esc(lang('LegalTexts.missingLine', [$row['language'], lang($modeKeys[$row['mode']] ?? 'LegalTexts.modeGeneral')])) ?>
                <?php else : ?>
                    <span class="badge text-bg-secondary"><?= esc(lang('LegalTexts.badgeFallback')) ?></span>
                    <?= esc(lang('LegalTexts.effectiveLine', [$row['language'], lang($modeKeys[$row['mode']] ?? 'LegalTexts.modeGeneral'), lang('LegalTexts.keyVatNote'), $row['effective']['language'], lang($modeKeys[(string) $row['effective']['billing_mode']] ?? 'LegalTexts.modeGeneral'), $row['effective']['revision']])) ?>
                <?php endif ?>
                <a href="<?= esc($row['newUrl'], 'attr') ?>"><?= esc(lang('LegalTexts.addVariantLink')) ?></a>
            </div>
        <?php endforeach ?>
    </section>
<?php endforeach ?>
<?= $this->endSection() ?>
