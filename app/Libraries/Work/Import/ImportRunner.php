<?php

declare(strict_types=1);

namespace App\Libraries\Work\Import;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\ClientService;
use App\Libraries\Platform\Transaction;
use App\Libraries\Work\TaskInput;
use App\Libraries\Work\TaskService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

/**
 * Ostry zapis importu (D-17, D-19, D-20): jedna transakcia, ulohy a casti iba cez doménové služby.
 * Nezavisi od PhpSpreadsheet.
 */
final class ImportRunner
{
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

    public function run(ImportPlan $plan, int $clientId): ImportResult
    {
        return Transaction::run($this->db, function () use ($plan, $clientId): ImportResult {
            $client = (new ClientService($this->db, $this->audit))->find($clientId);

            if ($client === null || ($client['archived_at'] ?? null) !== null) {
                throw new InvalidArgumentException('Klient neexistuje alebo je archivovaný.');
            }

            $tasks   = new TaskService($this->db, $this->audit);
            $created = 0;
            $skipped = 0;
            $parts   = 0;
            $keys    = [];

            foreach ($plan->items as $item) {
                $exists = $this->db->query('SELECT 1 FROM tasks WHERE import_key = :k:', ['k' => $item['importKey']])->getRow();

                if ($exists !== null) {
                    $skipped++;

                    continue;
                }

                $row = $item['row'];
                $raw = [
                    'client_id' => (string) $clientId,
                    'url'       => $row->url,
                    'title'     => $row->title,
                    'hours'     => $row->hours,
                    'rate'      => (string) $client['hourly_rate'],
                    'note'      => null,
                ];

                if (TaskInput::validate($raw) !== []) {
                    throw new InvalidArgumentException('Neplatné údaje v riadku ' . $row->rowNumber . ' zdrojového súboru.');
                }

                $id = $tasks->create(TaskInput::normalize($raw), $item['importKey']);
                $created++;
                $keys[] = $item['importKey'];

                if ($item['partHours'] !== null && $tasks->markDone($id, $row->month, $item['partHours']) !== null) {
                    $parts++;
                }
            }

            return new ImportResult($created, $skipped, $parts, $keys);
        });
    }
}
