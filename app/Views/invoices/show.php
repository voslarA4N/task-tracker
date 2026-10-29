<?php

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\MoneyFormat;

/**
 * Detail faktury iba na citanie: udaje vyhradne zo snapshotu a strukturovanych stlpcov.
 *
 * @var array<string, mixed>  $invoice
 * @var array<string, string> $dates
 */
$id       = (int) $invoice['id'];
$external = $invoice['origin'] === 'external';
$snap     = (array) $invoice['snapshot'];
$locale   = service('request')->getLocale();
$currency = (string) $invoice['currency'];
$number   = (string) ($invoice['number'] ?? '');
$ref      = (string) ($invoice['external_ref'] ?? '');
$refOk    = preg_match('#^https?://#i', $ref) === 1;
$heading  = $external ? ($number !== '' ? $number : lang('Invoices.externalTitle')) : $number;
$doc       = $invoice['document'] ?? null;
$reportDoc = $invoice['report_document'] ?? null;
$schema    = (int) ($invoice['snapshot_schema'] ?? 1);
$total    = MoneyFormat::format((string) $invoice['total'], $currency);
$entity   = (array) ($snap['entity'] ?? []);
$client   = (array) ($snap['client'] ?? []);
$bank     = (array) ($snap['bank'] ?? []);
$legal    = (array) ($snap['legal'] ?? []);
$legalTxt = (string) ($legal['texts']['vat_note']['body'] ?? '');
$hoursSum = '0.00';

foreach ($invoice['lines'] as $l) {
    $hoursSum = Hours::plus($hoursSum, (string) $l['hours']);
}
$empty = lang('Tasks.empty');
$status = (string) ($invoice['status'] ?? 'issued');
[$statusKey, $statusClass] = match (true) {
    $external          => ['Invoices.badgeExternal', 'text-bg-secondary'],
    $status === 'sent' => ['Invoices.statusSent', 'text-bg-light border'],
    $status === 'partially_paid' => ['Invoices.statusPartiallyPaid', 'text-bg-warning'],
    $status === 'paid' => ['Invoices.statusPaid', 'text-bg-success'],
    default            => ['Invoices.statusIssued', 'text-bg-secondary'],
};
$payState  = $payState ?? null;
$payments  = $payments ?? [];
$overdue   = ! $external && is_array($payState) && $payState['overdue'];
$canPay    = ! $external && in_array($status, ['issued', 'sent', 'partially_paid'], true);
$payPrimary = $canPay && $status !== 'issued';
$canSend = ! $external && $status === 'issued';
$canResend = ! $external && in_array($status, ['sent', 'partially_paid', 'paid'], true);
$history = (array) ($invoice['history'] ?? []);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc($heading) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <p class="mb-2"><a class="d-inline-block py-2" href="/invoices"><?= esc(lang('Invoices.backToList')) ?></a></p>

    <section class="app-card mb-4" aria-labelledby="inv-title">
        <h1 class="h4 mb-1" id="inv-title"><?= esc($heading) ?></h1>
        <p class="mb-1">
            <span class="st-badges">
                <span class="st-badge badge <?= esc($statusClass, 'attr') ?>"><?= esc(lang($statusKey)) ?></span>
                <?php if ($overdue) : ?>
                    <span class="st-badge badge text-bg-danger"><?= esc(lang('Invoices.statusOverdue', [$payState['days_overdue']])) ?></span>
                <?php endif ?>
            </span>
        </p>
        <p class="small text-body-secondary mb-3"><?= esc((string) $invoice['client_name']) ?><?= $dates['issue_date'] !== '' ? ' · ' . esc($dates['issue_date']) : '' ?></p>
        <?php if ($external) : ?>
            <p class="small text-body-secondary"><?= esc(lang('Invoices.externalHint')) ?></p>
            <?php if ($refOk) : ?>
                <p class="mb-3"><a class="d-inline-block py-2" href="<?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= esc(lang('Invoices.openDocument')) ?> &#8599;</a></p>
            <?php elseif ($ref !== '') : ?>
                <p class="small mb-3"><?= esc($ref) ?></p>
            <?php endif ?>
        <?php endif ?>

        <div class="inv-summary">
            <div class="small text-body-secondary"><?= esc(lang('Invoices.labelTotal')) ?></div>
            <div class="inv-summary-total tabular-nums"><?= esc($total) ?></div>
        </div>

        <?php if ($canSend) : ?>
            <div class="alert alert-secondary mt-3 mb-0" role="note"><?= esc(lang('Invoices.issuedSendHint', [$number])) ?></div>
        <?php endif ?>

        <?php if (! $external) : ?>
            <div class="inv-docs mt-3">
                <?php if ($canSend) : ?>
                    <a class="btn btn-primary btn-touch" href="/invoices/<?= $id ?>/send" hx-get="/invoices/<?= $id ?>/send" hx-target="#sheet" hx-swap="innerHTML"><?= esc(lang('Deliveries.sendCta')) ?></a>
                    <a class="btn btn-outline-secondary btn-touch" href="/invoices/<?= $id ?>/mark-sent" hx-get="/invoices/<?= $id ?>/mark-sent" hx-target="#sheet" hx-swap="innerHTML"><?= esc(lang('Deliveries.markSentCta')) ?></a>
                <?php endif ?>
