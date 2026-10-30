<?php

declare(strict_types=1);

namespace Tests\Unit\StagingSeed;

use App\Libraries\StagingSeed\Catalog;
use App\Libraries\StagingSeed\HistoryPlan;
use App\Libraries\StagingSeed\HistoryPlanner;
use App\Libraries\StagingSeed\Profile;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * D-03: plan historie je deterministicky, v hraniciach a bez porusenia invariantov pre kazde "dnes".
 *
 * @internal
 */
#[Group('unit')]
final class HistoryPlannerTest extends CIUnitTestCase
{
    /**
     * Zrkadlo legal_profiles z migracie: rezim DPH subjektu => povolene fakturacne rezimy klienta.
     */
    private const ALLOWED_MODES = [
        'sk_non_vat'      => ['domestic'],
        'cz_non_vat'      => ['domestic'],
        'pl_exempt_113_1' => ['domestic', 'eu_b2b', 'non_eu'],
    ];

    public function testSameInputGivesIdenticalPlan(): void
    {
        $a = HistoryPlanner::build(Profile::full(), '2026-10-28');
        $b = HistoryPlanner::build(Profile::full(), '2026-10-28');

        $this->assertSame(serialize($a->toArray()), serialize($b->toArray()));
        $this->assertNotSame(serialize($a->toArray()), serialize(HistoryPlanner::build(Profile::full(), '2026-10-28', 42)->toArray()));
    }

    public function testDeliveriesAreDeterministicAndConsistent(): void
    {
        $a = HistoryPlanner::build(Profile::full(), '2026-10-28');
        $b = HistoryPlanner::build(Profile::full(), '2026-10-28');

        $this->assertSame(serialize($a->deliveries), serialize($b->deliveries));
        $this->assertNotSame([], $a->deliveries);

        $issued = [];

        foreach ($a->invoices as $inv) {
            $issued[$inv['key']] = $inv['at'];
        }

        $prev  = '';
        $types = [];

        foreach ($a->deliveries as $e) {
            $this->assertLessThanOrEqual('2026-10-28T23:59:59', $e['at']);
            $this->assertArrayHasKey($e['invoice'], $issued);
            $this->assertGreaterThan($issued[$e['invoice']], $e['at']);
            $this->assertGreaterThanOrEqual($prev, $e['at']);

            $prev              = $e['at'];
            $types[$e['type']] = ($types[$e['type']] ?? 0) + 1;
        }

        foreach (['send', 'manual', 'payment', 'payment_void', 'reminder'] as $type) {
            $this->assertGreaterThanOrEqual(1, $types[$type] ?? 0, $type);
        }

        $this->assertSame(1, $types['payment_void']);
        $this->assertNotEmpty(array_filter($a->deliveries, static fn (array $e): bool => ($e['partial'] ?? false) === true));
    }

