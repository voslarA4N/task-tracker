<div class="mt-4" id="demo-list">
    <h2 class="h6"><?= esc(lang('Demo.listHeading')) ?></h2>
    <?php if (($rows ?? []) === []): ?>
        <p class="text-muted"><?= esc(lang('Demo.emptyList')) ?></p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($rows as $row): ?>
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span class="text-break"><?= esc($row['hours']) ?> h &times; <?= esc($row['rate']) ?></span>
                    <span class="fw-semibold"><?= esc($row['amount']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="d-flex justify-content-between mt-2 fw-semibold">
            <span><?= esc(lang('Demo.total')) ?></span>
            <span><?= esc($total) ?></span>
        </div>
    <?php endif; ?>
</div>
