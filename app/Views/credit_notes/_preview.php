<?php

use App\Controllers\Invoicing;
use App\Libraries\Fx\FxFormat;
use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Platform\Hours;
use App\Libraries\Platform\Money;
use App\Libraries\Platform\MoneyFormat;

/**
 * Obsah #cn-preview: nahlad dobropisu (iba citanie, nic sa nerezervuje).
 *
 * @var int                        $id
 * @var array<string, mixed>|null  $draft
 * @var array<string, string>      $values
 * @var array<string, string>      $errors
 * @var list<array<string, mixed>> $alerts
 */
$id      = (int) $id;
$locale  = service('request')->getLocale();
$base    = '/invoices/' . $id . '/credit-note';
$invalid = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-describedby="cn-' . $name . '-err"' : '';
?>
<?php foreach ($alerts as $alert) : ?>
    <div class="alert alert-<?= esc((string) ($alert['type'] ?? 'warning'), 'attr') ?>" role="alert">
        <?= esc((string) $alert['message']) ?>
        <?php if (isset($alert['link'])) : ?>
            <a class="alert-link d-inline-flex align-items-center py-2" href="<?= htmlspecialchars((string) $alert['link']['href'], ENT_QUOTES, 'UTF-8') ?>"><?= esc((string) $alert['link']['label']) ?></a>
        <?php endif ?>
    </div>
