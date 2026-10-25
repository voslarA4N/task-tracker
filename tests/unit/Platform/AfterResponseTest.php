<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Libraries\Platform\AfterResponse;
use ArrayObject;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\TestLogger;
use Config\Services;
use RuntimeException;

/**
 * @internal
 */
final class AfterResponseTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testServiceOutsideFpmRunsImmediately(): void
    {
        $after = service('afterResponse');
        $this->assertSame($after, service('afterResponse'));

        $ran = false;
        $after->defer(static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
        $this->assertSame(0, $after->pending());
    }

    public function testHeldTasksRunOnlyOnFlushAndOnce(): void
    {
        $after = new AfterResponse(true);
        $runs  = new ArrayObject();
        $after->defer(static function () use ($runs): void {
            $runs->append(true);
        });

        $this->assertCount(0, $runs);
        $this->assertSame(1, $after->pending());

        $after->flush();
        $after->flush();

        $this->assertCount(1, $runs);
        $this->assertSame(0, $after->pending());
    }

    public function testFailingTaskIsLoggedAndOthersStillRun(): void
    {
        $after = new AfterResponse(true);
        $ran   = false;
        $after->defer(static function (): void {
            throw new RuntimeException('smtp down secret-token');
        });
        $after->defer(static function () use (&$ran): void {
            $ran = true;
        });

        $after->flush();

        $this->assertTrue($ran);
        $this->assertTrue(TestLogger::didLog('error', 'after_response.failed ' . RuntimeException::class));
        $this->assertFalse(TestLogger::didLog('error', 'secret-token', false));
    }
}
