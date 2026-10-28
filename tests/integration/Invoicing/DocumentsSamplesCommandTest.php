<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Invoicing\Samples\SampleSnapshots;
use CodeIgniter\Test\StreamFilterTrait;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PdfText;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class DocumentsSamplesCommandTest extends PgTestCase
{
    use IssueFixtures;
    use MasterFixtures;
    use StreamFilterTrait;

    private const OUT = 'phpunit-samples';

    protected function tearDown(): void
    {
        foreach (glob(WRITEPATH . 'samples/' . self::OUT . '/*') ?: [] as $f) {
            unlink($f);
        }

        if (is_dir(WRITEPATH . 'samples/' . self::OUT)) {
            rmdir(WRITEPATH . 'samples/' . self::OUT);
        }

        parent::tearDown();
    }

    private function run2(string $args): string
    {
        $this->resetStreamFilterBuffer();
        command('documents:samples ' . $args);

        return $this->getStreamFilterBuffer();
    }

    private function counts(): string
    {
        $parts = [];

        foreach (['invoices', 'invoice_documents', 'invoice_counters', 'audit_log'] as $t) {
            $parts[] = $t . '=' . $this->owner()->query('SELECT count(*) AS c FROM ' . $t)->getRow()->c;
        }

        return implode(',', $parts);
    }

    public function testGeneratesAllProfilesWithoutDbWrites(): void
    {
        $before = $this->counts();
        $out    = $this->run2('--out ' . self::OUT);

        $this->assertStringContainsString('Hotovo', $out);
        $this->assertSame($before, $this->counts());

        foreach (SampleSnapshots::PROFILES as $profile) {
            $files = glob(WRITEPATH . 'samples/' . self::OUT . '/' . $profile . '-*-faktura.pdf') ?: [];
            $this->assertNotEmpty($files, $profile);

            foreach ($files as $inv) {
                $report = str_replace('-faktura.pdf', '-vykaz.pdf', $inv);
                $this->assertFileExists($report);
                $this->assertSame('%PDF-', substr((string) file_get_contents($inv), 0, 5));
                $this->assertSame('%PDF-', substr((string) file_get_contents($report), 0, 5));
            }
        }

        $this->assertGreaterThanOrEqual(10, count(glob(WRITEPATH . 'samples/' . self::OUT . '/*.pdf') ?: []));

        $text = PdfText::extract((string) file_get_contents(WRITEPATH . 'samples/' . self::OUT . '/sk-sk-faktura.pdf'));

        if ($text !== null) {
            $this->assertStringContainsString('VZOR', $text);
            $this->assertStringContainsString('VZOR', (string) PdfText::extract((string) file_get_contents(WRITEPATH . 'samples/' . self::OUT . '/sk-sk-vykaz.pdf')));
        }
    }

    public function testRejectsInvalidInput(): void
    {
        $this->assertStringContainsString('Neplatný vstup', $this->run2('--profile xx'));
        $this->assertStringContainsString('Neplatný vstup', $this->run2('--language de'));
        $this->assertStringContainsString('Neplatný vstup', $this->run2('--out ../x'));
    }

    public function testIssuedPdfHasNoSampleLabel(): void
    {
        $snapshot = SampleSnapshots::invoice('sk');
        unset($snapshot['sample']);

        $text = PdfText::extract((new MpdfInvoiceInv2Renderer())->render($snapshot)->bytes);

        if ($text === null) {
            $this->markTestSkipped('pdftotext is not available');
        }

        $this->assertStringNotContainsString('VZOR', $text);
    }
}
