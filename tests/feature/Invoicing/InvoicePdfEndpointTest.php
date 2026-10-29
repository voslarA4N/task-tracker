<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\MpdfInvoiceInv2Renderer;
use App\Libraries\Invoicing\MpdfInvoiceRenderer;
use App\Libraries\Invoicing\MpdfReportRenderer;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\FakePdfRenderer;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\OverridingLanguage;
use Tests\Support\SnapshotSample;

/**
 * @internal
 */
#[Group('feature')]
final class InvoicePdfEndpointTest extends AuthTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::resetSingle('language');
        Services::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return array{id: int, setup: array<string, mixed>}
     */
    private function issue(FakePdfRenderer $renderer, array $opts = []): array
    {
        $s      = $this->issueSetup($opts);
        $issued = $this->issuer($renderer)->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));

        return ['id' => $issued->id, 'setup' => $s];
    }

    public function testServesExactStoredBytesInline(): void
    {
        $i      = $this->issue(new FakePdfRenderer());
        $stored = (new DocumentStore($this->rw()))->read($i['id']);
        $result = $this->get('/invoices/' . $i['id'] . '/pdf');
        $r      = $result->response();

        $result->assertStatus(200);
        $this->assertNotNull($stored);
        $this->assertSame($stored->bytes, (string) $r->getBody());
        $this->assertSame('application/pdf', $r->getHeaderLine('Content-Type'));
        $this->assertSame('inline; filename="Faktura_202610001.pdf"', $r->getHeaderLine('Content-Disposition'));
        $this->assertSame('"' . $stored->sha256 . '"', $r->getHeaderLine('ETag'));
        $this->assertStringContainsString('no-store', $r->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('private', $r->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
    }

    public function testLegacyInv1PdfIsServedUnchanged(): void
    {
        $s     = $this->issueSetup();
        $bytes = (new MpdfInvoiceRenderer())->render(SnapshotSample::issued())->bytes;
        $id    = $this->insertIssuedInvoiceRaw($s['client'], $s['entity'], '2026-10', $s['parts'], $bytes);

        $this->assertSame($bytes, (string) $this->get('/invoices/' . $id . '/pdf')->response()->getBody());
    }

    public function testDownloadIsAttachment(): void
    {
        $i      = $this->issue(new FakePdfRenderer());
        $result = $this->get('/invoices/' . $i['id'] . '/pdf?download=1');

        $this->assertStringStartsWith('attachment;', $result->response()->getHeaderLine('Content-Disposition'));
    }

    public function testBytesUnchangedAfterMasterDataChangeAndRendererNotCalled(): void
    {
        $renderer = new FakePdfRenderer();
        $i        = $this->issue($renderer);
        $before   = (string) $this->get('/invoices/' . $i['id'] . '/pdf')->response()->getBody();
        $calls    = $renderer->calls;

        $this->owner()->query("UPDATE tasks SET rate = '99.00' WHERE id = :t:", ['t' => $i['setup']['tasks'][0]]);
        $this->owner()->query("UPDATE clients SET currency = 'PLN' WHERE id = :c:", ['c' => $i['setup']['client']]);

        $after = (string) $this->get('/invoices/' . $i['id'] . '/pdf')->response()->getBody();

        $this->assertSame($before, $after);
        $this->assertSame($calls, $renderer->calls);
    }

    public function testExternalInvoiceIs404(): void
    {
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-09');
        $id     = $this->insertExternalInvoice($client, '2026-09', [$alloc], null);

        $this->expectException(PageNotFoundException::class);
        $this->get('/invoices/' . $id . '/pdf');
    }

    public function testMissingInvoiceIs404(): void
    {
        $this->expectException(PageNotFoundException::class);
        $this->get('/invoices/999999/pdf');
    }

    public function testFilenameIsSanitized(): void
    {
        $i      = $this->issue(new FakePdfRenderer(), ['format' => 'FA/RRRR/###']);
        $result = $this->get('/invoices/' . $i['id'] . '/pdf');

        $this->assertSame('inline; filename="Faktura_FA_2026_001.pdf"', $result->response()->getHeaderLine('Content-Disposition'));
    }

    public function testReportServesStoredBytesWithCzechSlovakPrefix(): void
    {
        $i      = $this->issue(new FakePdfRenderer());
        $stored = (new DocumentStore($this->rw()))->read($i['id'], 'report');
        $result = $this->get('/invoices/' . $i['id'] . '/report');
        $r      = $result->response();

        $result->assertStatus(200);
        $this->assertNotNull($stored);
        $this->assertSame($stored->bytes, (string) $r->getBody());
        $this->assertSame('application/pdf', $r->getHeaderLine('Content-Type'));
        $this->assertSame('inline; filename="Vykaz_202610001.pdf"', $r->getHeaderLine('Content-Disposition'));
        $this->assertSame('"' . $stored->sha256 . '"', $r->getHeaderLine('ETag'));
        $this->assertStringContainsString('no-store', $r->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
    }

    public function testReportFilenameForEnglishClient(): void
    {
        $i = $this->issue(new FakePdfRenderer(), ['client' => ['doc_language' => 'en']]);

        $this->assertSame('inline; filename="Report_202610001.pdf"', $this->get('/invoices/' . $i['id'] . '/report')->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame('inline; filename="Invoice_202610001.pdf"', $this->get('/invoices/' . $i['id'] . '/pdf')->response()->getHeaderLine('Content-Disposition'));
    }

    public function testSchema1InvoiceHasNoReport404(): void
    {
        $s  = $this->issueSetup();
        $id = $this->insertIssuedInvoiceRaw($s['client'], $s['entity'], '2026-10', $s['parts']);

        $this->expectException(PageNotFoundException::class);
        $this->get('/invoices/' . $id . '/report');
    }

    public function testReportOfExternalInvoiceIs404(): void
    {
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-09');
        $id     = $this->insertExternalInvoice($client, '2026-09', [$alloc], null);

        $this->expectException(PageNotFoundException::class);
        $this->get('/invoices/' . $id . '/report');
    }

    public function testRedownloadAfterTranslationChangeReturnsIdenticalBytes(): void
    {
        $s      = $this->issueSetup();
        $issued = $this->issuer(new MpdfInvoiceInv2Renderer(), new MpdfReportRenderer())->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']));
        $before = [];

        foreach (['pdf', 'report'] as $path) {
            $r             = $this->get('/invoices/' . $issued->id . '/' . $path)->response();
            $before[$path] = [(string) $r->getBody(), $r->getHeaderLine('ETag')];
        }

        Services::injectMock('language', new OverridingLanguage('sk', ['InvoicePdf.invoice' => 'X-PREPIS', 'ReportPdf.title' => 'X-VYKAZ']));
        $this->assertSame('X-PREPIS', lang('InvoicePdf.invoice', [], 'sk'));

        foreach (['pdf', 'report'] as $path) {
            $r = $this->get('/invoices/' . $issued->id . '/' . $path)->response();
            $this->assertSame($before[$path][0], (string) $r->getBody());
            $this->assertSame($before[$path][1], $r->getHeaderLine('ETag'));
        }
    }
}
