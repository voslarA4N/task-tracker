<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Nastavenie dokladov: lehota pregenerovania PDF faktury v hodinach od vystavenia (D-13). 0 = nikdy.
 */
final class DocumentSettingsService
{
    public const MAX_HOURS = 8760;

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly AuditLogger $audit;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = $audit ?? new AuditLogger($this->db);
    }

    /**
     * Chybajuci riadok je poskodena schema: rovnako ako current() zlyha nahlas, nikdy nevymysla lehotu.
     *
     * @throws DocumentSettingsRejected
     */
    public function invoiceWindowHours(): int
    {
        $row = $this->db->query('SELECT regen_invoice_hours FROM document_settings WHERE id = 1')->getRowArray();

        if ($row === null) {
            throw new DocumentSettingsRejected('not_found');
        }

        return (int) $row['regen_invoice_hours'];
    }

    /**
     * @return array{regen_invoice_hours: int, revision: int, updated_at: string}
     */
    public function current(): array
    {
        $row = $this->db->query('SELECT regen_invoice_hours, revision, updated_at FROM document_settings WHERE id = 1')->getRowArray();

        if ($row === null) {
            throw new DocumentSettingsRejected('not_found');
        }

        return [
            'regen_invoice_hours' => (int) $row['regen_invoice_hours'],
            'revision'            => (int) $row['revision'],
            'updated_at'          => (string) $row['updated_at'],
        ];
    }

    /**
     * @return bool true ak sa hodnota zmenila
     *
     * @throws DocumentSettingsRejected
     */
    public function update(string $hours, int $revision): bool
    {
        if (preg_match('/^\d{1,4}$/', $hours) !== 1 || (int) $hours > self::MAX_HOURS) {
            throw new DocumentSettingsRejected('invalid', ['regen_invoice_hours']);
        }

        $new = (int) $hours;
        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($new, $revision, $now): bool {
            $row = $this->db->query('SELECT regen_invoice_hours, revision FROM document_settings WHERE id = 1 FOR UPDATE')->getRowArray();

            if ($row === null) {
                throw new DocumentSettingsRejected('not_found');
            }

            $oldRevision = (int) $row['revision'];

            if ($revision !== $oldRevision) {
                throw new DocumentSettingsRejected('stale');
            }

            $old = (int) $row['regen_invoice_hours'];

            if ($old === $new) {
                return false;
            }

            $this->db->query(
                'UPDATE document_settings SET regen_invoice_hours = :h:, revision = revision + 1, updated_at = :now: WHERE id = 1',
                ['h' => $new, 'now' => $now],
            );
            $this->audit->recordChange(
                'document_settings',
                1,
                'update',
                ['regen_invoice_hours' => $old, 'revision' => $oldRevision],
                ['regen_invoice_hours' => $new, 'revision' => $oldRevision + 1],
            );

            return true;
        });
    }
}
