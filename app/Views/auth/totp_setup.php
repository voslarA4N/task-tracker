<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card">
    <h2 class="h5 mb-3"><?= esc(lang('TwoFactor.setupTitle')) ?></h2>
    <p><?= esc(lang('TwoFactor.setupIntro')) ?></p>

    <?php /* SVG generuje kniznica bacon-qr-code zo serverom vytvoreneho URI, nie je to vstup pouzivatela: esc() by ho rozbil. */ ?>
    <div class="qr-box" role="img" aria-label="<?= esc(lang('TwoFactor.qrLabel'), 'attr') ?>"><?= $qrSvg ?></div>

    <p class="mb-1"><?= esc(lang('TwoFactor.manualIntro')) ?></p>
    <p><code class="totp-secret"><?= esc($secretFmt) ?></code></p>
    <button type="button" class="btn btn-outline-secondary mb-4" x-data="copySecret" @click="copy"
            data-secret="<?= esc($secret, 'attr') ?>"
            data-label-copy="<?= esc(lang('TwoFactor.copy'), 'attr') ?>"
            data-label-copied="<?= esc(lang('TwoFactor.copied'), 'attr') ?>"
            x-text="label"><?= esc(lang('TwoFactor.copy')) ?></button>

    <?php if (! empty($error)) : ?>
        <div class="alert alert-danger" role="alert" id="totp-error"><?= esc($error) ?></div>
    <?php endif ?>

    <form method="post" action="/2fa/setup" data-submit-once novalidate>
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
            <?= esc(lang('TwoFactor.activate')) ?>
        </button>
    </form>

    <p class="mt-3 text-center"><a href="/2fa/offer"><?= esc(lang('TwoFactor.offerBack')) ?></a></p>

    <form method="post" action="/logout" class="mt-3 text-center">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-link"><?= esc(lang('TwoFactor.cancel')) ?></button>
    </form>
</div>
<?= $this->endSection() ?>
