<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\ClientNameSql;
use App\Libraries\Invoicing\InvoiceFilter;
use App\Libraries\Invoicing\InvoiceQueries;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @internal
 */
final class InvoiceQueriesSortTest extends TestCase
{
    private function col(string $sort): string
    {
        return (string) (new ReflectionMethod(InvoiceQueries::class, 'sortColumn'))->invoke(null, $sort);
    }

    public function testClientSortColumnIsSnapshotNameExpression(): void
    {
        $this->assertSame(ClientNameSql::snapshotName('f'), $this->col('client'));
    }

    public function testEverySortKeyResolves(): void
    {
        foreach (InvoiceFilter::SORTS as $key) {
            $this->assertNotSame('', $this->col($key), $key);
        }
    }

    public function testUnknownSortFallsBackToDefault(): void
    {
        $this->assertSame($this->col(InvoiceFilter::DEFAULT_SORT), $this->col('bogus'));
    }
}
