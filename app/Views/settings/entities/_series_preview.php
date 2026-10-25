<?php
/**
 * @var array{error: bool, samples: list<string>, monthly: bool} $preview
 */
?>
<div id="series-preview" class="form-text" aria-live="polite">
    <?php if ($preview['error']) : ?>
        <div class="invalid-feedback d-block"><?= esc(lang('Entities.errorSeriesFormat')) ?></div>
    <?php else : ?>
        <?= esc(count($preview['samples']) > 1
            ? lang('Entities.seriesPreviewTwo', [$preview['samples'][0], $preview['samples'][1]])
            : lang('Entities.seriesPreviewLine', [$preview['samples'][0]])) ?>
        · <?= esc(lang('Entities.seriesResetLabel')) ?>: <?= esc(lang($preview['monthly'] ? 'Entities.seriesResetMonthly' : 'Entities.seriesResetYearly')) ?>
    <?php endif ?>
</div>
