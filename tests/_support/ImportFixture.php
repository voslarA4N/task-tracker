<?php

declare(strict_types=1);

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Anonymizovany XLSX rovnakej struktury ako zdrojovy subor (iba dev, nikdy realne data klienta).
 */
final class ImportFixture
{
    private const URL = 'https://taskman.example/?section=management&project=1&todo=';

    public static function path(): string
    {
        return WRITEPATH . 'tests/import-fixture.xlsx';
    }

    public static function write(string $path): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create fixture directory.');
        }

        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();

        // September 2026: hlavicka 1-3, ulohy 4-8, sucet 9
        self::header($sheet, 1, 'September 2026');

        $sep = [
            // [url mode, title, hours, done, billed]
            [4, 'link', 'Úloha A', 8, 'ÁNO', 'NIE'],
            [5, 'link', 'Úloha B', '8,5', 'ANO', 'NIE'],
            [6, 'link', 'Úloha C', "1\u{00A0}000", "áno\u{00A0}", 'NIE'],
            [7, 'text', 'Úloha D', 2, "A\u{0301}NO", 'NIE'],
            [8, 'js', 'Úloha E', '1.5', 'NIE', 'NIE'],
        ];

        foreach ($sep as [$r, $mode, $title, $hours, $done, $billed]) {
            self::task($sheet, $r, $mode, $title, $hours, $done, $billed, $r - 3);
        }

        self::sumRow($sheet, 9, 'C4:C8', 'D4:D8', 'E4:E8', 'F4:F8');

        // Oktober 2026: hlavicka 11-13, ulohy 14-18, sucet 19 (F so zamerne neuplnym rozsahom)
        self::header($sheet, 11, 'Oktober 2026');

        $oct = [
            [14, 'link', 'Úloha F', 4, 'NIE'],
            [15, 'text', 'Úloha G', 2, 'NIE'],
            [16, 'text', 'Úloha H', 1, 'ÁNO'],
            [17, 'text', 'Úloha I', '1.5', 'ÁNO'],
            [18, 'text', 'Úloha J', 3, 'ÁNO'],
        ];

        foreach ($oct as [$r, $mode, $title, $hours, $done]) {
            self::task($sheet, $r, $mode, $title, $hours, $done, 'NIE', $r);
        }

        // zla rucne zapisana suma (nie vzorec), 2 h x 27 = 54, nie 100
        $sheet->setCellValue('D15', 100);
        self::sumRow($sheet, 19, 'C14:C18', 'D14:D18', 'E14:E18', 'F14:F16');

        $sheet->setCellValue('I40', 'Rate na hodinu:');
        $sheet->setCellValue('J40', 27);

        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(true);
        $writer->save($path);
        $book->disconnectWorksheets();
    }

    private static function header(Worksheet $sheet, int $row, string $month): void
    {
        $sheet->setCellValue('A' . $row, 'Ulohy na fakturovanie:');
        $sheet->setCellValue('A' . ($row + 1), $month);
        $sheet->setCellValue('A' . ($row + 2), 'URL (Taskman)');
        $sheet->setCellValue('B' . ($row + 2), 'Nazov');
    }

    private static function task(
        Worksheet $sheet,
        int $r,
        string $mode,
        string $title,
        int|string $hours,
        string $done,
        string $billed,
        int $todo,
    ): void {
        if ($mode === 'link') {
            $url = self::URL . $todo;
            $sheet->setCellValue('A' . $r, 'Taskman ' . $todo);
            $sheet->getCell('A' . $r)->getHyperlink()->setUrl($url);
        } elseif ($mode === 'js') {
            $sheet->setCellValue('A' . $r, 'javascript:alert(1)');
        } else {
            $sheet->setCellValue('A' . $r, 'https://taskman.example/?section=mana');
        }

        $sheet->setCellValue('B' . $r, $title);
        $sheet->setCellValueExplicit('C' . $r, $hours, is_int($hours) || is_numeric($hours) ? 'n' : 's');
        $sheet->setCellValue('G' . $r, $done);
        $sheet->setCellValue('H' . $r, $billed);

        if (! is_int($hours) && ! is_numeric($hours)) {
            return; // text hours cannot be multiplied, leave amounts empty
        }

        $sheet->setCellValue('D' . $r, '=C' . $r . '*$J$40');

        if (str_contains(mb_strtoupper($done), 'NIE')) {
            return;
        }

        $sheet->setCellValue('E' . $r, '=C' . $r);
        $sheet->setCellValue('F' . $r, '=E' . $r . '*$J$40');
    }

    private static function sumRow(Worksheet $sheet, int $r, string $c, string $d, string $e, string $f): void
    {
        $sheet->setCellValue('A' . $r, 'Sumar za mesiac:');
        $sheet->setCellValue('C' . $r, '=SUM(' . $c . ')');
        $sheet->setCellValue('D' . $r, '=SUM(' . $d . ')');
        $sheet->setCellValue('E' . $r, '=SUM(' . $e . ')');
        $sheet->setCellValue('F' . $r, '=SUM(' . $f . ')');
    }
}
