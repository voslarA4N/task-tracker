<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\StagingSeed\Profile;
use App\Libraries\StagingSeed\SeedRefused;
use App\Libraries\StagingSeed\StagingSeeder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use InvalidArgumentException;
use Throwable;

/**
 * Staging seed (D-01 az D-05): vymyslena historia od 2026-01-01 do dnes cez aplikacne sluzby.
 * Bezi ako app_rw (default skupina), odmietne bezat bez `staging.seed = true`, na produkcnom hoste a na neprazdnej DB.
 */
final class StagingSeed extends BaseCommand
{
    private const REFUSED = [
        SeedRefused::FLAG_OFF        => 'staging.seed nie je true (povolene len v app.env stagingu)',
        SeedRefused::PRODUCTION_HOST => 'baseURL je produkcny hostitel',
        SeedRefused::NOT_PRISTINE    => 'databaza nie je prazdna',
        SeedRefused::CREDENTIALS     => 'chybaju alebo su neplatne udaje seed admina (SEED_ADMIN_EMAIL, SEED_ADMIN_PASSWORD, SEED_ADMIN_TOTP_SECRET)',
    ];

    protected $group       = 'Staging';
    protected $name        = 'staging:seed';
    protected $description = 'Naplni prazdnu stagingovu DB vymyslenou historiou (subjekty, klienti, ulohy, faktury).';
    protected $usage       = 'staging:seed [--profile=full|small]';
    protected $options     = ['--profile' => 'full (predvolene, cca 150 uloh a 40 faktur) alebo small (rychly test)'];

    public function run(array $params): int
    {
        $name = CLI::getOption('profile');

        try {
            $profile = Profile::byName(is_string($name) ? $name : 'full');
            $result  = (new StagingSeeder(Database::connect(), null, static function (string $line): void {
                CLI::write($line);
            }))->run($profile);
        } catch (SeedRefused $e) {
            CLI::error('Seed odmietnuty: ' . (self::REFUSED[$e->reason] ?? $e->reason) . ' [' . $e->reason . ']');

            return EXIT_ERROR;
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            // sprava databazovej chyby moze niest hodnoty dotazu, preto sa vypise iba trieda
            CLI::error('Seed zlyhal: ' . $e::class . ($e instanceof DatabaseException ? '' : ': ' . $e->getMessage()));

            return EXIT_ERROR;
        }

        foreach ($result['modules'] as $module => $ms) {
            CLI::write(sprintf('  %-10s %d.%03d s', $module, intdiv($ms, 1000), $ms % 1000));
        }

        foreach ($result['counts'] as $table => $count) {
            CLI::write(sprintf('  %-20s %d', $table, $count));
        }

        $numbers = $result['notes']['invoice_numbers'] ?? [];

        if (is_array($numbers) && $numbers !== []) {
            CLI::write('  prve cislo faktury: ' . $numbers[0] . ', posledne: ' . $numbers[count($numbers) - 1]);
        }

        CLI::write(sprintf('Seed hotovy za %d.%03d s', intdiv($result['millis'], 1000), $result['millis'] % 1000), 'green');

        return EXIT_SUCCESS;
    }
}
