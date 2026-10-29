<?php

/**
 * Spodny panel Odoslat klientovi (UI-SPEC A1).
 *
 * @var array<string, mixed> $invoice
 * @var string               $clientName
 * @var array<string, mixed> $draft
 */
?>
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <div>
            <h2 class="offcanvas-title h5 mb-1" id="work-sheet-title"><?= esc(lang(match ($kind ?? 'invoice') {
                'resend'   => 'Deliveries.resendTitle',
                'reminder' => 'Deliveries.reminderTitle',
                default    => 'Deliveries.sendTitle',
            })) ?></h2>
            <p class="small text-body-secondary mb-0"><?= esc(lang('Deliveries.sendMeta', [(string) $invoice['number'], $clientName, strtoupper((string) $draft['language'])])) ?></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= esc(lang('Deliveries.cancel'), 'attr') ?>"></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body">
        <?= view('invoices/_delivery_form', get_defined_vars()) ?>
    </div>
</div>
