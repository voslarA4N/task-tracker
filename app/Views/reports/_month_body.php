<?php

use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Reporting\ReportResult;

/**
 * Fragment #report-body: hlavicka, prepinac obdobia a rozpad po klientoch. Iba formatuje, nepocita.
 *
 * @var list<array{id: int, name_internal: string, country: string, currency: string, ended: bool}> $entities
 * @var ?array{id: int, name_internal: string, country: string, currency: string, ended: bool}      $entity
 * @var ?int                                                                                        $entityId
 * @var ?ReportResult                                                                               $result
 * @var string                                                                                      $period
 * @var string                                                                                      $periodLabel
 * @var ?string                                                                                     $prevUrl
 * @var ?string                                                                                     $nextUrl
 * @var string                                                                                      $locale
 */
?>
<div id="report-body">
    <?= view('reports/_header', ['entities' => $entities, 'entity' => $entity, 'entityId' => $entityId, 'period' => $period]) ?>
    <?php if ($result === null) : ?>
        <div class="alert alert-secondary" role="status">
            <?= esc(lang('Reports.noEntity')) ?>
            <a href="/settings/entities"><?= esc(lang('Reports.noEntityLink')) ?></a>
        </div>
    <?php else : ?>
        <?php $money = static fn (string $amount): string => MoneyFormat::format($amount, $result->currency, $locale) ?>
        <div class="rpt-switch d-flex align-items-center justify-content-between gap-2 mb-3">
            <a class="btn btn-outline-secondary btn-touch" href="<?= esc((string) $prevUrl) ?>" hx-get="<?= esc((string) $prevUrl) ?>" hx-target="#report-body" hx-swap="outerHTML" hx-push-url="true"><?= esc(lang('Reports.prev')) ?></a>
            <span class="fs-5 fw-semibold" aria-current="page"><?= esc($periodLabel) ?></span>
            <?php if ($nextUrl !== null) : ?>
                <a class="btn btn-outline-secondary btn-touch" href="<?= esc($nextUrl) ?>" hx-get="<?= esc($nextUrl) ?>" hx-target="#report-body" hx-swap="outerHTML" hx-push-url="true"><?= esc(lang('Reports.next')) ?></a>
            <?php else : ?>
                <span class="btn btn-outline-secondary btn-touch disabled" aria-disabled="true"><?= esc(lang('Reports.next')) ?></span>
            <?php endif ?>
        </div>

        <div aria-live="polite">
            <section class="app-card mb-3">
                <dl class="master-kv mb-0">
                    <dt><?= esc(lang('Reports.summaryInvoiced')) ?></dt>
                    <dd><?= esc($money($result->invoicedTotal)) ?></dd>
                    <?php if ($result->countedTotal !== $result->invoicedTotal) : ?>
                        <dt><?= esc(lang('Reports.summaryCounted')) ?></dt>
                        <dd><?= esc($money($result->countedTotal)) ?></dd>
                    <?php endif ?>
                </dl>
            </section>

            <?php if ($result->isEmpty()) : ?>
                <section class="py-5 text-center">
                    <h2 class="h5"><?= esc(lang('Reports.emptyTitle')) ?></h2>
                    <p class="text-body-secondary mb-0"><?= esc(lang('Reports.emptyBody')) ?></p>
                </section>
            <?php else : ?>
                <div class="dt-only-lg">
                    <table class="dt-table table table-hover">
                        <caption class="visually-hidden"><?= esc(lang('Reports.caption', [$periodLabel])) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?= esc(lang('Reports.colClient')) ?></th>
                                <th scope="col" class="dt-num text-end"><?= esc(lang('Reports.colInvoiced')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result->clients as $row) : ?>
                                <tr>
                                    <td><?= esc($row['client_name']) ?></td>
                                    <td class="dt-num text-end"><?= esc($money($row['invoiced'])) ?></td>
                                </tr>
                            <?php endforeach ?>
                            <tr class="rpt-tot">
                                <th scope="row"><?= esc(lang('Reports.total')) ?></th>
                                <td class="dt-num text-end"><?= esc($money($result->invoicedTotal)) ?></td>
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
                            </dl>
                        </div>
                    <?php endforeach ?>
                    <div class="rpt-card rpt-tot app-card mb-2">
                        <dl class="master-kv mb-0">
                            <dt><?= esc(lang('Reports.total')) ?></dt>
                            <dd><?= esc($money($result->invoicedTotal)) ?></dd>
                        </dl>
                    </div>
                </div>
            <?php endif ?>

            <p class="small text-body-secondary mt-3 mb-1"><?= esc(lang('Reports.noteCreditNotes')) ?></p>
            <p class="small text-body-secondary"><?= esc(lang('Reports.noteExternalExcluded')) ?></p>
        </div>
    <?php endif ?>
</div>
