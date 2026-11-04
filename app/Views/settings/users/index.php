<?php

/**
 * @var list<array{id: int, email: ?string, username: ?string, groups: list<string>, totp_enabled: bool, last_login: ?string, last_login_label: ?string}> $users
 */
$roleLabels = ['admin' => lang('Users.role_admin'), 'accountant' => lang('Users.role_accountant')];
$roleLabel  = static fn (string $g): string => $roleLabels[$g] ?? $g;
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('Users.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="work-wide">
    <p class="mb-2"><a class="d-inline-block py-2" href="/more"><?= esc(lang('Users.backToMore')) ?></a></p>
    <h1 class="h4 mb-1"><?= esc(lang('Users.title')) ?></h1>
    <p class="small text-body-secondary mb-3"><?= esc(lang('Users.intro')) ?></p>

    <div class="dt-only-sm">
        <?php foreach ($users as $u) : ?>
            <section class="app-card rpt-card mb-2">
                <p class="fw-semibold mb-1"><?= esc($u['email'] ?? $u['username'] ?? '') ?></p>
                <p class="mb-1">
                    <?php foreach ($u['groups'] as $g) : ?>
                        <span class="badge text-bg-light border"><?= esc($roleLabel($g)) ?></span>
                    <?php endforeach ?>
                    <?php if ($u['totp_enabled']) : ?>
                        <span class="badge text-bg-success"><?= esc(lang('Users.twofaOn')) ?></span>
                    <?php else : ?>
                        <span class="badge text-bg-warning"><?= esc(lang('Users.twofaOff')) ?></span>
                    <?php endif ?>
                </p>
                <p class="small text-body-secondary mb-0"><?= esc(lang('Users.colLastLogin')) ?>: <?= esc($u['last_login_label'] ?? lang('Users.never')) ?></p>
            </section>
        <?php endforeach ?>
    </div>
    <div class="dt-only-lg">
        <table class="table table-hover dt-table">
            <caption class="visually-hidden"><?= esc(lang('Users.tableCaption')) ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?= esc(lang('Users.colName')) ?></th>
                    <th scope="col"><?= esc(lang('Users.colRole')) ?></th>
                    <th scope="col"><?= esc(lang('Users.colTwofa')) ?></th>
                    <th scope="col"><?= esc(lang('Users.colLastLogin')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u) : ?>
                    <tr>
                        <td><?= esc($u['email'] ?? $u['username'] ?? '') ?></td>
                        <td>
                            <?php foreach ($u['groups'] as $g) : ?>
                                <span class="badge text-bg-light border"><?= esc($roleLabel($g)) ?></span>
                            <?php endforeach ?>
                        </td>
                        <td>
                            <?php if ($u['totp_enabled']) : ?>
                                <span class="badge text-bg-success"><?= esc(lang('Users.twofaOn')) ?></span>
                            <?php else : ?>
                                <span class="badge text-bg-warning"><?= esc(lang('Users.twofaOff')) ?></span>
                            <?php endif ?>
                        </td>
                        <td><?= esc($u['last_login_label'] ?? lang('Users.never')) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
