<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\ClientNameSql;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ClientNameSqlTest extends TestCase
{
    public function testDefaultAliases(): void
    {
        $this->assertSame("COALESCE(NULLIF(i.snapshot->'client'->>'name', ''), c.name)", ClientNameSql::snapshotName());
    }

    public function testInvoiceAliasF(): void
    {
        $this->assertSame("COALESCE(NULLIF(f.snapshot->'client'->>'name', ''), c.name)", ClientNameSql::snapshotName('f'));
    }

    public function testUnknownInvoiceAliasThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientNameSql::snapshotName('x');
    }

    public function testUnknownClientAliasThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientNameSql::snapshotName('i', 'z');
    }

    public function testInjectionAttemptThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientNameSql::snapshotName('i; DROP');
    }
}
