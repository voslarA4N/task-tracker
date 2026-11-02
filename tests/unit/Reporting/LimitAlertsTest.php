<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Libraries\Reporting\EntityLimits;
use App\Libraries\Reporting\LimitAlerts;
use App\Libraries\Reporting\LimitStatus;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class LimitAlertsTest extends CIUnitTestCase
{
    public function testNoReasonNoAlert(): void
    {
        $this->assertSame([], LimitAlerts::fromEntities([$this->entity(1, 'A', [$this->st('normal'), $this->st('normal')])]));
    }

    public function testCritBeforeWarn(): void
    {
        $out = LimitAlerts::fromEntities([$this->entity(1, 'A', [$this->st('warn', null, 'W'), $this->st('crit', null, 'C')])]);

        $this->assertSame(['crit', 'warn'], array_column($out, 'kind'));
        $this->assertSame('C', $out[0]['status']?->name);
    }

    public function testCurrencyMismatchIsConfig(): void
    {
        $out = LimitAlerts::fromEntities([$this->entity(1, 'A', [$this->st('normal', 'currency_mismatch')])]);

        $this->assertSame(['config'], array_column($out, 'kind'));
    }

    public function testFallbackYearInfo(): void
    {
        $out = LimitAlerts::fromEntities([$this->entity(1, 'A', [$this->st('normal')], 2025)]);

        $this->assertCount(1, $out);
        $this->assertSame('info', $out[0]['kind']);
        $this->assertNull($out[0]['status']);
        $this->assertSame(2025, $out[0]['usedYear']);
        $this->assertSame(2026, $out[0]['refYear']);
    }

    public function testNoLimitInfo(): void
    {
        $out = LimitAlerts::fromEntities([$this->entity(1, 'A', [], null, true)]);

        $this->assertCount(1, $out);
        $this->assertSame('info', $out[0]['kind']);
        $this->assertNull($out[0]['usedYear']);
    }

    public function testTwoEntitiesOrdering(): void
    {
        $out = LimitAlerts::fromEntities([
            $this->entity(1, 'A', [$this->st('warn')]),
            $this->entity(2, 'B', [$this->st('crit')], 2025),
        ]);

        $this->assertSame(['crit', 'warn', 'info'], array_column($out, 'kind'));
        $this->assertSame(['B', 'A', 'B'], array_column($out, 'entityName'));
    }

    /**
     * @param list<LimitStatus> $statuses
     */
    private function entity(int $id, string $name, array $statuses, ?int $usedYear = null, bool $noLimit = false): EntityLimits
    {
        return new EntityLimits($id, $name, 'SK', 'EUR', 2026, $usedYear, $noLimit, $statuses);
    }

    private function st(string $level, ?string $error = null, string $name = 'L'): LimitStatus
    {
        return new LimitStatus(
            1, 1, $name, 'SK', 2026, 2026, null, 'calendar_year', [80, 100],
            '2026-01-01', '2026-12-31', '0.00', 0, 0, '0.00', [2026], '0.00',
            '50000.00', '50000.00', null, false, '0.0', '0.00000000', '50000.00', '0.00',
            'EUR', $level, $error, false,
        );
    }
}
