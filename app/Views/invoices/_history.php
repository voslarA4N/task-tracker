<?php

/**
 * Historia odoslani faktury (MAIL-01, D-10, D-12).
 *
 * @var array<string, mixed>        $invoice
 * @var list<array<string, mixed>>  $history
 * @var string                      $locale
 */
$dateFmt = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, 'Europe/Bratislava');
$fmtTs   = static function (mixed $v) use ($dateFmt): string {
    if (! is_string($v) || $v === '') {
        return '';
    }

    return (string) $dateFmt->format(service('clock')->now()->modify($v));
};
$kindKeys = ['invoice' => 'Deliveries.kindInvoice', 'resend' => 'Deliveries.kindResend', 'reminder' => 'Deliveries.kindReminder', 'manual' => 'Deliveries.kindManual'];
$sentAt   = $invoice['sent_at'] ?? null;
$lastRem  = $invoice['last_reminder_at'] ?? null;
$none     = lang('Deliveries.none');
?>
<section class="app-card mb-4" aria-labelledby="inv-send-title">
    <h2 class="h5 mb-2" id="inv-send-title"><?= esc(lang('Deliveries.historyTitle')) ?></h2>
    <?php if ($history === []) : ?>
        <p class="fw-semibold mb-1"><?= esc(lang('Deliveries.historyEmptyTitle')) ?></p>
        <p class="small text-body-secondary mb-0"><?= esc(lang('Deliveries.historyEmptyBody')) ?></p>
    <?php else : ?>
        <?php if ($sentAt !== null) : ?>
            <p class="small text-body-secondary mb-2"><?= esc(lang('Deliveries.historySummary', [$fmtTs($sentAt), (int) ($invoice['send_count'] ?? 0), $lastRem !== null ? $fmtTs($lastRem) : $none])) ?></p>
        <?php endif ?>
        <ul class="dl-history list-unstyled mb-0">
            <?php foreach ($history as $h) : ?>
                <?php
                $rec     = (array) $h['recipients'];
                $to      = implode(', ', array_map('strval', (array) ($rec['to'] ?? [])));
                $cc      = implode(', ', array_map('strval', (array) ($rec['cc'] ?? [])));
                $bcc     = implode(', ', array_map('strval', (array) ($h['bcc'] ?? [])));
                $voided  = (bool) $h['voided'];
                $manual  = $h['kind'] === 'manual';
                $subject = mb_strimwidth((string) ($h['subject'] ?? ''), 0, 60, '…');
                ?>
                <li class="dl-history-row">
                    <div class="fw-semibold<?= $voided ? ' text-decoration-line-through' : '' ?>">
                        <?= esc(lang($kindKeys[(string) $h['kind']] ?? 'Deliveries.kindInvoice')) ?>
                        <?php if (! $manual) : ?>
                            <span class="badge text-bg-light border"><?= esc(lang($h['counts_as_sent'] ? 'Deliveries.badgeClient' : 'Deliveries.badgeOther')) ?></span>
                        <?php endif ?>
                        <?php if ($voided) : ?><span class="badge text-bg-secondary"><?= esc(lang('Deliveries.historyVoided')) ?></span><?php endif ?>
                    </div>
                    <div class="small text-body-secondary"><?= esc($fmtTs($h['sent_at'])) ?></div>
                    <?php if (! $manual) : ?>
                        <div class="small<?= $voided ? ' text-decoration-line-through' : '' ?>"><?= esc(lang('Deliveries.historyTo', [$to])) ?><?= $cc !== '' ? ' · ' . esc(lang('Deliveries.historyCc', [$cc])) : '' ?></div>
                        <?php if ($subject !== '') : ?><div class="small text-body-secondary"><?= esc($subject) ?></div><?php endif ?>
                    <?php endif ?>
                    <?php if ($voided && ! empty($h['void_reason'])) : ?>
                        <div class="small text-body-secondary"><?= esc(lang('Deliveries.historyVoidReason', [(string) $h['void_reason']])) ?></div>
                    <?php endif ?>
                    <?php if (! $manual) : ?>
                        <details class="dl-detail">
                            <summary class="dl-detail-summary"><?= esc(lang('Deliveries.historyDetail')) ?></summary>
                            <dl class="master-kv small mb-0">
                                <dt><?= esc(lang('Deliveries.labelFrom')) ?></dt>
                                <dd><?= esc((string) $h['from_name']) ?> &lt;<?= esc((string) $h['from_email']) ?>&gt;</dd>
                                <dt><?= esc(lang('Deliveries.labelReplyTo')) ?></dt>
                                <dd><?= ($h['reply_to'] ?? '') !== '' ? esc((string) $h['reply_to']) : esc($none) ?></dd>
                                <dt><?= esc(lang('Deliveries.labelBcc')) ?></dt>
                                <dd><?= $bcc !== '' ? esc($bcc) : esc($none) ?></dd>
                                <dt><?= esc(lang('Deliveries.labelMessageId')) ?></dt>
                                <dd class="dl-mono"><?= esc((string) ($h['message_id'] ?? '')) ?></dd>
                                <dt><?= esc(lang('Deliveries.labelTransport')) ?></dt>
                                <dd><?= $h['transport'] === 'log' ? esc(lang('Deliveries.transportLog')) : esc((string) $h['transport']) ?></dd>
                                <?php if (! empty($h['smtp_response'])) : ?>
                                    <dt><?= esc(lang('Deliveries.labelSmtpReply')) ?></dt>
                                    <dd class="dl-mono"><?= esc((string) $h['smtp_response']) ?></dd>
                                <?php endif ?>
                            </dl>
                        </details>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>
