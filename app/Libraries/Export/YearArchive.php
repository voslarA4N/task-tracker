<?php

declare(strict_types=1);

namespace App\Libraries\Export;

use App\Libraries\Invoicing\DocumentFilename;
use App\Libraries\Invoicing\DocumentIntegrityError;
use App\Libraries\Invoicing\DocumentStore;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Rocny archiv dokladov (NFR-09, D-10): ZIP so vsetkymi ulozenymi PDF vystavenych dokladov roka (vsetky verzie)
 * a CSV index so sha256. PDF sa citaju iba z invoice_documents cez DocumentStore::readVersion (overenie sha256),
 * nikdy sa negeneruju znova. Zapis na disk, jedno PDF naraz.
 */
final class YearArchive
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @throws DocumentIntegrityError
     */
    public function build(int $year): YearArchiveResult
    {
        if ($year < 2000 || $year > 9999) {
            throw new InvalidArgumentException('Invalid archive year.');
        }

        $rows = $this->db->query(
            "SELECT d.id AS document_id, d.invoice_id, d.kind, d.version, d.sha256, f.number, f.type, f.issue_date, f.total, f.currency,
                    f.doc_language, coalesce(f.snapshot->'entity'->>'trade_name', '') AS entity_name, coalesce(f.snapshot->'client'->>'name', '') AS client_name
             FROM invoices f
             JOIN invoice_documents d ON d.invoice_id = f.id
             WHERE f.origin = 'issued' AND f.issue_date >= :from: AND f.issue_date <= :to:
             ORDER BY f.issue_date, f.id, d.kind, d.version",
            ['from' => $year . '-01-01', 'to' => $year . '-12-31'],
        )->getResultArray();

        $base = WRITEPATH . 'archive';
        $dir  = $base . '/' . $year;
        $tmp  = $base . '/tmp';

        foreach ([$base, $dir, $tmp] as $d) {
            if (! is_dir($d) && ! mkdir($d, 0700, true) && ! is_dir($d)) {
                throw new RuntimeException('Cannot create archive directory.');
            }
        }

        $partial = tempnam($dir, 'zip');

        if ($partial === false) {
            throw new RuntimeException('Cannot create temporary archive file.');
        }

        $tmpFiles = [];
        $zipOpen  = false;
        $zip      = new ZipArchive();

        try {
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot open zip archive.');
            }

            $zipOpen = true;
            $store   = new DocumentStore($this->db);
            $used    = [];
            $csvRows = [];
            $invoice = [];

            foreach ($rows as $r) {
                $stored = $store->readVersion((int) $r['invoice_id'], (string) $r['kind'], (int) $r['version']);

                if ($stored === null) {
                    throw new RuntimeException('Missing document version for invoice ' . $r['invoice_id'] . '.');
                }

                $isCn = (string) $r['type'] === 'credit_note';
                $name = DocumentFilename::for((string) $r['kind'] === 'report' ? 'report' : ($isCn ? 'credit_note' : 'invoice'), trim((string) $r['doc_language']), (string) $r['number']);

                if ((int) $r['version'] > 1) {
                    $name = substr($name, 0, -4) . '-v' . $r['version'] . '.pdf';
                }

                if (isset($used[$name])) {
                    $name = substr($name, 0, -4) . '-' . $r['document_id'] . '.pdf';
                }

                $used[$name] = true;

                $file = tempnam($tmp, 'pdf');

                if ($file === false || file_put_contents($file, $stored->bytes) === false) {
                    throw new RuntimeException('Cannot write temporary document file.');
                }

                $tmpFiles[] = $file;
                $zip->addFile($file, 'doklady/' . $name);

                $invoice[(int) $r['invoice_id']] = true;
                $csvRows[]                       = [
                    ExportCell::text((string) $r['number']),
                    ExportCell::text($isCn ? 'dobropis' : 'faktúra'),
                    ExportCell::text((string) $r['issue_date']),
                    ExportCell::text((string) $r['entity_name']),
                    ExportCell::text((string) $r['client_name']),
                    ExportCell::money((string) $r['total']),
                    ExportCell::text(trim((string) $r['currency'])),
                    ExportCell::text($stored->sha256),
                    ExportCell::text($name),
                    ExportCell::text((string) $r['kind']),
                    ExportCell::text((string) $r['version']),
                ];

                // pamat drzi iba jedno PDF naraz
                unset($stored);
            }

            $headers = ['číslo', 'typ', 'dátum', 'subjekt', 'klient', 'suma', 'mena', 'sha256', 'súbor', 'druh', 'verzia'];
            $widths  = [18, 10, 12, 28, 28, 14, 8, 66, 40, 10, 8];
            $zip->addFromString('zoznam-dokladov.csv', CsvWriter::render(new ExportTable('archiv', $headers, $csvRows, $widths)));
            $zip->addFromString('README.txt', $this->readme($year));

            $zipOpen = false;

            if (! $zip->close()) {
                throw new RuntimeException('Cannot finalize zip archive.');
            }
        } finally {
            if ($zipOpen) {
                $zip->close();
            }

            foreach ($tmpFiles as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
        }

        try {
            chmod($partial, 0600);

            $final = $dir . '/work-tracker-archiv-' . $year . '.zip';
            $sha   = hash_file('sha256', $partial);
            $bytes = filesize($partial);

            if ($sha === false || $bytes === false) {
                throw new RuntimeException('Cannot read archive file.');
            }

            if (! rename($partial, $final)) {
                throw new RuntimeException('Cannot move archive into place.');
            }

            $shaFile = $final . '.sha256';

            if (file_put_contents($shaFile, $sha . '  ' . basename($final) . "\n") === false) {
                throw new RuntimeException('Cannot write archive checksum.');
            }

            chmod($shaFile, 0600);
        } finally {
            if (is_file($partial)) {
                unlink($partial);
            }
        }

        return new YearArchiveResult($final, count($invoice), count($rows), $bytes, $sha);
    }

    private function readme(int $year): string
    {
        return "Archív dokladov Work Tracker za rok {$year}.\n"
            . "Priečinok doklady/ obsahuje PDF faktúr, výkazov práce a dobropisov presne tak, ako boli vystavené (všetky verzie), súbor zoznam-dokladov.csv je ich zoznam.\n"
            . "Súbor otvoríte v Exceli (oddeľovač bodkočiarka, UTF-8), stĺpec sha256 je kontrolný súčet každého PDF.\n"
            . "Overenie: sha256sum doklady/<súbor>.pdf a porovnať so stĺpcom sha256. Celý archív overíte súborom .zip.sha256 (sha256sum -c).\n"
            . "Postup obnovy a uchovávania je v docs/ops/backup.md.\n";
    }
}
