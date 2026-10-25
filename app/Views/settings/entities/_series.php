<?php
use App\Libraries\Master\InvalidSeriesFormat;
use App\Libraries\Master\SeriesFormat;

/**
 * @var array<string, mixed>                    $entity
 * @var array<string, array<string, mixed>>     $series
 * @var array<string, bool>                     $seriesLocked
 * @var string                                  $today
 */
$eid    = (int) $entity['id'];
$labels = ['invoice' => 'Entities.seriesInvoices', 'credit_note' => 'Entities.seriesCreditNotes'];
?>
<section class="app-card" aria-labelledby="entity-series">
    <h2 class="h5" id="entity-series"><?= esc(lang('Entities.seriesTitle')) ?></h2>
    <?php foreach ($series as $kind => $row) : ?>
        <?php
        $initial = (int) $row['initial_number'];
        $sample  = lang('Entities.emptyValue');
        $monthly = false;

        try {
            $parsed  = SeriesFormat::parse((string) $row['format']);
            $monthly = $parsed->resetsMonthly();
            $sample  = $parsed->render($today, min($initial, $parsed->maxSequence()));
        } catch (InvalidSeriesFormat) {
        }

        $locked = $seriesLocked[$kind] ?? false;
        ?>
        <div class="master-row flex-column">
            <div class="fw-semibold"><?= esc(lang($labels[$kind])) ?></div>
            <div class="master-iban"><?= esc((string) $row['format']) ?></div>
            <div class="small text-body-secondary">
                <?= esc(lang('Entities.seriesPreviewLine', [$sample])) ?>
                · <?= esc(lang('Entities.seriesInitialLine', [(string) $initial])) ?>
                · <?= esc(lang('Entities.seriesResetLine', [lang($monthly ? 'Entities.seriesResetMonthly' : 'Entities.seriesResetYearly')])) ?>
            </div>
            <div class="master-actions">
                <a class="btn btn-outline-secondary btn-touch<?= $locked ? ' disabled' : '' ?>" href="/settings/entities/<?= $eid ?>/series/<?= esc($kind, 'attr') ?>/edit"<?= $locked ? ' aria-disabled="true" tabindex="-1"' : '' ?>><?= esc(lang('Entities.seriesEdit')) ?></a>
            </div>
            <?php if ($locked) : ?>
                <div class="small text-body-secondary"><?= esc(lang('Entities.seriesLockedHint')) ?></div>
            <?php endif ?>
        </div>
    <?php endforeach ?>
</section>
