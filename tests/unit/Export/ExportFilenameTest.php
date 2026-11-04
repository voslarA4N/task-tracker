<?php

declare(strict_types=1);

namespace Tests\Unit\Export;

use App\Libraries\Export\ExportFilename;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ExportFilenameTest extends TestCase
{
    public function testSlugs(): void
    {
        $this->assertSame('tomas-voslar-software', ExportFilename::slug('Tomáš Voslař – Software'));
        $this->assertSame('lodz-sp-z-o-o', ExportFilename::slug('ŁÓDŹ sp. z o.o.'));
        $this->assertSame('subjekt', ExportFilename::slug(''));
    }

    public function testMaliciousNameIsNeutralised(): void
    {
        $slug = ExportFilename::slug('"; rm -rf');

        $this->assertSame('rm-rf', $slug);
        $this->assertDoesNotMatchRegularExpression('/["; ]/', $slug);
    }

    public function testSlugIsLimited(): void
    {
        $this->assertLessThanOrEqual(40, strlen(ExportFilename::slug(str_repeat('abc ', 40))));
    }

    public function testMake(): void
    {
        $this->assertSame('prehlad-2026-10-tomas.csv', ExportFilename::make('prehlad', ['2026-10', 'Tomáš'], 'csv'));
    }

    public function testRejectsUnknownExtension(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExportFilename::make('x', [], 'exe');
    }
}
