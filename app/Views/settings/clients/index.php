<?php
/**
 * @var list<array<string, mixed>> $clients
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Clients.listTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/more"><?= esc(lang('Clients.backToMore')) ?></a></p>
<h1 class="h4"><?= esc(lang('Clients.listTitle')) ?></h1>

<section class="app-card" aria-label="<?= esc(lang('Clients.listTitle')) ?>">
    <?php if ($clients === []) : ?>
        <p class="fw-semibold mb-1"><?= esc(lang('Clients.emptyHeading')) ?></p>
        <p class="text-muted mb-0"><?= esc(lang('Clients.emptyBody')) ?></p>
    <?php else : ?>
        <?php foreach ($clients as $client) : ?>
            <a class="master-row text-decoration-none text-reset d-block" href="/settings/clients/<?= (int) $client['id'] ?>/edit">
                <div><span class="fw-semibold"><?= esc((string) $client['name']) ?></span></div>
                <div class="small text-body-secondary"><?= esc((string) $client['summary']) ?></div>
            </a>
        <?php endforeach ?>
    <?php endif ?>
</section>

<div class="mt-3">
    <a class="btn btn-primary w-100" href="/settings/clients/new"><?= esc(lang('Clients.addButton')) ?></a>
</div>
<?= $this->endSection() ?>
