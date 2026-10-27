<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\MpdfInvoiceRenderer;
use App\Libraries\Invoicing\RendererRegistry;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;
use Tests\Support\SnapshotSample;

/**
 * D-01, D-02: doklady inv-1 a ich subory ostavaju nezmenene po zavedeni inv-2.
 *
 * @internal
 */
#[Group('integration')]
final class LegacyInv1UnchangedTest extends PgTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private const INV1_HASHES = [
        'app/Libraries/Invoicing/MpdfInvoiceRenderer.php' => 'f4eed70b56385eaed47498d4a38c4563898fdf08b699a5fef262ca23dfc0d8d3',
        'app/Views/pdf/invoice_inv1.php'                  => '9a56d73a44a85b79e997b8f23e537340a6ac70fb646f7a958462a7228654b647',
        'resources/pdf/invoice-inv1.css'                  => 'f644c734e610d35b9db505b7b381420b836fb1e8ace5e060dfbce536b477834f',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    public function testStoredInv1DocumentIsReturnedByteForByte(): void
    {
        $s     = $this->issueSetup();
        $bytes = (new MpdfInvoiceRenderer())->render(SnapshotSample::issued())->bytes;
        $id    = $this->insertIssuedInvoiceRaw($s['client'], $s['entity'], '2026-10', $s['parts'], $bytes);

        $report = (int) $this->owner()->query("SELECT count(*) AS c FROM invoice_documents WHERE invoice_id = :id: AND kind = 'report'", ['id' => $id])->getRow()->c;
        $this->assertSame(0, $report);

        $read = (new DocumentStore($this->rw()))->read($id);
        $this->assertNotNull($read);
        $this->assertSame($bytes, $read->bytes);
        $this->assertSame(hash('sha256', $bytes), $read->sha256);
    }

    public function testInv1FilesAreByteIdentical(): void
    {
        foreach (self::INV1_HASHES as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', ROOTPATH . $path), $path);
        }
    }

    public function testRegistryStillResolvesInv1(): void
    {
        $this->assertInstanceOf(MpdfInvoiceRenderer::class, (new RendererRegistry())->for('inv-1'));
    }
}
