<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\Migrations\AddLegalTextsRevision;
use Config\Database;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\PgTestCase;

/**
 * WR-05: rollback migracie AddLegalTextsRevision nesmie zmazat znenia dlhsie ako 1000 znakov.
 *
 * @internal
 */
#[Group('integration')]
final class LegalTextsRevisionRollbackTest extends PgTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // nazov suboru migracie nie je PSR-4, CI4 ju nacitava cez MigrationRunner
        require_once APPPATH . 'Database/Migrations/2026-10-28-000002_AddLegalTextsRevision.php';
    }

    public function testDownRefusesWhenTextLongerThanOldLimitAndChangesNothing(): void
    {
        $row = $this->owner()->query("SELECT id, body FROM legal_texts WHERE country = 'SK' AND language = 'sk' AND key = 'vat_note'")->getRowArray();
        $this->assertNotNull($row);
        $long = str_repeat('a', 1500);
        $this->owner()->query('UPDATE legal_texts SET body = :b: WHERE id = :id:', ['b' => $long, 'id' => (int) $row['id']]);

        try {
            (new AddLegalTextsRevision(Database::forge($this->owner())))->down();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exceed 1000 characters', $e->getMessage());
        } finally {
            $after = $this->owner()->query('SELECT body, revision FROM legal_texts WHERE id = :id:', ['id' => (int) $row['id']])->getRowArray();
            $this->owner()->query('UPDATE legal_texts SET body = :b: WHERE id = :id:', ['b' => (string) $row['body'], 'id' => (int) $row['id']]);
        }

        $this->assertNotNull($after);
        $this->assertSame($long, $after['body']);
        $this->assertArrayHasKey('revision', $after);
    }
}
