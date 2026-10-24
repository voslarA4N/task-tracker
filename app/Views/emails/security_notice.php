<?php
$bodyKey = 'Notify.body_' . $kind;
/**
 * @var string      $kind
 * @var string      $locale
 * @var string|null $at
 * @var string|null $ip
 * @var string|null $device
 * @var int|null    $minutes
 */
?>
<?= lang($bodyKey, [], $locale) ?>


<?php if ($kind === 'account_locked' && $minutes !== null): ?>
<?= lang('Notify.lockedFor', ['minutes' => $minutes], $locale) ?>

<?php endif; ?>
<?php if ($at !== null): ?>
<?= lang('Notify.labelTime', [], $locale) ?>: <?= $at ?>

<?php endif; ?>
<?php if ($ip !== null): ?>
<?= lang('Notify.labelIp', [], $locale) ?>: <?= $ip ?>

<?php endif; ?>
<?php if ($device !== null): ?>
<?= lang('Notify.labelDevice', [], $locale) ?>: <?= $device ?>

<?php endif; ?>

<?= lang('Notify.notYou', [], $locale) ?>


<?= lang('Notify.signature', [], $locale) ?>
