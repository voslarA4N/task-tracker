<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Libraries\Platform\Money;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * Parita Money (bcmath) s PostgreSQL round(numeric, 2) na 2000 kombinaciach.
 *
 * @internal
 */
#[Group('integration')]
final class MoneyDbParityTest extends PgTestCase
{
    public function testMoneyMatchesPostgresRounding(): void
    {
        mt_srand(20261023);
        $pairs = [];

        for ($i = 0; $i < 2000; $i++) {
            $hours   = sprintf('%d.%02d', mt_rand(0, 99999), mt_rand(0, 99));
            $rate    = sprintf('%s%d.%02d', mt_rand(0, 9) === 0 ? '-' : '', mt_rand(0, 9999), mt_rand(0, 99));
            $pairs[] = [$hours, $rate];
        }

        $checked    = 0;
        $normalized = 0;

        foreach (array_chunk($pairs, 500, true) as $chunk) {
            $values = [];
            $binds  = [];

            foreach ($chunk as $ord => [$h, $r]) {
                $values[] = '(?::int, ?::numeric, ?::numeric)';
                array_push($binds, $ord, $h, $r);
            }

            $sql  = 'SELECT ord, round(h::numeric * r::numeric, 2)::text AS v FROM (VALUES ' . implode(', ', $values) . ') t(ord, h, r) ORDER BY ord';
            $rows = $this->rw()->query($sql, $binds)->getResult();

            $this->assertCount(count($chunk), $rows);

            foreach ($rows as $row) {
                [$h, $r]  = $pairs[(int) $row->ord];
                $expected = (string) $row->v;

                if ($expected === '-0.00') {
                    $expected = '0.00';
                    $normalized++;
                }

                $this->assertSame($expected, Money::of($r)->timesHours($h)->toDecimalString(), "hours={$h} rate={$r}");
                $checked++;
            }
        }

        $this->assertSame(2000, $checked, "normalized -0.00 count: {$normalized}");
    }
}
