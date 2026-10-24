<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card">
    <h2 class="h5 mb-3"><?= esc(lang('Login.heading')) ?></h2>

    <?php $info = session()->getFlashdata('auth_info'); ?>
    <?php if (is_string($info) && $info !== '') : ?>
        <div class="alert alert-info" role="status"><?= esc($info) ?></div>
    <?php endif ?>
    <?php if (! empty($error)) : ?>
        <div class="alert alert-danger" role="alert" id="login-error"><?= esc($error) ?></div>
    <?php endif ?>

    <form method="post" action="/login" data-submit-once novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="login-email" class="form-label"><?= esc(lang('Login.email')) ?></label>
            <input type="email" id="login-email" name="email"
                   class="form-control<?= ! empty($error) ? ' is-invalid' : '' ?>"
                   autocomplete="username" autocapitalize="none" inputmode="email" autofocus required
                   value="<?= esc($email ?? '', 'attr') ?>"
                   <?= ! empty($error) ? 'aria-describedby="login-error"' : '' ?>>
        </div>
        <div class="mb-3">
            <label for="login-password" class="form-label"><?= esc(lang('Login.password')) ?></label>
            <input type="password" id="login-password" name="password"
                   class="form-control<?= ! empty($error) ? ' is-invalid' : '' ?>"
                   autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('Login.submit')) ?>
        </button>
    </form>
    <?php if (! empty($lockedInfo)) : ?>
        <p class="small text-muted mt-3 mb-0" aria-live="polite"><?= esc($lockedInfo) ?></p>
    <?php endif ?>
    <p class="text-secondary small mt-3 mb-0"><?= esc(lang('Login.hint')) ?></p>
</div>
<?= $this->endSection() ?>
