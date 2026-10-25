<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * WR-01: Transaction::run je bezpečné pre vnorenie do transakcie volajúceho (Phase 5 vystavenie dokladu).
 *
 * @internal
 */
#[Group('integration')]
final class TransactionTest extends PgTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $this->rw()->resetTransStatus()->transException(false);
    }

    protected function tearDown(): void
    {
        while ($this->rw()->transDepth > 0) {
            $this->rw()->transRollback();
        }

        $this->rw()->resetTransStatus()->transException(false);
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function clientRows(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM clients')->getRow()->c;
    }

    /**
     * @param BaseConnection<mixed, mixed> $db
     */
    private static function exceptionMode(BaseConnection $db): bool
    {
        return (new ReflectionProperty(BaseConnection::class, 'transException'))->getValue($db) === true;
    }

    public function testRestoresCallerExceptionModeAfterNestedCall(): void
    {
        $db = $this->rw();
        $db->transException(true)->transStart();

        $this->createClient();

        $this->assertTrue(self::exceptionMode($db), 'nested service call must not switch off the caller transException mode');
        $this->assertSame(1, $db->transDepth);
        $db->transComplete();

        $this->assertSame(1, $this->clientRows());
    }

    public function testRestoresDisabledExceptionModeAtTopLevel(): void
    {
        $this->createClient();

        $this->assertFalse(self::exceptionMode($this->rw()));
        $this->assertSame(0, $this->rw()->transDepth);
    }

    public function testNestedFailureCaughtByCallerStillRollsBackOuterTransaction(): void
    {
        $db = $this->rw();
        $db->transException(true)->transStart();

        $this->createClient(['name' => 'Outer']);

        try {
            Transaction::run($db, static function () use ($db): void {
                $db->query("INSERT INTO clients (name) VALUES ('x')");
            });
            $this->fail('Expected exception');
        } catch (DatabaseException) {
            // Volajúci chybu zachytí a pokračuje.
        }

        $db->transComplete();

        $this->assertSame(0, $this->clientRows(), 'partial work of the outer transaction must not be committed');
        $this->assertSame(0, $db->transDepth);
    }

    public function testNestedNonDatabaseFailureMarksOuterTransactionFailed(): void
    {
        $db = $this->rw();
        $db->transException(true)->transStart();

        $this->createClient(['name' => 'Outer']);

        $caught = null;

        try {
            Transaction::run($db, static function () use ($db): void {
                if ($db->transDepth > 1) {
                    throw new RuntimeException('boom');
                }
            });
        } catch (RuntimeException $e) {
            // Volajúci chybu zachytí a pokračuje.
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);

        $this->assertSame(1, $db->transDepth, 'only the nested level is closed');
        $this->assertFalse($db->transStatus());
        $this->assertFalse($db->transComplete());
        $this->assertSame(0, $this->clientRows());
    }

    public function testTopLevelFailureLeavesConnectionUsable(): void
    {
        try {
            Transaction::run($this->rw(), function (): void {
                $this->rw()->query("INSERT INTO clients (name) VALUES ('x')");
            });
            $this->fail('Expected exception');
        } catch (DatabaseException) {
        }

        $this->assertTrue($this->rw()->transStatus());
        $this->assertSame(0, $this->rw()->transDepth);

        $this->createClient();
        $this->assertSame(1, $this->clientRows());
    }
}
