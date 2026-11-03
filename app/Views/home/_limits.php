<?php

use App\Libraries\Invoicing\PdfFormat;
use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Reporting\LimitMath;
use App\Libraries\Reporting\LimitStatus;

/**
 * Blok Limity obratu na prehlade. Bez dovodu sa nevykresli, neda sa zavriet ani odlozit (D-05, D-15).
 *
 * @var list<array{kind: string, entityName: string, status: ?LimitStatus, refYear: int, usedYear: ?int, country: string}> $limits
 */
if ($limits === []) {
    return;
}

$locale  = service('request')->getLocale();
$methods = [
    'calendar_year'       => 'Dashboard.limitsMethodCalendar',
    'rolling_12m'         => 'Dashboard.limitsMethodRolling',
    'pro_rata_first_year' => 'Dashboard.limitsMethodProRata',
];
$badges = [
    'crit'   => ['text-bg-danger', 'Dashboard.limitsBadgeCrit'],
    'config' => ['text-bg-danger', 'Dashboard.limitsBadgeConfig'],
    'failed' => ['text-bg-danger', 'Dashboard.limitsBadgeFailed'],
    'warn'   => ['text-bg-warning', 'Dashboard.limitsBadgeWarn'],
    'info'   => ['text-bg-info', 'Dashboard.limitsBadgeInfo'],
];
?>
<section class="work-group" aria-labelledby="limits-title">
    <h2 id="limits-title"><?= esc(lang('Dashboard.limitsTitle')) ?> <span class="badge text-bg-secondary"><?= count($limits) ?></span></h2>
    <div class="app-card p-0 overflow-hidden">
        <?php foreach ($limits as $row) : ?>
            <?php
            $kind   = (string) $row['kind'];
            $status = $row['status'];
            $mod    = in_array($kind, ['config', 'failed'], true) ? 'crit' : $kind;
            [$badgeClass, $badgeKey] = $badges[$kind];
            ?>
            <a class="lim-alert lim-alert--<?= esc($mod, 'attr') ?> d-block border-bottom" href="/settings/limits">
                <span class="badge <?= esc($badgeClass, 'attr') ?> me-1"><?= esc(lang($badgeKey)) ?></span>
                <?php if ($kind === 'failed') : ?>
                    <span><?= esc(lang('Dashboard.limitsFailed')) ?></span>
                <?php elseif ($status !== null && $kind !== 'config') : ?>
                    <span><?= esc(lang('Dashboard.limitsRow', [
                        $row['entityName'],
                        $status->name,
                        LimitMath::formatPercent($status->percent, $locale),
                        MoneyFormat::format($status->total, $status->currency, $locale),
                        MoneyFormat::format($status->limit, $status->currency, $locale),
                    ])) ?></span>
                    <span class="d-block small text-body-secondary"><?= esc(lang('Dashboard.limitsRowMeta', [
                        lang($methods[$status->method] ?? 'Dashboard.limitsMethodCalendar'),
                        PdfFormat::date($status->periodFrom, $locale),
                        PdfFormat::date($status->periodTo, $locale),
                    ])) ?></span>
                <?php elseif ($status !== null) : ?>
                    <span><?= esc(lang('Dashboard.limitsCurrency', [$row['entityName'], $status->name])) ?></span>
                <?php elseif ($row['usedYear'] !== null) : ?>
                    <span><?= esc(lang('Dashboard.limitsMissingYear', [$row['entityName'], $row['refYear'], $row['usedYear']])) ?></span>
                <?php else : ?>
                    <span><?= esc(lang('Dashboard.limitsNoLimit', [$row['entityName'], $row['country']])) ?></span>
                <?php endif ?>
            </a>
        <?php endforeach ?>
    </div>
</section>
