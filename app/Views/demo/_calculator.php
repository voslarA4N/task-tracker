<?php
$fields = ['hours' => lang('Demo.hours'), 'rate' => lang('Demo.rate')];
?>
<form id="demo-calculator" method="post" action="/demo/calculate" hx-post="/demo/calculate" hx-target="this" hx-swap="outerHTML" novalidate>
    <?= csrf_field() ?>
    <?php foreach ($fields as $name => $label): ?>
        <?php $error = $errors[$name] ?? null; ?>
        <div class="mb-3">
            <label class="form-label" for="demo-<?= esc($name, 'attr') ?>"><?= esc($label) ?></label>
            <input class="form-control<?= $error !== null ? ' is-invalid' : '' ?>" type="text" inputmode="decimal"
                   id="demo-<?= esc($name, 'attr') ?>" name="<?= esc($name, 'attr') ?>"
                   value="<?= esc($values[$name] ?? '', 'attr') ?>"
                   <?php if ($error !== null): ?>aria-invalid="true" aria-describedby="demo-<?= esc($name, 'attr') ?>-error"<?php endif; ?>>
            <?php if ($error !== null): ?>
                <div class="invalid-feedback" id="demo-<?= esc($name, 'attr') ?>-error"><?= esc($error) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <button class="btn btn-primary w-100" type="submit">
        <?= esc(lang('Demo.submit')) ?>
        <span class="spinner-border spinner-border-sm htmx-indicator" aria-hidden="true"></span>
    </button>
    <?php if (($result ?? null) !== null): ?>
        <div class="app-card mt-3">
            <div class="small text-body-secondary"><?= esc(lang('Demo.result')) ?></div>
            <div class="h4 mb-0"><?= esc($result) ?></div>
        </div>
    <?php endif; ?>
</form>
