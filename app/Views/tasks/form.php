<?php

/**
 * @var int|null                                                                  $id
 * @var array<string, string>                                                     $values
 * @var array<string, string>                                                     $errors
 * @var list<array<string, mixed>>                                                $clients
 * @var array<string, mixed>|null                                                 $client
 * @var bool                                                                      $lockedClient
 * @var array{symbol: string, decimal: string, group: string, symbolBefore: bool} $format
 * @var string                                                                    $sum
 * @var string                                                                    $preview
 */

use App\Libraries\Platform\MoneyFormat;

$errors ??= [];
$v        = static fn (string $k): string => (string) ($values[$k] ?? '');
$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="f-' . esc($name, 'attr') . '-error"' : '';
$feedback = static fn (string $name): string => isset($errors[$name])
    ? '<div class="invalid-feedback d-block" id="f-' . esc($name, 'attr') . '-error">' . esc($errors[$name]) . '</div>'
    : '';
$invalid = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';

$action = $id === null ? '/tasks' : '/tasks/' . $id;
$title  = lang($id === null ? 'Tasks.newTitle' : 'Tasks.editTitle');
$cancel = $id === null ? '/tasks' : '/tasks/' . $id;
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc($title) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-narrow">
<?php if ($clients === []) : ?>
    <section class="app-card text-center" aria-labelledby="task-no-client">
        <h1 class="h4 mb-2" id="task-no-client"><?= esc(lang('Tasks.noClientHeading')) ?></h1>
        <?php if (isset($errors['client_id'])) : ?>
            <div class="alert alert-danger" role="alert"><?= esc($errors['client_id']) ?></div>
        <?php endif ?>
        <p class="text-body-secondary"><?= esc(lang('Tasks.noClientBody')) ?></p>
        <a class="btn btn-primary btn-touch" href="/settings/clients/new"><?= esc(lang('Tasks.addClient')) ?></a>
    </section>
<?php else : ?>
    <section class="app-card" aria-labelledby="task-form-title">
        <h1 class="h4 mb-3" id="task-form-title"><?= esc($title) ?></h1>

        <?php if ($errors !== []) : ?>
            <div class="alert alert-danger" role="alert"><?= esc(lang('Tasks.errorForm')) ?></div>
        <?php endif ?>

        <form method="post" action="<?= esc($action, 'attr') ?>" data-submit-once novalidate
              x-data="taskForm" data-hours="<?= esc($v('hours'), 'attr') ?>" data-rate="<?= esc($v('rate'), 'attr') ?>"
              data-currency-symbol="<?= esc($format['symbol'], 'attr') ?>" data-decimal="<?= esc($format['decimal'], 'attr') ?>"
              data-group="<?= esc($format['group'], 'attr') ?>" data-symbol-before="<?= $format['symbolBefore'] ? '1' : '' ?>">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label" for="f-client_id"><?= esc(lang('Tasks.client')) ?></label>
                <?php if ($lockedClient) : ?>
                    <input type="hidden" name="client_id" value="<?= esc($v('client_id'), 'attr') ?>">
                    <div class="master-fixed" id="f-client_id" aria-describedby="f-client_id-hint"><?= esc((string) ($client['name'] ?? '')) ?></div>
                    <div class="form-text" id="f-client_id-hint"><?= esc(lang('Tasks.clientLockedHint')) ?></div>
                <?php else : ?>
                    <select class="form-select<?= $invalid('client_id') ?>" id="f-client_id" name="client_id"
                            hx-get="/tasks/client-defaults" hx-trigger="change" hx-target="#rate-field" hx-swap="outerHTML" hx-include="this"<?= $describe('client_id') ?>>
                        <?php foreach ($clients as $option) : ?>
                            <option value="<?= (int) $option['id'] ?>"<?= (string) $option['id'] === $v('client_id') ? ' selected' : '' ?>><?= esc((string) $option['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                <?php endif ?>
                <?= $feedback('client_id') ?>
            </div>

            <div class="mb-3">
                <label class="form-label" for="f-url"><?= esc(lang('Tasks.url')) ?></label>
                <input class="form-control<?= $invalid('url') ?>" type="url" id="f-url" name="url" value="<?= esc($v('url'), 'attr') ?>" autocomplete="off"
                       hx-get="/tasks/portal-preview" hx-trigger="keyup changed delay:300ms" hx-target="#portal-preview" hx-swap="outerHTML" hx-include="[name=client_id],[name=url]"
                       aria-describedby="f-url-hint<?= isset($errors['url']) ? ' f-url-error' : '' ?>">
                <div class="form-text" id="f-url-hint"><?= esc(lang(($client['portal_url_template'] ?? null) !== null ? 'Tasks.urlHint' : 'Tasks.urlHintPlain')) ?></div>
                <?= $feedback('url') ?>
                <?= $this->include('tasks/_portal_preview') ?>
            </div>

            <div class="mb-3">
                <label class="form-label" for="f-title"><?= esc(lang('Tasks.title')) ?></label>
                <input class="form-control<?= $invalid('title') ?>" type="text" id="f-title" name="title" value="<?= esc($v('title'), 'attr') ?>" autocomplete="off" autocapitalize="sentences"<?= $describe('title') ?>>
                <?= $feedback('title') ?>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label" for="f-hours"><?= esc(lang('Tasks.hours')) ?></label>
                    <input class="form-control<?= $invalid('hours') ?>" type="text" inputmode="decimal" id="f-hours" name="hours" value="<?= esc($v('hours'), 'attr') ?>" autocomplete="off"
                           x-on:input="setHours"<?= $describe('hours') ?>>
                    <?= $feedback('hours') ?>
                </div>
                <div class="col-6">
                    <?= $this->include('tasks/_rate_field') ?>
                </div>
            </div>

            <div class="master-fixed mb-1">
                <?= esc(lang('Tasks.sumLabel')) ?> <strong class="tabular-nums" x-text="sumText"><?= $sum === '' ? esc(lang('Tasks.empty')) : esc(MoneyFormat::format($sum, (string) ($client['currency'] ?? 'EUR'))) ?></strong>
            </div>
            <div class="form-text mb-3"><?= esc(lang('Tasks.sumHint')) ?> <?= esc(lang('Tasks.currencyHint', [(string) ($client['currency'] ?? '')])) ?></div>

            <div class="mb-3">
                <label class="form-label" for="f-note"><?= esc(lang('Tasks.note')) ?></label>
                <textarea class="form-control<?= $invalid('note') ?>" id="f-note" name="note" rows="3" autocomplete="off"<?= $describe('note') ?>><?= esc($v('note')) ?></textarea>
                <?= $feedback('note') ?>
            </div>

            <div class="master-sticky-actions">
                <button type="submit" class="btn btn-primary btn-touch w-100">
                    <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
                    <?= esc(lang('Tasks.saveButton')) ?>
                </button>
                <a class="btn btn-link w-100" href="<?= esc($cancel, 'attr') ?>"><?= esc(lang('Tasks.cancel')) ?></a>
            </div>
        </form>
    </section>
<?php endif ?>
</div>
<?= $this->endSection() ?>
