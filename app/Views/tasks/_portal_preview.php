<?php

/**
 * Zivy nahlad odkazu na ulohu: vzdy iba text, nikdy klikatelny odkaz (T-04-30).
 *
 * @var string $preview
 */
$preview = (string) ($preview ?? '');
?>
<div id="portal-preview" aria-live="polite">
    <?php if ($preview !== '') : ?>
        <p class="small text-body-secondary mb-0"><?= esc(lang('Tasks.previewLabel')) ?> <code><?= esc($preview) ?></code></p>
    <?php endif ?>
</div>
