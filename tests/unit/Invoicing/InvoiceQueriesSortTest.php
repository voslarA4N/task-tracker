<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\ClientNameSql;
use App\Libraries\Invoicing\InvoiceFilter;
use App\Libraries\Invoicing\InvoiceQueries;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
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

    /**
     * @return array<string, string>
     */
    private function sortSql(): array
    {
        /** @var array<string, string> $sql */
        $sql = (new ReflectionClassConstant(InvoiceQueries::class, 'SORT_SQL'))->getValue();

        return $sql;
    }

    public function testEverySortKeyResolves(): void
    {
        $sortSql = $this->sortSql();

        foreach (InvoiceFilter::SORTS as $key) {
            if ($key === 'client') {
                continue;
            }

            $this->assertArrayHasKey($key, $sortSql, $key);
            $this->assertSame($sortSql[$key], $this->col($key), $key);

            if ($key !== InvoiceFilter::DEFAULT_SORT) {
                $this->assertNotSame($this->col(InvoiceFilter::DEFAULT_SORT), $this->col($key), $key);
            }
        }
    }

    public function testSortSqlHasNoUnknownKeys(): void
    {
        foreach (array_keys($this->sortSql()) as $key) {
            $this->assertContains($key, InvoiceFilter::SORTS, (string) $key);
        }
    }

    public function testDefaultSortIsDirectSortSqlEntry(): void
    {
        $sortSql = $this->sortSql();

        $this->assertArrayHasKey(InvoiceFilter::DEFAULT_SORT, $sortSql);
        $this->assertSame($sortSql[InvoiceFilter::DEFAULT_SORT], $this->col('bogus'));
    }

    public function testUnknownSortFallsBackToDefault(): void
    {
        $this->assertSame($this->col(InvoiceFilter::DEFAULT_SORT), $this->col('bogus'));
    }
}
