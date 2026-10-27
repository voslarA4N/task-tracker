<?php

declare(strict_types=1);

use App\Libraries\Invoicing\PdfFormat;

/**
 * Sablona inv-1. Dostava iba snapshot $s; iba udaje zo snapshotu.
 *
 * @var array<string, mixed> $s
 */
$l   = $s['labels'];
$lg  = (string) $s['doc_language'];
$cur = (string) $s['currency'];
$e   = $s['entity'];
$c   = $s['client'];
$b   = $s['bank'];
$ids = static function (array $rows): array {
    return array_filter($rows, static fn (array $r): bool => $r[1] !== null && $r[1] !== '');
};
?>
<h1><?= esc($l['invoice']) ?> <?= esc($s['number']) ?></h1>
<table class="parties">
    <tr>
        <td>
            <b><?= esc($l['supplier']) ?></b><br>
            <?= esc($e['trade_name']) ?><br>
            <?= esc($e['address_line1']) ?><br>
            <?php if (! empty($e['address_line2'])) : ?><?= esc($e['address_line2']) ?><br><?php endif; ?>
            <?= esc($e['postal_code']) ?> <?= esc($e['city']) ?><br>
            <?php foreach ($ids([[$l['companyId'], $e['company_id']], [$l['taxId'], $e['tax_id']], [$l['vatId'], $e['vat_id']], [$l['regon'], $e['regon']]]) as $row) : ?>
                <?= esc($row[0]) ?>: <?= esc($row[1]) ?><br>
            <?php endforeach; ?>
            <?php if (! empty($e['registry_note'])) : ?><?= esc($e['registry_note']) ?><?php endif; ?>
        </td>
        <td>
            <b><?= esc($l['customer']) ?></b><br>
            <?= esc($c['name']) ?><br>
            <?= esc($c['address_line1']) ?><br>
            <?php if (! empty($c['address_line2'])) : ?><?= esc($c['address_line2']) ?><br><?php endif; ?>
            <?= esc($c['postal_code']) ?> <?= esc($c['city']) ?><br>
            <?php foreach ($ids([[$l['identifier'], $c['identifier_value']], [$l['taxId'], $c['tax_id']], [$l['vatId'], $c['vat_id']]]) as $row) : ?>
                <?= esc($row[0]) ?>: <?= esc($row[1]) ?><br>
            <?php endforeach; ?>
        </td>
    </tr>
</table>
<table class="meta">
    <tr><td><?= esc($l['issueDate']) ?>: <?= esc(PdfFormat::date($s['issue_date'], $lg)) ?></td><td><?= esc($l['deliveryDate']) ?>: <?= esc(PdfFormat::date($s['delivery_date'], $lg)) ?></td><td><?= esc($l['dueDate']) ?>: <?= esc(PdfFormat::date($s['due_date'], $lg)) ?></td></tr>
    <tr><td><?= esc($l['variableSymbol']) ?>: <?= esc($s['variable_symbol'] ?? $s['number']) ?></td><td colspan="2"><?= esc($l['paymentMethod']) ?>: <?= esc($l['paymentTransfer']) ?></td></tr>
    <tr><td><?= esc($l['bankAccount']) ?>: <?= esc($b['iban']) ?></td><td colspan="2"><?php if (! empty($b['swift'])) : ?><?= esc($l['swift']) ?>: <?= esc($b['swift']) ?><?php endif; ?></td></tr>
</table>
<table class="items">
    <thead>
        <tr><th>#</th><th><?= esc($l['item']) ?></th><th class="r"><?= esc($l['hours']) ?></th><th class="r"><?= esc($l['rate']) ?></th><th class="r"><?= esc($l['amount']) ?></th><th><?= esc($l['vatCode']) ?></th></tr>
    </thead>
    <tbody>
        <?php foreach ($s['lines'] as $line) : ?>
            <tr>
                <td><?= esc((string) $line['position']) ?></td>
                <td><?= esc($line['title']) ?></td>
                <td class="r"><?= esc(PdfFormat::hours($line['hours'], $lg)) ?></td>
                <td class="r"><?= esc(PdfFormat::amount($line['rate'], $cur, $lg)) ?></td>
                <td class="r"><?= esc(PdfFormat::amount($line['amount'], $cur, $lg)) ?></td>
                <td><?= esc((string) $line['vat_code']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<p class="r total"><b><?= esc($l['total']) ?>: <?= esc(PdfFormat::amount($s['totals']['total'], $cur, $lg)) ?></b></p>
<p class="note"><?= esc($s['legal']['texts']['vat_note']['body']) ?></p>