    public function testFullProfileBounds(): void
    {
        $plan = HistoryPlanner::build(Profile::full(), '2026-10-28');

        $this->assertCount(3, $plan->entities);
        $this->assertCount(8, $plan->clients);
        $this->assertGreaterThanOrEqual(130, count($plan->tasks));
        $this->assertLessThanOrEqual(170, count($plan->tasks));
        $this->assertGreaterThanOrEqual(36, count($plan->invoices));
        $this->assertLessThanOrEqual(44, count($plan->invoices));
        $this->assertGreaterThanOrEqual(3, count(array_filter($plan->invoices, static fn (array $i): bool => $i['kind'] === 'partial')));

        $multi = array_filter($plan->tasks, static fn (array $t): bool => count($t['parts']) >= 2);
        $this->assertGreaterThanOrEqual(10, count($multi));

        $this->assertTrue($this->hasLaterInvoicedPart($plan), 'a split task has an earlier part invoiced before the later one');

        $this->assertSame([], array_filter($plan->invoices, static fn (array $i): bool => $i['month'] === '2026-10'));

        $invoiced = $this->invoicedRefs($plan);
        $current  = 0;

        foreach ($plan->tasks as $t) {
            foreach ($t['parts'] as $pi => $p) {
                if ($p['month'] === '2026-10' && ! isset($invoiced[$t['key'] . '#' . $pi])) {
                    $current++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(5, $current);

        // posledny cely mesiac: presne jeden klient s praciou bez mesacnej faktury
        $withWork = [];

        foreach ($plan->tasks as $t) {
            foreach ($t['parts'] as $p) {
                if ($p['month'] === '2026-09') {
                    $withWork[$t['client']] = true;
                }
            }
        }

        $monthly = [];

        foreach ($plan->invoices as $i) {
            if ($i['month'] === '2026-09' && $i['kind'] === 'monthly') {
                $monthly[$i['client']] = true;
            }
        }

        $this->assertCount(1, array_diff_key($withWork, $monthly));
    }

    #[DataProvider('provideInvariants')]
    public function testInvariants(string $today): void
    {
        $plan = HistoryPlanner::build(Profile::full(), $today);
        $bank = [];

        foreach ($plan->entities as $e) {
            $bank[$e['key']] = $e;

            foreach ($e['later_versions'] as $v) {
                $this->assertLessThanOrEqual($today, $v['valid_from']);
            }
        }

        $clients = [];

        foreach ($plan->clients as $c) {
            $clients[$c['key']] = $c;
            $entity             = $bank[$c['entity']];

            $this->assertSame(Catalog::CLIENTS[$c['key']]['currency'] ?? $entity['currency'], $c['currency']);
            $this->assertContains($c['billing_mode'], self::ALLOWED_MODES[$entity['vat_regime']]);
        }

        $tasks = [];

        foreach ($plan->tasks as $t) {
            $tasks[$t['key']] = $t;
            $this->assertArrayHasKey($t['client'], $clients);
            $this->assertInRange($t['created_at'], $today);

            $prev = $t['created_at'];

            foreach ($t['parts'] as $p) {
                $this->assertInRange($p['at'], $today);
                $this->assertGreaterThanOrEqual($prev, $p['at']);
                $this->assertSame($p['month'], substr($p['at'], 0, 7));
                $prev = $p['at'];
            }

            if ($t['done_at'] !== null) {
                $this->assertNotSame([], $t['parts']);
                $this->assertSame($t['parts'][count($t['parts']) - 1]['at'], $t['done_at']);
            }
        }

        $used = [];
        $last = '';

        foreach ($plan->invoices as $i) {
            $this->assertGreaterThanOrEqual($last, $i['at']);
            $last = $i['at'];
            $this->assertInRange($i['at'], $today);
            $this->assertSame(substr($i['at'], 0, 10), $i['issue_date']);
            $this->assertLessThan(substr($today, 0, 7), $i['month'], 'current month is never invoiced');
            $this->assertSame($clients[$i['client']]['entity'], $i['entity']);
            $this->assertNotSame([], $i['parts']);

            foreach ($i['parts'] as $ref) {
                $key = $ref['task'] . '#' . $ref['index'];
                $this->assertArrayNotHasKey($key, $used, 'part referenced at most once');
                $used[$key] = true;

                $task = $tasks[$ref['task']];
                $part = $task['parts'][$ref['index']];

                $this->assertSame($i['client'], $task['client']);
                $this->assertSame($i['month'], $part['month']);
                $this->assertLessThanOrEqual($i['at'], $part['at']);
            }
        }

        if ($today === '2026-01-10' || $today === '2026-01-01') {
            $this->assertSame([], $plan->invoices);
        }

        if ($today === '2026-11-01') {
            $this->assertSame([], array_filter($plan->invoices, static fn (array $i): bool => $i['month'] === '2026-10'));
        }

        if ($plan->tasks !== []) {
            $this->assertLessThanOrEqual('2026-01-07', substr($plan->tasks[0]['created_at'], 0, 10));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvariants(): iterable
    {
        foreach (['2026-01-01', '2026-01-10', '2026-03-20', '2026-10-28', '2026-11-01', '2026-12-31'] as $d) {
            yield $d => [$d];
        }
    }

    public function testSmallProfile(): void
    {
        $plan = HistoryPlanner::build(Profile::small(), '2026-03-20');

        $this->assertCount(4, $plan->clients);
        $this->assertGreaterThanOrEqual(5, count($plan->invoices));
        $this->assertLessThanOrEqual(9, count($plan->invoices));
        $this->assertGreaterThanOrEqual(1, count(array_filter($plan->invoices, static fn (array $i): bool => $i['kind'] === 'partial')));
    }

    private function assertInRange(string $at, string $today): void
    {
        $this->assertGreaterThanOrEqual(HistoryPlanner::START, substr($at, 0, 10));
        $this->assertLessThanOrEqual($today, substr($at, 0, 10));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00$/', $at);
    }

    /**
     * @return array<string, int> "task#index" => poradie faktury
     */
    private function invoicedRefs(HistoryPlan $plan): array
    {
        $out = [];

        foreach ($plan->invoices as $n => $i) {
            foreach ($i['parts'] as $ref) {
                $out[$ref['task'] . '#' . $ref['index']] = $n;
            }
        }

        return $out;
    }

    private function hasLaterInvoicedPart(HistoryPlan $plan): bool
    {
        $invoiced = $this->invoicedRefs($plan);

        foreach ($plan->tasks as $t) {
            $parts = count($t['parts']);

            for ($k = 1; $k < $parts; $k++) {
                $earlier = $invoiced[$t['key'] . '#' . ($k - 1)] ?? null;
                $later   = $invoiced[$t['key'] . '#' . $k] ?? null;

                if ($earlier !== null && ($later === null || $later > $earlier)) {
                    return true;
                }
            }
        }

        return false;
    }
}
