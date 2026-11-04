<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Export\YearArchive;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Rocny archiv dokladov (NFR-09, D-10): ZIP vsetkych ulozenych PDF roka + CSV index so sha256.
 *
 * @internal
 */
final class ArchiveYear extends BaseCommand
{
    protected $group       = 'Export';
    protected $name        = 'archive:year';
    protected $description = 'Vytvorí ZIP archív všetkých vystavených dokladov roka s CSV indexom a sha256.';
    protected $usage       = 'archive:year [rok]';
    protected $arguments   = ['rok' => 'Rok archívu (predvolene predchádzajúci rok)'];

    public function run(array $params): int
    {
        $current = (int) service('clock')->now()->format('Y');
        $arg     = $params[0] ?? null;

        if ($arg === null) {
            $year = $current - 1;
        } elseif (preg_match('/^\d{4}$/D', $arg) === 1 && (int) $arg >= 2000 && (int) $arg <= $current) {
            $year = (int) $arg;
        } else {
            CLI::error('Neplatný rok. Použitie: ' . $this->usage);

            return EXIT_ERROR;
        }

        try {
            $r = (new YearArchive())->build($year);
        } catch (Throwable $e) {
            CLI::error('Archív sa nevytvoril: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $size = $r->bytes >= 1048576 ? intdiv($r->bytes, 1048576) . ' MB' : intdiv($r->bytes, 1024) . ' kB';

        CLI::write("Archív roka {$year}: {$r->documents} dokladov, {$r->pdfs} PDF, {$size}");
        CLI::write('PATH=' . $r->path);

        return EXIT_SUCCESS;
    }
}
