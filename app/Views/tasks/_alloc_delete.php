<?php

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Work\BillingMonth;

/**
 * Potvrdenie zmazania casti v tom istom paneli (D-10).
 *
 * @var array<string, mixed>      $task
 * @var array<string, mixed>|null $allocation
 * @var bool                      $lockedError
 */
$locked = $lockedError ?? false;
$id     = (int) ($allocation['id'] ?? 0);
$locale = service('request')->getLocale();
?>
<?php if ($allocation === null) : ?>
<?php elseif ($locked) : ?>
    <div class="alert alert-danger" role="alert"><?= esc(lang('Allocations.errorLocked')) ?></div>
    <div class="master-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Allocations.close')) ?></button>
    </div>
<?php else : ?>
<p><?= esc(lang('Allocations.deleteConfirm', [
    mb_strtolower(BillingMonth::label((string) $allocation['billing_month'], $locale)),
    Hours::format((string) $allocation['hours'], $locale),
    MoneyFormat::format((string) $allocation['amount'], (string) $allocation['currency']),
    (string) $task['title'],
])) ?></p>
<form method="post" action="/allocations/<?= $id ?>/delete" hx-post="/allocations/<?= $id ?>/delete" hx-target="#sheet-body" hx-swap="innerHTML" class="m-0" data-submit-once>
    <?= csrf_field() ?>
    <div class="master-actions">
        <button type="button" class="btn btn-outline-secondary btn-touch" data-bs-dismiss="offcanvas"><?= esc(lang('Allocations.cancel')) ?></button>
        <button type="submit" class="btn btn-danger btn-touch">
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            <?= esc(lang('Allocations.delete')) ?>
        </button>
    </div>
</form>
<?php endif ?>