<?php endforeach ?>
<?php if (is_array($draft)) : ?>
    <?php
    $orig      = $draft['original'];
    $snap      = $draft['snapshot'];
    $currency  = trim((string) $orig['currency']);
    $fmt       = static fn (string $amount): string => MoneyFormat::format($amount, $currency);
    $fx        = $draft['fx'];
    $entityCcy = is_array($fx) ? trim((string) ($fx['entity_currency'] ?? '')) : '';
    $sumOk     = $draft['sum_check'] === '0.00';
    $canGo     = $draft['token'] !== null && $errors === [] && $sumOk;
    $hx        = 'hx-post="' . esc($base . '/preview', 'attr') . '" hx-target="#cn-preview" hx-swap="innerHTML" hx-include="#cn-form" hx-indicator="#cn-loading" hx-disabled-elt="#cn-continue"';
    $lines     = $draft['lines'];
    $visible   = array_slice($lines, 0, 100);
    $hidden    = array_slice($lines, 100);
    $lineRow   = static function (array $l) use ($fmt, $locale): string {
        return '<div class="cn-line"><div class="cn-line-desc"><div>' . esc((string) $l['title']) . '</div>'
            . '<div class="small text-body-secondary">' . esc(lang('CreditNotes.lineMeta', [Hours::format((string) $l['hours'], $locale), $fmt((string) $l['rate'])])) . '</div></div>'
            . '<div class="cn-line-amount">' . esc($fmt((string) $l['amount'])) . '</div></div>';
    };
    ?>
    <h1 class="h5 mb-1"><?= esc(lang('CreditNotes.title')) ?></h1>
    <p class="small text-body-secondary mb-3"><?= esc(lang('CreditNotes.intro', [(string) $orig['number'], Invoicing::dateLabel((string) $orig['issue_date'])])) ?></p>
    <span class="small text-body-secondary htmx-indicator" id="cn-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <?= esc(lang('CreditNotes.loading')) ?></span>

    <?php if (Money::of($draft['paid_amount'])->compare(Money::zero()) > 0) : ?>
        <div class="alert alert-warning small" role="alert"><?= esc(lang('CreditNotes.warnPayments', [$fmt($draft['paid_amount'])])) ?></div>
    <?php endif ?>
    <div class="alert alert-secondary small" role="note"><?= esc(lang('CreditNotes.warnRelease', [$draft['release_count']])) ?></div>

    <section class="app-card mb-3">
        <div class="mb-3">
            <label class="form-label" for="cn-type"><?= esc(lang('CreditNotes.labelType')) ?></label>
            <select class="form-select<?= $invalid('correction_type') ?>" id="cn-type" name="correction_type" required hx-trigger="change" <?= $hx ?><?= $describe('correction_type') ?>>
                <option value="" disabled<?= $values['correction_type'] === '' ? ' selected' : '' ?>><?= esc(lang('CreditNotes.typePlaceholder')) ?></option>
                <?php foreach (CorrectionType::cases() as $case) : ?>
                    <?php if ($case === CorrectionType::FxError && ! $draft['has_fx']) { continue; } ?>
                    <option value="<?= esc($case->value, 'attr') ?>"<?= $values['correction_type'] === $case->value ? ' selected' : '' ?>><?= esc(lang($case->uiLabelKey())) ?></option>
                <?php endforeach ?>
            </select>
            <?php if (isset($errors['correction_type'])) : ?><div class="invalid-feedback d-block" id="cn-correction_type-err"><?= esc($errors['correction_type']) ?></div><?php endif ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="cn-reason"><?= esc(lang('CreditNotes.labelReason')) ?></label>
            <textarea class="form-control<?= $invalid('reason') ?>" id="cn-reason" name="reason" rows="4" maxlength="500" required hx-trigger="change, keyup changed delay:500ms" <?= $hx ?><?= $describe('reason') ?>><?= esc($values['reason']) ?></textarea>
            <?php if (isset($errors['reason'])) : ?><div class="invalid-feedback d-block" id="cn-reason-err"><?= esc($errors['reason']) ?></div><?php endif ?>
            <div class="form-text"><?= esc(lang('CreditNotes.hintReason')) ?></div>
        </div>

        <div class="mb-0">
            <label class="form-label" for="cn-issue-date"><?= esc(lang('CreditNotes.labelIssueDate')) ?></label>
            <input class="form-control<?= $invalid('issue_date') ?>" type="date" id="cn-issue-date" name="issue_date" value="<?= esc($values['issue_date'], 'attr') ?>" min="<?= esc((string) $orig['issue_date'], 'attr') ?>" required hx-trigger="change" <?= $hx ?><?= $describe('issue_date') ?>>
            <?php if (isset($errors['issue_date'])) : ?><div class="invalid-feedback d-block" id="cn-issue_date-err"><?= esc($errors['issue_date']) ?></div><?php endif ?>
        </div>
    </section>

    <?php if ($draft['backdate'] !== null) : ?>
        <div class="alert alert-warning" role="alert">
            <p class="mb-2"><?= esc(lang('Invoicing.backdateWarning', [Invoicing::dateLabel((string) $draft['issue_date']), (string) $draft['backdate']['number'], Invoicing::dateLabel((string) $draft['backdate']['issue_date'])])) ?></p>
            <div class="form-check">
                <input class="form-check-input<?= $invalid('confirm_backdate') ?>" type="checkbox" id="cn-confirm-backdate" name="confirm_backdate" value="1"<?= ($values['confirm_backdate'] ?? '') === '1' ? ' checked' : '' ?><?= $describe('confirm_backdate') ?>>
                <label class="form-check-label" for="cn-confirm-backdate"><?= esc(lang('Invoicing.backdateConfirm')) ?></label>
            </div>
            <?php if (isset($errors['confirm_backdate'])) : ?><div class="invalid-feedback d-block" id="cn-confirm_backdate-err"><?= esc($errors['confirm_backdate']) ?></div><?php endif ?>
        </div>
    <?php endif ?>

    <section class="app-card mb-3" aria-labelledby="cn-card-title">
        <h2 class="h6 visually-hidden" id="cn-card-title"><?= esc(lang('CreditNotes.cardTitle')) ?></h2>
        <dl class="master-kv">
            <dt><?= esc(lang('CreditNotes.labelNumber')) ?></dt>
            <dd><?= esc($draft['number_preview'] !== '' ? $draft['number_preview'] : lang('Tasks.empty')) ?><div class="small text-body-secondary"><?= esc(lang('CreditNotes.hintNumber')) ?></div></dd>
            <dt><?= esc(lang('CreditNotes.labelCorrects')) ?></dt>
            <dd><a href="/invoices/<?= $id ?>"><?= esc((string) $orig['number']) ?></a> · <?= esc(Invoicing::dateLabel((string) $orig['issue_date'])) ?></dd>
            <dt><?= esc(lang('CreditNotes.labelClient')) ?></dt>
            <dd><?= esc((string) ($snap['client']['name'] ?? '')) ?></dd>
            <dt><?= esc(lang('CreditNotes.labelEntity')) ?></dt>
            <dd><?= esc((string) ($snap['entity']['trade_name'] ?? '')) ?></dd>
            <dt><?= esc(lang('CreditNotes.labelCurrency')) ?></dt>
            <dd><?= esc($currency) ?></dd>
            <dt><?= esc(lang('CreditNotes.labelDeliveryDate')) ?></dt>
            <dd><?= esc(Invoicing::dateLabel((string) $snap['delivery_date'])) ?></dd>
            <dt><?= esc(lang('CreditNotes.labelDueDate')) ?></dt>
            <dd><?= esc(Invoicing::dateLabel((string) $draft['issue_date'])) ?><div class="small text-body-secondary"><?= esc(lang('CreditNotes.hintDue')) ?></div></dd>
            <?php if (is_array($fx)) : ?>
                <dt><?= esc(lang('CreditNotes.labelRate')) ?></dt>
                <dd><?= esc(FxFormat::expression($fx, $locale)) ?><div class="small text-body-secondary"><?= esc(lang('CreditNotes.hintFx')) ?></div></dd>
            <?php endif ?>
        </dl>
    </section>

    <section class="app-card mb-3 cn-lines" aria-labelledby="cn-lines-title">
        <h2 class="h6" id="cn-lines-title"><?= esc(lang('CreditNotes.linesTitle')) ?></h2>
        <?php foreach ($visible as $line) : ?>
            <?= $lineRow($line) ?>
        <?php endforeach ?>
        <?php if ($hidden !== []) : ?>
            <details>
                <summary><?= esc(lang('CreditNotes.showAllLines', [count($lines)])) ?></summary>
                <?php foreach ($hidden as $line) : ?>
                    <?= $lineRow($line) ?>
                <?php endforeach ?>
            </details>
        <?php endif ?>
    </section>

    <section class="app-card mb-3 inv-summary">
        <div class="cn-total"><?= esc(lang('CreditNotes.total')) ?> <?= esc($fmt((string) $snap['totals']['total'])) ?></div>
        <?php if (is_array($fx) && $entityCcy !== '') : ?>
            <div class="fx-sub"><?= esc(lang('CreditNotes.totalIn', [$entityCcy])) ?> <?= esc(MoneyFormat::format((string) $fx['total'], $entityCcy)) ?></div>
        <?php endif ?>
        <div class="cn-check"><?= esc(lang('CreditNotes.sumCheck', [$fmt($draft['sum_check'])])) ?></div>
    </section>

    <?php if (! $sumOk) : ?>
        <div class="alert alert-danger" role="alert"><?= esc(lang('CreditNotes.errorSum')) ?></div>
    <?php endif ?>

    <input type="hidden" name="preview_token" value="<?= esc((string) ($draft['token'] ?? ''), 'attr') ?>">
    <input type="hidden" name="request_key" value="<?= esc($values['request_key'], 'attr') ?>">

    <div class="master-sticky-actions d-flex gap-2 justify-content-between">
        <a class="btn btn-outline-secondary btn-touch d-inline-flex align-items-center" href="/invoices/<?= $id ?>"><?= esc(lang('CreditNotes.cancel')) ?></a>
        <button type="submit" class="btn btn-primary btn-touch" id="cn-continue" formaction="<?= esc($base . '/confirm', 'attr') ?>" hx-post="<?= esc($base . '/confirm', 'attr') ?>" hx-target="#sheet" hx-swap="innerHTML" hx-include="#cn-form"<?= $canGo ? '' : ' disabled' ?>><?= esc(lang('CreditNotes.continue')) ?></button>
    </div>
<?php endif ?>
