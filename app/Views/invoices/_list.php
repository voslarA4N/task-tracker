<?php

use App\Libraries\Platform\MoneyFormat;

/**
 * Fragment zoznamu faktur (#invoice-list).
 *
 * @var list<array<string, mixed>> $rows
 * @var bool                       $truncated
 * @var string                     $status
 */
?>
<div id="invoice-list">
    <p class="small text-body-secondary mb-2"><?= esc(lang('Invoices.countHint', [count($rows)])) ?></p>
    <?php if ($rows === [] && ($status ?? '') !== '') : ?>
        <section class="app-card">
            <p class="text-muted mb-0"><?= esc(lang('Invoices.emptyFiltered')) ?></p>
        </section>
    <?php elseif ($rows === []) : ?>
        <section class="app-card">
            <p class="fw-semibold mb-1"><?= esc(lang('Invoices.emptyTitle')) ?></p>
            <p class="text-muted mb-3"><?= esc(lang('Invoices.emptyBody')) ?></p>
            <a class="btn btn-primary btn-touch d-inline-flex align-items-center" href="/invoices/new"><?= esc(lang('Invoices.newCta')) ?></a>
        </section>
    <?php else : ?>
        <section class="app-card p-0 overflow-hidden" aria-label="<?= esc(lang('Invoices.title'), 'attr') ?>">
            <?php foreach ($rows as $row) : ?>
                <?php
                $external = $row['origin'] === 'external';
                $number   = (string) ($row['number'] ?? '');
                $title    = $external ? ($number !== '' ? $number : lang('Invoices.externalTitle')) : $number;
                $meta     = $row['issue_date_label'] !== '' ? lang('Invoices.rowMeta', [(string) $row['client_name'], (string) $row['issue_date_label']]) : (string) $row['client_name'];
                $st       = (string) ($row['status'] ?? 'issued');
                $isCn     = ($row['type'] ?? 'invoice') === 'credit_note';
                [$stKey, $stClass] = match (true) {
                    $isCn                    => ['Invoices.badgeCreditNote', 'text-bg-info'],
                    $st === 'cancelled'      => ['Invoices.statusCancelled', 'text-bg-dark'],
                    $st === 'sent'           => ['Invoices.statusSent', 'text-bg-light border'],
                    $st === 'partially_paid' => ['Invoices.statusPartiallyPaid', 'text-bg-warning'],
                    $st === 'paid'           => ['Invoices.statusPaid', 'text-bg-success'],
                    default                  => ['Invoices.statusIssued', 'text-bg-secondary'],
                };
                ?>
                <a class="inv-row" href="/invoices/<?= (int) $row['id'] ?>">
                    <span class="inv-row-title"><?= esc($title) ?></span>
                    <span class="st-badges">
                        <?php if ($external) : ?>
                            <span class="st-badge badge text-bg-secondary"><?= esc(lang('Invoices.badgeExternal')) ?></span>
                        <?php else : ?>
                            <span class="st-badge badge <?= esc($stClass, 'attr') ?>"><?= esc(lang($stKey)) ?></span>
                            <?php if ($isCn) : ?>
                                <span class="st-badge badge text-bg-secondary"><?= esc(lang(! empty($row['sent_at']) ? 'Invoices.statusCnSent' : 'Invoices.statusCnIssued')) ?></span>
                            <?php endif ?>
                            <?php if (! empty($row['overdue']) && ! $isCn && $st !== 'cancelled') : ?>
                                <span class="st-badge badge text-bg-danger"><?= esc(lang('Invoices.statusOverdue', [(int) $row['days_overdue']])) ?></span>
                            <?php endif ?>
                        <?php endif ?>
                    </span>
                    <span class="inv-row-meta w-100"><?= esc($meta) ?></span>
                    <?php if ($isCn && (string) ($row['corrected_number'] ?? '') !== '') : ?>
                        <span class="small text-body-secondary w-100"><?= esc(lang('Invoices.listCorrects', [(string) $row['corrected_number']])) ?></span>
                    <?php elseif (! $isCn && $st === 'cancelled' && (string) ($row['credit_note_number'] ?? '') !== '') : ?>
                        <span class="small text-body-secondary w-100"><?= esc(lang('Invoices.listCreditNote', [(string) $row['credit_note_number']])) ?></span>
                    <?php endif ?>
                    <span class="inv-row-amount w-100"><?= esc(MoneyFormat::format((string) $row['total'], (string) $row['currency'])) ?></span>
                    <?php if (($row['fx_rate'] ?? null) !== null && trim((string) ($row['entity_currency'] ?? '')) !== '') : ?>
                        <span class="fx-sub w-100"><?= esc(MoneyFormat::format((string) $row['total_entity_ccy'], trim((string) $row['entity_currency']))) ?></span>
                    <?php endif ?>
                    <?php if (! $external && $st === 'partially_paid') : ?>
                        <span class="inv-row-meta w-100"><?= esc(lang('Invoices.balanceLeft', [MoneyFormat::format((string) $row['balance'], (string) $row['currency'])])) ?></span>
                    <?php endif ?>
                </a>
            <?php endforeach ?>
        </section>
        <?php if ($truncated) : ?>
            <p class="small text-body-secondary mt-2"><?= esc(lang('Invoices.limitHint')) ?></p>
        <?php endif ?>
    <?php endif ?>
</div>
