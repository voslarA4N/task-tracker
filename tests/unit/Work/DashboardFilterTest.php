<?php

declare(strict_types=1);

namespace Tests\Unit\Work;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Work\DashboardFilter;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * @internal
 */
final class DashboardFilterTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testDefaults(): void
    {
        $f = DashboardFilter::fromQuery([], 3);
        $this->assertSame('2026-10', $f->month);
        $this->assertSame(3, $f->clientId);
    }

    public function testAllClients(): void
    {
        $f = DashboardFilter::fromQuery(['client' => 'all'], 3);
        $this->assertNull($f->clientId);
        $this->assertSame(['month' => '2026-10', 'client' => 'all'], $f->toQuery());
    }

    public function testInvalidValuesFallBack(): void
    {
        $f = DashboardFilter::fromQuery(['month' => '2026-13', 'client' => 'x'], null);
        $this->assertSame('2026-10', $f->month);
        $this->assertNull($f->clientId);
    }

    public function testNumericClientAndMonth(): void
    {
        $f = DashboardFilter::fromQuery(['month' => '2026-11', 'client' => '7'], null);
        $this->assertSame('2026-11', $f->month);
        $this->assertSame(7, $f->clientId);
        $this->assertSame(['month' => '2026-11', 'client' => '7'], $f->toQuery());
    }
}
