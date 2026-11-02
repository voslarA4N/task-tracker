<?php
/**
 * @var list<\App\Libraries\Reporting\EntityLimits>                                                                                                                                      $entities
 * @var list<array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool, revision: int}> $limits
 * @var bool                                                                                                                                                                             $hasSeed
 * @var array<int|string, string>                                                                                                                                                        $activeFrom
 * @var string                                                                                                                                                                           $locale
 */

use App\Libraries\Platform\MoneyFormat;

$countries = ['SK' => 'FxSettings.countrySK', 'PL' => 'FxSettings.countryPL', 'CZ' => 'FxSettings.countryCZ'];
$methods   = ['calendar_year' => 'Limits.methodCalendar', 'rolling_12m' => 'Limits.methodRolling', 'pro_rata_first_year' => 'Limits.methodProRata'];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Limits.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <p class="mb-2"><a class="d-inline-block py-2" href="/more"><?= esc(lang('Limits.backToMore')) ?></a></p>
    <h1 class="h4 mb-1"><?= esc(lang('Limits.title')) ?></h1>
    <p class="small text-body-secondary"><?= esc(lang('Limits.intro')) ?></p>
    <?php if ($hasSeed) : ?>
        <div class="alert alert-secondary small" role="status"><?= esc(lang('Limits.seedNotice')) ?></div>
    <?php endif ?>

    <section class="work-group mb-4">
        <h2 class="h5 fw-semibold"><?= esc(lang('Limits.sectionUsage')) ?></h2>
        <?php if ($entities === []) : ?>
            <p class="text-body-secondary"><?= esc(lang('Limits.noActiveEntities')) ?></p>
        <?php endif ?>
        <?php foreach ($entities as $entity) : ?>
            <?php
            $countryName = lang($countries[$entity->country] ?? 'FxSettings.countrySK');
            $clean       = array_filter($entity->statuses, static fn ($s): bool => $s->error === null);
            ?>
            <div class="mb-4">
                <p class="mb-2"><?= esc($entity->entityName) ?> <span class="small text-body-secondary"><?= esc(lang('Limits.entityMeta', [$entity->country, $entity->currency])) ?></span></p>
                <?php if ($entity->noLimit) : ?>
                    <div class="alert alert-info small" role="status"><?= esc(lang('Limits.alertNoLimit', [$countryName])) ?></div>
                <?php else : ?>
                    <div class="lim-cards">
                        <?php foreach ($entity->statuses as $i => $status) : ?>
                            <?= view('settings/limits/_card', [
                                'status'         => $status,
                                'strictest'      => $i === 0 && count($clean) > 1 && $status->error === null,
                                'activeFrom'     => $activeFrom[$entity->entityId] ?? null,
                                'locale'         => $locale,
                                'entityCurrency' => $entity->currency,
                            ]) ?>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </div>
        <?php endforeach ?>
    </section>

    <section class="work-group mb-4">
        <h2 class="h5 fw-semibold"><?= esc(lang('Limits.sectionTable')) ?></h2>
        <?php if ($limits === []) : ?>
            <div class="text-center py-5">
                <h3 class="h5 fw-semibold"><?= esc(lang('Limits.emptyTitle')) ?></h3>
                <p class="text-body-secondary"><?= esc(lang('Limits.emptyBody')) ?></p>
            </div>
        <?php else : ?>
            <div class="app-card p-0">
                <?php foreach ($limits as $limit) : ?>
                    <div class="lim-row border-bottom">
                        <div>
                            <div><?= esc($limit['name']) ?>
                                <?php if ($limit['is_seed']) : ?>
                                    <span class="badge text-bg-light border lim-draft"><?= esc(lang('Limits.badgeDraft')) ?></span>
                                <?php endif ?>
                            </div>
                            <div class="small text-body-secondary"><?= esc(lang('Limits.rowMeta', [
                                $limit['country'],
                                (string) $limit['year'],
                                MoneyFormat::format($limit['amount'], $limit['currency'], $locale),
                                lang($methods[$limit['calc_method']] ?? 'Limits.methodCalendar'),
                                lang('Limits.thresholdsList', [implode(', ', $limit['thresholds'])]),
                            ])) ?></div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </section>
</div>
<?= $this->endSection() ?>
