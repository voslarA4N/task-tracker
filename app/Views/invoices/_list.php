<?php

use App\Libraries\Platform\MoneyFormat;

/**
 * Fragment zoznamu faktur (#invoice-list).
 *
 * @var list<array<string, mixed>> $rows
 * @var bool                       $truncated
 */
?>
<div id="invoice-list">
    <p class="small text-body-secondary mb-2"><?= esc(lang('Invoices.countHint', [count($rows)])) ?></p>
    <?php if ($rows === []) : ?>
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
                ?>
                <a class="inv-row" href="/invoices/<?= (int) $row['id'] ?>">
                    <span class="inv-row-title"><?= esc($title) ?></span>
                    <span class="badge <?= $external ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= esc(lang($external ? 'Invoices.badgeExternal' : 'Invoices.badgeIssued')) ?></span>
                    <span class="inv-row-meta w-100"><?= esc($meta) ?></span>
                    <span class="inv-row-amount w-100"><?= esc(MoneyFormat::format((string) $row['total'], (string) $row['currency'])) ?></span>
                </a>
            <?php endforeach ?>
        </section>
        <?php if ($truncated) : ?>
            <p class="small text-body-secondary mt-2"><?= esc(lang('Invoices.limitHint')) ?></p>
        <?php endif ?>
    <?php endif ?>
</div>
