<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Libraries\Platform\DbBool;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class DbBoolTest extends CIUnitTestCase
{
    #[DataProvider('provideOf')]
    public function testOf(mixed $in, bool $expected): void
    {
        $this->assertSame($expected, DbBool::of($in));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function provideOf(): iterable
    {
        yield 'true' => [true, true];

        yield 't' => ['t', true];

        yield 'string true' => ['true', true];

        yield 'string 1' => ['1', true];

        yield 'int 1' => [1, true];

        yield 'false' => [false, false];

        yield 'f' => ['f', false];

        yield 'string false' => ['false', false];

        yield 'string 0' => ['0', false];

        yield 'int 0' => [0, false];

        yield 'null' => [null, false];
    }

    public function testToDb(): void
    {
        $this->assertSame('true', DbBool::toDb(true));
        $this->assertSame('false', DbBool::toDb(false));
    }
}
