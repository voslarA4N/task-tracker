<section class="app-card security-card" aria-labelledby="sec-events">
    <h2 class="h5 mb-0" id="sec-events"><?= esc(lang('Account.eventsHeading')) ?></h2>
    <small class="text-muted d-block mb-2"><?= esc(lang('Account.eventsSubheading')) ?></small>

    <?php if ($events === []) : ?>
        <p class="fw-semibold mb-1"><?= esc(lang('Account.eventsEmptyHeading')) ?></p>
        <p class="text-muted mb-0"><?= esc(lang('Account.eventsEmptyBody')) ?></p>
    <?php else : ?>
        <div class="d-md-none">
            <?php foreach ($events as $event) : ?>
                <div class="event-row" data-event-row>
                    <div class="d-flex justify-content-between align-items-center">
                        <span><?= esc($event['time']) ?></span>
                        <span class="badge <?= $event['ok'] ? 'text-bg-success' : 'text-bg-danger' ?>"><?= esc(lang('Account.' . $event['statusKey'])) ?></span>
                    </div>
                    <div class="small text-muted">
                        <code class="event-ip"><?= esc($event['ip']) ?></code>
                        <?php if ($event['device'] !== null) : ?> &middot; <?= esc($event['device']) ?><?php endif ?>
                        &middot; <?= esc(lang('Account.' . $event['reasonKey'])) ?>
                    </div>
                </div>
            <?php endforeach ?>
        </div>

        <div class="d-none d-md-block">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col"><?= esc(lang('Account.eventsColTime')) ?></th>
                        <th scope="col"><?= esc(lang('Account.eventsColResult')) ?></th>
                        <th scope="col"><?= esc(lang('Account.eventsColReason')) ?></th>
                        <th scope="col"><?= esc(lang('Account.eventsColIp')) ?></th>
                        <th scope="col"><?= esc(lang('Account.eventsColDevice')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event) : ?>
                        <tr>
                            <td><?= esc($event['time']) ?></td>
                            <td><span class="badge <?= $event['ok'] ? 'text-bg-success' : 'text-bg-danger' ?>"><?= esc(lang('Account.' . $event['statusKey'])) ?></span></td>
                            <td><?= esc(lang('Account.' . $event['reasonKey'])) ?></td>
                            <td><code class="event-ip"><?= esc($event['ip']) ?></code></td>
                            <td><?= esc($event['device'] ?? '') ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
