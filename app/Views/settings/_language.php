<?php
$languageError = $languageError ?? null;
$names         = [
    'sk' => lang('Account.languageSk'),
    'pl' => lang('Account.languagePl'),
    'cs' => lang('Account.languageCs'),
    'en' => lang('Account.languageEn'),
];
?>
<section class="app-card security-card" aria-labelledby="sec-language">
    <h2 class="h5" id="sec-language"><?= esc(lang('Account.languageHeading')) ?></h2>
    <p class="small text-body-secondary" id="language-intro"><?= esc(lang('Account.languageIntro')) ?></p>

    <?php if (! empty($languageError)) : ?>
        <div class="alert alert-danger" role="alert" id="language-error"><?= esc((string) $languageError) ?></div>
    <?php endif ?>

    <form method="post" action="/settings/security/locale" data-submit-once novalidate>
        <?= csrf_field() ?>
        <fieldset class="mb-3" aria-describedby="language-intro">
            <legend class="visually-hidden"><?= esc(lang('Account.languageHeading')) ?></legend>
            <?php foreach ($supportedLocales as $code) : ?>
                <div class="form-check py-2">
                    <input class="form-check-input" type="radio" name="locale" id="locale-<?= esc($code, 'attr') ?>" value="<?= esc($code, 'attr') ?>"<?= $code === $currentLocale ? ' checked' : '' ?>>
                    <label class="form-check-label" for="locale-<?= esc($code, 'attr') ?>"><?= esc($names[$code] ?? $code) ?></label>
                    <?php if ($code === $currentLocale) : ?>
                        <span class="small text-body-secondary"><?= esc(lang('Account.languageCurrent')) ?></span>
                    <?php endif ?>
                </div>
            <?php endforeach ?>
        </fieldset>
        <button type="submit" class="btn btn-primary btn-touch w-100 w-lg-auto">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('Account.languageSave')) ?>
        </button>
    </form>
</section>
