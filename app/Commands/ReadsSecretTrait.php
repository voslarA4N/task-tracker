<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\CLI;

/**
 * Skryte zadanie tajomstva v CLI: heslo sa nikdy neberie z argumentov ani sa nevypisuje.
 */
trait ReadsSecretTrait
{
    private function readSecret(string $prompt): string
    {
        CLI::write($prompt, null, null);

        if (! stream_isatty(STDIN)) {
            return rtrim((string) fgets(STDIN), "\r\n");
        }

        // Bez echa: heslo sa nesmie zobrazit v terminali.
        exec('stty -echo');

        try {
            return rtrim((string) fgets(STDIN), "\r\n");
        } finally {
            exec('stty echo');
        }
    }
}
