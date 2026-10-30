<?php

use App\Controllers\Invoicing;
use App\Libraries\Invoicing\Draft;
use App\Libraries\Invoicing\IssueCommand;
use App\Libraries\Platform\Hours;
use App\Libraries\Platform\MoneyFormat;

/**
 * Fragment #inv-preview: nahlad faktury mesiaca (bez cisla, bez zapisu).
 *
 * @var list<array<string, mixed>>      $clients
 * @var array<string, string>           $values
 * @var array<string, string>           $errors
 * @var Draft|null                      $draft
 * @var string                          $locale
 * @var string                          $refresh
 * @var string                          $monthLabel
 * @var string                          $clientName
 * @var string                          $requestKey
 * @var array<string, mixed>|null       $notice
 * @var list<array<string, mixed>>      $alerts
 */
$empty = lang('Tasks.empty');
$query = http_build_query($values);
$dateAttrs = static fn (string $include): string => 'hx-get="/fragments/invoice-preview" hx-trigger="change" hx-target="#inv-preview" hx-swap="outerHTML" hx-push-url="true" hx-indicator="#inv-fx-loading" hx-disabled-elt="#inv-issue-btn" hx-include="' . $include . ', #inv-payment-method, #inv-payment-note, #inv-payment-note-dirty, #inv-fx-mode, #inv-fx-rate, #inv-fx-date, #inv-fx-anchor"';
?>
<div id="inv-preview"<?php if (($refresh ?? '') !== '') : ?> hx-get="<?= htmlspecialchars($refresh, ENT_QUOTES, 'UTF-8') ?>" hx-trigger="allocationChanged from:body" hx-target="#inv-preview" hx-swap="outerHTML" hx-include="#inv-payment-method, #inv-payment-note, #inv-payment-note-dirty, #inv-fx-mode, #inv-fx-rate, #inv-fx-date, #inv-fx-anchor"<?php endif ?>>
<?php if (is_array($notice ?? null)) : ?>
    <div class="alert alert-success" role="alert" aria-live="polite"><?= esc((string) ($notice['message'] ?? '')) ?></div>
<?php endif ?>

<?php foreach (($alerts ?? []) as $alert) : ?>
    <div class="alert alert-danger" role="alert">
        <?= esc($alert['message']) ?>
        <?php if (isset($alert['link'])) : ?>
            <a class="alert-link d-inline-flex align-items-center py-2" href="<?= esc($alert['link']['href'], 'attr') ?>"><?= esc($alert['link']['label']) ?></a>
        <?php endif ?>
    </div>
<?php endforeach ?>

<?php if ($clients === []) : ?>
    <section class="app-card">
        <p class="fw-semibold mb-1"><?= esc(lang('Invoicing.emptyNoClientTitle')) ?></p>
        <p class="text-muted mb-0"><?= esc(lang('Invoicing.emptyNoClientBody')) ?></p>
    </section>
<?php elseif ($errors !== []) : ?>
    <?php foreach ($errors as $name => $message) : ?>
        <div class="alert alert-danger invalid-feedback d-block" role="alert" id="inv-<?= esc((string) $name, 'attr') ?>-error"><?= esc($message) ?></div>
    <?php endforeach ?>
