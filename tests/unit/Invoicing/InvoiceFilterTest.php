<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\InvoiceFilter;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class InvoiceFilterTest extends TestCase
{
    public function testDefaults(): void
    {
        $f = InvoiceFilter::fromQuery([]);

        $this->assertSame('issue_date', $f->sort);
        $this->assertSame('desc', $f->dir);
        $this->assertSame([], $f->toQuery());
        $this->assertSame(0, $f->activeCount());
        $this->assertTrue($f->isDefaultSort());
    }

    public function testValidSort(): void
    {
        $f = InvoiceFilter::fromQuery(['sort' => 'total', 'dir' => 'asc']);

        $this->assertSame('total', $f->sort);
        $this->assertSame('asc', $f->dir);
        $this->assertFalse($f->isDefaultSort());
        $this->assertSame(0, $f->activeCount());
        $this->assertSame(['sort' => 'total', 'dir' => 'asc'], $f->toQuery());
    }

    public function testEvilSortAndDirFallBack(): void
    {
        $f = InvoiceFilter::fromQuery(['sort' => 'i.id; DROP', 'dir' => 'x']);

        $this->assertSame(InvoiceFilter::DEFAULT_SORT, $f->sort);
        $this->assertSame('desc', $f->dir);
    }

    public function testClientAndEntityIds(): void
    {
        $this->assertSame(12, InvoiceFilter::fromQuery(['client' => '12'])->clientId);
        $this->assertNull(InvoiceFilter::fromQuery(['client' => 'abc'])->clientId);
        $this->assertSame(3, InvoiceFilter::fromQuery(['entity' => '3'])->entityId);
        $this->assertNull(InvoiceFilter::fromQuery(['entity' => ['1']])->entityId);
    }

    public function testDates(): void
    {
        $this->assertNull(InvoiceFilter::fromQuery(['from' => '2026-13-01'])->from);
        $this->assertNull(InvoiceFilter::fromQuery(['to' => '2026-02-30'])->to);
        $this->assertSame('2026-10-01', InvoiceFilter::fromQuery(['from' => '2026-10-01'])->from);
    }

    public function testQueryTrimmedToFiftyChars(): void
    {
        $f = InvoiceFilter::fromQuery(['q' => str_repeat('a', 80)]);

        $this->assertSame(50, mb_strlen((string) $f->q));
        $this->assertNull(InvoiceFilter::fromQuery(['q' => '  '])->q);
    }

    public function testStatusWhitelist(): void
    {
        $this->assertSame('paid', InvoiceFilter::fromQuery(['status' => 'paid'])->status);
        $this->assertNull(InvoiceFilter::fromQuery(['status' => 'bogus'])->status);
    }

    public function testActiveCountIgnoresSort(): void
    {
        $f = InvoiceFilter::fromQuery(['client' => '5', 'q' => 'x', 'sort' => 'total']);

        $this->assertSame(2, $f->activeCount());
    }

    public function testWithSortTogglesActiveColumn(): void
    {
        $f = InvoiceFilter::fromQuery(['sort' => 'total', 'dir' => 'desc', 'client' => '5']);

        $this->assertSame(['client' => '5', 'sort' => 'total', 'dir' => 'asc'], $f->withSort('total'));
        $this->assertSame('asc', $f->withSort('client')['dir']);
        $this->assertSame('desc', $f->withSort('due_date')['dir']);
        $this->assertSame('desc', $f->withSort('total_entity_ccy')['dir']);
    }

    public function testWithSortOnDefaultColumnAlwaysCarriesSortAndDir(): void
    {
        $f = InvoiceFilter::fromQuery([]);

        $this->assertSame(['sort' => 'issue_date', 'dir' => 'asc'], $f->withSort('issue_date'));
    }
}
