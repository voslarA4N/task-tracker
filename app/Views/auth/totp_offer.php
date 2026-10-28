<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card">
    <h2 class="h5 mb-3"><?= esc(lang('TwoFactor.offerTitle')) ?></h2>
    <p><?= esc(lang('TwoFactor.offerIntro')) ?></p>

    <a class="btn btn-primary btn-lg w-100" href="/2fa/setup"><?= esc(lang('TwoFactor.offerSetup')) ?></a>

    <form method="post" action="/2fa/offer/decline" data-submit-once class="mt-3">
        <?= csrf_field() ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
            <label class="form-check-label" for="remember"><?= esc(lang('TwoFactor.remember')) ?></label>
            <div class="form-text"><?= esc(lang('TwoFactor.rememberHelpNoTotp')) ?></div>
        </div>
        <button type="submit" class="btn btn-outline-secondary btn-lg w-100">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('TwoFactor.offerDecline')) ?>
        </button>
        <div class="form-text"><?= esc(lang('TwoFactor.offerDeclineHelp')) ?></div>
    </form>

    <form method="post" action="/logout" class="mt-3 text-center">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-link"><?= esc(lang('TwoFactor.cancel')) ?></button>
    </form>
</div>
<?= $this->endSection() ?>
