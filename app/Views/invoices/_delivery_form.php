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
$kind    = (string) ($kind ?? 'invoice');
$action  = '/invoices/' . $id . match ($kind) {
    'resend'   => '/resend',
    'reminder' => '/remind',
    default    => '/send',
};
$clientEmails = implode(',', array_map(static fn ($e): string => strtolower((string) $e), (array) ($draft['client_emails'] ?? [])));
$sender  = (array) $draft['sender'];
$bcc     = (array) $sender['bcc'];
$replyTo = (string) ($sender['reply_to'] ?? '');
$field   = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-describedby="dl-' . $name . '-err"' : '';
?>
<?php if (! empty($alert)) : ?>
    <div class="alert alert-danger" role="alert"><?= esc($alert) ?></div>
<?php endif ?>
<form method="post" action="<?= esc($action, 'attr') ?>" hx-post="<?= esc($action, 'attr') ?>" hx-target="#sheet-body" hx-swap="innerHTML" data-submit-once data-client-emails="<?= esc($clientEmails, 'attr') ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="request_key" value="<?= esc($values['request_key'], 'attr') ?>">
    <?php if ($kind === 'reminder') : ?>
        <input type="hidden" name="return_to" value="<?= esc((string) ($values['return_to'] ?? '/invoices/' . $id), 'attr') ?>">
    <?php endif ?>

<?php foreach (['to' => 'Deliveries.labelTo', 'cc' => 'Deliveries.labelCc'] as $fname => $flabel) : ?>
    <div class="dl-field mb-3" x-data="dlRecipients" data-field="<?= $fname ?>">
        <label class="form-label" for="dl-<?= $fname ?>"><?= esc(lang($flabel)) ?></label>
        <div data-dl-rawwrap>
            <input class="form-control<?= $field($fname) ?>" type="text" id="dl-<?= $fname ?>" name="<?= $fname ?>" value="<?= esc($values[$fname], 'attr') ?>" data-dl-raw inputmode="email" autocapitalize="off" autocomplete="off" spellcheck="false"<?= $describe($fname) ?>>
            <div class="form-text"><?= esc(lang('Deliveries.addressHint')) ?></div>
        </div>
        <?php if (isset($errors[$fname])) : ?><div class="invalid-feedback d-block" id="dl-<?= $fname ?>-err"><?= esc($errors[$fname]) ?></div><?php endif ?>
        <ul class="dl-chips list-unstyled mb-2">
            <template x-for="addr in list" x-bind:key="addr">
                <li class="dl-chip">
                    <span class="dl-chip-addr" x-text="addr"></span>
                    <button type="button" class="btn btn-outline-secondary btn-touch" x-bind:data-addr="addr" x-on:click="remove"><?= esc(lang('Deliveries.removeAddress')) ?></button>
                </li>
            </template>
        </ul>
        <div class="dl-add" data-dl-addwrap hidden x-bind:hidden="notEnhanced">
            <label class="visually-hidden" for="dl-new-<?= $fname ?>"><?= esc(lang('Deliveries.addAddress')) ?></label>
            <input class="form-control" type="email" id="dl-new-<?= $fname ?>" data-dl-new placeholder="<?= esc(lang('Deliveries.addAddress'), 'attr') ?>" inputmode="email" autocapitalize="off" autocomplete="off" spellcheck="false" x-on:keydown.enter.prevent="add">
            <button type="button" class="btn btn-outline-secondary btn-touch" x-on:click="add"><?= esc(lang('Deliveries.addCta')) ?></button>
        </div>
        <?php if ($fname === 'to') : ?>
            <div class="alert alert-warning mt-2 mb-0" role="status" hidden x-bind:hidden="noWarn"><?= esc(lang('Deliveries.warnOutside')) ?></div>
        <?php endif ?>
    </div>
<?php endforeach ?>

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
    <?php if ($kind !== 'reminder' && count((array) $draft['attachments']) < 2) : ?>
        <p class="small text-body-secondary"><?= esc(lang('Invoices.reportMissing')) ?></p>
    <?php endif ?>

    <div class="master-sticky-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Deliveries.cancel')) ?></button>
        <button type="submit" class="btn btn-primary btn-touch">
            <span class="spinner-border spinner-border-sm htmx-indicator d-none" aria-hidden="true"></span>
            <?= esc(lang($retry ? 'Deliveries.retry' : match ($kind) {
                'resend'   => 'Deliveries.resendCta',
                'reminder' => 'Deliveries.reminderCta',
                default    => 'Deliveries.sendCta',
            })) ?>
        </button>
    </div>
</form>
