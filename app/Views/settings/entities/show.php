<?php
use App\Libraries\Master\Country;
use App\Libraries\Master\DocLanguage;
use App\Libraries\Master\VatRegime;

/**
 * @var array<string, mixed> $entity
 * @var array<string, mixed> $version
 * @var Country              $country
 */
$dash   = lang('Entities.emptyValue');
$show   = static fn (mixed $v): string => $v === null || $v === '' ? lang('Entities.emptyValue') : (string) $v;
$isPl   = $country === Country::PL;
$lang   = DocLanguage::tryFrom((string) $version['doc_language']);
$regime = VatRegime::tryFrom((string) $version['vat_regime']);
$rows   = [
    [lang('Entities.tradeName'), $show($version['trade_name'])],
    [lang('Entities.addressLine1'), $show(trim((string) $version['address_line1'] . ' ' . (string) ($version['address_line2'] ?? '')))],
    [lang('Entities.postalCode'), $show($version['postal_code'])],
    [lang('Entities.city'), $show($version['city'])],
    [$isPl ? lang('Entities.companyIdPl') : lang('Entities.companyIdSk'), $show($version['company_id'])],
];

if ($isPl) {
    $rows[] = [lang('Entities.regon'), $show($version['regon'])];
}

$rows[] = [lang('Entities.taxId'), $show($version['tax_id'])];
$rows[] = [lang('Entities.vatId'), $show($version['vat_id'])];
$rows[] = [lang('Entities.registryNote'), $show($version['registry_note'])];
$rows[] = [lang('Entities.email'), $show($version['email'])];
$rows[] = [lang('Entities.phone'), $show($version['phone'])];
$rows[] = [lang('Entities.docLanguage'), $lang === null ? $dash : $lang->label()];
$rows[] = [lang('Entities.vatRegime'), $regime === null ? $dash : $regime->label()];
$rows[] = [lang('Entities.dueDays'), $show($version['due_days'])];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Entities.detailTitle')) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/more"><?= esc(lang('Entities.backToMore')) ?></a></p>

<section class="app-card" aria-labelledby="entity-name">
    <h1 class="h4" id="entity-name">
        <?= esc((string) $entity['name_internal']) ?>
        <span class="badge text-bg-secondary"><?= esc(lang('Entities.' . $status)) ?></span>
    </h1>
    <div class="master-actions">
        <a class="btn btn-primary btn-touch" href="/settings/entities/<?= (int) $entity['id'] ?>/versions/new"><?= esc(lang('Entities.newVersion')) ?></a>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-12 col-md-6">
            <div class="small text-body-secondary"><?= esc(lang('Entities.country')) ?></div>
            <div class="master-fixed"><?= esc($country->label()) ?></div>
        </div>
        <div class="col-12 col-md-6">
            <div class="small text-body-secondary"><?= esc(lang('Entities.currency')) ?></div>
            <div class="master-fixed"><?= esc((string) $entity['currency']) ?></div>
        </div>
    </div>
    <div class="small text-body-secondary mt-1"><?= esc(lang('Entities.fixedHint')) ?></div>
    <dl class="master-kv mt-3 mb-0">
        <dt><?= esc(lang('Entities.activeFrom')) ?></dt>
        <dd><?= esc($activeFrom) ?></dd>
        <dt><?= esc(lang('Entities.activeTo')) ?></dt>
        <dd><?= esc($activeTo ?? $dash) ?></dd>
    </dl>
</section>

<section class="app-card security-card" aria-labelledby="entity-current">
    <h2 class="h5" id="entity-current"><?= esc(lang('Entities.currentData')) ?></h2>
    <div class="small text-body-secondary mb-2"><?= esc(lang('Entities.validFromLine', [$validFrom])) ?></div>
    <dl class="master-kv mb-0">
        <?php foreach ($rows as [$label, $value]) : ?>
            <dt><?= esc($label) ?></dt>
            <dd><?= esc($value) ?></dd>
        <?php endforeach ?>
    </dl>
</section>
<?= $this->include('settings/entities/_history') ?>
<?= $this->endSection() ?>
