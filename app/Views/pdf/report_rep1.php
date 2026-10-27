<?php

declare(strict_types=1);

use App\Libraries\Invoicing\PdfFormat;

/**
 * Sablona rep-1. Dostava iba snapshot $s; sucty sa neprepocitavaju, citaju sa z totals.
 *
 * @var array<string, mixed> $s
 */
$l     = $s['labels']['report'];
$lg    = (string) $s['doc_language'];
$cur   = (string) $s['currency'];
$r     = $s['report'];
$print = $s['legal']['print'];
$showRate = $r['uniform_rate'] === null;

$rowsByPos = [];
foreach ($r['rows'] as $row) {
    $rowsByPos[$row['position']] = $row;
}

$party = static function (array $keys, array $x, array $labels, bool $supplier): string {
    $out = [];
    foreach ($keys as $key) {
        if ($key === 'trade_name' || $key === 'name') {
            $out[] = '<b>' . esc((string) $x[$key]) . '</b>';
        } elseif ($key === 'address') {
            $out[] = esc((string) $x['address_line1']) . ', ' . esc((string) $x['postal_code']) . ' ' . esc((string) $x['city']);
        } elseif ($key === 'company_id' || $key === 'identifier') {
            $value = $x[$key === 'company_id' ? 'company_id' : 'identifier_value'] ?? null;
            if ($value !== null && $value !== '') {
                $out[] = esc((string) $value);
            }
        }
    }

    return implode('<br>', $out);
};
?>
<h1><?= esc($l['title']) ?></h1>
<div class="ref"><?= esc($l['period']) ?>: <?= esc((string) $r['period']) ?> · <?= esc(str_replace('{0}', (string) $s['number'], $l['invoiceRef'])) ?></div>
<table class="parties">
    <tr>
        <td><b><?= esc($s['labels']['supplier']) ?></b><br><?= $party($print['supplier'], $s['entity'], $s['labels'], true) ?></td>
        <td><b><?= esc($s['labels']['customer']) ?></b><br><?= $party($print['customer'], $s['client'], $s['labels'], false) ?></td>
    </tr>
</table>
<table class="items">
    <thead>
        <tr>
            <th>#</th><th><?= esc($l['portal']) ?></th><th><?= esc($l['task']) ?></th><th class="r"><?= esc($l['estimated']) ?></th><th class="r"><?= esc($l['billed']) ?></th>
            <?php if ($showRate) : ?><th class="r"><?= esc($l['rate']) ?></th><?php endif; ?>
            <th class="r"><?= esc($l['amount']) ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($s['lines'] as $line) : ?>
            <?php $row = $rowsByPos[$line['position']]; ?>
            <tr>
                <td><?= esc((string) $line['position']) ?></td>
                <td>
                    <?php if ($row['portal_url'] !== null) : ?>
                        <a href="<?= esc((string) $row['portal_url'], 'attr') ?>"><?= esc((string) $row['portal_label']) ?></a>
                    <?php else : ?>
                        <?= esc((string) ($row['portal_label'] ?? '')) ?>
                    <?php endif; ?>
                </td>
                <td><?= esc($line['title']) ?></td>
                <td class="r"><?= esc((string) ($row['estimated'] ?? '')) ?></td>
                <td class="r"><?= esc($row['billed']) ?></td>
                <?php if ($showRate) : ?><td class="r"><?= esc(PdfFormat::amount($line['rate'], $cur, $lg)) ?></td><?php endif; ?>
                <td class="r"><?= esc(PdfFormat::amount($line['amount'], $cur, $lg)) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<table class="totals">
    <tr>
        <td><?= esc($l['total']) ?></td>
        <td class="r"><?= esc(str_replace('{0}', PdfFormat::hours($s['totals']['hours'], $lg), $l['hoursOnly'])) ?></td>
        <td class="r"><?= esc(PdfFormat::amount($s['totals']['total'], $cur, $lg)) ?></td>
    </tr>
</table>
<?php if (! $showRate) : ?>
    <p><?= esc(str_replace('{0}', PdfFormat::amount((string) $r['uniform_rate'], $cur, $lg), $l['rateLine'])) ?></p>
<?php endif; ?>
<?php if ($print['vat_note']) : ?>
    <p class="note"><span class="note-heading"><?= esc($l['vatNoteHeading']) ?></span><br><?= esc($s['legal']['texts']['vat_note']['body']) ?></p>
<?php endif; ?>
