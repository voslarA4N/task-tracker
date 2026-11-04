<?php

/**
 * Tlacidla exportu prehladu (D-08). Obycajny odkaz so stiahnutim, nie htmx.
 *
 * @var string $csvUrl
 */
?>
<div class="rpt-export d-flex align-items-center gap-2 mb-3">
    <span class="small text-body-secondary"><?= esc(lang('Export.label')) ?></span>
    <a class="btn btn-outline-secondary btn-touch" href="<?= esc($csvUrl) ?>" download><?= esc(lang('Export.csv')) ?></a>
</div>
