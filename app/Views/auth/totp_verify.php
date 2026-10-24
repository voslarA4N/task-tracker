<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card">
    <h2 class="h5 mb-3"><?= esc(lang('TwoFactor.verifyTitle')) ?></h2>
    <p><?= esc(lang('TwoFactor.verifyIntro')) ?></p>

    <?php if (! empty($error)) : ?>
        <div class="alert alert-danger" role="alert" id="totp-error"><?= esc($error) ?></div>
    <?php endif ?>

    <form method="post" action="/2fa/verify" data-submit-once novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="totp-code" class="form-label"><?= esc(lang('TwoFactor.codeLabel')) ?></label>
            <input type="text" id="totp-code" name="code"
                   class="form-control auth-code-input<?= ! empty($error) ? ' is-invalid' : '' ?>"
                   inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" autofocus required
                   <?= ! empty($error) ? 'aria-describedby="totp-error"' : '' ?>>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('TwoFactor.verifySubmit')) ?>
        </button>
    </form>

    <form method="post" action="/logout" class="mt-3 text-center">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-link"><?= esc(lang('TwoFactor.cancel')) ?></button>
    </form>
</div>
<?= $this->endSection() ?>
