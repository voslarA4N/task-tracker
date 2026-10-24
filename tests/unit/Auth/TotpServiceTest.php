<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Libraries\Auth\TotpService;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\PsrClock;
use CodeIgniter\Test\CIUnitTestCase;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class TotpServiceTest extends CIUnitTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private FrozenClock $clock;
    private TotpService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock('2026-10-24T10:00:00+02:00');
        $this->svc   = new TotpService($this->clock);
    }

    private function code(int $offset = 0): string
    {
        return TOTP::createFromSecret(self::SECRET, new PsrClock($this->clock))
            ->at($this->clock->now()->getTimestamp() + $offset);
    }

    private function step(int $offset = 0): int
    {
        return intdiv($this->clock->now()->getTimestamp() + $offset, 30);
    }

    public function testCurrentCodeMatchesCurrentStep(): void
    {
        $this->assertSame($this->step(), $this->svc->matchStep(self::SECRET, $this->code(), null));
    }

    public function testNeighbouringStepsAreAccepted(): void
    {
        $this->assertSame($this->step(-30), $this->svc->matchStep(self::SECRET, $this->code(-30), null));
        $this->assertSame($this->step(30), $this->svc->matchStep(self::SECRET, $this->code(30), null));
    }

    public function testTwoStepsAwayRejected(): void
    {
        $this->assertNull($this->svc->matchStep(self::SECRET, $this->code(-60), null));
        $this->assertNull($this->svc->matchStep(self::SECRET, $this->code(60), null));
    }

    public function testWrongCodeRejected(): void
    {
        $wrong = $this->code() === '000000' ? '000001' : '000000';
        $this->assertNull($this->svc->matchStep(self::SECRET, $wrong, null));
    }

    #[DataProvider('provideMalformedCodeRejected')]
    public function testMalformedCodeRejected(string $code): void
    {
        $this->assertNull($this->svc->matchStep(self::SECRET, $code, null));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedCodeRejected(): iterable
    {
        yield 'five digits' => ['12345'];

        yield 'seven digits' => ['1234567'];

        yield 'letters' => ['abcdef'];

        yield 'with space' => ['12 345'];
    }

    public function testReplayWithLastStepRejected(): void
    {
        $this->assertNull($this->svc->matchStep(self::SECRET, $this->code(), $this->step()));
    }

    public function testLastStepBeforeCurrentAccepted(): void
    {
        $this->assertSame($this->step(), $this->svc->matchStep(self::SECRET, $this->code(), $this->step() - 1));
    }

    public function testNewSecretFormat(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $this->svc->newSecret());
    }

    public function testProvisioningUri(): void
    {
        $uri = $this->svc->provisioningUri(self::SECRET, 'owner@example.test');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('issuer=Work%20Tracker', $uri);
    }

    public function testQrSvgIsInlineSafe(): void
    {
        $svg = $this->svc->qrSvg($this->svc->provisioningUri(self::SECRET, 'owner@example.test'));
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
        $this->assertStringNotContainsString('style=', $svg);
    }

    public function testEncryptDecryptRoundTripTwice(): void
    {
        $enc1 = $this->svc->encryptSecret(self::SECRET);
        $this->assertSame(self::SECRET, $this->svc->decryptSecret($enc1));
        $enc2 = $this->svc->encryptSecret(self::SECRET);
        $this->assertSame(self::SECRET, $this->svc->decryptSecret($enc2));
        $this->assertStringNotContainsString(self::SECRET, $enc1);
        $this->assertStringNotContainsString(self::SECRET, (string) base64_decode($enc1, true));
    }

    public function testFormatSecretForDisplay(): void
    {
        $this->assertSame('ABCD EFGH', $this->svc->formatSecretForDisplay('ABCDEFGH'));
    }
}
