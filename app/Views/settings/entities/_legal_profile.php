<?php
/**
 * @var list<array{label: string, code: ?string, body: ?string, provisional: bool, missing: bool}> $legalRows
 */
?>
<section class="app-card" aria-labelledby="entity-legal-title" id="entity-legal">
    <h2 class="h5" id="entity-legal-title"><?= esc(lang('Entities.legalTitle')) ?></h2>
    <?php foreach ($legalRows as $row) : ?>
        <div class="master-row flex-column">
            <div class="fw-semibold"><?= esc($row['label']) ?></div>
            <?php if ($row['missing']) : ?>
                <div class="alert alert-danger mb-0" role="alert"><?= esc(lang('Entities.legalMissing')) ?></div>
            <?php else : ?>
                <dl class="master-kv mb-0">
                    <dt><?= esc(lang('Entities.legalCode')) ?></dt>
                    <dd><?php if ($row['code'] !== null) : ?><code><?= esc($row['code']) ?></code><?php else : ?><?= esc(lang('Entities.emptyValue')) ?><?php endif ?></dd>
                    <dt><?= esc(lang('Entities.legalWording')) ?></dt>
                    <dd><?= esc((string) $row['body']) ?></dd>
                </dl>
                <?php if ($row['provisional']) : ?>
                    <div><span class="badge text-bg-warning"><?= esc(lang('Entities.legalProvisional')) ?></span></div>
                <?php endif ?>
            <?php endif ?>
        </div>
    <?php endforeach ?>
    <div class="small text-body-secondary mt-2"><?= esc(lang('Entities.legalHint')) ?></div>
</section>
