<?php

/**
 * Fragment zoznamu denníka (#audit-list).
 *
 * @var list<array<string, mixed>>      $rows
 * @var bool                            $hasMore
 * @var App\Libraries\Audit\AuditFilter $filter
 */
$page = $filter->page;
$link = static fn (int $p): string => '/settings/audit' . (($q = http_build_query($filter->withPage($p))) === '' ? '' : '?' . $q);
?>
<div id="audit-list">
    <p class="small text-body-secondary mb-2"><?= esc(lang('AuditLog.countOnPage', [count($rows)])) ?></p>
    <?php if ($rows === []) : ?>
        <section class="app-card">
            <?php if ($filter->isActive()) : ?>
                <p class="fw-semibold mb-1"><?= esc(lang('AuditLog.emptyTitle')) ?></p>
                <p class="text-muted mb-0"><?= esc(lang('AuditLog.emptyBody')) ?></p>
            <?php else : ?>
                <p class="text-muted mb-0"><?= esc(lang('AuditLog.emptyNone')) ?></p>
            <?php endif ?>
        </section>
    <?php else : ?>
        <div class="dt-only-sm">
            <section class="app-card p-0 overflow-hidden" aria-label="<?= esc(lang('AuditLog.title'), 'attr') ?>">
                <?php foreach ($rows as $r) : ?>
                    <a class="inv-row" href="/settings/audit/<?= (int) $r['id'] ?>">
                        <span class="inv-row-title"><?= esc((string) $r['time_label']) ?></span>
                        <span class="inv-row-meta w-100"><?= esc((string) $r['action_label']) ?> · <?= esc((string) $r['entity_label']) ?> #<?= (int) $r['entity_id'] ?></span>
                        <span class="inv-row-meta w-100"><?= esc($r['user_label'] !== null ? (string) $r['user_label'] : lang('AuditLog.userSystem')) ?></span>
                    </a>
                <?php endforeach ?>
            </section>
        </div>
        <div class="dt-only-lg">
            <table class="table table-hover dt-table">
                <caption class="visually-hidden"><?= esc(lang('AuditLog.tableCaption')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= esc(lang('AuditLog.colTime')) ?></th>
                        <th scope="col"><?= esc(lang('AuditLog.colUser')) ?></th>
                        <th scope="col"><?= esc(lang('AuditLog.colAction')) ?></th>
                        <th scope="col"><?= esc(lang('AuditLog.colEntity')) ?></th>
                        <th scope="col"><?= esc(lang('AuditLog.colSummary')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= esc(lang('AuditLog.detail')) ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r) : ?>
                        <tr>
                            <td><?= esc((string) $r['time_label']) ?></td>
                            <td><?= esc($r['user_label'] !== null ? (string) $r['user_label'] : lang('AuditLog.userSystem')) ?></td>
                            <td><span class="badge text-bg-light border"><?= esc((string) $r['action_label']) ?></span></td>
                            <td>
                                <?php if ($r['entity_href'] !== null) : ?>
                                    <a href="<?= esc((string) $r['entity_href'], 'attr') ?>"><?= esc((string) $r['entity_label']) ?> #<?= (int) $r['entity_id'] ?></a>
                                <?php else : ?>
                                    <?= esc((string) $r['entity_label']) ?> #<?= (int) $r['entity_id'] ?>
                                <?php endif ?>
                            </td>
                            <td><?= esc(lang('AuditLog.changedCount', [(int) $r['changed_count']])) ?></td>
                            <td class="text-end"><a class="btn btn-outline-secondary btn-touch" href="/settings/audit/<?= (int) $r['id'] ?>" hx-get="/settings/audit/<?= (int) $r['id'] ?>" hx-target="#sheet" hx-swap="innerHTML"><?= esc(lang('AuditLog.detail')) ?></a></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
    <?php if ($page > 1 || $hasMore) : ?>
        <nav class="d-flex align-items-center justify-content-between gap-2 mt-3" aria-label="<?= esc(lang('AuditLog.tableCaption'), 'attr') ?>">
            <?php if ($page > 1) : ?>
                <a class="btn btn-outline-secondary btn-touch" href="<?= esc($link($page - 1), 'attr') ?>" hx-get="<?= esc($link($page - 1), 'attr') ?>" hx-target="#audit-list" hx-swap="outerHTML" hx-push-url="true"><?= esc(lang('AuditLog.newer')) ?></a>
            <?php else : ?>
                <span></span>
            <?php endif ?>
            <span class="small text-body-secondary"><?= esc(lang('AuditLog.pageN', [$page])) ?></span>
            <?php if ($hasMore) : ?>
                <a class="btn btn-outline-secondary btn-touch" href="<?= esc($link($page + 1), 'attr') ?>" hx-get="<?= esc($link($page + 1), 'attr') ?>" hx-target="#audit-list" hx-swap="outerHTML" hx-push-url="true"><?= esc(lang('AuditLog.older')) ?></a>
            <?php else : ?>
                <span></span>
            <?php endif ?>
        </nav>
    <?php endif ?>
</div>
