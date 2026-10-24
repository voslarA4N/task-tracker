<?php $codesError = $codesError ?? null; ?>
<section class="app-card security-card" aria-labelledby="sec-2fa">
    <h2 class="h5" id="sec-2fa"><?= esc(lang('Account.twofaHeading')) ?></h2>
    <p class="mb-1"><span class="badge text-bg-success"><?= esc(lang('Account.twofaActive')) ?></span></p>
    <p class="mb-3"><?= esc(lang('Account.codesRemaining', [$remainingCodes])) ?></p>

    <?php if (! empty($codesError)) : ?>
        <div class="alert alert-danger" role="alert" id="codes-error"><?= esc((string) $codesError) ?></div>
    <?php endif ?>

    <button type="button" class="btn btn-outline-danger btn-touch" data-bs-toggle="modal" data-bs-target="#regenerate-codes"><?= esc(lang('Account.regenerate')) ?></button>

    <div class="modal fade" id="regenerate-codes" tabindex="-1" aria-labelledby="regenerate-codes-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="/settings/security/backup-codes" data-submit-once novalidate>
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <h3 class="modal-title h5" id="regenerate-codes-title"><?= esc(lang('Account.regenerate')) ?></h3>
                    </div>
                    <div class="modal-body">
                        <p><?= esc(lang('Account.regenerateConfirm')) ?></p>
                        <label for="regen-password" class="form-label"><?= esc(lang('Account.currentPassword')) ?></label>
                        <input type="password" id="regen-password" name="current_password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <div class="modal-footer flex-column flex-sm-row">
                        <button type="submit" class="btn btn-danger w-100 w-sm-auto"><?= esc(lang('Account.regenerateSubmit')) ?></button>
                        <button type="button" class="btn btn-outline-secondary w-100 w-sm-auto" data-bs-dismiss="modal"><?= esc(lang('Account.cancel')) ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
