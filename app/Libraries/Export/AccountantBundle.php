<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Reporting\BundleSelector;
use App\Libraries\Reporting\ReportPeriod;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * ZIP pre uctovnicku (D-09 az D-12): ulozene PDF faktur a dobropisov (nikdy znovu generovane) a zoznam-dokladov.csv.
 * D-07: cistenie docasneho suboru je bezpecne voci vynimkam na kazdej ceste (aj pri zlyhanom close()).
 */
final class AccountantBundle
{
    public const MAX_BYTES = 67108864;

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly Closure $zipFactory;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     * @param (Closure(): ZipArchive)|null      $zipFactory test seam, production uses a plain ZipArchive
     */
    public function __construct(?BaseConnection $db = null, ?Closure $zipFactory = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->zipFactory = $zipFactory ?? static fn (): ZipArchive => new ZipArchive();
    }

    /**
     * @throws BundleRejected
     */
    public function build(int $entityId, ReportPeriod $p): string
    {
        $docs = (new BundleSelector($this->db))->documents($entityId, $p);

        if ($docs === []) {
            throw new BundleRejected('empty');
        }

        $missing = [];
        $total   = 0;

        foreach ($docs as $d) {
            if ($d['byte_size'] === null) {
                $missing[] = (string) $d['number'];
            } else {
                $total += $d['byte_size'];
            }
        }

        if ($missing !== []) {
            throw new BundleRejected('missing_pdf', $missing);
        }

        if ($total > self::MAX_BYTES) {
            throw new BundleRejected('too_large');
        }

        $dir = WRITEPATH . 'exports';

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $tmp = tempnam($dir, 'zip');

        if ($tmp === false) {
            throw new RuntimeException('Cannot create temporary bundle file.');
        }

        $zip    = ($this->zipFactory)();
        $opened = false;
        $closed = false;

        try {
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot open zip archive.');
            }

            $opened = true;

            $store = new DocumentStore($this->db);
            $used  = [];

            foreach ($docs as $d) {
                $stored = $store->read($d['id'], 'invoice');

                if ($stored === null) {
                    throw new BundleRejected('missing_pdf', [(string) $d['number']]);
                }

                $name = DocumentListExport::pdfName($d);

                if (isset($used[$name])) {
                    $name = substr($name, 0, -4) . '-' . $d['id'] . '.pdf';
                }

                $used[$name] = true;
                $zip->addFromString('doklady/' . $name, $stored->bytes);
            }

            $zip->addFromString('zoznam-dokladov.csv', CsvWriter::render(DocumentListExport::table($docs, true)));

            // close() always invalidates the handle, success or failure, so mark it closed before calling.
            $closed = true;

            if (! $zip->close()) {
                throw new RuntimeException('Cannot finalize zip archive.');
            }

            $bytes = file_get_contents($tmp);

            if ($bytes === false) {
                throw new RuntimeException('Cannot read temporary bundle file.');
            }

            return $bytes;
        } finally {
            if ($opened && ! $closed) {
                // Discard pending entries and close first, otherwise libzip re-creates $tmp on destruct after unlink.
                try {
                    $zip->unchangeAll();
                    $zip->close();
                } catch (Throwable) {
                    // Handle already gone, fall through to unlink.
                }
            }

            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
