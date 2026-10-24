<?php $passwordError = $passwordError ?? null; ?>
<section class="app-card security-card" aria-labelledby="sec-password">
    <h2 class="h5" id="sec-password"><?= esc(lang('Account.passwordHeading')) ?></h2>

    <?php if (! empty($passwordError)) : ?>
        <div class="alert alert-danger" role="alert" id="password-error"><?= esc((string) $passwordError) ?></div>
    <?php endif ?>

    <form method="post" action="/settings/security/password" data-submit-once novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="pw-current" class="form-label"><?= esc(lang('Account.currentPassword')) ?></label>
            <input type="password" id="pw-current" name="current_password" class="form-control" autocomplete="current-password" required>
        </div>
        <div class="mb-3">
            <label for="pw-new" class="form-label"><?= esc(lang('Account.newPassword')) ?></label>
            <input type="password" id="pw-new" name="password" class="form-control" autocomplete="new-password" required aria-describedby="pw-help">
            <div class="form-text" id="pw-help"><?= esc(lang('Account.passwordHelp')) ?></div>
        </div>
        <div class="mb-3">
            <label for="pw-confirm" class="form-label"><?= esc(lang('Account.newPasswordConfirm')) ?></label>
            <input type="password" id="pw-confirm" name="password_confirm" class="form-control" autocomplete="new-password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-touch">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('Account.changePassword')) ?>
        </button>
    </form>
</section>
