<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class InvoiceDocumentTest extends PgTestCase
{
    use InvoiceFixtures;
    use MasterFixtures;

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

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function setUpPart(): array
    {
        $entity = $this->createEntity('SK');
        $client = $this->createClient();

        return [$entity, $client, $this->createAllocation($this->createTask($client), '8.00', '2026-10')];
    }

    private function assertDbError(string $regex, callable $fn): void
    {
        try {
            $fn();
        } catch (DatabaseException $e) {
            $this->assertMatchesRegularExpression($regex, $e->getMessage());

            return;
        }

        $this->fail('Expected DatabaseException matching ' . $regex);
    }

    private function invoiceCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM invoices')->getRow()->c;
    }

    public function testIssuedWithoutDocumentFails(): void
    {
        [$entity, $client, $alloc] = $this->setUpPart();
        $this->assertDbError('/is incomplete/', fn () => $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc], '%PDF', ['without_document' => true]));
        $this->assertSame(0, $this->invoiceCount());
    }

    public function testLineSumMustMatchTotal(): void
    {
        [$entity, $client, $alloc] = $this->setUpPart();
        $this->assertDbError('/is incomplete/', fn () => $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc], '%PDF', ['total' => '1.00']));
        $this->assertSame(0, $this->invoiceCount());
    }

    public function testIssuedLineNeedsVatCode(): void
    {
        [$entity, $client, $alloc] = $this->setUpPart();
        $this->assertDbError('/is incomplete/', fn () => $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc], '%PDF', ['vat_code' => null]));
    }

    public function testWrongSha256Fails(): void
    {
        [$entity, $client, $alloc] = $this->setUpPart();
        $this->assertDbError('/check constraint/i', fn () => $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc], '%PDF', ['sha256' => hash('sha256', 'iny obsah')]));
        $this->assertSame(0, $this->invoiceCount());
    }

    public function testBinaryRoundTripAndHash(): void
    {
        [$entity, $client, $alloc] = $this->setUpPart();
        $bytes = "\0\\'\"\xFF";
        $id    = $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc], $bytes);

        $row = $this->rw()->query("SELECT content, sha256, encode(sha256(content), 'hex') AS h FROM invoice_documents WHERE invoice_id = ?", [$id])->getRow();
        $this->assertSame($bytes, pg_unescape_bytea((string) $row->content));
        $this->assertSame(hash('sha256', $bytes), $row->h);
        $this->assertSame($row->h, trim((string) $row->sha256));
    }

    public function testCannotAddLineToCommittedInvoice(): void
    {
        [$entity, $client, $alloc] = $this->setUpPart();
        $id = $this->insertIssuedInvoiceRaw($client, $entity, '2026-10', [$alloc]);

        $this->assertDbError('/is incomplete/', function () use ($id): void {
            $db = $this->owner();
            $db->transException(true)->transBegin();

            try {
                $db->query("INSERT INTO invoice_lines (invoice_id, position, title, hours, rate, amount, vat_code) VALUES ({$id}, 2, 'x', 1.00, 27.00, 27.00, 'np')");
                $db->query('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                $db->transRollback();
                $db->resetTransStatus();
                $db->transException(false);
            }
        });
    }
}
