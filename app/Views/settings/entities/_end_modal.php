<?php
/**
 * @var array<string, mixed> $entity
 * @var string               $today
 * @var string|null          $endError
 */
?>
<div class="modal fade" id="end-entity" tabindex="-1" aria-labelledby="end-entity-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/settings/entities/<?= (int) $entity['id'] ?>/end" data-submit-once novalidate>
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h3 class="modal-title h5" id="end-entity-title"><?= esc(lang('Entities.endButton')) ?></h3>
                </div>
                <div class="modal-body">
                    <p class="mb-3"><?= esc(lang('Entities.endConfirm', [(string) $entity['name_internal']])) ?></p>
                    <label class="form-label" for="end-active_to"><?= esc(lang('Entities.activeTo')) ?></label>
                    <input class="form-control" type="date" id="end-active_to" name="active_to" value="<?= esc($today, 'attr') ?>" min="<?= esc((string) $entity['active_from'], 'attr') ?>">
                </div>
                <div class="modal-footer flex-column flex-sm-row">
                    <button type="submit" class="btn btn-danger w-100 w-sm-auto"><?= esc(lang('Entities.endButton')) ?></button>
                    <button type="button" class="btn btn-outline-secondary w-100 w-sm-auto" data-bs-dismiss="modal"><?= esc(lang('Entities.cancel')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
