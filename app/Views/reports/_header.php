<?php

/**
 * @var list<array{id: int, name_internal: string, country: string, currency: string, ended: bool}> $entities
 * @var ?array{id: int, name_internal: string, country: string, currency: string, ended: bool}      $entity
 * @var ?int                                                                                        $entityId
 * @var string                                                                                      $period
 */
?>
<h1 class="h4 mb-1"><?= esc(lang('Reports.title')) ?></h1>
<p class="small text-body-secondary"><?= esc(lang('Reports.intro')) ?></p>
<?php if (count($entities) > 1) : ?>
    <form class="mb-3" method="get" action="/reports/month" hx-get="/reports/month" hx-trigger="change" hx-target="#report-body" hx-swap="outerHTML" hx-push-url="true">
        <input type="hidden" name="period" value="<?= esc($period, 'attr') ?>">
        <label class="form-label small mb-1" for="rpt-entity"><?= esc(lang('Reports.entityLabel')) ?></label>
        <select name="entity" class="form-select btn-touch" id="rpt-entity">
            <?php foreach ($entities as $e) : ?>
                <option value="<?= esc((string) $e['id'], 'attr') ?>"<?= $e['id'] === $entityId ? ' selected' : '' ?>><?= esc(lang('Reports.entityOption', [$e['name_internal'], $e['currency']])) ?></option>
            <?php endforeach ?>
        </select>
        <noscript><button class="btn btn-outline-secondary btn-touch mt-2" type="submit"><?= esc(lang('Reports.entityApply')) ?></button></noscript>
    </form>
<?php elseif ($entity !== null) : ?>
    <p class="small text-body-secondary"><?= esc(lang('Reports.entityMeta', [$entity['name_internal'], $entity['currency']])) ?></p>
<?php endif ?>
