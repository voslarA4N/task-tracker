<?php
/**
 * @var array<string, list<array<string, mixed>>> $groups
 */
$kindKeys = ['invoice' => 'EmailTemplates.kindInvoice', 'reminder' => 'EmailTemplates.kindReminder'];
$langKeys = ['sk' => 'EmailTemplates.lang_sk', 'pl' => 'EmailTemplates.lang_pl', 'cs' => 'EmailTemplates.lang_cs', 'en' => 'EmailTemplates.lang_en'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('EmailTemplates.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/more"><?= esc(lang('EmailTemplates.backToMore')) ?></a></p>
<h1 class="h4 mb-1"><?= esc(lang('EmailTemplates.title')) ?></h1>
<p class="small text-body-secondary"><?= esc(lang('EmailTemplates.intro')) ?></p>
<div class="alert alert-secondary small" role="status"><?= esc(lang('EmailTemplates.fallbackInfo')) ?></div>

<?php foreach ($groups as $key => $rows) : ?>
    <section class="app-card lt-group">
        <h2 class="h6"><?= esc(lang($kindKeys[$key] ?? 'EmailTemplates.title')) ?></h2>
        <?php foreach ($rows as $row) : ?>
            <a class="lt-row text-decoration-none text-reset" href="/settings/email-templates/<?= (int) $row['id'] ?>/edit">
                <span class="lt-main">
                    <span class="d-block"><span class="fw-semibold"><?= esc(lang($langKeys[(string) $row['language']] ?? 'EmailTemplates.title')) ?></span> <span class="text-body-secondary"><?= esc((string) $row['language']) ?></span></span>
                    <span class="d-block small text-body-secondary text-truncate"><?= esc(lang('EmailTemplates.subjectLine', [(string) $row['subject']])) ?> · <?= esc(lang('EmailTemplates.revisionN', [(int) $row['revision']])) ?> · <?= esc(lang('EmailTemplates.updatedOn', [substr((string) $row['updated_at'], 0, 10)])) ?></span>
                </span>
            </a>
        <?php endforeach ?>
    </section>
<?php endforeach ?>
<?= $this->endSection() ?>
