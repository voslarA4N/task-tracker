<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\CreditNoteSnapshotBuilder;
use App\Libraries\Invoicing\DocumentTemplates;
use App\Libraries\Invoicing\RendererRegistry;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class DocumentTemplatesTest extends CIUnitTestCase
{
    public function testCurrentTemplates(): void
    {
        $this->assertSame('inv-2', DocumentTemplates::current('invoice', 'invoice'));
        $this->assertSame('rep-2', DocumentTemplates::current('report', 'invoice'));
        $this->assertSame('cn-1', DocumentTemplates::current('invoice', 'credit_note'));
    }

    public function testCreditNoteReportThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DocumentTemplates::current('report', 'credit_note');
    }

    public function testUnknownKindThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DocumentTemplates::current('xyz', 'invoice');
    }

    public function testEveryCurrentTemplateHasRenderer(): void
    {
        $registry = new RendererRegistry();
        $resolved = 0;

        foreach ([['invoice', 'invoice'], ['report', 'invoice'], ['invoice', 'credit_note']] as [$k, $t]) {
            $registry->for(DocumentTemplates::current($k, $t));
            $resolved++;
        }

        $this->assertSame(3, $resolved);
    }

    public function testCreditNoteBuilderUsesCentralConstant(): void
    {
        $this->assertSame(DocumentTemplates::current('invoice', 'credit_note'), CreditNoteSnapshotBuilder::TEMPLATE_VERSION);
    }
}
