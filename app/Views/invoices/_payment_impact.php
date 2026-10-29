<?php

/**
 * Nahlad dopadu uhrady (pocita server, D-19).
 *
 * @var string $balanceAfter
 * @var string $statusAfter
 */
$labels = ['issued' => 'Invoices.statusIssued', 'sent' => 'Invoices.statusSent', 'partially_paid' => 'Invoices.statusPartiallyPaid', 'paid' => 'Invoices.statusPaid'];
?>
<?= esc(lang('Payments.impact', [$balanceAfter, lang($labels[$statusAfter] ?? 'Invoices.statusIssued')])) ?>
