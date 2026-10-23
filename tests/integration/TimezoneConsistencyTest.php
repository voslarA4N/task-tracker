<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class TimezoneConsistencyTest extends PgTestCase
{
    public function testPhpCi4AndPostgresShareTimezone(): void
    {
        $this->assertSame('Europe/Bratislava', date_default_timezone_get());
        $this->assertSame('Europe/Bratislava', config(\Config\App::class)->appTimezone);
        $this->assertSame('Europe/Bratislava', $this->rw()->query('SHOW timezone')->getRow()->TimeZone);
    }
}
