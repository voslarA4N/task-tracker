<?php

declare(strict_types=1);
?>
<div class="alert alert-light border install-hint d-flex align-items-start gap-2" data-install-hint role="status" hidden>
    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0"><path d="M12 3v12M8 7l4-4 4 4M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1"/></svg>
    <p class="mb-0 flex-grow-1"><?= esc(lang('Pwa.installHint')) ?></p>
    <button type="button" class="btn-close install-hint-close" data-install-hint-close aria-label="<?= esc(lang('Pwa.close'), 'attr') ?>"></button>
</div>
