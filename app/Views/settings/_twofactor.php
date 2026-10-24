<section class="app-card security-card" aria-labelledby="sec-2fa">
    <h2 class="h5" id="sec-2fa"><?= esc(lang('Account.twofaHeading')) ?></h2>
    <p class="mb-1"><span class="badge text-bg-success"><?= esc(lang('Account.twofaActive')) ?></span></p>
    <p class="mb-0"><?= esc(lang('Account.codesRemaining', [$remainingCodes])) ?></p>
</section>
