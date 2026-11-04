<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * XLSX export (D-06, D-07): iba explicitne StringCell/NumericCell, nikdy Cell::fromValue (z "=..." robi vzorec).
 */
final class XlsxWriter
{
    public static function render(ExportTable $t): string
    {
        $dir = WRITEPATH . 'exports';

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $tmp = tempnam($dir, 'xlsx');

        if ($tmp === false) {
            throw new RuntimeException('Cannot create temporary export file.');
        }

        try {
            $options = new Options();

            foreach ($t->widths as $i => $width) {
                $options->setColumnWidth($width, $i + 1);
            }

            $writer = new Writer($options);
            $writer->openToFile($tmp);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName($t->sheetName);
            $sheet->setSheetView((new SheetView())->withFreezeRow(2));

            $bold = (new Style())->withFontBold(true);
            $writer->addRow(new Row(array_map(static fn (string $h) => new StringCell($h, $bold), $t->headers)));

            $money   = (new Style())->withFormat('#,##0.00');
            $decimal = (new Style())->withFormat('0.0000####');

            foreach ($t->rows as $row) {
                $cells = [];

                foreach ($row as $c) {
                    $cells[] = match ($c->kind) {
                        'money'   => new NumericCell(XlsxNumber::fromCell($c), $money),
                        'decimal' => new NumericCell(XlsxNumber::fromCell($c), $decimal),
                        default   => new StringCell($c->value, null),
                    };
                }
                $writer->addRow(new Row($cells));
            }

            $writer->close();

            $bytes = file_get_contents($tmp);

            if ($bytes === false) {
                throw new RuntimeException('Cannot read temporary export file.');
            }

            return $bytes;
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
