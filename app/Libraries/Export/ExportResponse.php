<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Odpoved so suborom na stiahnutie (vzor Invoices::serve). Nazov suboru musi prejst cez ExportFilename.
 */
final class ExportResponse
{
    public static function attachment(ResponseInterface $r, string $bytes, string $contentType, string $filename): ResponseInterface
    {
        return $r
            ->setStatusCode(200)
            ->setHeader('Content-Type', $contentType)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Length', (string) strlen($bytes))
            ->setBody($bytes);
    }
}
