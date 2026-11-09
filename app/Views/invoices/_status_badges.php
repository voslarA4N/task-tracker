<?php

/**
 * Odznaky stavu faktury (karta aj tabulka).
 *
 * @var array<string, mixed> $row
 */
$external = $row['origin'] === 'external';
$st       = (string) ($row['status'] ?? 'issued');
$isCn     = ($row['type'] ?? 'invoice') === 'credit_note';
[$stKey, $stClass] = match (true) {
    $isCn                    => ['Invoices.badgeCreditNote', 'text-bg-info'],
    $st === 'cancelled'      => ['Invoices.statusCancelled', 'text-bg-dark'],
    $st === 'sent'           => ['Invoices.statusSent', 'text-bg-light border'],
    $st === 'partially_paid' => ['Invoices.statusPartiallyPaid', 'text-bg-warning'],
    $st === 'paid'           => ['Invoices.statusPaid', 'text-bg-success'],
    default                  => ['Invoices.statusIssued', 'text-bg-secondary'],
};
?>
<?php if ($external) : ?>
    <span class="st-badge badge text-wrap text-bg-secondary"><?= esc(lang('Invoices.badgeExternal')) ?></span>
<?php else : ?>
    <span class="st-badge badge text-wrap <?= esc($stClass, 'attr') ?>"><?= esc(lang($stKey)) ?></span>
    <?php if ($isCn) : ?>
        <span class="st-badge badge text-wrap text-bg-secondary"><?= esc(lang(! empty($row['sent_at']) ? 'Invoices.statusCnSent' : 'Invoices.statusCnIssued')) ?></span>
    <?php endif ?>
    <?php if (! empty($row['overdue']) && ! $isCn && $st !== 'cancelled') : ?>
        <span class="st-badge badge text-wrap text-bg-danger"><?= esc(lang('Invoices.statusOverdue', [(int) $row['days_overdue']])) ?></span>
    <?php endif ?>
<?php endif ?>
