<?php

declare(strict_types=1);

namespace Tests\Unit\Export;

use App\Libraries\Export\FormulaGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FormulaGuardTest extends TestCase
{
    #[DataProvider('provideGuard')]
    public function testGuard(string $in, string $out): void
    {
        $this->assertSame($out, FormulaGuard::text($in));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideGuard(): iterable
    {
        yield 'equals' => ['=1+1', "'=1+1"];

        yield 'plus' => ['+cmd', "'+cmd"];

        yield 'minus' => ['-2', "'-2"];

        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];

        yield 'tab' => ["\tx", "'\tx"];

        yield 'cr' => ["\rx", "'\rx"];

        yield 'plain' => ['Klient s.r.o.', 'Klient s.r.o.'];

        yield 'empty' => ['', ''];

        yield 'leading space' => [' =x', ' =x'];
    }
}
