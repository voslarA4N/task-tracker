<section class="app-card security-card" aria-labelledby="sec-devices">
    <h2 class="h5" id="sec-devices"><?= esc(lang('Account.devicesHeading')) ?></h2>

    <?php if ($devices === []) : ?>
        <p class="fw-semibold mb-1"><?= esc(lang('Account.devicesEmptyHeading')) ?></p>
        <p class="text-muted mb-0"><?= esc(lang('Account.devicesEmptyBody')) ?></p>
    <?php else : ?>
        <?php foreach ($devices as $device) : ?>
            <?php $isCurrent = $device['id'] === $currentDeviceId; ?>
            <div class="device-row">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <div>
                        <span class="fw-semibold"><?= esc($device['label']) ?></span>
                        <?php if ($isCurrent) : ?>
                            <span class="badge text-bg-secondary"><?= esc(lang('Account.thisDevice')) ?></span>
                        <?php endif ?>
                        <div class="small text-muted"><?= esc(lang('Account.deviceDates', [$device['created'], $device['last'], $device['expires']])) ?></div>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm btn-touch" data-bs-toggle="modal" data-bs-target="#revoke-<?= (int) $device['id'] ?>"><?= esc(lang('Account.revoke')) ?></button>
                </div>

                <div class="modal fade" id="revoke-<?= (int) $device['id'] ?>" tabindex="-1" aria-labelledby="revoke-title-<?= (int) $device['id'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h3 class="modal-title h5" id="revoke-title-<?= (int) $device['id'] ?>"><?= esc(lang('Account.revokeSubmit')) ?></h3>
                            </div>
                            <div class="modal-body">
                                <p class="mb-2"><?= esc(lang('Account.revokeConfirm', [$device['label']])) ?></p>
                                <?php if ($isCurrent) : ?>
                                    <p class="fw-semibold mb-0"><?= esc(lang('Account.revokeCurrentWarning')) ?></p>
                                <?php endif ?>
                            </div>
                            <div class="modal-footer flex-column flex-sm-row">
                                <form method="post" action="/settings/security/devices/<?= (int) $device['id'] ?>/revoke" class="w-100 w-sm-auto m-0">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-danger w-100"><?= esc(lang('Account.revokeSubmit')) ?></button>
                                </form>
                                <button type="button" class="btn btn-outline-secondary w-100 w-sm-auto" data-bs-dismiss="modal"><?= esc(lang('Account.cancel')) ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach ?>
    <?php endif ?>
</section>
