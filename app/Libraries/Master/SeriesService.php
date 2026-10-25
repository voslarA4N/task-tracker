<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Konfiguracia cislenych radov subjektu (ENT-01, D-09, D-10). Pridelenie cisla je Phase 5 (D-11).
 * Kazdy dotaz nesie entity_id (ochrana pred IDOR).
 */
class SeriesService
{
    public const KINDS = ['invoice', 'credit_note'];

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
     * @return array<string, array<string, mixed>> kluc = kind
     */
    public function listFor(int $entityId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM invoice_series WHERE entity_id = :e: ORDER BY kind DESC',
            ['e' => $entityId],
        )->getResultArray();

        $out = [];

        foreach (self::KINDS as $kind) {
            foreach ($rows as $row) {
                if ($row['kind'] === $kind) {
                    $out[$kind] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $entityId, string $kind): ?array
    {
        if (! in_array($kind, self::KINDS, true)) {
            return null;
        }

        $row = $this->db->query(
            'SELECT * FROM invoice_series WHERE entity_id = :e: AND kind = :k:',
            ['e' => $entityId, 'k' => $kind],
        )->getRowArray();

        return $row ?? null;
    }

    /**
     * @throws InvalidSeriesFormat
     * @throws SeriesRejected
     */
    public function update(int $entityId, string $kind, string $format, string $initialNumber): void
    {
        $parsed = SeriesFormat::parse($format);

        if (preg_match('/^\d{1,9}$/D', $initialNumber) !== 1) {
            throw new SeriesRejected('initial_out_of_range');
        }

        $initial = (int) $initialNumber;

        if ($initial < 1 || $initial > $parsed->maxSequence()) {
            throw new SeriesRejected('initial_out_of_range');
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw new SeriesRejected('initial_out_of_range');
        }

        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        Transaction::run($this->db, function () use ($entityId, $kind, $parsed, $initial, $now): void {
            // Oba rady subjektu uzamknuté (v poradí id): kontroly, audit "before" aj UPDATE vidia ten istý stav
            // a sú serializované s pridelením čísla v Phase 5 (WR-02).
            $rows = $this->db->query(
                'SELECT * FROM invoice_series WHERE entity_id = :e: ORDER BY id FOR UPDATE',
                ['e' => $entityId],
            )->getResultArray();

            $series = null;

            foreach ($rows as $row) {
                if ($row['kind'] === $kind) {
                    $series = $row;
                } elseif ($this->collides((string) $row['format'], $parsed)) {
                    throw new SeriesRejected('same_as_other');
                }
            }

            if ($series === null) {
                throw new SeriesRejected('initial_out_of_range');
            }

            $seriesId = (int) $series['id'];

            if ($series['format'] !== $parsed->format() && $this->isFormatLocked($seriesId)) {
                throw new SeriesRejected('locked');
            }

            $old = ['format' => (string) $series['format'], 'initial_number' => (string) $series['initial_number']];
            $new = ['format' => $parsed->format(), 'initial_number' => (string) $initial];

            if ($old === $new) {
                return;
            }

            $this->db->query(
                'UPDATE invoice_series SET format = :f:, initial_number = :n:, updated_at = :now: WHERE id = :id: AND entity_id = :e:',
                ['f' => $new['format'], 'n' => $initial, 'now' => $now, 'id' => $seriesId, 'e' => $entityId],
            );
            $this->audit->recordChange('invoice_series', $seriesId, 'update', $old, $new);
        });
    }

    /**
     * Faktúry a dobropisy subjektu nesmú nikdy dostať rovnaké číslo (WR-05), nestačí rozdielny reťazec formátu.
     */
    private function collides(string $otherFormat, SeriesFormat $parsed): bool
    {
        try {
            return SeriesFormat::parse($otherFormat)->mayCollideWith($parsed);
        } catch (InvalidSeriesFormat) {
            // Uložený formát, ktorý dnešné pravidlá už neprijímajú: aspoň zhoda reťazca.
            return strcasecmp($otherFormat, $parsed->format()) === 0;
        }
    }

    /**
     * V Phase 3 este neexistuju vystavene doklady, preto je rad vzdy odomknuty.
     * Phase 5 nahradi telo kontrolou, ci rad obsahuje vystavene doklady (D-10, RESEARCH A7).
     */
    public function isFormatLocked(int $seriesId): bool
    {
        return false;
    }
}
