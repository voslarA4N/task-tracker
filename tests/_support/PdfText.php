<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Text z PDF cez pdftotext (normalizovane medzery vratane nezalomitelnych).
 */
final class PdfText
{
    public static function extract(string $pdfBytes): ?string
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            return null;
        }

        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, $pdfBytes);
        $out = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' -');
        unlink($file);

        return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $out);
    }
}
