<?php

use App\Libraries\Master\PortalUrlTemplate;

/**
 * Zivy nahlad vzoru odkazu na portal: vzdy iba text, nikdy odkaz (T-03-37).
 *
 * @var array<string, string> $values
 */
$tpl   = trim((string) ($values['portal_url_template'] ?? ''));
$error = $tpl === '' ? null : PortalUrlTemplate::validate($tpl);
?>
<div id="portal-preview" aria-live="polite">
    <?php if ($tpl !== '' && $error === null) : ?>
        <p class="small text-body-secondary mb-0"><?= esc(lang('Clients.portalPreview')) ?> <code><?= esc(PortalUrlTemplate::render($tpl, '123')) ?></code></p>
    <?php elseif ($error !== null) : ?>
        <div class="invalid-feedback d-block"><?= esc($error) ?></div>
    <?php endif ?>
</div>
