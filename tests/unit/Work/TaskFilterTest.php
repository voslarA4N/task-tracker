<?php

declare(strict_types=1);

namespace Tests\Unit\Work;

use App\Libraries\Work\TaskFilter;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TaskFilterTest extends TestCase
{
    public function testDefaults(): void
    {
        $f = TaskFilter::fromQuery([]);
        $this->assertSame('current', $f->state);
        $this->assertNull($f->clientId);
        $this->assertNull($f->month);
        $this->assertNull($f->q);
        $this->assertSame(0, $f->activeCount());
        $this->assertSame([], $f->toQuery());
    }

    public function testInvalidValuesFallBack(): void
    {
        $f = TaskFilter::fromQuery(['state' => 'x', 'client' => 'abc', 'month' => '2026-13', 'q' => '  ']);
        $this->assertSame('current', $f->state);
        $this->assertNull($f->clientId);
        $this->assertNull($f->month);
        $this->assertNull($f->q);
        $this->assertSame(0, $f->activeCount());
    }

    public function testValidValues(): void
    {
        $f = TaskFilter::fromQuery(['client' => '3', 'state' => 'done', 'month' => '2026-10', 'q' => 'faktúra', 'evil' => '1']);
        $this->assertSame(3, $f->clientId);
        $this->assertSame('done', $f->state);
        $this->assertSame('2026-10', $f->month);
        $this->assertSame('faktúra', $f->q);
        $this->assertSame(4, $f->activeCount());
        $this->assertSame(['client' => '3', 'state' => 'done', 'month' => '2026-10', 'q' => 'faktúra'], $f->toQuery());
    }

    public function testDashboardGroup(): void
    {
        $f = TaskFilter::fromQuery(['group' => 'due', 'month' => '2026-10', 'state' => 'billed']);
        $this->assertSame('due', $f->group);
        $this->assertSame('current', $f->state);
        $this->assertSame(['month' => '2026-10', 'group' => 'due'], $f->toQuery());

        // Skupina zavisla od mesiaca bez mesiaca sa ignoruje, neznama skupina tiez.
        $this->assertNull(TaskFilter::fromQuery(['group' => 'done_due'])->group);
        $this->assertNull(TaskFilter::fromQuery(['group' => 'evil', 'month' => '2026-10'])->group);
        $this->assertNull(TaskFilter::fromQuery(['group' => ['due'], 'month' => '2026-10'])->group);
        $this->assertSame('in_progress', TaskFilter::fromQuery(['group' => 'in_progress'])->group);
    }

    public function testToQueryOmitsDefaults(): void
    {
        $f = TaskFilter::fromQuery(['state' => 'current', 'q' => 'a']);
        $this->assertSame(['q' => 'a'], $f->toQuery());
    }

    public function testQueryTruncatedTo100(): void
    {
        $f = TaskFilter::fromQuery(['q' => str_repeat('a', 150)]);
        $this->assertSame(100, mb_strlen((string) $f->q));
    }
}
