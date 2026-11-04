<?php

declare(strict_types=1);

namespace Tests\Unit\Work;

use App\Libraries\Work\TaskFilter;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TaskFilterSortTest extends TestCase
{
    public function testValidSort(): void
    {
        $f = TaskFilter::fromQuery(['sort' => 'hours', 'dir' => 'asc']);

        $this->assertSame('hours', $f->sort);
        $this->assertSame('asc', $f->dir);
        $this->assertSame(['sort' => 'hours', 'dir' => 'asc'], $f->toQuery());
    }

    public function testEvilSortFallsBackToDefault(): void
    {
        $f = TaskFilter::fromQuery(['sort' => 't.id;--', 'dir' => 'asc']);

        $this->assertSame('default', $f->sort);
        $this->assertSame([], $f->toQuery());
    }

    public function testInvalidDirIsDesc(): void
    {
        $this->assertSame('desc', TaskFilter::fromQuery(['sort' => 'hours', 'dir' => 'up'])->dir);
    }

    public function testDefaultNotInQuery(): void
    {
        $this->assertSame([], TaskFilter::fromQuery([])->toQuery());
    }

    public function testActiveCountIgnoresSort(): void
    {
        $this->assertSame(0, TaskFilter::fromQuery(['sort' => 'hours'])->activeCount());
        $this->assertSame(1, TaskFilter::fromQuery(['sort' => 'hours', 'client' => '3'])->activeCount());
    }

    public function testWithSortToggleKeepsFilters(): void
    {
        $f = TaskFilter::fromQuery(['sort' => 'hours', 'dir' => 'asc', 'client' => '3', 'state' => 'all', 'month' => '2026-10', 'q' => 'abc']);
        $q = $f->withSort('hours');

        $this->assertSame('desc', $q['dir']);
        $this->assertSame('hours', $q['sort']);
        $this->assertSame('3', $q['client']);
        $this->assertSame('all', $q['state']);
        $this->assertSame('2026-10', $q['month']);
        $this->assertSame('abc', $q['q']);
    }
}
