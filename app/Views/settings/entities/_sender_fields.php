<?php
/**
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<string>          $allowedDomains
 */
$field = static fn (string $k): string => (string) ($values[$k] ?? '');
$bad   = static fn (string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
$desc  = static fn (string $k): string => 'f-' . $k . '-hint' . (isset($errors[$k]) ? ' f-' . $k . '-err' : '');
?>
<section class="sender-card" aria-labelledby="sender-title">
    <h2 class="h5 mt-4 mb-1" id="sender-title"><?= esc(lang('Entities.senderTitle')) ?></h2>
    <p class="small text-body-secondary mb-3"><?= esc(lang('Entities.senderIntro')) ?></p>

    <div class="mb-3">
        <label class="form-label" for="f-mail_from_name"><?= esc(lang('Entities.senderName')) ?></label>
        <input class="form-control<?= $bad('mail_from_name') ?>" type="text" id="f-mail_from_name" name="mail_from_name" maxlength="100"
               value="<?= esc($field('mail_from_name'), 'attr') ?>" autocomplete="off"<?= isset($errors['mail_from_name']) ? ' aria-describedby="f-mail_from_name-err"' : '' ?>>
        <?php if (isset($errors['mail_from_name'])) : ?>
            <div class="invalid-feedback" id="f-mail_from_name-err"><?= esc($errors['mail_from_name']) ?></div>
        <?php endif ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="f-mail_from_email"><?= esc(lang('Entities.senderEmail')) ?></label>
        <input class="form-control<?= $bad('mail_from_email') ?>" type="email" inputmode="email" autocapitalize="off" id="f-mail_from_email" name="mail_from_email"
               value="<?= esc($field('mail_from_email'), 'attr') ?>" autocomplete="off" aria-describedby="<?= esc($desc('mail_from_email'), 'attr') ?>">
        <div class="form-text" id="f-mail_from_email-hint"><?= esc(lang('Entities.senderEmailHint', [implode(', ', $allowedDomains)])) ?></div>
        <?php if (isset($errors['mail_from_email'])) : ?>
            <div class="invalid-feedback" id="f-mail_from_email-err"><?= esc($errors['mail_from_email']) ?></div>
        <?php endif ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="f-mail_reply_to"><?= esc(lang('Entities.senderReplyTo')) ?></label>
        <input class="form-control<?= $bad('mail_reply_to') ?>" type="email" inputmode="email" autocapitalize="off" id="f-mail_reply_to" name="mail_reply_to"
               value="<?= esc($field('mail_reply_to'), 'attr') ?>" autocomplete="off"<?= isset($errors['mail_reply_to']) ? ' aria-describedby="f-mail_reply_to-err"' : '' ?>>
        <?php if (isset($errors['mail_reply_to'])) : ?>
            <div class="invalid-feedback" id="f-mail_reply_to-err"><?= esc($errors['mail_reply_to']) ?></div>
        <?php endif ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="f-mail_bcc"><?= esc(lang('Entities.senderBcc')) ?></label>
        <textarea class="form-control<?= $bad('mail_bcc') ?>" id="f-mail_bcc" name="mail_bcc" rows="2" autocapitalize="off" spellcheck="false"
                  aria-describedby="<?= esc($desc('mail_bcc'), 'attr') ?>"><?= esc($field('mail_bcc')) ?></textarea>
        <div class="form-text" id="f-mail_bcc-hint"><?= esc(lang('Entities.senderBccHint', [\App\Libraries\Master\CcEmails::MAX])) ?></div>
        <?php if (isset($errors['mail_bcc'])) : ?>
            <div class="invalid-feedback" id="f-mail_bcc-err"><?= esc($errors['mail_bcc']) ?></div>
        <?php endif ?>
    </div>
</section>
