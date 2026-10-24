<?php
/**
 * @var string $link
 * @var int    $minutes
 * @var string $locale
 */
?>
<?= lang('PasswordReset.mailIntro', [], $locale) ?>


<?= $link ?>


<?= lang('PasswordReset.mailValidity', ['minutes' => $minutes], $locale) ?>

<?= lang('PasswordReset.mail2fa', [], $locale) ?>


<?= lang('PasswordReset.mailIgnore', [], $locale) ?>


<?= lang('Notify.signature', [], $locale) ?>
