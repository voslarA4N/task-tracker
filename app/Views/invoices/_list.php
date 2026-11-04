<?php

use App\Libraries\Invoicing\InvoiceFilter;
use App\Libraries\Platform\MoneyFormat;

/**
 * Fragment zoznamu faktur (#invoice-list).
 *
 * @var list<array<string, mixed>> $rows
 * @var bool                       $truncated
 * @var string                     $status
 * @var InvoiceFilter              $filter
 */
$filtered = $filter->activeCount() > 0;
$cols     = [
    'number'           => ['Invoices.colNumber', false],
    'client'           => ['Invoices.colClient', false],
    'issue_date'       => ['Invoices.colIssued', false],
    'turnover_date'    => ['Invoices.colTurnover', false],
    'due_date'         => ['Invoices.colDue', false],
    'currency'         => ['Invoices.colCurrency', false],
    'total'            => ['Invoices.colTotal', true],
    'total_entity_ccy' => ['Invoices.colEntityTotal', true],
];
?>
<div id="invoice-list">
    <input type="hidden" name="sort" value="<?= esc($filter->sort, 'attr') ?>" form="inv-filter">
    <input type="hidden" name="dir" value="<?= esc($filter->dir, 'attr') ?>" form="inv-filter">
    <p class="small text-body-secondary mb-2"><?= esc(lang('Invoices.countHint', [count($rows)])) ?></p>
    <?php if ($rows !== [] || $filtered) : ?>
        <?php $exportQuery = http_build_query($filter->toQuery()); ?>
        <div class="rpt-export d-flex align-items-center flex-wrap gap-2 mb-3">
            <span class="small text-body-secondary"><?= esc(lang('Export.label')) ?></span>
            <a class="btn btn-outline-secondary btn-touch" href="<?= esc('/invoices.csv' . ($exportQuery !== '' ? '?' . $exportQuery : '')) ?>" download><?= esc(lang('Export.csv')) ?></a>
            <a class="btn btn-outline-secondary btn-touch" href="<?= esc('/invoices.xlsx' . ($exportQuery !== '' ? '?' . $exportQuery : '')) ?>" download><?= esc(lang('Export.xlsx')) ?></a>
            <?php if ($truncated) : ?>
                <span class="small text-body-secondary"><?= esc(lang('Export.hintAllRows')) ?></span>
            <?php endif ?>
        </div>
    <?php endif ?>
    <?php if ($rows === [] && $filtered) : ?>
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
        <div class="dt-only-sm">
        <section class="app-card p-0 overflow-hidden" aria-label="<?= esc(lang('Invoices.title'), 'attr') ?>">
            <?php foreach ($rows as $row) : ?>
                <?php
                $external = $row['origin'] === 'external';
                $number   = (string) ($row['number'] ?? '');
                $title    = $external ? ($number !== '' ? $number : lang('Invoices.externalTitle')) : $number;
                $meta     = $row['issue_date_label'] !== '' ? lang('Invoices.rowMeta', [(string) $row['client_name'], (string) $row['issue_date_label']]) : (string) $row['client_name'];
                $st       = (string) ($row['status'] ?? 'issued');
                $isCn     = ($row['type'] ?? 'invoice') === 'credit_note';
                ?>
                <a class="inv-row" href="/invoices/<?= (int) $row['id'] ?>">
                    <span class="inv-row-title"><?= esc($title) ?></span>
                    <span class="st-badges">
                        <?= view('invoices/_status_badges', ['row' => $row]) ?>
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
        </div>
        <div class="dt-only-lg">
            <table class="table table-hover dt-table">
                <caption class="visually-hidden"><?= esc(lang('Invoices.tableCaption')) ?></caption>
                <thead>
                    <tr>
                        <?php foreach ($cols as $col => [$key, $num]) : ?>
                            <?php
                            $active = $filter->sort === $col;
                            $aria   = $active ? ($filter->dir === 'asc' ? 'ascending' : 'descending') : 'none';
                            $href   = '/invoices?' . http_build_query($filter->withSort($col));
                            ?>
                            <th scope="col" aria-sort="<?= esc($aria, 'attr') ?>"<?= $num ? ' class="dt-num"' : '' ?>>
                                <a class="dt-sort" href="<?= esc($href, 'attr') ?>" hx-get="<?= esc($href, 'attr') ?>" hx-target="#invoice-list" hx-swap="outerHTML" hx-push-url="true"><?= esc(lang($key)) ?><span aria-hidden="true"><?= $active ? ($filter->dir === 'asc' ? '▲' : '▼') : '' ?></span></a>
                            </th>
                        <?php endforeach ?>
                        <th scope="col"><?= esc(lang('Invoices.colStatus')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <?php
                        $external = $row['origin'] === 'external';
                        $number   = (string) ($row['number'] ?? '');
                        $title    = $external ? ($number !== '' ? $number : lang('Invoices.externalTitle')) : $number;
                        $entCcy   = trim((string) ($row['entity_currency'] ?? ''));
                        ?>
                        <tr>
                            <td><a href="/invoices/<?= (int) $row['id'] ?>"><?= esc($title) ?></a></td>
                            <td><?= esc((string) $row['client_name']) ?></td>
                            <td><?= esc((string) $row['issue_date_label']) ?></td>
                            <td><?= esc((string) $row['turnover_date_label']) ?></td>
                            <td><?= esc((string) $row['due_date_label']) ?></td>
                            <td><?= esc((string) $row['currency']) ?></td>
                            <td class="dt-num"><?= esc(MoneyFormat::format((string) $row['total'], (string) $row['currency'])) ?></td>
                            <td class="dt-num"><?= ($row['total_entity_ccy'] ?? null) !== null && $entCcy !== '' ? esc(MoneyFormat::format((string) $row['total_entity_ccy'], $entCcy)) : '—' ?></td>
                            <td><?= view('invoices/_status_badges', ['row' => $row]) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php if ($truncated) : ?>
            <p class="small text-body-secondary mt-2"><?= esc(lang('Invoices.limitHint')) ?></p>
        <?php endif ?>
    <?php endif ?>
</div>
