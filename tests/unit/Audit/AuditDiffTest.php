<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Libraries\Audit\AuditDiff;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Group('unit')]
final class AuditDiffTest extends TestCase
{
    public function testCreateHasNoBefore(): void
    {
        $this->assertSame([['key' => 'a', 'before' => null, 'after' => '1', 'changed' => true]], AuditDiff::fields(null, ['a' => 1]));
    }

    public function testUnchangedAndChanged(): void
    {
        $f = AuditDiff::fields(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3]);
        $this->assertFalse($f[0]['changed']);
        $this->assertTrue($f[1]['changed']);
        $this->assertSame('2', $f[1]['before']);
        $this->assertSame('3', $f[1]['after']);
    }

    public function testDeleteHasNoAfter(): void
    {
        $f = AuditDiff::fields(['a' => 'x'], null);
        $this->assertNull($f[0]['after']);
        $this->assertTrue($f[0]['changed']);
    }

    public function testNestedAndBool(): void
    {
        $f = AuditDiff::fields(null, ['n' => ['m' => 'Ťažký'], 'b' => true]);
        $this->assertSame('{"m":"Ťažký"}', $f[0]['after']);
        $this->assertSame('true', $f[1]['after']);
    }
}
