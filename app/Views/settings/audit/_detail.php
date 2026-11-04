<?php

/**
 * Detail záznamu: pole, pred, po (D-13).
 *
 * @var array<string, mixed>                                                      $row
 * @var list<array{key: string, before: ?string, after: ?string, changed: bool}> $fields
 * @var string                                                                    $time_label
 * @var string                                                                    $entity_label
 * @var string                                                                    $action_label
 */
$user = $row['user_label'] !== null ? (string) $row['user_label'] : lang('AuditLog.userSystem');
$dash = lang('AuditLog.emptyValue');
?>
<p class="small text-body-secondary mb-3"><?= esc(lang('AuditLog.detailMeta', [$time_label, $user, $action_label, $entity_label . ' #' . (int) $row['entity_id']])) ?></p>
<div class="dt-only-lg">
    <table class="table dt-table">
        <thead>
            <tr>
                <th scope="col"><?= esc(lang('AuditLog.colField')) ?></th>
                <th scope="col"><?= esc(lang('AuditLog.colBefore')) ?></th>
                <th scope="col"><?= esc(lang('AuditLog.colAfter')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fields as $f) : ?>
                <tr>
                    <th scope="row" class="fw-semibold"><?= esc($f['key']) ?><?php if ($f['changed']) : ?> <span class="small text-body-secondary"><?= esc(lang('AuditLog.changed')) ?></span><?php endif ?></th>
                    <td><div class="aud-diff<?= $f['changed'] ? ' aud-changed' : '' ?>"><?= esc($f['before'] ?? $dash) ?></div></td>
                    <td><div class="aud-diff<?= $f['changed'] ? ' aud-changed' : '' ?>"><?= esc($f['after'] ?? $dash) ?></div></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
<div class="dt-only-sm">
    <?php foreach ($fields as $f) : ?>
        <div class="mb-3">
            <p class="fw-semibold mb-1"><?= esc($f['key']) ?><?php if ($f['changed']) : ?> <span class="small text-body-secondary"><?= esc(lang('AuditLog.changed')) ?></span><?php endif ?></p>
            <p class="small text-body-secondary mb-0"><?= esc(lang('AuditLog.colBefore')) ?></p>
            <div class="aud-diff<?= $f['changed'] ? ' aud-changed' : '' ?> mb-1"><?= esc($f['before'] ?? $dash) ?></div>
            <p class="small text-body-secondary mb-0"><?= esc(lang('AuditLog.colAfter')) ?></p>
            <div class="aud-diff<?= $f['changed'] ? ' aud-changed' : '' ?>"><?= esc($f['after'] ?? $dash) ?></div>
        </div>
    <?php endforeach ?>
</div>
