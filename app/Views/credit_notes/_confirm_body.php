<?php

use App\Controllers\Invoicing;
use App\Libraries\Fx\FxFormat;
use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Platform\MoneyFormat;

/**
 * Rekapitulacia a formular vystavenia dobropisu (spolocne pre sheet a stranku bez JS).
 *
 * @var int                       $id
 * @var array<string, mixed>      $draft
 * @var array<string, string>     $values
 * @var bool                      $sheet
 */
$id        = (int) $id;
$locale    = service('request')->getLocale();
$orig      = $draft['original'];
$snap      = $draft['snapshot'];
$currency  = trim((string) $orig['currency']);
$fx        = $draft['fx'];
$entityCcy = is_array($fx) ? trim((string) ($fx['entity_currency'] ?? '')) : '';
$type      = CorrectionType::tryFrom($values['correction_type']);
$hidden    = [
    'preview_token'    => (string) ($draft['token'] ?? ''),
    'request_key'      => $values['request_key'],
    'reason'           => $values['reason'],
    'correction_type'  => $values['correction_type'],
    'issue_date'       => (string) $draft['issue_date'],
    'confirm_backdate' => ($values['confirm_backdate'] ?? '') === '1' ? '1' : '0',
];
?>
<dl class="master-kv mb-2">
    <dt><?= esc(lang('CreditNotes.labelCorrects')) ?></dt>
    <dd><?= esc((string) $orig['number']) ?> · <?= esc(Invoicing::dateLabel((string) $orig['issue_date'])) ?></dd>
    <dt><?= esc(lang('CreditNotes.labelType')) ?></dt>
    <dd><?= esc($type === null ? '' : lang($type->uiLabelKey())) ?></dd>
    <dt><?= esc(lang('CreditNotes.labelReason')) ?></dt>
    <dd class="cn-reason"><?= esc($values['reason']) ?></dd>
    <dt><?= esc(lang('CreditNotes.labelIssueDate')) ?></dt>
    <dd><?= esc(Invoicing::dateLabel((string) $draft['issue_date'])) ?></dd>
    <dt><?= esc(lang('CreditNotes.total')) ?></dt>
    <dd class="fw-semibold tabular-nums"><?= esc(MoneyFormat::format((string) $snap['totals']['total'], $currency)) ?></dd>
    <?php if (is_array($fx) && $entityCcy !== '') : ?>
        <dt><?= esc(lang('CreditNotes.totalIn', [$entityCcy])) ?></dt>
        <dd class="fw-semibold tabular-nums"><?= esc(MoneyFormat::format((string) $fx['total'], $entityCcy)) ?></dd>
        <dt><?= esc(lang('CreditNotes.labelRate')) ?></dt>
        <dd><?= esc(FxFormat::expression($fx, $locale)) ?></dd>
    <?php endif ?>
</dl>
<div class="alert alert-warning small" role="alert"><?= esc(lang('CreditNotes.confirmWarn', [(string) $orig['number']])) ?></div>
<form method="post" action="/invoices/<?= $id ?>/credit-note" data-submit-once novalidate>
    <?= csrf_field() ?>
    <?php foreach ($hidden as $name => $value) : ?>
        <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
    <?php endforeach ?>
    <div class="master-sticky-actions d-flex gap-2 justify-content-between">
        <?php if (! empty($sheet)) : ?>
            <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('CreditNotes.back')) ?></button>
        <?php else : ?>
            <a class="btn btn-outline-secondary btn-touch d-inline-flex align-items-center" href="/invoices/<?= $id ?>/credit-note?issue_date=<?= esc((string) $draft['issue_date'], 'url') ?>"><?= esc(lang('CreditNotes.back')) ?></a>
        <?php endif ?>
        <button type="submit" class="btn btn-danger btn-touch">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('CreditNotes.submit')) ?>
        </button>
    </div>
</form>
