<?php

declare(strict_types=1);

use App\Libraries\Invoicing\PdfFormat;

/**
 * Sablona inv-2. Dostava iba snapshot $s; iba udaje zo snapshotu, polia podla legal.print.
 *
 * @var array<string, mixed>                              $s
 * @var array{uri: string, standard: string, caption: string}|null $qr
 */
$l     = $s['labels'];
$lg    = (string) $s['doc_language'];
$cur   = (string) $s['currency'];
$e     = $s['entity'];
$c     = $s['client'];
$b     = $s['bank'];
$p     = $s['payment'];
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

$methodLabel = ['transfer' => 'paymentTransfer', 'cash' => 'paymentCash', 'card' => 'paymentCard', 'other' => 'paymentOther'][$p['method']] ?? 'paymentOther';
$showBank    = in_array($p['method'], ['transfer', 'other'], true);
$hasVatCol   = $print['line_vat_code'];
$unit        = $print['line_unit'] ? ' ' . $l['unitHours'] : '';
?>
<h1><?= esc($l['invoice']) ?> <?= esc($s['number']) ?></h1>
<div class="ref">
    <?php if ($print['payment_reference'] === 'variable_symbol') : ?>
        <?= esc($l['variableSymbol']) ?>: <?= esc((string) ($s['variable_symbol'] ?? $s['number'])) ?>
    <?php else : ?>
        <?= esc($l['paymentTitle']) ?>: <?= esc($s['number']) ?>
    <?php endif; ?>
</div>
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
<table class="payblock"><tr><td class="payleft<?= $qr === null ? ' full' : '' ?>">
<table class="meta">
    <?php foreach ($print['meta'] as $key) : ?>
        <?php if ($key === 'issue_date') : ?>
            <tr><td><?= esc($l['issueDate']) ?>: <?= esc(PdfFormat::date($s['issue_date'], $lg)) ?></td></tr>
        <?php elseif ($key === 'delivery_date') : ?>
            <tr><td><?= esc($l['deliveryDate']) ?>: <?= esc(PdfFormat::date($s['delivery_date'], $lg)) ?></td></tr>
        <?php elseif ($key === 'due_date') : ?>
            <tr><td><?= esc($l['dueDate']) ?>: <?= esc(PdfFormat::date($s['due_date'], $lg)) ?></td></tr>
        <?php elseif ($key === 'payment_method') : ?>
            <tr><td><?= esc($l['paymentMethod']) ?>: <?= esc($l[$methodLabel]) ?></td></tr>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($showBank) : ?>
        <tr><td><?= esc($l['bankAccount']) ?>: <?= esc($b['iban']) ?></td></tr>
        <?php if (! empty($b['swift'])) : ?><tr><td><?= esc($l['swift']) ?>: <?= esc($b['swift']) ?></td></tr><?php endif; ?>
        <?php if (! empty($b['label'])) : ?><tr><td><?= esc($l['bankName']) ?>: <?= esc($b['label']) ?></td></tr><?php endif; ?>
    <?php endif; ?>
</table>
</td>
<?php if ($qr !== null) : ?>
    <td class="payqr"><img src="<?= esc($qr['uri']) ?>" width="32mm" height="32mm"><div class="qr-caption"><?= esc($qr['standard']) ?><br><?= esc($qr['caption']) ?></div></td>
<?php endif; ?>
</tr></table>
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
</table>
<?php if (! empty($s['fx'])) : ?>
    <p class="small"><?= esc((string) ($s['fx']['text'] ?? '')) ?></p>
<?php endif; ?>
<?php if ($print['vat_note']) : ?>
    <p class="note"><span class="note-heading"><?= esc($l['vatNoteHeading']) ?></span><br><?= esc($s['legal']['texts']['vat_note']['body']) ?></p>
<?php endif; ?>
<?php if (! empty($p['note'])) : ?>
    <p class="note"><span class="note-heading"><?= esc($l['note']) ?></span><br><?= nl2br(esc((string) $p['note'])) ?></p>
<?php endif; ?>
