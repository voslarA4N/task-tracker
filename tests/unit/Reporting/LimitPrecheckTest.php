<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Libraries\Invoicing\Draft;
use App\Libraries\Reporting\EntityLimits;
use App\Libraries\Reporting\LimitMath;
use App\Libraries\Reporting\LimitPrecheck;
use App\Libraries\Reporting\LimitStatus;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class LimitPrecheckTest extends CIUnitTestCase
{
    /**
     * @param list<int> $thresholds
     */
    private function st(string $total, string $limit = '50000.00', array $thresholds = [80, 100], ?string $error = null, string $name = 'Registrácia DPH'): LimitStatus
    {
        return new LimitStatus(
            1,
            1,
            $name,
            'SK',
            2026,
            2026,
            null,
            'calendar_year',
            $thresholds,
            '2026-01-01',
            '2026-12-31',
            $total,
            0,
            0,
            '0.00',
            [],
            $total,
            $limit,
            $limit,
            null,
            false,
            LimitMath::percent($total, $limit),
            LimitMath::ratio($total, $limit),
            '0.00',
            '0.00',
            'EUR',
            LimitMath::level($total, $limit, $thresholds),
            $error,
            false,
        );
    }

    /**
     * @param list<LimitStatus> $statuses
     */
    private function limits(array $statuses, bool $noLimit = false, ?int $usedYear = null, int $refYear = 2026): EntityLimits
    {
        return new EntityLimits(1, 'Subjekt', 'SK', 'EUR', $refYear, $usedYear, $noLimit, $statuses);
    }

    public function testCrossing80(): void
    {
        $r = LimitPrecheck::evaluate($this->limits([$this->st('39000.00')]), '1000.00');

        $this->assertSame('warning', $r['state']);
        $this->assertSame('warn', $r['level']);
        $this->assertCount(1, $r['rows']);
        $this->assertSame('78.0', $r['rows'][0]['beforePercent']);
        $this->assertSame('80.0', $r['rows'][0]['afterPercent']);
        $this->assertSame('warn', $r['rows'][0]['level']);
        $this->assertFalse($r['rows'][0]['alreadyOver']);
        $this->assertNull($r['fallback']);
    }

    public function testJustBelowThreshold(): void
    {
        $this->assertNull(LimitPrecheck::evaluate($this->limits([$this->st('39000.00')]), '999.99'));
    }

    public function testCrit(): void
    {
        $r = LimitPrecheck::evaluate($this->limits([$this->st('45000.00')]), '6000.00');

        $this->assertSame('crit', $r['level']);
        $this->assertSame('102.0', $r['rows'][0]['afterPercent']);
    }

    public function testAlreadyOver(): void
    {
        $r = LimitPrecheck::evaluate($this->limits([$this->st('42000.00')]), '500.00');

        $this->assertTrue($r['rows'][0]['alreadyOver']);
        $this->assertSame('warn', $r['level']);
    }

    public function testOnlyReachedLimitListed(): void
    {
        $r = LimitPrecheck::evaluate($this->limits([
            $this->st('49000.00', '50000.00', [80, 100], null, 'A'),
            $this->st('49000.00', '62500.00', [100], null, 'B'),
        ]), '2000.00');

        $this->assertCount(1, $r['rows']);
        $this->assertSame('A', $r['rows'][0]['name']);
    }

    public function testCurrencyMismatchOnly(): void
    {
        $r = LimitPrecheck::evaluate($this->limits([$this->st('0.00', '50000.00', [80, 100], 'currency_mismatch')]), '1000.00');

        $this->assertSame(['state' => 'unavailable', 'reason' => 'currency'], $r);
    }

    public function testNoLimit(): void
    {
        $this->assertNull(LimitPrecheck::evaluate($this->limits([], true), '1000.00'));
    }

    public function testFallback(): void
    {
        $r = LimitPrecheck::evaluate($this->limits([$this->st('49000.00')], false, 2025), '2000.00');

        $this->assertSame(['refYear' => 2026, 'usedYear' => 2025], $r['fallback']);
    }

    /**
     * @param array<string, mixed>      $snapshot
     * @param array<string, mixed>|null $fx
     */
    private function draft(array $snapshot, ?array $fx = null, ?int $entityId = 1): Draft
    {
        return new Draft(1, '2026-10', $entityId, '2026-10-27', '2026-10-31', '2026-11-10', [], '0.00', '0.00', 1, 'EUR', [], null, $snapshot, null, false, [], [], true, fx: $fx);
    }

    /**
     * @return array<string, mixed>
     */
    private function snap(bool $counts = true, string $total = '1000.00', ?string $fxTotal = null): array
    {
        $s = ['client' => ['counts_toward_limit' => $counts], 'totals' => ['total' => $total]];
        if ($fxTotal !== null) {
            $s['fx'] = ['total' => $fxTotal];
        }

        return $s;
    }

    public function testForDraftRules(): void
    {
        $seen = null;
        $pre  = new LimitPrecheck(function (int $e, string $d) use (&$seen): EntityLimits {
            $seen = [$e, $d];

            return $this->limits([$this->st('49000.00')]);
        });

        $this->assertNull($pre->forDraft($this->draft($this->snap(false))));
        $this->assertNull($pre->forDraft($this->draft($this->snap(), null, null)));
        $this->assertSame(['state' => 'unavailable', 'reason' => 'rate'], $pre->forDraft($this->draft($this->snap(), ['state' => 'unavailable'])));

        $r = $pre->forDraft($this->draft($this->snap(true, '4300.00', '1000.00'), ['state' => 'manual']));
        $this->assertSame('1000.00', $r['rows'][0]['add']);
        $this->assertTrue($r['fx']);
        $this->assertSame([1, '2026-10-31'], $seen);

        $r = $pre->forDraft($this->draft($this->snap(true, '2000.00')));
        $this->assertSame('2000.00', $r['rows'][0]['add']);
        $this->assertFalse($r['fx']);
    }
}