<?php if ($payPrimary) : ?>
                <?php if ($canPay) : ?>
                    <a class="btn <?= $payPrimary ? 'btn-primary' : 'btn-outline-secondary' ?> btn-touch" href="/invoices/<?= $id ?>/payments" hx-get="/invoices/<?= $id ?>/payments" hx-target="#sheet" hx-swap="innerHTML"><?= esc(lang('Payments.cta')) ?></a>
                <?php endif ?>
<?php endif ?>
                <?php if ($canResend) : ?>
                    <a class="btn btn-outline-secondary btn-touch" href="/invoices/<?= $id ?>/resend" hx-get="/invoices/<?= $id ?>/resend" hx-target="#sheet" hx-swap="innerHTML"><?= esc(lang('Deliveries.resendCta')) ?></a>
                <?php endif ?>
<?php if (! $payPrimary) : ?>
                <?php if ($canPay) : ?>
                    <a class="btn <?= $payPrimary ? 'btn-primary' : 'btn-outline-secondary' ?> btn-touch" href="/invoices/<?= $id ?>/payments" hx-get="/invoices/<?= $id ?>/payments" hx-target="#sheet" hx-swap="innerHTML"><?= esc(lang('Payments.cta')) ?></a>
                <?php endif ?>
<?php endif ?>
                <a class="btn btn-outline-secondary btn-touch" href="/invoices/<?= $id ?>/pdf" target="_blank" rel="noopener noreferrer"><?= esc(lang('Invoices.invoicePdf')) ?></a>
                <?php if ($reportDoc !== null || $schema >= 2) : ?>
                    <a class="btn btn-outline-secondary btn-touch" href="/invoices/<?= $id ?>/report" target="_blank" rel="noopener noreferrer"><?= esc(lang('Invoices.reportPdf')) ?></a>
                <?php else : ?>
                    <span class="small text-body-secondary"><?= esc(lang('Invoices.noReport')) ?></span>
                <?php endif ?>
            </div>
            <?php if (is_array($doc)) : ?>
                <p class="small text-body-secondary mt-2 mb-0" title="<?= esc((string) $doc['sha256'], 'attr') ?>"><?= esc(lang('Invoices.shaHint', [substr((string) $doc['sha256'], 0, 16)])) ?></p>
            <?php endif ?>
            <?php if (is_array($reportDoc)) : ?>
                <p class="small text-body-secondary mt-1 mb-0" title="<?= esc((string) $reportDoc['sha256'], 'attr') ?>"><?= esc(lang('Invoices.shaHintReport', [substr((string) $reportDoc['sha256'], 0, 16)])) ?></p>
            <?php endif ?>
        <?php endif ?>
    </section>

    <section class="app-card mb-4" aria-labelledby="inv-items-title">
        <h2 class="h5 mb-2" id="inv-items-title"><?= esc(lang('Invoices.labelItems')) ?></h2>
        <?php foreach ($invoice['lines'] as $l) : ?>
            <div class="inv-line">
                <div>
                    <div class="fw-semibold"><?= esc((string) $l['title']) ?></div>
                    <div class="small text-body-secondary"><?= esc(lang('Invoices.lineMeta', [Hours::format((string) $l['hours'], $locale), MoneyFormat::format((string) $l['rate'], $currency), (string) ($l['vat_code'] ?? '-')])) ?></div>
                    <?php if ($l['task_exists']) : ?>
                        <a class="d-inline-block py-2 small" href="/tasks/<?= (int) $l['source_task_id'] ?>"><?= esc(lang('Invoices.showTask')) ?></a>
                    <?php endif ?>
                </div>
                <div class="fw-semibold text-end tabular-nums"><?= esc(MoneyFormat::format((string) $l['amount'], $currency)) ?></div>
            </div>
        <?php endforeach ?>
        <div class="inv-total">
            <div class="d-flex justify-content-between">
                <span><?= esc(lang('Invoices.labelTotal')) ?></span>
                <span class="tabular-nums"><?= esc($total) ?></span>
            </div>
            <div class="small text-body-secondary"><?= esc(lang('Invoices.totalMeta', [count($invoice['lines']), Hours::format($hoursSum, $locale)])) ?></div>
        </div>
    </section>

    <?php if (! $external) : ?>
        <?php if (is_array($payState)) : ?>
            <?= view('invoices/_payments', ['invoice' => $invoice, 'payState' => $payState, 'payments' => $payments, 'locale' => $locale, 'dates' => $dates, 'canPay' => $canPay]) ?>
        <?php endif ?>
        <?= view('invoices/_history', ['invoice' => $invoice, 'history' => $history, 'locale' => $locale]) ?>

        <section class="app-card mb-4" aria-labelledby="inv-data-title">
            <h2 class="h5 mb-2" id="inv-data-title"><?= esc(lang('Invoices.labelDetails')) ?></h2>
            <dl class="master-kv">
                <dt><?= esc(lang('Invoices.labelEntity')) ?></dt>
                <dd><?= esc((string) ($entity['trade_name'] ?? $empty)) ?><?= ($entity['company_id'] ?? null) !== null ? ' · ' . esc((string) $entity['company_id']) : '' ?></dd>
                <dt><?= esc(lang('Invoices.labelClient')) ?></dt>
                <dd><?= esc((string) ($client['name'] ?? $empty)) ?></dd>
                <dt><?= esc(lang('Invoices.labelIssueDate')) ?></dt>
                <dd><?= esc($dates['issue_date']) ?></dd>
                <dt><?= esc(lang('Invoices.labelDeliveryDate')) ?></dt>
                <dd><?= esc($dates['delivery_date']) ?></dd>
                <dt><?= esc(lang('Invoices.labelDueDate')) ?></dt>
                <dd><?= esc($dates['due_date']) ?></dd>
                <dt><?= esc(lang('Invoices.labelCurrency')) ?></dt>
                <dd><?= esc($currency) ?></dd>
                <dt><?= esc(lang('Invoices.labelBank')) ?></dt>
                <dd><?= esc((string) ($bank['iban'] ?? $empty)) ?></dd>
                <dt><?= esc(lang('Invoices.labelPayment')) ?></dt>
                <dd><?= esc(lang('Invoices.paymentTransfer')) ?></dd>
                <dt><?= esc(lang('Invoices.labelVatRegime')) ?></dt>
                <dd><?= esc((string) ($legal['vat_regime'] ?? $empty)) ?></dd>
                <dt><?= esc(lang('Invoices.labelExemption')) ?></dt>
                <dd><?= esc((string) ($legal['exemption_code'] ?? $empty)) ?></dd>
                <dt><?= esc(lang('Invoices.labelLegalText')) ?></dt>
                <dd class="small"><?= $legalTxt === '' ? esc($empty) : esc($legalTxt) ?></dd>
                <dt><?= esc(lang('Invoices.labelTemplate')) ?></dt>
                <dd><?= esc((string) ($invoice['template_version'] ?? $empty)) ?></dd>
                <dt><?= esc(lang('Invoices.labelReportTemplate')) ?></dt>
                <dd><?= is_array($reportDoc) ? esc((string) $reportDoc['template_version']) : esc($empty) ?></dd>
                <dt><?= esc(lang('Invoices.labelSha')) ?></dt>
                <dd class="small"><?= is_array($doc) ? esc((string) $doc['sha256']) : esc($empty) ?></dd>
            </dl>
        </section>

        <div class="alert alert-secondary small" role="note"><?= esc(lang('Invoices.immutableHint')) ?></div>
    <?php endif ?>
    <div id="sheet"></div>
</div>
<?= $this->endSection() ?>
