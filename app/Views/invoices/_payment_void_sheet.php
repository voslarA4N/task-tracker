<?php

/**
 * Spodny panel storna uhrady (UI-SPEC B4).
 *
 * @var array<string, mixed> $payment
 * @var string               $amount
 * @var string               $paidOn
 */
?>
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <div>
            <h2 class="offcanvas-title h5 mb-1" id="work-sheet-title"><?= esc(lang('Payments.voidTitle')) ?></h2>
            <p class="small text-body-secondary mb-0"><?= esc(lang('Payments.voidMeta', [$amount, $paidOn])) ?></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Payments.back'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?= view('invoices/_payment_void_form', get_defined_vars()) ?>
    </div>
</div>
