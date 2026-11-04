<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use RuntimeException;

/**
 * CSV pre Excel v SK/PL/CZ lokalite: UTF-8 BOM, oddelovac ';', desatinna ciarka bez oddelovaca tisicov (D-06).
 */
final class CsvWriter
{
    public static function render(ExportTable $t): string
    {
        $h = fopen('php://temp', 'w+b');

        if ($h === false) {
            throw new RuntimeException('Cannot open temp stream.');
        }

        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, array_map(static fn (string $c): string => FormulaGuard::text($c), $t->headers), ';', '"', '');

        foreach ($t->rows as $row) {
            $out = [];

            foreach ($row as $cell) {
                $out[] = $cell->kind === 'text' ? FormulaGuard::text($cell->value) : str_replace('.', ',', $cell->value);
            }

            fputcsv($h, $out, ';', '"', '');
        }

        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);

        return $csv;
    }
}
