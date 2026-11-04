<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\DocumentIntegrityError;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\RenderedPdf;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DeliveryFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * D-03, D-11: citatelia berú najvyssiu verziu, konkretna verzia sa cita s overenim sha256.
 *
 * @internal
 */
#[Group('integration')]
final class DocumentVersionsReadTest extends PgTestCase
{
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private const V1 = '%PDF-1.4 version one';
    private const V2 = '%PDF-1.4 version two, longer';

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
        $this->configureMail();
    }

    protected function tearDown(): void
    {
        $this->resetMail();
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function newInvoice(string $bytes = self::V1): int
    {
        $entity = $this->createEntity('SK');
        $client = $this->createClient();
        $alloc  = $this->createAllocation($this->createTask($client), '8.00', '2026-10');

        return $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc], $bytes, ['with_report' => true]);
    }

    private function addVersion(int $invoiceId, int $version, string $bytes, string $kind = 'invoice', ?string $sha = null): void
    {
        $this->owner()->query(
            "INSERT INTO invoice_documents (invoice_id, kind, version, content, byte_size, sha256, template_version, renderer, created_at)
             VALUES (:i:, :k:, :v:, decode(:hex:, 'hex'), :size:, :sha:, 'test-2', 'test', now())",
            ['i' => $invoiceId, 'k' => $kind, 'v' => $version, 'hex' => bin2hex($bytes), 'size' => strlen($bytes), 'sha' => $sha ?? hash('sha256', $bytes)],
        );
    }

    private function pdf(string $bytes): RenderedPdf
    {
        return RenderedPdf::fromBytes($bytes, 'tpl', 'test');
    }

    public function testStoreReturnsNextVersionPerKind(): void
    {
        $id    = $this->newInvoice();
        $store = new DocumentStore($this->owner());
        // fixture uz ulozil verziu 1 pre oba druhy, store() pridava 2
        $this->assertSame(2, $store->store($id, 'invoice', $this->pdf(self::V2)));
        $this->assertSame(3, $store->store($id, 'invoice', $this->pdf('%PDF-1.4 three')));
        $this->assertSame(2, $store->store($id, 'report', $this->pdf(self::V2)));
    }

    public function testReadAndMetaReturnHighestVersion(): void
    {
        $id = $this->newInvoice();
        $this->addVersion($id, 2, self::V2);
        $store = new DocumentStore($this->owner());

        $doc = $store->read($id);
        $this->assertSame(self::V2, $doc?->bytes);
        $this->assertSame(2, $doc->version);

        $meta = $store->meta($id);
        $this->assertSame(2, $meta['version']);
        $this->assertSame(hash('sha256', self::V2), $meta['sha256']);
        $this->assertNotSame('', $meta['created_at']);
    }

    public function testReadVersionIsExactAndScopedToInvoice(): void
    {
        $id    = $this->newInvoice();
        $other = $this->newInvoice('%PDF-1.4 other invoice');
        $this->addVersion($id, 2, self::V2);
        $store = new DocumentStore($this->owner());

        $v1 = $store->readVersion($id, 'invoice', 1);
        $this->assertSame(self::V1, $v1?->bytes);
        $this->assertSame(1, $v1->version);
        $this->assertNull($store->readVersion($id, 'invoice', 3));
        $this->assertNull($store->readVersion($other, 'invoice', 2));
        $this->assertSame('%PDF-1.4 other invoice', $store->readVersion($other, 'invoice', 1)?->bytes);
    }

    public function testVersionsListsHistoryWithoutContent(): void
    {
        $id = $this->newInvoice();
        $this->addVersion($id, 2, self::V2);
        $list = (new DocumentStore($this->owner()))->versions($id);

        $this->assertCount(3, $list);
        $this->assertSame([['invoice', 2], ['invoice', 1], ['report', 1]], array_map(static fn (array $r): array => [$r['kind'], $r['version']], $list));
        $this->assertArrayNotHasKey('content', $list[0]);
    }

    public function testVerifyRejectsMismatchedHash(): void
    {
        $this->expectException(DocumentIntegrityError::class);
        DocumentStore::verify(self::V1, hash('sha256', self::V2));
    }

    public function testMailerAttachesHighestVersion(): void
    {
        $inv = $this->issuedInvoice();
        $this->addVersion($inv['id'], 2, self::V2);
        $this->mailer()->send($this->sendCommand($inv['id']));

        $this->assertStringContainsString(chunk_split(base64_encode(self::V2), 76, "\r\n"), $this->lastEmail->capturedBody);
    }
}
