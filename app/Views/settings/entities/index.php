<?php
/**
 * @var list<array<string, mixed>> $entities
 */
$badge = [
    'active'   => ['text-bg-success', 'Entities.statusActive'],
    'prepared' => ['text-bg-light border', 'Entities.statusPrepared'],
    'ended'    => ['text-bg-secondary', 'Entities.statusEnded'],
];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Entities.listTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/more"><?= esc(lang('Entities.backToMore')) ?></a></p>
<h1 class="h4"><?= esc(lang('Entities.listTitle')) ?></h1>

<section class="app-card" aria-label="<?= esc(lang('Entities.listTitle')) ?>">
    <?php if ($entities === []) : ?>
        <p class="fw-semibold mb-1"><?= esc(lang('Entities.emptyHeading')) ?></p>
        <p class="text-muted mb-0"><?= esc(lang('Entities.emptyBody')) ?></p>
    <?php else : ?>
        <?php foreach ($entities as $entity) : ?>
            <?php [$cls, $key] = $badge[$entity['status']]; ?>
            <a class="master-row text-decoration-none text-reset d-block" href="/settings/entities/<?= (int) $entity['id'] ?>">
                <div>
                    <span class="fw-semibold"><?= esc((string) $entity['name_internal']) ?></span>
                    <span class="badge <?= esc($cls) ?>"><?= esc(lang($key)) ?></span>
                </div>
                <div class="small text-body-secondary"><?= esc($entity['country_label'] . ' · ' . $entity['currency'] . ' · ' . $entity['regime_label']) ?></div>
                <div class="small text-body-secondary"><?= esc((string) $entity['period']) ?></div>
            </a>
        <?php endforeach ?>
    <?php endif ?>
</section>

<div class="master-actions mt-3">
    <a class="btn btn-primary" href="/settings/entities/new"><?= esc(lang('Entities.addButton')) ?></a>
</div>
<?= $this->endSection() ?>
