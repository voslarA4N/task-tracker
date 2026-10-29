<?php
/**
 * @var string|null $error
 * @var string      $subject
 * @var string      $text
 */
?>
<?php if ($error !== null) : ?>
    <div class="alert alert-danger small" role="alert"><?= esc($error) ?></div>
<?php else : ?>
    <div class="app-card et-preview-box">
        <p class="small text-body-secondary mb-1"><?= esc(lang('EmailTemplates.sampleValues')) ?></p>
        <p class="fw-semibold mb-2"><?= esc($subject) ?></p>
        <div class="et-preview-text"><?= esc($text) ?></div>
    </div>
<?php endif ?>
