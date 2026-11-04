<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\RegeneratePolicy;
use App\Libraries\Invoicing\RegenerateRejected;
use App\Libraries\Platform\Clock;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class RegeneratePolicyTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    private function inv(array $o = []): array
    {
        return $o + ['origin' => 'issued', 'type' => 'invoice', 'snapshot_schema' => 3, 'created_at' => '2026-11-01 10:00:00+01', 'regen_until' => '2026-11-04 10:00:00+01'];
    }

    private function at(string $s): DateTimeImmutable
    {
        return Clock::parseInstant($s);
    }

    public function testReportAllowedAnytime(): void
    {
        $this->assertNull(RegeneratePolicy::check($this->inv(), 'report', $this->at('2027-11-01 10:00:00+01')));
        $this->assertNull(RegeneratePolicy::check($this->inv(['regen_until' => '2026-11-01 10:00:00+01']), 'report', $this->at('2027-11-01 10:00:00+01')));
    }

    public function testReportNotRegenerableCases(): void
    {
        $now = $this->at('2026-11-02 10:00:00+01');
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(['snapshot_schema' => 1]), 'report', $now));
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(['type' => 'credit_note', 'snapshot_schema' => 4]), 'report', $now));
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(['origin' => 'external']), 'report', $now));
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(['origin' => 'external']), 'invoice', $now));
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(), 'contract', $now));
    }

    public function testBoundary72h(): void
    {
        $this->assertNull(RegeneratePolicy::check($this->inv(), 'invoice', $this->at('2026-11-04 10:00:00+01')));
        $this->assertSame('window_expired', RegeneratePolicy::check($this->inv(), 'invoice', $this->at('2026-11-04 10:00:01+01')));
        $this->assertNull(RegeneratePolicy::check($this->inv(), 'invoice', $this->at('2026-11-01 10:00:00+01')));
    }

    public function testZeroWindowMeansNever(): void
    {
        $this->assertSame('window_expired', RegeneratePolicy::check($this->inv(['regen_until' => '2026-11-01 10:00:00+01']), 'invoice', $this->at('2026-11-01 10:00:00+01')));
    }

    public function testSchemaOneAndCreditNote(): void
    {
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(['snapshot_schema' => 1]), 'invoice', $this->at('2026-11-02 10:00:00+01')));
        $cn = $this->inv(['type' => 'credit_note', 'snapshot_schema' => 4]);
        $this->assertNull(RegeneratePolicy::check($cn, 'invoice', $this->at('2026-11-02 10:00:00+01')));
        $this->assertSame('window_expired', RegeneratePolicy::check($cn, 'invoice', $this->at('2026-11-05 10:00:00+01')));
    }

    public function testTimezoneComparesInstants(): void
    {
        $inv = $this->inv(['created_at' => '2026-11-01 09:00:00+00', 'regen_until' => '2026-11-04 09:00:00+00']);
        $this->assertNull(RegeneratePolicy::check($inv, 'invoice', $this->at('2026-11-04 10:00:00+01')));
    }

    public function testStatusIgnored(): void
    {
        $now = $this->at('2026-11-02 10:00:00+01');
        $this->assertSame(
            RegeneratePolicy::check($this->inv(), 'invoice', $now),
            RegeneratePolicy::check($this->inv(['status' => 'cancelled']), 'invoice', $now),
        );
    }

    public function testAssertAllowedAndCanRegenerate(): void
    {
        $late = $this->at('2026-11-10 10:00:00+01');

        try {
            RegeneratePolicy::assertAllowed($this->inv(), 'invoice', $late);
            $this->fail('Expected rejection');
        } catch (RegenerateRejected $e) {
            $this->assertSame('window_expired', $e->reason);
        }

        $this->assertFalse(RegeneratePolicy::canRegenerate($this->inv(), 'invoice', $late));
        $this->assertTrue(RegeneratePolicy::canRegenerate($this->inv(), 'report', $late));
    }

    public function testDeadline(): void
    {
        $this->assertSame('2026-11-04T10:00:00+01:00', RegeneratePolicy::deadline($this->inv())?->format('c'));
        $this->assertNull(RegeneratePolicy::deadline($this->inv(['regen_until' => '2026-11-01 10:00:00+01'])));
    }

    public function testMissingOrInvalidTimestampsFailClosed(): void
    {
        $now = $this->at('2026-11-02 10:00:00+01');

        foreach ([null, '', 'now', 'tomorrow', '2026-11-01 10:00:00', 'garbage'] as $bad) {
            foreach (['created_at', 'regen_until'] as $key) {
                $inv = $this->inv([$key => $bad]);
                $this->assertNull(RegeneratePolicy::deadline($inv), $key . ' ' . var_export($bad, true));
                $this->assertSame('window_expired', RegeneratePolicy::check($inv, 'invoice', $now), $key . ' ' . var_export($bad, true));
            }
        }

        foreach (['created_at', 'regen_until'] as $key) {
            $inv = $this->inv();
            unset($inv[$key]);
            $this->assertSame('window_expired', RegeneratePolicy::check($inv, 'invoice', $now), 'missing ' . $key);
        }
    }

    public function testUnknownReasonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RegenerateRejected('unknown');
    }
}
