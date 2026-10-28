<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Account.enableTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="app-card security-card" aria-labelledby="enable-2fa-title">
    <h2 class="h5" id="enable-2fa-title"><?= esc(lang('Account.enableTitle')) ?></h2>
    <p><?= esc(lang('TwoFactor.setupIntro')) ?></p>

    <?php /* SVG generuje kniznica bacon-qr-code zo serverom vytvoreneho URI, nie je to vstup pouzivatela: esc() by ho rozbil. */ ?>
    <div class="qr-box" role="img" aria-label="<?= esc(lang('TwoFactor.qrLabel'), 'attr') ?>"><?= $qrSvg ?></div>

    <p class="mb-1"><?= esc(lang('TwoFactor.manualIntro')) ?></p>
    <p><code class="totp-secret"><?= esc($secretFmt) ?></code></p>
    <button type="button" class="btn btn-outline-secondary btn-touch mb-4" x-data="copySecret" @click="copy"
            data-secret="<?= esc($secret, 'attr') ?>"
            data-label-copy="<?= esc(lang('TwoFactor.copy'), 'attr') ?>"
            data-label-copied="<?= esc(lang('TwoFactor.copied'), 'attr') ?>"
            x-text="label"><?= esc(lang('TwoFactor.copy')) ?></button>

    <?php if (! empty($error)) : ?>
        <div class="alert alert-danger" role="alert" id="totp-error"><?= esc($error) ?></div>
    <?php endif ?>

    <form method="post" action="/settings/security/2fa/setup" data-submit-once novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="enable-password" class="form-label"><?= esc(lang('Account.currentPassword')) ?></label>
            <input type="password" id="enable-password" name="current_password" class="form-control" autocomplete="current-password" required>
        </div>
        <div class="mb-3">
            <label for="totp-code" class="form-label"><?= esc(lang('TwoFactor.codeLabel')) ?></label>
            <input type="text" id="totp-code" name="code"
                   class="form-control auth-code-input<?= ! empty($error) ? ' is-invalid' : '' ?>"
                   inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" required
                   <?= ! empty($error) ? 'aria-describedby="totp-error"' : '' ?>>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('Account.enableSubmit')) ?>
        </button>
    </form>

    <p class="mt-3 text-center"><a class="btn btn-link" href="/settings/security"><?= esc(lang('Account.cancel')) ?></a></p>
</section>
<?= $this->endSection() ?>
