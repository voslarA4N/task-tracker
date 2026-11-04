<?php

/**
 * Historia verzii dokumentov vystavenej faktury (D-03). Karty bez tabulky, zalamuju sa na mobile.
 *
 * @var array<string, mixed>  $invoice
 * @var array<string, mixed>  $dates
 */
$id       = (int) $invoice['id'];
$versions = (array) ($invoice['document_versions'] ?? []);
$isCn     = ($invoice['type'] ?? 'invoice') === 'credit_note';
$latest   = [];

foreach ($versions as $v) {
    $latest[$v['kind']] = max($latest[$v['kind']] ?? 0, (int) $v['version']);
}

// najprv faktura/dobropis, potom vykaz; v ramci druhu od najnovsej
usort($versions, static fn (array $a, array $b): int => [$a['kind'] === 'report' ? 1 : 0, -$a['version']] <=> [$b['kind'] === 'report' ? 1 : 0, -$b['version']]);
?>
<?php if ($versions !== []) : ?>
    <section class="app-card mb-4" aria-labelledby="inv-versions-title">
        <h2 class="h5 mb-2" id="inv-versions-title"><?= esc(lang('Invoices.versionsTitle')) ?></h2>
        <ul class="list-group list-group-flush">
            <?php foreach ($versions as $v) : ?>
                <?php
                $kind   = (string) $v['kind'];
                $n      = (int) $v['version'];
                $kindKey = $kind === 'report' ? 'Invoices.versionsKindReport' : ($isCn ? 'Invoices.versionsKindCreditNote' : 'Invoices.versionsKindInvoice');
                $href   = '/invoices/' . $id . '/' . ($kind === 'report' ? 'report' : 'pdf') . '/v/' . $n;
                $sha    = (string) $v['sha256'];
                ?>
                <li class="list-group-item px-0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-semibold"><?= esc(lang($kindKey)) ?> · <?= esc(lang('Invoices.versionsLabel', [$n])) ?></span>
                        <?php if ($n === ($latest[$kind] ?? 0)) : ?>
                            <span class="badge text-bg-success"><?= esc(lang('Invoices.versionsLatest')) ?></span>
                        <?php endif ?>
                    </div>
                    <div class="small text-body-secondary mt-1">
                        <?= esc(lang('Invoices.versionsTemplate')) ?>: <code><?= esc((string) $v['template_version']) ?></code>
                        · <?= esc(lang('Invoices.versionsCreated')) ?>: <?= esc((string) ($dates['versions'][$kind . '-' . $n] ?? '')) ?>
                        · <code title="<?= esc($sha, 'attr') ?>"><?= esc(substr($sha, 0, 12)) ?></code>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <a class="btn btn-outline-secondary btn-touch" href="<?= $href ?>" target="_blank" rel="noopener noreferrer"><?= esc(lang('Invoices.versionsDownload')) ?></a>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    </section>
<?php endif ?>
