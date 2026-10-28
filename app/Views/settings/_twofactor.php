<?php $codesError = $codesError ?? null; $disableError = $disableError ?? null; $totpActive = $totpActive ?? false; ?>
<section class="app-card security-card" aria-labelledby="sec-2fa">
    <h2 class="h5" id="sec-2fa"><?= esc(lang('Account.twofaHeading')) ?></h2>
    <?php if (! $totpActive) : ?>
        <p class="mb-1"><span class="badge text-bg-secondary"><?= esc(lang('Account.twofaInactive')) ?></span></p>
        <p class="mb-3"><?= esc(lang('Account.twofaInactiveHelp')) ?></p>
        <a class="btn btn-primary btn-touch" href="/settings/security/2fa/setup"><?= esc(lang('Account.enable')) ?></a>
    <?php else : ?>
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

    <?php if (! empty($disableError)) : ?>
        <div class="alert alert-danger mt-3" role="alert" id="disable-error"><?= esc((string) $disableError) ?></div>
    <?php endif ?>

    <button type="button" class="btn btn-outline-danger btn-touch mt-2" data-bs-toggle="modal" data-bs-target="#disable-2fa"><?= esc(lang('Account.disable')) ?></button>

    <div class="modal fade" id="disable-2fa" tabindex="-1" aria-labelledby="disable-2fa-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="/settings/security/2fa/disable" data-submit-once novalidate>
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <h3 class="modal-title h5" id="disable-2fa-title"><?= esc(lang('Account.disable')) ?></h3>
                    </div>
                    <div class="modal-body">
                        <p><?= esc(lang('Account.disableConfirm')) ?></p>
                        <label for="disable-password" class="form-label"><?= esc(lang('Account.currentPassword')) ?></label>
                        <input type="password" id="disable-password" name="current_password" class="form-control mb-3" autocomplete="current-password" required>
                        <label for="disable-code" class="form-label"><?= esc(lang('Account.disableCodeLabel')) ?></label>
                        <input type="text" id="disable-code" name="code" class="form-control" autocomplete="one-time-code" required>
                    </div>
                    <div class="modal-footer flex-column flex-sm-row">
                        <button type="submit" class="btn btn-danger w-100 w-sm-auto"><?= esc(lang('Account.disableSubmit')) ?></button>
                        <button type="button" class="btn btn-outline-secondary w-100 w-sm-auto" data-bs-dismiss="modal"><?= esc(lang('Account.cancel')) ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif ?>
</section>
