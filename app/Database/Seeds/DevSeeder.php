<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use Config\Database;
use RuntimeException;

/**
 * Vyvojove seed data (D-09). V produkcii odmietne bezat.
 */
class DevSeeder extends Seeder
{
    public static function assertAllowed(string $environment): void
    {
        if ($environment === 'production') {
            throw new RuntimeException('DevSeeder must never run in production');
        }
    }

    public function run(): void
    {
        self::assertAllowed(ENVIRONMENT);

        $this->db = Database::connect('owner');

        CLI::write('DevSeeder: no seed data in phase 1');
    }
}
