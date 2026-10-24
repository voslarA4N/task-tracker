<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card">
    <h2 class="h5 mb-3"><?= esc(lang('PasswordReset.newTitle')) ?></h2>

    <?php if (! empty($invalid)) : ?>
        <div class="alert alert-danger" role="alert"><?= esc(lang('PasswordReset.invalid')) ?></div>
        <p class="mb-0 text-center"><a href="/password/forgot" class="d-inline-block py-2"><?= esc(lang('PasswordReset.title')) ?></a></p>
    <?php else : ?>
        <?php if (! empty($error)) : ?>
            <div class="alert alert-danger" role="alert" id="reset-error"><?= esc($error) ?></div>
        <?php endif ?>

        <form method="post" action="/password/reset/<?= esc($token, 'attr') ?>" data-submit-once novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="reset-password" class="form-label"><?= esc(lang('PasswordReset.password')) ?></label>
                <input type="password" id="reset-password" name="password"
                       class="form-control<?= ! empty($error) ? ' is-invalid' : '' ?>"
                       autocomplete="new-password" autofocus required aria-describedby="reset-help">
                <div class="form-text" id="reset-help"><?= esc(lang('PasswordReset.passwordHelp')) ?></div>
            </div>
            <div class="mb-3">
                <label for="reset-confirm" class="form-label"><?= esc(lang('PasswordReset.passwordConfirm')) ?></label>
                <input type="password" id="reset-confirm" name="password_confirm"
                       class="form-control<?= ! empty($error) ? ' is-invalid' : '' ?>"
                       autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100">
                <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
                <?= esc(lang('PasswordReset.submit')) ?>
            </button>
        </form>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
