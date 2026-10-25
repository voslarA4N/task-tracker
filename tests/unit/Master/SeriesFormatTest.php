<?php

declare(strict_types=1);

namespace Tests\Unit\Master;

use App\Libraries\Master\InvalidSeriesFormat;
use App\Libraries\Master\SeriesFormat;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SeriesFormatTest extends TestCase
{
    #[DataProvider('provideRender')]
    public function testRender(string $format, string $date, int $sequence, string $expected): void
    {
        $this->assertSame($expected, SeriesFormat::parse($format)->render($date, $sequence));
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function provideRender(): iterable
    {
        yield 'default' => ['RRRRMM###', '2026-10-25', 1, '202610001'];

        yield 'prefix' => ['DB-RRRRMM###', '2026-10-25', 12, 'DB-202610012'];

        yield 'short year' => ['RR/####', '2026-01-05', 7, '26/0007'];

        yield 'max sequence' => ['FA-RRRR-#####', '2026-12-31', 99999, 'FA-2026-99999'];

        yield 'literal R' => ['FAKTURA-RRRR-###', '2026-03-01', 3, 'FAKTURA-2026-003'];

        yield 'literal M' => ['M-RRRR###', '2026-03-01', 3, 'M-2026003'];

        yield 'month before' => ['MMRRRR/###', '2026-03-01', 15, '032026/015'];

        yield 'single digit' => ['RRRR#', '2026-03-01', 9, '20269'];

        yield 'nine digits' => ['RRRR#########', '2026-03-01', 1, '2026000000001'];

        yield 'dots underscores' => ['A.B_C-RRRR###', '2026-03-01', 1, 'A.B_C-2026001'];
    }

    #[DataProvider('provideInvalid')]
    public function testInvalid(string $format, string $reason): void
    {
        try {
            SeriesFormat::parse($format);
            $this->fail('Expected InvalidSeriesFormat.');
        } catch (InvalidSeriesFormat $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'no year' => ['###', 'no_year'];

        yield 'only month' => ['MM###', 'no_year'];

        yield 'two years glued' => ['RRRRRR###', 'two_years'];

        yield 'two years apart' => ['RRRR-RR-###', 'two_years'];

        yield 'no sequence' => ['RRRRMM', 'no_sequence'];

        yield 'two sequences' => ['RRRR#-#', 'two_sequences'];

        yield 'sequence too long' => ['RRRR##########', 'sequence_too_long'];

        yield 'two months' => ['RRRRMMMM###', 'two_months'];

        yield 'space' => ['RRRR ###', 'bad_char'];

        yield 'plus' => ['RRRR+###', 'bad_char'];

        yield 'backslash' => ['RRRR\\###', 'bad_char'];

        yield 'diacritics' => ['ČÍSLO-RRRR###', 'bad_char'];

        yield 'newline' => ["RRRR###\n", 'bad_char'];

        yield 'empty' => ['', 'empty'];

        yield 'too long' => ['ABCDEFGHIJKLMNOPQRSTUVWXYZ-RRRR###', 'too_long'];
    }

    #[DataProvider('provideMayCollideWith')]
    public function testMayCollideWith(string $a, string $b, bool $expected): void
    {
        $this->assertSame($expected, SeriesFormat::parse($a)->mayCollideWith(SeriesFormat::parse($b)));
        $this->assertSame($expected, SeriesFormat::parse($b)->mayCollideWith(SeriesFormat::parse($a)));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideMayCollideWith(): iterable
    {
        yield 'identical' => ['RRRRMM###', 'RRRRMM###', true];

        yield 'yearly vs monthly same width' => ['RRRR####', 'RRRRMM##', true];

        yield 'literal zero vs digit' => ['RRRR0###', 'RRRR####', true];

        yield 'letter case' => ['db-RRRR###', 'DB-RRRR###', true];

        yield 'default pair' => ['RRRRMM###', 'DB-RRRRMM###', false];

        yield 'different width' => ['RRRR###', 'RRRR####', false];

        yield 'different letter' => ['FA-RRRR###', 'DB-RRRR###', false];

        yield 'separator vs digit' => ['RRRR-###', 'RRRR####', false];
    }

    public function testCollisionExamplesRenderEqualNumbers(): void
    {
        // Príklady z WR-05: rôzne reťazce formátu, rovnaké číslo dokladu.
        $this->assertSame(
            SeriesFormat::parse('RRRR####')->render('2026-10-25', 1001),
            SeriesFormat::parse('RRRRMM##')->render('2026-10-25', 1),
        );
        $this->assertSame(
            SeriesFormat::parse('RRRR0###')->render('2026-10-25', 1),
            SeriesFormat::parse('RRRR####')->render('2026-10-25', 1),
        );
    }

    public function testThirtyCharsOutputIsAllowed(): void
    {
        $format = 'ABCDEFGHIJKLMNOPQRSTUVWXY-RR##';

        $this->assertSame('ABCDEFGHIJKLMNOPQRSTUVWXY-2601', SeriesFormat::parse($format)->render('2026-01-01', 1));
    }

    public function testResetsMonthlyAndPeriodKey(): void
    {
        $monthly = SeriesFormat::parse('RRRRMM###');
        $yearly  = SeriesFormat::parse('RR/####');

        $this->assertTrue($monthly->resetsMonthly());
        $this->assertFalse($yearly->resetsMonthly());
        $this->assertSame('202610', $monthly->periodKey('2026-10-25'));
        $this->assertSame('2026', SeriesFormat::parse('RRRR###')->periodKey('2026-10-25'));
        $this->assertSame('2026', $yearly->periodKey('2026-01-05'));
    }

    public function testDigitsAndMaxSequence(): void
    {
        $format = SeriesFormat::parse('RRRRMM###');

        $this->assertSame(3, $format->digits());
        $this->assertSame(999, $format->maxSequence());
        $this->assertSame('RRRRMM###', $format->format());
    }

    public function testOverflow(): void
    {
        $this->expectException(OverflowException::class);
        SeriesFormat::parse('RRRRMM###')->render('2026-10-25', 1000);
    }

    public function testZeroSequenceRejected(): void
    {
        $this->expectException(OverflowException::class);
        SeriesFormat::parse('RRRRMM###')->render('2026-10-25', 0);
    }
}
