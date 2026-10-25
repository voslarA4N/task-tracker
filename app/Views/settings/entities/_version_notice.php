<?php
/**
 * @var string|null $future formátovaný dátum alebo null
 * @var string|null $later  formátovaný dátum alebo null
 */
$future = $future ?? null;
$later  = $later ?? null;
?>
<?php if ($future !== null) : ?>
    <div class="alert alert-info small" role="status"><?= esc(lang('Entities.noticeFuture', [$future])) ?></div>
<?php endif ?>
<?php if ($later !== null) : ?>
    <div class="alert alert-warning small" role="status"><?= esc(lang('Entities.noticeLater', [$later])) ?></div>
<?php endif ?>
