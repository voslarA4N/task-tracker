<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\BankAccountSelector;
use App\Libraries\Master\BankAccountService;
use App\Libraries\Master\NoBankAccount;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class BankAccountTest extends PgTestCase
{
    use MasterFixtures;

    private const SK_IBAN = 'SK3112000000198742637541';
    private const PL_IBAN = 'PL61109010140000071219812874';
    private const CZ_IBAN = 'CZ6508000000192000145399';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        parent::tearDown();
    }

    private function service(): BankAccountService
    {
        return new BankAccountService($this->rw(), new AuditLogger($this->rw()));
    }

    private function selector(): BankAccountSelector
    {
        return new BankAccountSelector($this->rw());
    }

    /**
     * @return array<string, mixed>
     */
    private function values(string $iban, string $currency, bool $default = false, string $label = 'Ucet'): array
    {
        return ['label' => $label, 'iban' => $iban, 'swift' => null, 'currency' => $currency, 'is_default' => $default];
    }

    private function defaultCount(int $entityId): int
    {
        return (int) $this->owner()->query(
            'SELECT count(*) AS c FROM entity_bank_accounts WHERE entity_id = ? AND is_default AND archived_at IS NULL',
            [$entityId],
        )->getRow()->c;
    }

    private function auditCount(string $action): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM audit_log WHERE entity_type = ? AND action = ?', ['bank_account', $action])->getRow()->c;
    }

    public function testFirstAccountIsDefaultSecondIsNot(): void
    {
        $e  = $this->createEntity('PL');
        $a  = $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));
        $b  = $this->service()->add($e, $this->values(self::PL_IBAN, 'PLN'));
        $fa = $this->service()->find($e, $a);
        $fb = $this->service()->find($e, $b);

        $this->assertTrue($fa['is_default']);
        $this->assertFalse($fb['is_default']);
        $this->assertSame(1, $this->defaultCount($e));
        $this->assertSame(2, $this->auditCount('create'));
    }

    public function testAddWithDefaultTakesOverDefault(): void
    {
        $e = $this->createEntity('PL');
        $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));
        $b = $this->service()->add($e, $this->values(self::PL_IBAN, 'PLN', true));

        $this->assertSame(1, $this->defaultCount($e));
        $this->assertTrue($this->service()->find($e, $b)['is_default']);
    }

    public function testSetDefaultKeepsExactlyOneAndAudits(): void
    {
        $e = $this->createEntity('PL');
        $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));
        $b = $this->service()->add($e, $this->values(self::PL_IBAN, 'PLN'));

        $this->service()->setDefault($e, $b);

        $this->assertSame(1, $this->defaultCount($e));
        $this->assertTrue($this->service()->find($e, $b)['is_default']);
        $this->assertSame(1, $this->auditCount('set_default'));
    }

    public function testArchiveDefaultPromotesOldestActive(): void
    {
        $e = $this->createEntity('PL');
        $a = $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));
        $b = $this->service()->add($e, $this->values(self::PL_IBAN, 'PLN'));
        $c = $this->service()->add($e, $this->values(self::CZ_IBAN, 'CZK'));

        $this->service()->archive($e, $a);

        $fa = $this->service()->find($e, $a);
        $this->assertFalse($fa['is_default']);
        $this->assertNotNull($fa['archived_at']);
        $this->assertTrue($this->service()->find($e, $b)['is_default']);
        $this->assertFalse($this->service()->find($e, $c)['is_default']);
        $this->assertSame(1, $this->defaultCount($e));
        $this->assertSame(1, $this->auditCount('archive'));
        $this->assertCount(2, $this->service()->listFor($e, false));
        $this->assertCount(1, $this->service()->listFor($e, true));
    }

    public function testForCurrencyMatchingAccount(): void
    {
        $e = $this->createEntity('PL');
        $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));
        $b = $this->service()->add($e, $this->values(self::PL_IBAN, 'PLN'));

        $r = $this->selector()->forCurrency($e, 'PLN');

        $this->assertSame($b, (int) $r['id']);
        $this->assertTrue($r['currency_match']);
    }

    public function testForCurrencyFallsBackToDefault(): void
    {
        $e = $this->createEntity('PL');
        $a = $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));
        $this->service()->add($e, $this->values(self::PL_IBAN, 'PLN'));

        $r = $this->selector()->forCurrency($e, 'CZK');

        $this->assertSame($a, (int) $r['id']);
        $this->assertFalse($r['currency_match']);
    }

    public function testForCurrencyWithoutAccountsThrows(): void
    {
        $e = $this->createEntity('SK');

        $this->expectException(NoBankAccount::class);
        $this->selector()->forCurrency($e, 'EUR');
    }

    public function testForCurrencyIgnoresArchivedAccount(): void
    {
        $e = $this->createEntity('PL');
        $a = $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));
        $b = $this->service()->add($e, $this->values(self::PL_IBAN, 'PLN'));
        $this->service()->archive($e, $b);

        $r = $this->selector()->forCurrency($e, 'PLN');

        $this->assertSame($a, (int) $r['id']);
        $this->assertFalse($r['currency_match']);
    }

    public function testUpdateAuditsIbanChange(): void
    {
        $e = $this->createEntity('SK');
        $a = $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));

        $this->service()->update($e, $a, ['label' => 'Ucet', 'iban' => self::CZ_IBAN, 'swift' => null, 'is_default' => true]);

        $this->assertSame(self::CZ_IBAN, $this->service()->find($e, $a)['iban']);
        $row = $this->owner()->query("SELECT before_data, after_data FROM audit_log WHERE entity_type = 'bank_account' AND action = 'update'")->getRow();
        $this->assertNotNull($row);
        $this->assertStringContainsString(self::SK_IBAN, (string) $row->before_data);
        $this->assertStringContainsString(self::CZ_IBAN, (string) $row->after_data);
    }

    public function testOtherEntityCannotTouchAccount(): void
    {
        $e     = $this->createEntity('SK');
        $other = $this->createEntity('PL');
        $a     = $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));

        $this->assertNull($this->service()->find($other, $a));

        foreach ([
            fn () => $this->service()->update($other, $a, ['label' => 'x', 'iban' => self::CZ_IBAN, 'swift' => null, 'is_default' => false]),
            fn () => $this->service()->setDefault($other, $a),
            fn () => $this->service()->archive($other, $a),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $fa = $this->service()->find($e, $a);
        $this->assertSame(self::SK_IBAN, $fa['iban']);
        $this->assertNull($fa['archived_at']);
    }

    public function testCurrencyUpdateAndDeleteAreDenied(): void
    {
        $e = $this->createEntity('SK');
        $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));

        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE entity_bank_accounts SET currency = 'PLN'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM entity_bank_accounts'));
    }

    public function testSecondActiveDefaultViolatesUniqueIndex(): void
    {
        $e = $this->createEntity('PL');
        $this->service()->add($e, $this->values(self::SK_IBAN, 'EUR'));
        $b = $this->service()->add($e, $this->values(self::PL_IBAN, 'PLN'));

        $this->expectException(DatabaseException::class);

        try {
            $this->rw()->query('UPDATE entity_bank_accounts SET is_default = true WHERE id = ?', [$b]);
        } finally {
            $this->rw()->resetTransStatus();
        }
    }
}
