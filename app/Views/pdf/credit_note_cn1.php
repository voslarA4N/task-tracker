<?php

declare(strict_types=1);

use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Invoicing\PdfFormat;

/**
 * Sablona cn-1 (dobropis). Dostava iba snapshot $s; iba udaje zo snapshotu, polia podla legal.print.
 *
 * @var array<string, mixed> $s
 */
$l     = $s['labels'];
$lg    = (string) $s['doc_language'];
$cur   = (string) $s['currency'];
$e     = $s['entity'];
$c     = $s['client'];
$print = $s['legal']['print'];

$addr = static function (array $x): string {
    $out = esc($x['address_line1']);
    if (! empty($x['address_line2'])) {
        $out .= '<br>' . esc($x['address_line2']);
    }

    return $out . '<br>' . esc($x['postal_code']) . ' ' . esc($x['city']);
};

$partyRows = static function (array $keys, array $x, array $labels, bool $supplier) use ($addr): array {
    $rows = [];
    foreach ($keys as $key) {
        switch ($key) {
            case 'trade_name':
            case 'name':
                $rows[] = '<b>' . esc((string) $x[$key]) . '</b>';
                break;

            case 'address':
                $rows[] = $addr($x);
                break;

            default:
                $map = $supplier
                    ? ['company_id' => ['companyId', 'company_id'], 'tax_id' => ['tax_id', 'tax_id'], 'vat_id' => ['vatId', 'vat_id'], 'registry_note' => [null, 'registry_note']]
                    : ['identifier' => ['identifier', 'identifier_value'], 'tax_id' => ['tax_id', 'tax_id'], 'vat_id' => ['vatId', 'vat_id']];
                [$labelKey, $field] = $map[$key];
                $value              = $x[$field] ?? null;
                if ($value === null || $value === '') {
                    break;
                }
                if ($labelKey === 'tax_id') {
                    $labelKey = ($x['country'] ?? '') === 'PL' ? 'nip' : 'taxId';
                }
                $rows[] = $labelKey === null ? esc((string) $value) : esc((string) $labels[$labelKey]) . ': ' . esc((string) $value);
        }
    }

    return $rows;
};

$hasVatCol = $print['line_vat_code'];
$unit      = $print['line_unit'] ? ' ' . $l['unitHours'] : '';
$corr      = $s['correction'];
?>
<h1><?= esc($l['cnTitle']) ?> <?= esc($s['number']) ?></h1>
<table class="parties">
    <tr>
        <td>
            <b><?= esc($l['supplier']) ?></b><br>
            <?= implode('<br>', $partyRows($print['supplier'], $e, $l, true)) ?>
        </td>
        <td>
            <b><?= esc($l['customer']) ?></b><br>
            <?= implode('<br>', $partyRows($print['customer'], $c, $l, false)) ?>
        </td>
    </tr>
</table>
<table class="meta">
    <tr><td><?= esc($l['issueDate']) ?>: <?= esc(PdfFormat::date($s['issue_date'], $lg)) ?></td></tr>
    <tr><td><?= esc($l['deliveryDate']) ?>: <?= esc(PdfFormat::date($s['delivery_date'], $lg)) ?></td></tr>
</table>
<div class="correction"><?= esc($l['cnCorrects']) ?> <?= esc((string) $corr['original_number']) ?> <?= esc($l['cnOfDate']) ?> <?= esc(PdfFormat::date($corr['original_issue_date'], $lg)) ?></div>
<div><?= esc($l['cnType']) ?>: <?= esc((string) $l[CorrectionType::from((string) $corr['type'])->pdfLabelKey()]) ?></div>
<div class="correction-reason"><?= esc($l['cnReason']) ?>: <?= nl2br(esc((string) $corr['reason'])) ?></div>
<div class="correction-reason"><?= esc($l['cnScopeFull']) ?></div>
<table class="items">
    <thead>
        <tr><th>#</th><th><?= esc($l['item']) ?></th><th class="r"><?= esc($l['hours']) ?></th><th class="r"><?= esc($l['rate']) ?></th><th class="r"><?= esc($l['amount']) ?></th><?php if ($hasVatCol) : ?><th><?= esc($l['vatCode']) ?></th><?php endif; ?></tr>
    </thead>
    <tbody>
        <?php foreach ($s['lines'] as $line) : ?>
            <tr>
                <td><?= esc((string) $line['position']) ?></td>
                <td><?= esc($line['title']) ?></td>
                <td class="r"><?= esc(PdfFormat::hours($line['hours'], $lg) . $unit) ?></td>
                <td class="r"><?= esc(PdfFormat::amount($line['rate'], $cur, $lg)) ?></td>
                <td class="r"><?= esc(PdfFormat::amount($line['amount'], $cur, $lg)) ?></td>
                <?php if ($hasVatCol) : ?><td><?= esc((string) $line['vat_code']) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<table class="totals">
    <tr><td><?= esc($l['total']) ?></td><td class="r"><?= esc(PdfFormat::amount($s['totals']['total'], $cur, $lg)) ?></td></tr>
<?php if (! empty($s['fx'])) : ?>
    <tr><td colspan="2" class="r fxnote"><?= esc((string) ($s['fx']['text']['rate_line'] ?? '')) ?></td></tr>
    <tr><td colspan="2" class="r fxnote"><?= esc((string) ($s['fx']['text']['date_line'] ?? '')) ?></td></tr>
    <tr><td><?= esc((string) ($s['fx']['text']['total_label'] ?? '')) ?></td><td class="r"><?= esc((string) ($s['fx']['text']['total_value'] ?? '')) ?></td></tr>
<?php endif; ?>
</table>
<?php if ($print['vat_note']) : ?>
    <p class="note"><span class="note-heading"><?= esc($l['vatNoteHeading']) ?></span><br><?= esc($s['legal']['texts']['vat_note']['body']) ?></p>
<?php endif; ?>
