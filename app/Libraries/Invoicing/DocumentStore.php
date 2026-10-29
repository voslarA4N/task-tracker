<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * PDF dokladov v bytea (D-16). Binarne data iba cez decode(hex) a pg_unescape_bytea, nikdy Query Builder.
 */
class DocumentStore
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

    public function store(int $invoiceId, string $kind, RenderedPdf $pdf): void
    {
        $this->db->query(
            "INSERT INTO invoice_documents (invoice_id, kind, content, byte_size, sha256, template_version, renderer, created_at)
             VALUES (:i:, :k:, decode(:hex:, 'hex'), :size:, :sha:, :tpl:, :r:, :now:)",
            [
                'i'    => $invoiceId,
                'k'    => $kind,
                'hex'  => bin2hex($pdf->bytes),
                'size' => strlen($pdf->bytes),
                'sha'  => $pdf->sha256,
                'tpl'  => $pdf->templateVersion,
                'r'    => $pdf->renderer,
                'now'  => service('clock')->now()->format('Y-m-d H:i:sP'),
            ],
        );
    }

    /**
     * @throws DocumentIntegrityError
     */
    public function read(int $invoiceId, string $kind = 'invoice'): ?StoredDocument
    {
        $row = $this->db->query(
            'SELECT content, sha256, byte_size, template_version, renderer FROM invoice_documents WHERE invoice_id = :i: AND kind = :k:',
            ['i' => $invoiceId, 'k' => $kind],
        )->getRowArray();

        if ($row === null) {
            return null;
        }

        $bytes = pg_unescape_bytea((string) $row['content']);
        $sha   = trim((string) $row['sha256']);

        self::verify($bytes, $sha);

        return new StoredDocument($bytes, $sha, (int) $row['byte_size'], (string) $row['template_version'], (string) $row['renderer']);
    }

    /**
     * @return array{sha256: string, byte_size: int, template_version: string, renderer: string}|null
     */
    public function meta(int $invoiceId, string $kind = 'invoice'): ?array
    {
        $row = $this->db->query(
            'SELECT sha256, byte_size, template_version, renderer FROM invoice_documents WHERE invoice_id = :i: AND kind = :k:',
            ['i' => $invoiceId, 'k' => $kind],
        )->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'sha256'           => trim((string) $row['sha256']),
            'byte_size'        => (int) $row['byte_size'],
            'template_version' => (string) $row['template_version'],
            'renderer'         => (string) $row['renderer'],
        ];
    }

    /**
     * @throws DocumentIntegrityError
     */
    public static function verify(string $bytes, string $sha256): void
    {
        if (! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new DocumentIntegrityError('Stored document does not match its sha256.');
        }
    }
}
