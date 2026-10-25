<?php

declare(strict_types=1);

namespace App\Libraries\Work\Import;

use App\Libraries\Platform\Hours;
use App\Libraries\Platform\Money;
use InvalidArgumentException;
use Normalizer;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Jedina trieda importu, ktora smie pouzit PhpSpreadsheet (iba require-dev). Cita zdrojovy XLSX
 * vratane skutocnych bunkovych hyperlinkov (bez setReadDataOnly) a normalizuje hodnoty.
 */
final class ExcelReader
{
    private const MONTHS = [
        'januar'    => '01', 'februar' => '02', 'marec' => '03', 'april' => '04',
        'maj'       => '05', 'jun' => '06', 'jul' => '07', 'august' => '08',
        'september' => '09', 'oktober' => '10', 'november' => '11', 'december' => '12',
    ];

    public function read(string $path): ImportSheet
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException('Subor neexistuje: ' . $path);
        }

        if (! class_exists(IOFactory::class)) {
            throw new RuntimeException('PhpSpreadsheet nie je nainštalovaný (iba dev)');
        }

        $reader = IOFactory::createReader('Xlsx');
        $book   = $reader->load($path);
        $sheet  = $book->getSheet(0);

        $result = new ImportSheet($this->findRate($sheet), $this->readBlocks($sheet));
        $book->disconnectWorksheets();

        return $result;
    }

    private function findRate(Worksheet $sheet): ?string
    {
        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();

        foreach ($sheet->rangeToArray('A1:' . $highestCol . $highestRow, null, false, false, true) as $rowNo => $cells) {
            foreach (array_keys($cells) as $col) {
                $text = $sheet->getCell($col . $rowNo)->getValue();

                if (is_string($text) && str_contains($this->fold($text), 'rate na hodinu')) {
                    $next = $sheet->getCell($this->nextColumn((string) $col) . $rowNo);

                    return $this->amount($this->numeric($next));
                }
            }
        }

        return null;
    }

    /**
     * @return list<ImportBlock>
     */
    private function readBlocks(Worksheet $sheet): array
    {
        $blocks  = [];
        $highest = $sheet->getHighestRow();
        $r       = 1;

        while ($r <= $highest) {
            if (! str_starts_with($this->fold($this->text($sheet, 'A' . $r)), 'ulohy na fakturovanie')) {
                $r++;

                continue;
            }

            $month = $this->month($this->text($sheet, 'A' . ($r + 1)));
            $r += 2;

            // hlavicka (URL (Taskman)) a prazdne riadky
            while ($r <= $highest && str_contains($this->fold($this->text($sheet, 'A' . $r)), 'url (taskman)')) {
                $r++;
            }

            $first = $r;
            $rows  = [];

            while ($r <= $highest && ! str_starts_with($this->fold($this->text($sheet, 'A' . $r)), 'sumar za mesiac')) {
                $title = $this->title($this->text($sheet, 'B' . $r));

                if ($title !== '') {
                    $rows[] = $this->readRow($sheet, $r, $month, $title);
                }

                $r++;
            }

            $sums = [];

            if ($r <= $highest) {
                foreach (['C', 'D', 'E', 'F'] as $col) {
                    $cell       = $sheet->getCell($col . $r);
                    $raw        = $cell->getValue();
                    $isFormula  = is_string($raw) && str_starts_with($raw, '=');
                    $sums[$col] = [
                        'value'   => $this->amountOrHours($col, $this->numeric($cell)),
                        'formula' => $isFormula ? $raw : null,
                    ];
                }
            }

            $blocks[] = new ImportBlock($month, $first, max($first, $r - 1), $rows, $sums);
            $r++;
        }

        return $blocks;
    }

    private function readRow(Worksheet $sheet, int $r, string $month, string $title): ImportRow
    {
        $a                = $sheet->getCell('A' . $r);
        [$url, $specific] = $this->url($a);

        $hoursRaw = $this->numeric($sheet->getCell('C' . $r));

        try {
            $hours = Hours::of($hoursRaw ?? '0');
        } catch (InvalidArgumentException) {
            throw new RuntimeException('Neplatné hodiny v riadku ' . $r . '.');
        }

        return new ImportRow(
            $r,
            $month,
            $url,
            $specific,
            $title,
            $hours,
            $this->amount($this->numeric($sheet->getCell('D' . $r))),
            $this->yes($this->text($sheet, 'G' . $r)),
            $this->yes($this->text($sheet, 'H' . $r)),
        );
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function url(Cell $cell): array
    {
        $hasLink = $cell->hasHyperlink();
        $raw     = $hasLink ? $cell->getHyperlink()->getUrl() : (string) $cell->getValue();
        $raw     = trim($raw);

        if ($raw === '' || strlen($raw) > 2000) {
            return [null, $hasLink];
        }

        $parts = parse_url($raw);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === ''
                             || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return [null, $hasLink];
        }

        return [$raw, $hasLink];
    }

    private function text(Worksheet $sheet, string $coordinate): string
    {
        $cell = $sheet->getCell($coordinate);
        $v    = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * Cislo z bunky ako retazec bez float aritmetiky (vzorec: ulozena vypocitana hodnota).
     */
    private function numeric(Cell $cell): ?string
    {
        $v = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

        if ($v === null || $v === '') {
            return null;
        }

        if (is_int($v)) {
            return (string) $v;
        }

        if (is_float($v)) {
            return var_export($v, true);
        }

        if (is_string($v)) {
            $clean = preg_replace('/[\s\x{00A0}]+/u', '', $v) ?? '';
            $clean = str_replace(',', '.', $clean);

            return $clean === '' ? null : $clean;
        }

        return null;
    }

    private function amount(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/^-?\d{1,10}(\.\d{1,2})?$/D', $value) === 1 ? Money::of($value)->toDecimalString() : null;
    }

    private function amountOrHours(string $col, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (in_array($col, ['C', 'E'], true)) {
            return preg_match('/^\d{1,5}(\.\d{1,2})?$/D', $value) === 1 ? Hours::of($value) : null;
        }

        return $this->amount($value);
    }

    private function yes(string $raw): bool
    {
        $n = Normalizer::normalize($raw, Normalizer::FORM_C);
        $n = preg_replace('/[\s\x{00A0}]+/u', '', $n === false ? $raw : $n) ?? '';

        return in_array(mb_strtoupper($n), ['ÁNO', 'ANO'], true);
    }

    private function title(string $raw): string
    {
        $n = Normalizer::normalize($raw, Normalizer::FORM_C);

        return trim($n === false ? $raw : $n);
    }

    private function month(string $raw): string
    {
        $parts = preg_split('/[\s\x{00A0}]+/u', trim($raw)) ?: [];

        if (count($parts) !== 2 || preg_match('/^\d{4}$/D', $parts[1]) !== 1) {
            throw new RuntimeException('Neplatný mesiac: ' . $raw);
        }

        $key = $this->fold($parts[0]);

        if (! isset(self::MONTHS[$key])) {
            throw new RuntimeException('Neznámy mesiac: ' . $raw);
        }

        return $parts[1] . '-' . self::MONTHS[$key];
    }

    /**
     * Bez diakritiky, male pismena, zlucene medzery.
     */
    private function fold(string $raw): string
    {
        $d = Normalizer::normalize($raw, Normalizer::FORM_D);
        $d = preg_replace('/\p{Mn}+/u', '', $d === false ? $raw : $d) ?? '';
        $d = preg_replace('/[\s\x{00A0}]+/u', ' ', $d) ?? '';

        return mb_strtolower(trim($d));
    }

    private function nextColumn(string $col): string
    {
        $i = 0;

        foreach (str_split($col) as $ch) {
            $i = $i * 26 + (ord($ch) - 64);
        }

        $i++;
        $out = '';

        while ($i > 0) {
            $m   = ($i - 1) % 26;
            $out = chr(65 + $m) . $out;
            $i   = intdiv($i - 1, 26);
        }

        return $out;
    }
}
