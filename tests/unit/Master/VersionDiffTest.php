<?php

declare(strict_types=1);

namespace Tests\Unit\Master;

use App\Libraries\Master\VersionDiff;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class VersionDiffTest extends CIUnitTestCase
{
    /**
     * @param array<string, string|null> $over
     *
     * @return array<string, string|null>
     */
    private function version(array $over = []): array
    {
        return $over + [
            'trade_name' => 'Jan', 'address_line1' => 'Hlavna 1', 'address_line2' => null, 'postal_code' => '81101',
            'city' => 'Bratislava', 'company_id' => '12345678', 'regon' => null, 'tax_id' => null, 'vat_id' => null,
            'registry_note' => null, 'email' => 'a@example.test', 'phone' => null, 'doc_language' => 'sk',
            'vat_regime' => 'sk_non_vat', 'due_days' => '14',
        ];
    }

    public function testNullOlderGivesNoChange(): void
    {
        $this->assertSame([], VersionDiff::changedFields(null, $this->version()));
    }

    public function testChangedFieldsInVersionFieldsOrder(): void
    {
        $new = $this->version(['email' => 'b@example.test', 'address_line1' => 'Nova 2']);

        $this->assertSame(['address_line1', 'email'], VersionDiff::changedFields($this->version(), $new));
    }

    public function testNullAndEmptyStringAreEqual(): void
    {
        $this->assertSame([], VersionDiff::changedFields($this->version(), $this->version(['phone' => ''])));
    }

    public function testKeysOutsideVersionFieldsAreIgnored(): void
    {
        $old = $this->version(['id' => '1', 'valid_from' => '2026-01-01']);
        $new = $this->version(['id' => '2', 'valid_from' => '2026-11-01']);

        $this->assertSame([], VersionDiff::changedFields($old, $new));
    }
}
