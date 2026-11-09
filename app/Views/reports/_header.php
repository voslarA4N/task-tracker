<?php

/**
 * @var list<array{id: int, name_internal: string, country: string, currency: string, ended: bool}> $entities
 * @var ?array{id: int, name_internal: string, country: string, currency: string, ended: bool}      $entity
 * @var ?int                                                                                        $entityId
 * @var string                                                                                      $view     'month'|'year'
 * @var string                                                                                      $monthKey
 * @var string                                                                                      $yearKey
 */
$entityQ  = $entityId === null ? [] : ['entity' => $entityId];
$monthUrl = '/reports/month?' . http_build_query($entityQ + ['period' => $monthKey]);
$yearUrl  = '/reports/year?' . http_build_query($entityQ + ['year' => $yearKey]);
$action   = $view === 'year' ? '/reports/year' : '/reports/month';
?>
<h1 class="h4 mb-1"><?= esc(lang('Reports.title')) ?></h1>
<p class="small text-body-secondary"><?= esc(lang('Reports.intro')) ?></p>
<ul class="nav nav-pills flex-wrap gap-2 mb-3" aria-label="<?= esc(lang('Reports.switchLabel'), 'attr') ?>">
    <li class="nav-item"><a class="nav-link text-center btn-touch<?= $view === 'month' ? ' active' : '' ?>" href="<?= esc($monthUrl) ?>"<?= $view === 'month' ? ' aria-current="page"' : '' ?>><?= esc(lang('Reports.tabMonth')) ?></a></li>
    <li class="nav-item"><a class="nav-link text-center btn-touch<?= $view === 'year' ? ' active' : '' ?>" href="<?= esc($yearUrl) ?>"<?= $view === 'year' ? ' aria-current="page"' : '' ?>><?= esc(lang('Reports.tabYear')) ?></a></li>
</ul>
<?php if (count($entities) > 1) : ?>
    <form class="mb-3" method="get" action="<?= esc($action, 'attr') ?>" hx-get="<?= esc($action, 'attr') ?>" hx-trigger="change" hx-target="#report-body" hx-swap="outerHTML" hx-push-url="true">
        <?php if ($view === 'year') : ?>
            <input type="hidden" name="year" value="<?= esc($yearKey, 'attr') ?>">
        <?php else : ?>
            <input type="hidden" name="period" value="<?= esc($monthKey, 'attr') ?>">
        <?php endif ?>
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
