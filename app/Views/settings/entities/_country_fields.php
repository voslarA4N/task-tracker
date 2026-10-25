<?php
/**
 * htmx odpoveď na zmenu krajiny vo formulári Nový subjekt: mena a DPH režim do #entity-regime-fields,
 * identita (REGON, popis IČO/NIP, nápoveda registra) mimo poradia cez hx-swap-oob (WR-09).
 */
?>
<?= $this->include('settings/entities/_regime_fields') ?>
<div id="entity-identity-fields" hx-swap-oob="true">
    <?= $this->include('settings/entities/_identity_fields') ?>
</div>
