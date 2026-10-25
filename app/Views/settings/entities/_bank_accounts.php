<?php
use App\Libraries\Master\Iban;

/**
 * @var array<string, mixed>            $entity
 * @var bool|null                       $archivedOnly   iba fragment archivovanych uctov
 * @var list<array<string, mixed>>      $bankAccounts   aktivne ucty
 * @var list<array<string, mixed>>      $archivedList   archivovane ucty
 * @var bool|null                       $openArchived   zobrazit archivovane aj bez JS
 */
$eid          = (int) $entity['id'];
$base         = '/settings/entities/' . $eid . '/bank-accounts';
$archivedOnly = $archivedOnly ?? false;
$openArchived = $openArchived ?? false;
$archivedList = $archivedList ?? [];
$archivedRows = static function (array $rows): void {
    foreach ($rows as $acc) : ?>
        <div class="master-row flex-column">
            <div><span class="fw-semibold"><?= esc((string) $acc['label']) ?></span> <span class="badge text-bg-secondary"><?= esc(lang('Entities.badgeArchived')) ?></span></div>
            <div class="master-iban"><?= esc(Iban::format((string) $acc['iban'])) ?></div>
            <div class="small text-body-secondary"><?= esc((string) $acc['currency']) ?></div>
        </div>
    <?php endforeach;
};
?>
<?php if ($archivedOnly) : ?>
    <?php $archivedRows($archivedList) ?>
<?php else : ?>
<section class="app-card" aria-labelledby="entity-bank">
    <h2 class="h5" id="entity-bank"><?= esc(lang('Entities.bankTitle')) ?></h2>
    <?php if ($bankAccounts === []) : ?>
        <p class="fw-semibold mb-1"><?= esc(lang('Entities.bankEmptyHeading')) ?></p>
        <p class="text-muted"><?= esc(lang('Entities.bankEmptyBody')) ?></p>
    <?php else : ?>
        <?php foreach ($bankAccounts as $acc) : ?>
            <?php $aid = (int) $acc['id']; ?>
            <div class="master-row flex-column">
                <div class="fw-semibold"><?= esc((string) $acc['label']) ?></div>
                <div class="master-iban"><?= esc(Iban::format((string) $acc['iban'])) ?></div>
                <div class="small text-body-secondary">
                    <?= esc((string) $acc['currency']) ?>
                    <?php if ($acc['swift'] !== null && $acc['swift'] !== '') : ?> · <?= esc(lang('Entities.bankSwiftLine', [(string) $acc['swift']])) ?><?php endif ?>
                    <?php if ($acc['is_default']) : ?> · <span class="badge text-bg-secondary"><?= esc(lang('Entities.badgeDefault')) ?></span><?php endif ?>
                </div>
                <div class="master-actions">
                    <a class="btn btn-link btn-touch d-inline-flex align-items-center" href="<?= $base . '/' . $aid . '/edit' ?>"><?= esc(lang('Entities.bankEdit')) ?></a>
                    <?php if (! $acc['is_default']) : ?>
                        <form method="post" action="<?= $base . '/' . $aid . '/default' ?>" data-submit-once>
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-secondary btn-touch w-100"><?= esc(lang('Entities.bankSetDefault')) ?></button>
                        </form>
                    <?php endif ?>
                    <button type="button" class="btn btn-outline-danger btn-touch" data-bs-toggle="modal" data-bs-target="#archive-account-<?= $aid ?>"><?= esc(lang('Entities.bankArchive')) ?></button>
                </div>
            </div>
            <div class="modal fade" id="archive-account-<?= $aid ?>" tabindex="-1" aria-labelledby="archive-account-title-<?= $aid ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" action="<?= $base . '/' . $aid . '/archive' ?>" data-submit-once>
                            <?= csrf_field() ?>
                            <div class="modal-header">
                                <h3 class="modal-title h5" id="archive-account-title-<?= $aid ?>"><?= esc(lang('Entities.bankArchiveTitle')) ?></h3>
                            </div>
                            <div class="modal-body">
                                <p class="mb-0"><?= esc(lang('Entities.bankArchiveConfirm', [Iban::format((string) $acc['iban'])])) ?></p>
                            </div>
                            <div class="modal-footer flex-column flex-sm-row">
                                <button type="submit" class="btn btn-danger w-100 w-sm-auto"><?= esc(lang('Entities.bankArchiveSubmit')) ?></button>
                                <button type="button" class="btn btn-outline-secondary w-100 w-sm-auto" data-bs-dismiss="modal"><?= esc(lang('Entities.cancel')) ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach ?>
    <?php endif ?>

    <a class="btn btn-outline-secondary btn-touch w-100 mt-3" href="<?= $base . '/new' ?>"><?= esc(lang('Entities.bankAdd')) ?></a>

    <?php if ($archivedList !== []) : ?>
        <p class="mt-3 mb-2">
            <a href="?archived=1" hx-get="<?= $base . '/archived' ?>" hx-target="#bank-archived" hx-swap="innerHTML"><?= esc(lang('Entities.showArchived', [count($archivedList)])) ?></a>
        </p>
    <?php endif ?>
    <div id="bank-archived"><?php if ($openArchived) : ?><?php $archivedRows($archivedList) ?><?php endif ?></div>
</section>
<?php endif ?>
