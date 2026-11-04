<?php

use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Reporting\ReportResult;

/**
 * Fragment #report-body rocneho pohladu: mesiace 1 az 12 a rozpad po klientoch. Iba formatuje, nepocita.
 *
 * @var list<array{id: int, name_internal: string, country: string, currency: string, ended: bool}> $entities
 * @var ?array{id: int, name_internal: string, country: string, currency: string, ended: bool}      $entity
 * @var ?int                                                                                        $entityId
 * @var ?ReportResult                                                                               $result
 * @var int                                                                                         $year
 * @var list<array{month: string, invoiced: string, counted: string, paid: string, label: string, href: string}> $monthRows
 * @var ?string                                                                                     $prevUrl
 * @var ?string                                                                                     $nextUrl
 * @var string                                                                                      $locale
 * @var string                                                                                      $view
 * @var string                                                                                      $monthKey
 * @var string                                                                                      $yearKey
 */
?>
<div id="report-body">
    <?= view('reports/_header', ['entities' => $entities, 'entity' => $entity, 'entityId' => $entityId, 'view' => $view, 'monthKey' => $monthKey, 'yearKey' => $yearKey]) ?>
    <?php if ($result === null) : ?>
        <div class="alert alert-secondary" role="status">
            <?= esc(lang('Reports.noEntity')) ?>
            <a href="/settings/entities"><?= esc(lang('Reports.noEntityLink')) ?></a>
        </div>
    <?php else : ?>
        <?php $money = static fn (string $amount): string => MoneyFormat::format($amount, $result->currency, $locale) ?>
        <div class="rpt-switch d-flex align-items-center justify-content-between gap-2 mb-3">
            <?php if ($prevUrl !== null) : ?>
                <a class="btn btn-outline-secondary btn-touch" href="<?= esc($prevUrl) ?>" hx-get="<?= esc($prevUrl) ?>" hx-target="#report-body" hx-swap="outerHTML" hx-push-url="true"><?= esc(lang('Reports.prev')) ?></a>
            <?php else : ?>
                <span class="btn btn-outline-secondary btn-touch disabled" aria-disabled="true"><?= esc(lang('Reports.prev')) ?></span>
            <?php endif ?>
            <span class="fs-5 fw-semibold" aria-current="page"><?= esc((string) $year) ?></span>
            <?php if ($nextUrl !== null) : ?>
                <a class="btn btn-outline-secondary btn-touch" href="<?= esc($nextUrl) ?>" hx-get="<?= esc($nextUrl) ?>" hx-target="#report-body" hx-swap="outerHTML" hx-push-url="true"><?= esc(lang('Reports.next')) ?></a>
            <?php else : ?>
                <span class="btn btn-outline-secondary btn-touch disabled" aria-disabled="true"><?= esc(lang('Reports.next')) ?></span>
            <?php endif ?>
        </div>

        <?= view('reports/_export', ['csvUrl' => '/reports/year.csv?' . http_build_query(['entity' => $entityId, 'year' => $year]), 'xlsxUrl' => '/reports/year.xlsx?' . http_build_query(['entity' => $entityId, 'year' => $year])]) ?>

        <div aria-live="polite">
            <section class="app-card mb-3">
                <dl class="master-kv mb-0">
                    <dt><?= esc(lang('Reports.summaryInvoiced')) ?></dt>
                    <dd><?= esc($money($result->invoicedTotal)) ?></dd>
                    <?php if ($result->countedTotal !== $result->invoicedTotal) : ?>
                        <dt><?= esc(lang('Reports.summaryCounted')) ?></dt>
                        <dd><?= esc($money($result->countedTotal)) ?></dd>
                    <?php endif ?>
                    <dt><?= esc(lang('Reports.summaryPaid')) ?></dt>
                    <dd><?= esc($money($result->paidTotal)) ?></dd>
                </dl>
            </section>

            <h2 class="h5 mt-4"><?= esc(lang('Reports.sectionByMonth')) ?></h2>
            <div class="dt-only-lg">
                <table class="dt-table table table-hover">
                    <caption class="visually-hidden"><?= esc(lang('Reports.captionMonths', [(string) $year])) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= esc(lang('Reports.colMonth')) ?></th>
                            <th scope="col" class="dt-num text-end"><?= esc(lang('Reports.colInvoiced')) ?></th>
                            <th scope="col" class="dt-num text-end"><?= esc(lang('Reports.colPaid')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthRows as $row) : ?>
                            <tr>
                                <td><a class="rpt-month-link" href="<?= esc($row['href']) ?>"><?= esc($row['label']) ?></a></td>
                                <td class="dt-num text-end"><?= esc($money($row['invoiced'])) ?></td>
                                <td class="dt-num text-end"><?= esc($money($row['paid'])) ?></td>
                            </tr>
                        <?php endforeach ?>
                        <tr class="rpt-tot">
                            <th scope="row"><?= esc(lang('Reports.totalYear', [(string) $year])) ?></th>
                            <td class="dt-num text-end"><?= esc($money($result->invoicedTotal)) ?></td>
                            <td class="dt-num text-end"><?= esc($money($result->paidTotal)) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="dt-only-sm">
                <?php foreach ($monthRows as $row) : ?>
                    <div class="rpt-card app-card mb-2">
                        <p class="mb-1"><a class="d-inline-block py-2" href="<?= esc($row['href']) ?>"><?= esc($row['label']) ?></a></p>
                        <dl class="master-kv mb-0">
                            <dt><?= esc(lang('Reports.colInvoiced')) ?></dt>
                            <dd><?= esc($money($row['invoiced'])) ?></dd>
                            <dt><?= esc(lang('Reports.colPaid')) ?></dt>
                            <dd><?= esc($money($row['paid'])) ?></dd>
                        </dl>
                    </div>
                <?php endforeach ?>
                <div class="rpt-card rpt-tot app-card mb-2">
                    <dl class="master-kv mb-0">
                        <dt><?= esc(lang('Reports.totalYear', [(string) $year])) ?></dt>
                        <dd><?= esc($money($result->invoicedTotal)) ?></dd>
                        <dt><?= esc(lang('Reports.colPaid')) ?></dt>
                        <dd><?= esc($money($result->paidTotal)) ?></dd>
                    </dl>
                </div>
            </div>

            <h2 class="h5 mt-4"><?= esc(lang('Reports.sectionByClient')) ?></h2>
            <?php if ($result->isEmpty()) : ?>
                <section class="py-5 text-center">
                    <h3 class="h5"><?= esc(lang('Reports.emptyTitle')) ?></h3>
                    <p class="text-body-secondary mb-0"><?= esc(lang('Reports.emptyBody')) ?></p>
                </section>
            <?php else : ?>
                <div class="dt-only-lg">
                    <table class="dt-table table table-hover">
                        <caption class="visually-hidden"><?= esc(lang('Reports.captionClients', [(string) $year])) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?= esc(lang('Reports.colClient')) ?></th>
                                <th scope="col" class="dt-num text-end"><?= esc(lang('Reports.colInvoiced')) ?></th>
                                <th scope="col" class="dt-num text-end"><?= esc(lang('Reports.colPaid')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result->clients as $row) : ?>
                                <tr>
                                    <td><?= esc($row['client_name']) ?></td>
                                    <td class="dt-num text-end"><?= esc($money($row['invoiced'])) ?></td>
                                    <td class="dt-num text-end"><?= esc($money($row['paid'])) ?></td>
                                </tr>
                            <?php endforeach ?>
                            <tr class="rpt-tot">
                                <th scope="row"><?= esc(lang('Reports.total')) ?></th>
                                <td class="dt-num text-end"><?= esc($money($result->invoicedTotal)) ?></td>
                                <td class="dt-num text-end"><?= esc($money($result->paidTotal)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="dt-only-sm">
                    <?php foreach ($result->clients as $row) : ?>
                        <div class="rpt-card app-card mb-2">
                            <p class="mb-1"><?= esc($row['client_name']) ?></p>
                            <dl class="master-kv mb-0">
                                <dt><?= esc(lang('Reports.colInvoiced')) ?></dt>
                                <dd><?= esc($money($row['invoiced'])) ?></dd>
                                <dt><?= esc(lang('Reports.colPaid')) ?></dt>
                                <dd><?= esc($money($row['paid'])) ?></dd>
                            </dl>
                        </div>
                    <?php endforeach ?>
                    <div class="rpt-card rpt-tot app-card mb-2">
                        <dl class="master-kv mb-0">
                            <dt><?= esc(lang('Reports.total')) ?></dt>
                            <dd><?= esc($money($result->invoicedTotal)) ?></dd>
                            <dt><?= esc(lang('Reports.colPaid')) ?></dt>
                            <dd><?= esc($money($result->paidTotal)) ?></dd>
                        </dl>
                    </div>
                </div>
            <?php endif ?>

            <p class="small text-body-secondary mt-3 mb-1"><?= esc(lang('Reports.noteCreditNotes')) ?></p>
            <p class="small text-body-secondary"><?= esc(lang('Reports.noteExternalExcluded')) ?></p>
        </div>
    <?php endif ?>
</div>
