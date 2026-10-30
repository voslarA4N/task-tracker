<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Master\CurrencyRepository;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class CurrencyRepositoryTest extends PgTestCase
{
    public function testCodesAndLookups(): void
    {
        $repo = new CurrencyRepository($this->rw());
        $this->assertSame(['EUR', 'PLN', 'CZK'], $repo->codes());
        $this->assertTrue($repo->isActive('PLN'));
        $this->assertFalse($repo->isActive('USD'));
        $this->assertFalse($repo->isActive('eur'));
        $this->assertSame(2, $repo->decimals('EUR'));
    }

    public function testInactiveCurrency(): void
    {
        $this->owner()->query("UPDATE currencies SET active = false WHERE code = 'CZK'");

        try {
            $repo = new CurrencyRepository($this->rw());
            $this->assertFalse($repo->isActive('CZK'));
            $this->assertNotContains('CZK', $repo->codes());
            $this->assertTrue($repo->exists('CZK'));
        } finally {
            $this->owner()->query("UPDATE currencies SET active = true WHERE code = 'CZK'");
        }
    }
}
