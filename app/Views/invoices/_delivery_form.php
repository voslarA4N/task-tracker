<?php

/**
 * Formular odoslania faktury (spolocny pre sheet a no-JS stranku).
 *
 * @var array<string, mixed>  $invoice
 * @var array<string, mixed>  $draft
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var string|null           $alert
 * @var bool                  $retry
 * @var string                $backUrl
 */
$id      = (int) $invoice['id'];
$action  = '/invoices/' . $id . '/send';
$sender  = (array) $draft['sender'];
$bcc     = (array) $sender['bcc'];
$replyTo = (string) ($sender['reply_to'] ?? '');
$field   = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-describedby="dl-' . $name . '-err"' : '';
?>
<?php if (! empty($alert)) : ?>
    <div class="alert alert-danger" role="alert"><?= esc($alert) ?></div>
<?php endif ?>
<form method="post" action="<?= esc($action, 'attr') ?>" hx-post="<?= esc($action, 'attr') ?>" hx-target="#sheet-body" hx-swap="innerHTML" data-submit-once novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="request_key" value="<?= esc($values['request_key'], 'attr') ?>">

    <div class="dl-field mb-3">
        <label class="form-label" for="dl-to"><?= esc(lang('Deliveries.labelTo')) ?></label>
        <input class="form-control<?= $field('to') ?>" type="text" id="dl-to" name="to" value="<?= esc($values['to'], 'attr') ?>" inputmode="email" autocapitalize="off" autocomplete="off" spellcheck="false"<?= $describe('to') ?>>
        <?php if (isset($errors['to'])) : ?><div class="invalid-feedback" id="dl-to-err"><?= esc($errors['to']) ?></div><?php endif ?>
        <div class="form-text"><?= esc(lang('Deliveries.addressHint')) ?></div>
    </div>
    <div class="dl-field mb-3">
        <label class="form-label" for="dl-cc"><?= esc(lang('Deliveries.labelCc')) ?></label>
        <input class="form-control<?= $field('cc') ?>" type="text" id="dl-cc" name="cc" value="<?= esc($values['cc'], 'attr') ?>" inputmode="email" autocapitalize="off" autocomplete="off" spellcheck="false"<?= $describe('cc') ?>>
        <?php if (isset($errors['cc'])) : ?><div class="invalid-feedback" id="dl-cc-err"><?= esc($errors['cc']) ?></div><?php endif ?>
    </div>

    <dl class="master-kv small mb-3">
        <dt><?= esc(lang('Deliveries.labelFrom')) ?></dt>
        <dd><?= esc((string) $sender['from_name']) ?> &lt;<?= esc((string) $sender['from_email']) ?>&gt;</dd>
        <dt><?= esc(lang('Deliveries.labelReplyTo')) ?></dt>
        <dd><?= $replyTo !== '' ? esc($replyTo) : esc(lang('Deliveries.none')) ?></dd>
        <dt><?= esc(lang('Deliveries.labelBcc')) ?></dt>
        <dd><?= $bcc !== [] ? esc(implode(', ', array_map('strval', $bcc))) : esc(lang('Deliveries.none')) ?></dd>
    </dl>

    <div class="dl-field mb-3">
        <label class="form-label" for="dl-subject"><?= esc(lang('Deliveries.labelSubject')) ?></label>
        <input class="form-control<?= $field('subject') ?>" type="text" id="dl-subject" name="subject" maxlength="200" value="<?= esc($values['subject'], 'attr') ?>"<?= $describe('subject') ?>>
        <?php if (isset($errors['subject'])) : ?><div class="invalid-feedback" id="dl-subject-err"><?= esc($errors['subject']) ?></div><?php endif ?>
    </div>
    <div class="dl-field mb-3">
        <label class="form-label" for="dl-body"><?= esc(lang('Deliveries.labelBody')) ?></label>
        <textarea class="form-control lt-body<?= $field('body') ?>" id="dl-body" name="body" rows="10"<?= $describe('body') ?>><?= esc($values['body']) ?></textarea>
        <?php if (isset($errors['body'])) : ?><div class="invalid-feedback" id="dl-body-err"><?= esc($errors['body']) ?></div><?php endif ?>
        <div class="form-text"><?= esc(lang('Deliveries.bodyHint')) ?></div>
    </div>

    <h3 class="h6 mb-2"><?= esc(lang('Deliveries.attachments')) ?></h3>
    <ul class="dl-attach list-unstyled mb-3">
        <?php foreach ((array) $draft['attachments'] as $a) : ?>
            <li>
                <span class="dl-attach-name"><?= esc((string) $a['name']) ?></span>
                <span class="small text-body-secondary"><?= esc(lang('Deliveries.attachMeta', [max(1, intdiv((int) $a['size'] + 1023, 1024)), substr((string) $a['sha256'], 0, 12)])) ?></span>
            </li>
        <?php endforeach ?>
    </ul>
    <?php if (count((array) $draft['attachments']) < 2) : ?>
        <p class="small text-body-secondary"><?= esc(lang('Invoices.reportMissing')) ?></p>
    <?php endif ?>

    <div class="master-sticky-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Deliveries.cancel')) ?></button>
        <button type="submit" class="btn btn-primary btn-touch">
            <span class="spinner-border spinner-border-sm htmx-indicator d-none" aria-hidden="true"></span>
            <?= esc(lang($retry ? 'Deliveries.retry' : 'Deliveries.sendCta')) ?>
        </button>
    </div>
</form>
