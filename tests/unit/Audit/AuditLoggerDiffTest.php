<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Libraries\Audit\AuditLogger;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AuditLoggerDiffTest extends CIUnitTestCase
{
    public function testDiffReturnsOnlyChangedKeys(): void
    {
        [$b, $a] = AuditLogger::diff(['a' => '1', 'b' => '2'], ['a' => '1', 'b' => '3']);
        $this->assertSame(['b' => '2'], $b);
        $this->assertSame(['b' => '3'], $a);
    }

    public function testNullVersusEmptyStringIsChange(): void
    {
        [$b, $a] = AuditLogger::diff(['a' => null], ['a' => '']);
        $this->assertSame(['a' => null], $b);
        $this->assertSame(['a' => ''], $a);
    }

    public function testAddedKeyHasNullBefore(): void
    {
        [$b, $a] = AuditLogger::diff(['a' => '1'], ['a' => '1', 'n' => 'x']);
        $this->assertSame(['n' => null], $b);
        $this->assertSame(['n' => 'x'], $a);
    }

    public function testArraysCompareByValue(): void
    {
        [$b, $a] = AuditLogger::diff(['cc' => ['a@x.sk']], ['cc' => ['a@x.sk']]);
        $this->assertSame([], $b);
        $this->assertSame([], $a);

        [$b, $a] = AuditLogger::diff(['cc' => ['a@x.sk']], ['cc' => ['b@x.sk']]);
        $this->assertSame(['cc' => ['a@x.sk']], $b);
        $this->assertSame(['cc' => ['b@x.sk']], $a);
    }

    public function testScrubRemovesSensitiveKeys(): void
    {
        $out = AuditLogger::scrub([
            'password'    => 'x',
            'totp_secret' => 'y',
            'secret_key'  => 'z',
            'token_hash'  => 'q',
            'code_hash'   => 'h',
            'hash'        => 'h2',
            'name'        => 'n',
        ]);
        $this->assertSame(['name' => 'n'], $out);
    }
}
