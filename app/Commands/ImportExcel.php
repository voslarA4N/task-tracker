<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Master\ClientService;
use App\Libraries\Work\Import\ExcelReader;
use App\Libraries\Work\Import\ImportPlanner;
use App\Libraries\Work\Import\ImportReportFormatter;
use App\Libraries\Work\Import\ImportRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DomainException;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Jednorazovy import uloh zo zdrojoveho Excelu (D-16, len dev). Predvolene suchy beh, zapis az s --commit.
 *
 * @internal
 */
final class ImportExcel extends BaseCommand
{
    protected $group       = 'Import';
    protected $name        = 'import:excel';
    protected $description = 'Import úloh z Excelu (predvolene suchý beh, zápis s --commit).';
    protected $usage       = 'import:excel <path> --client <id> [--commit]';
    protected $arguments   = ['path' => 'Cesta k XLSX súboru'];
    protected $options     = [
        '--client' => 'ID klienta (povinné), hodnota za medzerou',
        '--commit' => 'Skutočný zápis do databázy (zadať až za cestu a --client)',
    ];

    public function run(array $params): int
    {
        // Guard: v produkcii (bez require-dev) nesmie nic padnut ani pri spark list.
        if (! class_exists(IOFactory::class)) {
            CLI::error('PhpSpreadsheet nie je nainštalovaný. Import beží len vo vývojovom prostredí.');

            return EXIT_ERROR;
        }

        $path     = $params[0] ?? null;
        $clientId = $params['client'] ?? CLI::getOption('client');
        $commit   = array_key_exists('commit', $params) || CLI::getOption('commit') !== null;

        if (! is_string($path) || $path === '' || ! is_string($clientId) || preg_match('/^\d{1,18}$/D', $clientId) !== 1) {
            CLI::error('Použitie: ' . $this->usage);

            return EXIT_ERROR;
        }

        if (! is_file($path)) {
            CLI::error('Súbor neexistuje: ' . $path);

            return EXIT_ERROR;
        }

        try {
            $client = (new ClientService())->find((int) $clientId);

            if ($client === null) {
                CLI::error('Klient ' . $clientId . ' neexistuje.');

                return EXIT_ERROR;
            }

            $sheet = (new ExcelReader())->read($path);
            $plan  = (new ImportPlanner())->plan($sheet, (string) $client['hourly_rate'], (string) $client['currency']);

            foreach ((new ImportReportFormatter())->lines($plan, (string) $client['currency'], 'sk') as $line) {
                CLI::write($line);
            }

            if (! $commit) {
                CLI::write('Suchý beh, nič sa nezapísalo. Spustite znova s --commit.', 'yellow');

                return EXIT_SUCCESS;
            }

            $result = (new ImportRunner())->run($plan, (int) $clientId);
        } catch (DomainException|InvalidArgumentException|RuntimeException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Vytvorené: ' . $result->created . ', preskočené (už importované): ' . $result->skipped . ', časti: ' . $result->parts, 'green');

        $warning = (new ImportReportFormatter())->billedWarning($plan, (string) $client['currency'], 'sk');

        if ($warning !== null) {
            CLI::write($warning, 'yellow');
        }

        return EXIT_SUCCESS;
    }
}
