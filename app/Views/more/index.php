<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$items = [
    ['/reports/month', 'Reports.navTitle', 'Reports.navHint', 'reports'],
    ['/reports/accountant', 'Bundle.navTitle', 'Bundle.navHint', 'bundle'],
    ['/settings/entities', 'Entities.navTitle', null, 'entities'],
    ['/settings/clients', 'Clients.navTitle', null, 'clients'],
    ['/settings/legal-texts', 'LegalTexts.navTitle', null, 'legal-texts'],
    ['/settings/fx', 'FxSettings.navTitle', null, 'fx'],
    ['/settings/documents', 'DocumentSettings.navTitle', null, 'documents'],
    ['/settings/limits', 'Limits.navTitle', null, 'limits'],
    ['/settings/audit', 'AuditLog.navTitle', 'AuditLog.navHint', 'audit'],
    ['/settings/users', 'Users.navTitle', 'Users.navHint', 'users'],
    ['/settings/email-templates', 'EmailTemplates.navTitle', 'EmailTemplates.navHint', 'email-templates'],
    ['/settings/security', 'Account.securityTitle', null, 'security'],
];
?>
<div class="more-grid">
<?php foreach ($items as [$href, $title, $hint, $icon]): ?>
    <a class="more-tile" href="<?= $href ?>"><?= view('layouts/_icon', ['name' => $icon, 'size' => 28, 'class' => 'more-tile-icon'], ['saveData' => false]) ?><span class="more-tile-title"><?= esc(lang($title)) ?></span><?php if ($hint !== null): ?><span class="more-tile-hint"><?= esc(lang($hint)) ?></span><?php endif; ?></a>
<?php endforeach; ?>
</div>
<?= $this->endSection() ?>
