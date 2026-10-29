<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Libraries\Delivery\SenderIdentity;
use App\Libraries\Delivery\SenderRejected;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SenderIdentityTest extends CIUnitTestCase
{
    private string $original = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = config('Email')->allowedFromDomains;
    }

    protected function tearDown(): void
    {
        config('Email')->allowedFromDomains = $this->original;
        parent::tearDown();
    }

    private function reason(string $email): string
    {
        try {
            SenderIdentity::assertAllowed($email);
        } catch (SenderRejected $e) {
            return $e->reason;
        }

        return 'ok';
    }

    public function testAllowedDomainsAreTrimmedAndLowercased(): void
    {
        config('Email')->allowedFromDomains = 'vostack.dev, Example.org , ,vostack.dev';

        $this->assertSame(['vostack.dev', 'example.org'], SenderIdentity::allowedDomains());
    }

    public function testAssertAllowed(): void
    {
        config('Email')->allowedFromDomains = 'vostack.dev, Example.org ';

        $this->assertSame('ok', $this->reason('noreply@vostack.dev'));
        $this->assertSame('ok', $this->reason('X@EXAMPLE.org'));
        $this->assertSame('domain', $this->reason('x@evil.sk'));
        $this->assertSame('domain', $this->reason('x@sub.vostack.dev'));
        $this->assertSame('invalid', $this->reason('bez-zavinaca'));
        $this->assertSame('invalid', $this->reason("x@vostack.dev\r\nBcc: a@b.sk"));
    }
}
