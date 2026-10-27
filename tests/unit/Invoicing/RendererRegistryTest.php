<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Invoicing\MpdfInvoiceRenderer;
use App\Libraries\Invoicing\RendererRegistry;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakePdfRenderer;

/**
 * @internal
 */
#[Group('unit')]
final class RendererRegistryTest extends CIUnitTestCase
{
    public function testResolvesKnownVersions(): void
    {
        $r = new RendererRegistry();

        $this->assertInstanceOf(MpdfInvoiceRenderer::class, $r->for('inv-1'));
        $this->assertInstanceOf(MpdfInvoiceInv2Renderer::class, $r->for('inv-2'));
    }

    public function testUnknownVersionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RendererRegistry())->for('xyz');
    }

    public function testOverrideWins(): void
    {
        $fake = new FakePdfRenderer('inv-2');

        $this->assertSame($fake, (new RendererRegistry(['inv-2' => $fake]))->for('inv-2'));
    }
}
