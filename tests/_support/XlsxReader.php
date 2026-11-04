<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Minimalne citanie XLSX v testoch (ZipArchive + SimpleXML), bez dalsej kniznice.
 */
final class XlsxReader
{
    /**
     * @return list<list<array{type: string, value: string, style: ?string}>>
     */
    public static function cells(string $bytes): array
    {
        $xml  = new SimpleXMLElement(self::part($bytes, 'xl/worksheets/sheet1.xml'));
        $rows = [];

        foreach ($xml->sheetData->row as $row) {
            $r = [];

            foreach ($row->c as $c) {
                $type = (string) ($c['t'] ?? 'n');
                $val  = $type === 'inlineStr' ? (string) $c->is->t : (string) $c->v;
                $s    = isset($c['s']) ? (string) $c['s'] : null;
                $r[]  = ['type' => $type, 'value' => $val, 'style' => $s];
            }
            $rows[] = $r;
        }

        return $rows;
    }

    public static function sheetXml(string $bytes): string
    {
        return self::part($bytes, 'xl/worksheets/sheet1.xml');
    }

    public static function stylesXml(string $bytes): string
    {
        return self::part($bytes, 'xl/styles.xml');
    }

    /**
     * @return list<string>
     */
    public static function numFmts(string $bytes): array
    {
        $xml = new SimpleXMLElement(self::stylesXml($bytes));
        $out = [];

        foreach ($xml->numFmts->numFmt ?? [] as $f) {
            $out[] = (string) $f['formatCode'];
        }

        return $out;
    }

    private static function part(string $bytes, string $name): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xr');

        if ($tmp === false) {
            throw new RuntimeException('tempnam failed');
        }

        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive();

            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('Not a zip');
            }
            $content = $zip->getFromName($name);
            $zip->close();

            if ($content === false) {
                throw new RuntimeException('Missing part ' . $name);
            }

            return $content;
        } finally {
            unlink($tmp);
        }
    }
}
