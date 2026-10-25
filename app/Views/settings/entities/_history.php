<?php
use App\Libraries\Master\DocLanguage;
use App\Libraries\Master\VatRegime;

/**
 * @var list<array<string, mixed>> $history      položky z EntityVersionResolver::history + from_label, changed_labels
 * @var array<string, string>      $fieldLabels  popisky polí
 */
$dash = lang('Entities.emptyValue');
$show = static function (string $field, ?string $v) use ($dash): string {
    if ($v === null || $v === '') {
        return $dash;
    }

    return match ($field) {
        'doc_language' => DocLanguage::tryFrom($v)?->label() ?? $v,
        'vat_regime'   => VatRegime::tryFrom($v)?->label() ?? $v,
        default        => $v,
    };
};
?>
<section class="app-card" aria-labelledby="entity-history">
    <h2 class="h5" id="entity-history"><?= esc(lang('Entities.historyTitle')) ?></h2>
    <?php if (count($history) === 1) : ?>
        <p class="small text-body-secondary mb-0"><?= esc(lang('Entities.historyEmpty', [$history[0]['from_label']])) ?></p>
    <?php else : ?>
        <?php foreach ($history as $item) : ?>
            <div class="master-row flex-column">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <strong><?= esc(lang('Entities.historyFrom', [$item['from_label']])) ?></strong>
                    <?php if ($item['is_current']) : ?>
                        <span class="badge text-bg-success"><?= esc(lang('Entities.badgeCurrent')) ?></span>
                    <?php elseif ($item['is_future']) : ?>
                        <span class="badge text-bg-light border"><?= esc(lang('Entities.badgeFuture')) ?></span>
                    <?php endif ?>
                </div>
                <div class="small text-body-secondary">
                    <?= $item['changed'] === []
                        ? esc(lang('Entities.historyFirstVersion'))
                        : esc(lang('Entities.historyChanged', [implode(', ', array_map(static fn (string $f): string => $fieldLabels[$f] ?? $f, $item['changed']))])) ?>
                </div>
                <div>
                    <button class="btn btn-link btn-touch p-0" type="button" data-bs-toggle="collapse"
                            data-bs-target="#version-<?= (int) $item['id'] ?>" aria-expanded="false" aria-controls="version-<?= (int) $item['id'] ?>">
                        <?= esc(lang('Entities.historyShow')) ?>
                    </button>
                </div>
                <div class="collapse" id="version-<?= (int) $item['id'] ?>">
                    <dl class="master-kv mt-2">
                        <?php foreach ($item['values'] as $field => $value) : ?>
                            <?php $isChanged = in_array($field, $item['changed'], true); ?>
                            <dt><?= esc($fieldLabels[$field] ?? $field) ?></dt>
                            <dd<?= $isChanged ? ' class="master-changed"' : '' ?>>
                                <?= esc($show($field, $value)) ?>
                                <?php if ($isChanged) : ?>
                                    <span class="badge text-bg-secondary"><?= esc(lang('Entities.badgeChanged')) ?></span>
                                <?php endif ?>
                            </dd>
                        <?php endforeach ?>
                    </dl>
                    <a class="btn btn-outline-secondary btn-touch" href="/settings/entities/<?= (int) $entity['id'] ?>/versions/new?from=<?= (int) $item['id'] ?>"><?= esc(lang('Entities.newFromThis')) ?></a>
                </div>
            </div>
        <?php endforeach ?>
    <?php endif ?>
</section>
