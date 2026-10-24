<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card">
    <h2 class="h5 mb-3"><?= esc(lang('PasswordReset.title')) ?></h2>

    <?php if (! empty($sent)) : ?>
        <div class="alert alert-info" role="status"><?= esc(lang('PasswordReset.sent')) ?></div>
    <?php endif ?>
    <?php if (! empty($error)) : ?>
        <div class="alert alert-danger" role="alert" id="forgot-error"><?= esc($error) ?></div>
    <?php endif ?>

    <form method="post" action="/password/forgot" data-submit-once novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="forgot-email" class="form-label"><?= esc(lang('Login.email')) ?></label>
            <input type="email" id="forgot-email" name="email"
                   class="form-control<?= ! empty($error) ? ' is-invalid' : '' ?>"
                   autocomplete="username" autocapitalize="none" inputmode="email" autofocus required
                   value="<?= esc($email ?? '', 'attr') ?>"
                   <?= ! empty($error) ? 'aria-describedby="forgot-error"' : '' ?>>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('PasswordReset.send')) ?>
        </button>
    </form>
    <p class="mt-3 mb-0 text-center"><a href="/login" class="d-inline-block py-2"><?= esc(lang('PasswordReset.backToLogin')) ?></a></p>
</div>
<?= $this->endSection() ?>
