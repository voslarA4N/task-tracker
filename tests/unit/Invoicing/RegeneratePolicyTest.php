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
        return $o + ['origin' => 'issued', 'type' => 'invoice', 'snapshot_schema' => 3, 'created_at' => '2026-11-01 10:00:00+01'];
    }

    private function at(string $s): DateTimeImmutable
    {
        return Clock::parseInstant($s);
    }

    public function testReportAllowedAnytime(): void
    {
        $this->assertNull(RegeneratePolicy::check($this->inv(), 'report', 72, $this->at('2027-11-01 10:00:00+01')));
        $this->assertNull(RegeneratePolicy::check($this->inv(), 'report', 0, $this->at('2027-11-01 10:00:00+01')));
    }

    public function testReportNotRegenerableCases(): void
    {
        $now = $this->at('2026-11-02 10:00:00+01');
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(['snapshot_schema' => 1]), 'report', 72, $now));
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(['type' => 'credit_note', 'snapshot_schema' => 4]), 'report', 72, $now));
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(['origin' => 'external']), 'report', 72, $now));
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(['origin' => 'external']), 'invoice', 72, $now));
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(), 'contract', 72, $now));
    }

    public function testBoundary72h(): void
    {
        $this->assertNull(RegeneratePolicy::check($this->inv(), 'invoice', 72, $this->at('2026-11-04 10:00:00+01')));
        $this->assertSame('window_expired', RegeneratePolicy::check($this->inv(), 'invoice', 72, $this->at('2026-11-04 10:00:01+01')));
        $this->assertNull(RegeneratePolicy::check($this->inv(), 'invoice', 72, $this->at('2026-11-01 10:00:00+01')));
    }

    public function testZeroWindowMeansNever(): void
    {
        $this->assertSame('window_expired', RegeneratePolicy::check($this->inv(), 'invoice', 0, $this->at('2026-11-01 10:00:00+01')));
    }

    public function testSchemaOneAndCreditNote(): void
    {
        $this->assertSame('not_regenerable', RegeneratePolicy::check($this->inv(['snapshot_schema' => 1]), 'invoice', 72, $this->at('2026-11-02 10:00:00+01')));
        $cn = $this->inv(['type' => 'credit_note', 'snapshot_schema' => 4]);
        $this->assertNull(RegeneratePolicy::check($cn, 'invoice', 72, $this->at('2026-11-02 10:00:00+01')));
        $this->assertSame('window_expired', RegeneratePolicy::check($cn, 'invoice', 72, $this->at('2026-11-05 10:00:00+01')));
    }

    public function testTimezoneComparesInstants(): void
    {
        $inv = $this->inv(['created_at' => '2026-11-01 09:00:00+00']);
        $this->assertNull(RegeneratePolicy::check($inv, 'invoice', 72, $this->at('2026-11-04 10:00:00+01')));
    }

    public function testStatusIgnored(): void
    {
        $now = $this->at('2026-11-02 10:00:00+01');
        $this->assertSame(
            RegeneratePolicy::check($this->inv(), 'invoice', 72, $now),
            RegeneratePolicy::check($this->inv(['status' => 'cancelled']), 'invoice', 72, $now),
        );
    }

    public function testAssertAllowedAndCanRegenerate(): void
    {
        $late = $this->at('2026-11-10 10:00:00+01');

        try {
            RegeneratePolicy::assertAllowed($this->inv(), 'invoice', 72, $late);
            $this->fail('Expected rejection');
        } catch (RegenerateRejected $e) {
            $this->assertSame('window_expired', $e->reason);
        }

        $this->assertFalse(RegeneratePolicy::canRegenerate($this->inv(), 'invoice', 72, $late));
        $this->assertTrue(RegeneratePolicy::canRegenerate($this->inv(), 'report', 72, $late));
    }

    public function testDeadline(): void
    {
        $this->assertSame('2026-11-04T10:00:00+01:00', RegeneratePolicy::deadline($this->inv(), 72)?->format('c'));
        $this->assertNull(RegeneratePolicy::deadline($this->inv(), 0));
    }

    public function testMissingOrInvalidCreatedAtFailsClosed(): void
    {
        $now = $this->at('2026-11-02 10:00:00+01');

        foreach ([null, '', 'now', 'tomorrow', '2026-11-01 10:00:00', 'garbage'] as $bad) {
            $inv = $this->inv(['created_at' => $bad]);
            $this->assertNull(RegeneratePolicy::deadline($inv, 72), var_export($bad, true));
            $this->assertSame('window_expired', RegeneratePolicy::check($inv, 'invoice', 72, $now), var_export($bad, true));
        }

        $noKey = $this->inv();
        unset($noKey['created_at']);
        $this->assertSame('window_expired', RegeneratePolicy::check($noKey, 'invoice', 72, $now));
    }

    public function testUnknownReasonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RegenerateRejected('unknown');
    }
}
