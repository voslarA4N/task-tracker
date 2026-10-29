<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\DocumentFilename;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DocumentFilenameTest extends TestCase
{
    #[DataProvider('provideFor')]
    public function testFor(string $kind, string $lang, string $number, string $expected): void
    {
        $this->assertSame($expected, DocumentFilename::for($kind, $lang, $number));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideFor(): iterable
    {
        yield 'invoice sk' => ['invoice', 'sk', '202610001', 'Faktura_202610001.pdf'];

        yield 'report sk' => ['report', 'sk', '202610001', 'Vykaz_202610001.pdf'];

        yield 'report pl' => ['report', 'pl', '202610001', 'Wykaz_202610001.pdf'];

        yield 'invoice en' => ['invoice', 'en', '202610001', 'Invoice_202610001.pdf'];

        yield 'report en' => ['report', 'en', '202610001', 'Report_202610001.pdf'];

        yield 'slashes' => ['invoice', 'cs', 'FA/2026/001', 'Faktura_FA_2026_001.pdf'];

        yield 'unknown language' => ['report', 'de', '1', 'Vykaz_1.pdf'];

        yield 'injection' => ['invoice', 'sk', "1\"\r\nX: y", 'Faktura_1___X__y.pdf'];
    }

    public function testUnknownKindThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DocumentFilename::for('x', 'sk', '1');
    }
}
