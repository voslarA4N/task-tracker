<?php

declare(strict_types=1);

namespace Tests\Unit\Master;

use App\Libraries\Master\PortalUrlTemplate;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class PortalUrlTemplateTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validProvider(): iterable
    {
        yield 'https'      => ['https://taskman.example/task/{id}'];
        yield 'upper http' => ['HTTP://x.example/?t={id}'];
        yield 'port'       => ['https://x.example:8443/t/{id}#top'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'javascript'       => ['javascript:alert(1)//{id}'];
        yield 'javascript upper' => ['JaVaScRiPt:alert(1)//{id}'];
        yield 'data'             => ['data:text/html,{id}'];
        yield 'protocol relative' => ['//evil.example/{id}'];
        yield 'ftp'              => ['ftp://x.example/{id}'];
        yield 'no placeholder'   => ['https://x.example/task'];
        yield 'two placeholders' => ['https://x.example/{id}/{id}'];
        yield 'space'            => ['https://x.example/ {id}'];
        yield 'newline'          => ["https://x.example/\n{id}"];
        yield 'no host'          => ['https:///{id}'];
        yield 'too long'         => ['https://x.example/' . str_repeat('a', 500) . '{id}'];
        yield 'empty'            => [''];
    }

    #[DataProvider('validProvider')]
    public function testValid(string $tpl): void
    {
        $this->assertNull(PortalUrlTemplate::validate($tpl));
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(string $tpl): void
    {
        $this->assertSame(lang('Clients.errorPortalTemplate'), PortalUrlTemplate::validate($tpl));
    }

    public function testRenderEncodesId(): void
    {
        $this->assertSame(
            'https://t.example/task/a%20b%2F..%2Fx',
            PortalUrlTemplate::render('https://t.example/task/{id}', 'a b/../x'),
        );
    }
}