<?php elseif ($draft instanceof Draft) : ?>
    <?php
    $currency = $draft->currency ?? '';
    $fmt      = static fn (string $amount): string => MoneyFormat::format($amount, $currency);
    $entityName = $empty;

    foreach ($draft->entities as $e) {
        if ($e['id'] === $draft->entityId) {
            $entityName = $e['name_internal'];
        }
    }

    $bank        = $draft->snapshot['bank'] ?? null;
    $bankText    = is_array($bank) ? $bank['label'] . ' · ' . $bank['iban'] : $empty;
    $needsConfirm = $draft->backdate !== null;
    $methodLabels = [
    'transfer' => lang('Invoicing.paymentTransfer'),
    'cash'     => lang('Invoicing.paymentCash'),
    'card'     => lang('Invoicing.paymentCard'),
    'other'    => lang('Invoicing.paymentOther'),
];
    ?>

    <section class="app-card mb-3" aria-labelledby="inv-dates-title">
        <h2 class="h6 visually-hidden" id="inv-dates-title"><?= esc(lang('Invoicing.labelIssueDate')) ?></h2>
        <div class="inv-dates">
            <div>
                <label class="form-label" for="inv-issue-date"><?= esc(lang('Invoicing.labelIssueDate')) ?></label>
                <input class="form-control" type="date" id="inv-issue-date" name="issue_date" value="<?= esc($draft->issueDate, 'attr') ?>"
                       <?= $dateAttrs('#inv-client, #inv-month, #inv-delivery-date') ?>>
            </div>
            <div>
                <label class="form-label" for="inv-delivery-date"><?= esc(lang('Invoicing.labelDeliveryDate')) ?></label>
                <input class="form-control" type="date" id="inv-delivery-date" name="delivery_date" value="<?= esc($draft->deliveryDate, 'attr') ?>"
                       <?= $dateAttrs('#inv-client, #inv-month, #inv-entity, #inv-issue-date, #inv-due-date') ?>>
            </div>
            <div>
                <label class="form-label" for="inv-due-date"><?= esc(lang('Invoicing.labelDueDate')) ?></label>
                <input class="form-control" type="date" id="inv-due-date" name="due_date" value="<?= esc($draft->dueDate, 'attr') ?>"
                       <?= $dateAttrs('#inv-client, #inv-month, #inv-entity, #inv-issue-date, #inv-delivery-date') ?>>
            </div>
        </div>
    </section>

    <?php if ($draft->entityId === null) : ?>
        <section class="app-card">
            <p class="fw-semibold mb-1"><?= esc(lang('Invoicing.emptyNoEntityTitle')) ?></p>
            <p class="text-muted mb-0"><?= esc(lang('Invoicing.emptyNoEntityBody', [Invoicing::dateLabel($draft->issueDate)])) ?></p>
        </section>
    <?php elseif ($draft->lineCount === 0) : ?>
        <section class="app-card">
            <p class="fw-semibold mb-1"><?= esc(lang('Invoicing.emptyNothingTitle')) ?></p>
            <p class="text-muted mb-0"><?= esc(lang('Invoicing.emptyNothingBody', [$clientName, $monthLabel])) ?></p>
            <?php if ($draft->hasLockedInMonth) : ?>
                <p class="small text-body-secondary mt-2 mb-0"><?= esc(lang('Invoicing.lockedHint')) ?></p>
            <?php endif ?>
        </section>
    <?php else : ?>
        <?php
        $fxState = $draft->fx['state'] ?? 'ok';
        echo view('invoicing/_fx_card', ['draft' => $draft, 'locale' => $locale]);
        ?>
        <div x-data="invIssue" data-can-issue="<?= $draft->canIssue ? '1' : '0' ?>" data-needs-confirm="<?= $needsConfirm ? '1' : '0' ?>">
            <div x-data="invPay" data-dirty="<?= $draft->paymentNoteDirty ? '1' : '0' ?>">
            <section class="app-card mb-3 inv-summary" aria-labelledby="inv-summary-title">
                <h2 class="h6 visually-hidden" id="inv-summary-title"><?= esc(lang('Invoicing.title')) ?></h2>
                <dl class="master-kv mb-2">
                    <dt><?= esc(lang('Invoicing.labelEntity')) ?></dt>
                    <dd><?= esc($entityName) ?></dd>
                    <dt><?= esc(lang('Invoicing.labelClient')) ?></dt>
                    <dd><?= esc($clientName) ?></dd>
                    <dt><?= esc(lang('Invoicing.labelCurrency')) ?></dt>
                    <dd><?= esc($currency) ?></dd>
                    <dt><?= esc(lang('Invoicing.labelBank')) ?></dt>
                    <dd class="master-iban"><?= esc($bankText) ?></dd>
                </dl>
                <div aria-live="polite">
                    <div class="small text-body-secondary"><?= esc(lang('Invoicing.labelTotal')) ?></div>
                    <div class="inv-summary-total"><?= esc($fmt($draft->total)) ?></div>
                    <?php if ($draft->fx !== null && ! in_array($fxState, ['ok', 'manual'], true)) : ?>
                        <div class="small text-body-secondary"><?= esc(lang('Currency.pendingTotal', [$draft->fx['entity_currency']])) ?></div>
                    <?php endif ?>
                    <div class="small text-body-secondary"><?= esc(lang('Invoicing.countLine', [$draft->lineCount, Hours::format($draft->hours, $locale)])) ?></div>
                </div>
            </section>

            <?php if ($needsConfirm) : ?>
                <div class="alert alert-warning inv-warn" role="alert">
                    <p class="mb-2"><?= esc(lang('Invoicing.backdateWarning', [
                        Invoicing::dateLabel($draft->issueDate),
                        (string) $draft->backdate['number'],
                        Invoicing::dateLabel((string) $draft->backdate['issue_date']),
                    ])) ?></p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="inv-confirm-backdate" name="confirm_backdate" value="1" form="inv-issue-form" x-on:change="toggle">
                        <label class="form-check-label" for="inv-confirm-backdate"><?= esc(lang('Invoicing.backdateConfirm')) ?></label>
                    </div>
                </div>
            <?php endif ?>

            <?php foreach ($draft->errors as $error) : ?>
                <div class="alert alert-danger" role="alert"><?= esc(Invoicing::errorMessage($error['reason'], $error['context'])) ?></div>
            <?php endforeach ?>

            <section class="app-card mb-3 inv-pay" aria-labelledby="inv-pay-title">
                <h2 class="h5" id="inv-pay-title"><?= esc(lang('Invoicing.paymentHeading')) ?></h2>
                <div class="mb-3">
                    <label class="form-label" for="inv-payment-method"><?= esc(lang('Invoicing.paymentMethodLabel')) ?></label>
                    <select class="form-select<?= isset($payErrors['payment_method']) ? ' is-invalid' : '' ?>" id="inv-payment-method" name="payment_method" form="inv-issue-form" x-on:change="onMethodChange">
                        <?php foreach (IssueCommand::PAYMENT_METHODS as $method) : ?>
                            <option value="<?= esc($method, 'attr') ?>"<?= $draft->paymentMethod === $method ? ' selected' : '' ?>><?= esc($methodLabels[$method]) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?php if (isset($payErrors['payment_method'])) : ?>
                        <div class="invalid-feedback d-block"><?= esc($payErrors['payment_method']) ?></div>
                    <?php endif ?>
                    <div class="form-text"<?= $draft->paymentMethod === 'transfer' ? '' : ' hidden' ?> x-bind:hidden="isNotTransfer"><?= esc(lang('Invoicing.paymentHintQr')) ?></div>
                    <div class="form-text"<?= $draft->paymentMethod === 'transfer' ? ' hidden' : '' ?> x-bind:hidden="isTransfer"><?= esc(lang('Invoicing.paymentHintNoQr')) ?></div>
                </div>
                <div>
                    <label class="form-label" for="inv-payment-note"><?= esc(lang('Invoicing.paymentNoteLabel')) ?></label>
                    <textarea class="form-control<?= isset($payErrors['payment_note']) ? ' is-invalid' : '' ?>" id="inv-payment-note" name="payment_note" form="inv-issue-form" rows="3" maxlength="500" x-on:input="onNoteInput"><?= esc((string) $draft->paymentNote) ?></textarea>
                    <input type="hidden" id="inv-payment-note-dirty" name="payment_note_dirty" form="inv-issue-form" value="<?= $draft->paymentNoteDirty ? '1' : '0' ?>" x-bind:value="dirtyValue">
                    <?php if (isset($payErrors['payment_note'])) : ?>
                        <div class="invalid-feedback d-block"><?= esc($payErrors['payment_note']) ?></div>
                    <?php endif ?>
                    <div class="d-flex justify-content-between gap-2 form-text">
                        <span><?= esc(lang('Invoicing.paymentNoteHint')) ?> <a class="d-inline-flex align-items-center py-2" href="/settings/legal-texts"><?= esc(lang('Invoicing.paymentNoteEditDefault')) ?></a></span>
                        <span class="tabular-nums text-nowrap" x-text="countLabel"><?= mb_strlen((string) $draft->paymentNote) ?> / 500</span>
                    </div>
                </div>
            </section>
            <?php if ($draft->zbpTitleWarning) : ?>
                <div class="alert alert-warning small" role="status"><?= esc(lang('Invoicing.zbpTitleTooLong')) ?></div>
            <?php endif ?>
            <?php if ($draft->zbpAmountWarning) : ?>
                <div class="alert alert-warning small" role="status"><?= esc(lang('Invoicing.zbpAmountTooHigh')) ?></div>
            <?php endif ?>
            <?php if ($draft->provisionalText) : ?>
                <div class="alert alert-warning small" role="alert"><?= esc(lang('Invoicing.provisionalWarning')) ?></div>
            <?php endif ?>

            <section class="app-card mb-3" aria-labelledby="inv-items-title">
                <h2 class="h5" id="inv-items-title"><?= esc(lang('Invoicing.labelItems')) ?></h2>
                <?php foreach ($draft->lines as $line) : ?>
                    <?php $moveUrl = '/invoices/preview-move/' . (int) $line['source_allocation_id'] . '?' . $query; ?>
                    <div class="inv-line">
                        <div>
                            <div class="fw-semibold"><?= esc((string) $line['title']) ?></div>
                            <div class="small text-body-secondary"><?= esc(lang('Invoicing.lineMeta', [
                                Hours::format((string) $line['hours'], $locale),
                                $fmt((string) $line['rate']),
                                $line['vat_code'] === null ? $empty : (string) $line['vat_code'],
                            ])) ?></div>
                            <a class="d-inline-flex align-items-center py-2" href="<?= htmlspecialchars($moveUrl, ENT_QUOTES, 'UTF-8') ?>" hx-get="<?= htmlspecialchars($moveUrl, ENT_QUOTES, 'UTF-8') ?>" hx-target="#sheet" hx-push-url="false"><?= esc(lang('Invoicing.moveLink')) ?></a>
                        </div>
                        <div class="text-end"><?= esc($fmt((string) $line['amount'])) ?></div>
                    </div>
                <?php endforeach ?>
                <div class="inv-total d-flex justify-content-between">
                    <span><?= esc(lang('Invoicing.labelTotal')) ?></span>
                    <span><?= esc($fmt($draft->total)) ?></span>
                </div>
                <?php if ($draft->hasLockedInMonth) : ?>
                    <p class="small text-body-secondary mt-2 mb-0"><?= esc(lang('Invoicing.lockedHint')) ?></p>
                <?php endif ?>
            </section>

            <form id="inv-issue-form" method="post" action="/invoices/issue">
                <?= csrf_field() ?>
                <input type="hidden" name="client_id" value="<?= (int) $draft->clientId ?>">
                <input type="hidden" name="billing_month" value="<?= esc($draft->month, 'attr') ?>">
                <input type="hidden" name="entity_id" value="<?= (int) $draft->entityId ?>">
                <?php foreach ($draft->partIds as $partId) : ?>
                    <input type="hidden" name="part_ids[]" value="<?= (int) $partId ?>">
                <?php endforeach ?>
                <input type="hidden" name="preview_token" value="<?= esc((string) $draft->previewToken, 'attr') ?>">
                <input type="hidden" name="request_key" value="<?= esc($requestKey, 'attr') ?>">
                <input type="hidden" name="issue_date" value="<?= esc($draft->issueDate, 'attr') ?>">
                <input type="hidden" name="delivery_date" value="<?= esc($draft->deliveryDate, 'attr') ?>">
                <input type="hidden" name="due_date" value="<?= esc($draft->dueDate, 'attr') ?>">
            </form>

            <div class="alert alert-warning" role="alert" hidden x-bind:hidden="fresh"><?= esc(lang('Invoicing.previewStaleRefresh')) ?></div>

            <?php if ($draft->fx !== null && in_array($fxState, ['unavailable', 'unsupported'], true)) : ?>
                <p class="small text-body-secondary"><?= esc(lang($fxState === 'unsupported' || ($draft->fx['future_bound'] ?? false) ? 'Currency.issueHintManual' : 'Currency.issueHintRetry')) ?></p>
            <?php endif ?>
            <div class="master-sticky-actions d-flex align-items-center justify-content-between gap-2">
                <div class="fw-semibold tabular-nums"><?= esc(lang('Invoicing.labelTotal')) ?> <?= esc($fmt($draft->total)) ?></div>
                <button type="button" class="btn btn-primary btn-touch" id="inv-issue-btn" data-bs-toggle="offcanvas" data-bs-target="#inv-confirm" x-bind:disabled="disabled"<?= $draft->canIssue ? '' : ' disabled' ?>><?= esc(lang('Invoicing.issueCta')) ?></button>
                <noscript><button type="submit" form="inv-issue-form" class="btn btn-primary btn-touch"<?= $draft->canIssue ? '' : ' disabled' ?>><?= esc(lang('Invoicing.issueCta')) ?></button></noscript>
            </div>
            <?= view('invoicing/_confirm_sheet', ['draft' => $draft, 'clientName' => $clientName, 'entityName' => $entityName, 'monthLabel' => $monthLabel, 'total' => $fmt($draft->total), 'needsConfirm' => $needsConfirm]) ?>
            </div>
        </div>
    <?php endif ?>
<?php endif ?>

<?php if (is_htmx_fragment_request() && $clients !== []) : ?>
    <?= view('invoicing/_entity_field', ['entities' => $entities ?? [], 'values' => $values, 'errors' => $errors, 'oob' => true]) ?>
<?php endif ?>
</div>
