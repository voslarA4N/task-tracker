<?php

/**
 * Spike mPDF 8.3.1 pod PHP 8.5 s E_ALL (Phase 1, plan 01-06).
 *
 * CLI:  php scripts/spikes/mpdf.php [--cold]
 * FPM:  ?cold=1 (spúšťané cez scripts/spikes/mpdf-fpm.sh, nie je dostupné cez nginx)
 *
 * Výstup: JSON s časom, vrcholom pamäte a deprecations.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$root = dirname(__DIR__, 2);
$isFpm = PHP_SAPI === 'fpm-fcgi';

/** @var array<string, string> $issues */
$issues = [];
/** @var array<string, string> $deprecations */
$deprecations = [];

set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$issues, &$deprecations, $root): bool {
    $where = str_replace($root . '/', '', $errfile) . ':' . $errline;
    $entry = $errstr . ' @ ' . $where;
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        $deprecations[$entry] = $entry;
        $issues[$entry]       = $entry;
    } elseif ($errno === E_NOTICE || $errno === E_USER_NOTICE || $errno === E_WARNING || $errno === E_USER_WARNING) {
        $issues[$entry] = $entry;
    }

    return true; // neprerušuj, nezobrazuj
});

function spikeOut(array $data, bool $isFpm, int $code): never
{
    if ($isFpm) {
        header('Content-Type: application/json');
    }
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

    exit($code);
}

$cold    = in_array('--cold', $argv ?? [], true) || ($isFpm && ($_GET['cold'] ?? '') === '1');
$tempDir = $root . '/writable/mpdf/spike';
$pdfPath = $root . '/writable/mpdf/spike-output.pdf';

try {
    require $root . '/vendor/autoload.php';

    if ($cold && is_dir($tempDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
    }

    if (! is_dir($tempDir) && ! mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
        spikeOut(['error' => 'tempDir not writable: ' . $tempDir], $isFpm, 1);
    }

    if (! is_writable($tempDir)) {
        spikeOut(['error' => 'tempDir not writable: ' . $tempDir], $isFpm, 1);
    }

    if (file_exists($pdfPath) ? ! is_writable($pdfPath) : ! is_writable(dirname($pdfPath))) {
        spikeOut(['error' => 'tempDir not writable: ' . $pdfPath], $isFpm, 1);
    }

    $rows = '';

    for ($i = 1; $i <= 40; $i++) {
        $rows .= '<tr><td>' . $i . '</td><td>Úloha č. ' . $i . ' – łńěťžô ČŠŽÝÁÍÉ</td>'
            . '<td class="r">1,50</td><td class="r">45,00</td><td class="r">67,50</td></tr>';
    }

    $html = <<<HTML
        <style>
            body { font-family: dejavusans; font-size: 9pt; }
            h1 { font-size: 16pt; }
            table.items { width: 100%; border-collapse: collapse; }
            table.items th, table.items td { border-bottom: 0.2mm solid #999; padding: 1mm; }
            .r { text-align: right; }
        </style>
        <h1>Číslo faktúry 202610001</h1>
        <table width="100%"><tr>
            <td width="50%"><b>Dodávateľ</b><br>Tomáš Voslař – Žilina</td>
            <td width="50%"><b>Odberateľ</b><br>Łódź Spółka z o.o.</td>
        </tr></table>
        <br>
        <table class="items">
            <thead><tr><th>#</th><th>Popis</th><th class="r">Hodiny</th><th class="r">Sadzba</th><th class="r">Suma</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
        <p class="r"><b>Spolu: 2 700,00 €</b></p>
        <p>Nie som platiteľ DPH.</p>
        HTML;

    $start = hrtime(true);

    $mpdf = new \Mpdf\Mpdf([
        'mode'              => 'utf-8',
        'format'            => 'A4',
        'tempDir'           => $tempDir,
        'fontDir'           => [$root . '/resources/fonts'],
        'fontdata'          => ['dejavusans' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf']],
        'default_font'      => 'dejavusans',
        'useSubstitutions' => false,
        'autoScriptToLang' => false,
        'autoLangToFont' => false,
    ]);
    $mpdf->WriteHTML($html);
    $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);

    $elapsedMs = intdiv((int) (hrtime(true) - $start), 1_000_000);

    spikeOut([
        'php_version'       => PHP_VERSION,
        'sapi'              => PHP_SAPI,
        'mpdf_version'      => \Composer\InstalledVersions::getPrettyVersion('mpdf/mpdf'),
        'mode'              => $cold ? 'cold' : 'warm',
        'elapsed_ms'        => $elapsedMs,
        'peak_memory_mb'    => intdiv(memory_get_peak_usage(true), 1048576),
        'memory_limit'      => ini_get('memory_limit'),
        'pdf_bytes'         => (int) filesize($pdfPath),
        'pdf_path'          => $pdfPath,
        'deprecations_count' => count($deprecations),
        'deprecations'      => array_values($deprecations),
        'issues_count'      => count($issues),
    ], $isFpm, 0);
} catch (Throwable $e) {
    spikeOut(['error' => $e::class . ': ' . $e->getMessage()], $isFpm, 1);
}
