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

    /**
     * Ulozi novu verziu dokumentu (max + 1, prva = 1) a vrati jej cislo (D-02).
     */
    public function store(int $invoiceId, string $kind, RenderedPdf $pdf): int
    {
        $row = $this->db->query(
            "INSERT INTO invoice_documents (invoice_id, kind, version, content, byte_size, sha256, template_version, renderer, created_at)
             SELECT :i:, :k:, coalesce(max(version), 0) + 1, decode(:hex:, 'hex'), :size:, :sha:, :tpl:, :r:, :now:
             FROM invoice_documents WHERE invoice_id = :i: AND kind = :k:
             RETURNING version",
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
        )->getRow();

        return (int) $row->version;
    }

    /**
     * Najvyssia verzia.
     *
     * @throws DocumentIntegrityError
     */
    public function read(int $invoiceId, string $kind = 'invoice'): ?StoredDocument
    {
        $row = $this->db->query(
            'SELECT content, sha256, byte_size, template_version, renderer, version FROM invoice_documents WHERE invoice_id = :i: AND kind = :k: ORDER BY version DESC LIMIT 1',
            ['i' => $invoiceId, 'k' => $kind],
        )->getRowArray();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @throws DocumentIntegrityError
     */
    public function readVersion(int $invoiceId, string $kind, int $version): ?StoredDocument
    {
        $row = $this->db->query(
            'SELECT content, sha256, byte_size, template_version, renderer, version FROM invoice_documents WHERE invoice_id = :i: AND kind = :k: AND version = :v:',
            ['i' => $invoiceId, 'k' => $kind, 'v' => $version],
        )->getRowArray();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Metadata najvyssej verzie.
     *
     * @return array{version: int, sha256: string, byte_size: int, template_version: string, renderer: string, created_at: string}|null
     */
    public function meta(int $invoiceId, string $kind = 'invoice'): ?array
    {
        $row = $this->db->query(
            'SELECT version, sha256, byte_size, template_version, renderer, created_at FROM invoice_documents WHERE invoice_id = :i: AND kind = :k: ORDER BY version DESC LIMIT 1',
            ['i' => $invoiceId, 'k' => $kind],
        )->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'version'          => (int) $row['version'],
            'sha256'           => trim((string) $row['sha256']),
            'byte_size'        => (int) $row['byte_size'],
            'template_version' => (string) $row['template_version'],
            'renderer'         => (string) $row['renderer'],
            'created_at'       => (string) $row['created_at'],
        ];
    }

    /**
     * Historia verzii bez obsahu.
     *
     * @return list<array{kind: string, version: int, template_version: string, renderer: string, sha256: string, byte_size: int, created_at: string}>
     */
    public function versions(int $invoiceId): array
    {
        $rows = $this->db->query(
            'SELECT kind, version, template_version, renderer, sha256, byte_size, created_at FROM invoice_documents WHERE invoice_id = :i: ORDER BY kind, version DESC',
            ['i' => $invoiceId],
        )->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'kind'             => (string) $row['kind'],
                'version'          => (int) $row['version'],
                'template_version' => (string) $row['template_version'],
                'renderer'         => (string) $row['renderer'],
                'sha256'           => trim((string) $row['sha256']),
                'byte_size'        => (int) $row['byte_size'],
                'created_at'       => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws DocumentIntegrityError
     */
    private function hydrate(array $row): StoredDocument
    {
        $bytes = pg_unescape_bytea((string) $row['content']);
        $sha   = trim((string) $row['sha256']);

        self::verify($bytes, $sha);

        return new StoredDocument($bytes, $sha, (int) $row['byte_size'], (string) $row['template_version'], (string) $row['renderer'], (int) $row['version']);
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
