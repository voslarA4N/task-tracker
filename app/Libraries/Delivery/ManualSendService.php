<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;

/**
 * Rucne oznacenie faktury ako odoslanej a jeho storno (Phase 7, D-29, vzor storna D-28).
 * invoice_sends a invoice_send_voids su insert-only: ziadny UPDATE ani DELETE, storno je novy zaznam.
 */
final class ManualSendService
{
    public const REQUEST_KEY_CONSTRAINT = 'invoice_sends_request_key_key';
    private const VOID_CONSTRAINT       = 'invoice_send_voids_send_id_key';
    private const MAX_TEXT              = 200;

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
     * Vrati id zaznamu invoice_sends (pri opakovanom request_key id existujuceho).
     *
     * @throws SendRejected
     */
    public function markSent(int $invoiceId, string $date, ?string $note, string $requestKey, ?int $userId): int
    {
        $replay = $this->replayOf($requestKey);

        if ($replay !== null) {
            return $replay;
        }

        $clock = service('clock');

        if ($clock->parseDate($date) === null) {
            throw new SendRejected('invalid_date');
        }

        $today = $clock->today();

        if ($date > $today) {
            throw new SendRejected('future_date');
        }

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > self::MAX_TEXT) {
            throw new SendRejected('note_too_long');
        }

        $now    = $clock->now();
        $sentAt = $date === $today
            ? $now
            : $clock->parseDateTime($date . 'T12:00:00');

        if ($sentAt === null) {
            throw new SendRejected('invalid_date');
        }

        try {
            return Transaction::run($this->db, fn (): int => $this->markInTransaction($invoiceId, $sentAt->format('Y-m-d H:i:sP'), $now->format('Y-m-d H:i:sP'), $note, $requestKey, $userId));
        } catch (DatabaseException $e) {
            if (InvoiceIssuer::isUniqueViolation($e, self::REQUEST_KEY_CONSTRAINT)) {
                $replay = $this->replayOf($requestKey);

                if ($replay !== null) {
                    return $replay;
                }
            }

            throw $e;
        }
    }

    /**
     * Storno rucneho oznacenia s povinnym dovodom. Vrati id faktury.
     *
     * @throws SendRejected
     */
    public function voidManual(int $sendId, string $reason, ?int $userId): int
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new SendRejected('empty_reason');
        }

        if (mb_strlen($reason) > self::MAX_TEXT) {
            throw new SendRejected('note_too_long');
        }

        try {
            return Transaction::run($this->db, function () use ($sendId, $reason, $userId): int {
                $send = $this->db->query('SELECT kind, invoice_id FROM invoice_sends WHERE id = :id:', ['id' => $sendId])->getRowArray();

                if ($send === null) {
                    throw new SendRejected('not_found');
                }

                if ($send['kind'] !== 'manual') {
                    throw new SendRejected('not_manual');
                }

                $invoiceId = (int) $send['invoice_id'];
                $this->lock($invoiceId);

                if ($this->db->query('SELECT 1 FROM invoice_send_voids WHERE send_id = :id:', ['id' => $sendId])->getRowArray() !== null) {
                    throw new SendRejected('already_voided');
                }

                $voidId = (int) $this->db->query(
                    'INSERT INTO invoice_send_voids (send_id, reason, voided_at, voided_by) VALUES (:s:, :r:, :at:, :u:) RETURNING id',
                    ['s' => $sendId, 'r' => $reason, 'at' => service('clock')->now()->format('Y-m-d H:i:sP'), 'u' => $userId],
                )->getRow()->id;

                $this->audit->record('invoice_send_void', $voidId, 'create', null, [
                    'send_id'    => $sendId,
                    'invoice_id' => $invoiceId,
                    'reason'     => $reason,
                ]);

                return $invoiceId;
            });
        } catch (DatabaseException $e) {
            if (InvoiceIssuer::isUniqueViolation($e, self::VOID_CONSTRAINT)) {
                throw new SendRejected('already_voided');
            }

            throw $e;
        }
    }

    private function markInTransaction(int $invoiceId, string $sentAt, string $createdAt, ?string $note, string $requestKey, ?int $userId): int
    {
        $this->lock($invoiceId);

        $replay = $this->replayOf($requestKey);

        if ($replay !== null) {
            return $replay;
        }

        // stav dorucenia je sent_at, nie status: status v2 uprednostnuje platby (paid pred sent, WR-01)
        $row = $this->db->query('SELECT origin, type, sent_at FROM invoice_status_v WHERE id = :id:', ['id' => $invoiceId])->getRowArray();

        if ($row === null) {
            throw new SendRejected('not_found');
        }

        if ($row['origin'] === 'external') {
            throw new SendRejected('external');
        }

        if ($row['type'] !== 'invoice') {
            throw new SendRejected('not_sendable');
        }

        if ($row['sent_at'] !== null) {
            throw new SendRejected('already_sent');
        }

        $id = (int) $this->db->query(
            "INSERT INTO invoice_sends (invoice_id, kind, sent_at, transport, counts_as_sent, note, request_key, created_at, created_by)
             VALUES (:i:, 'manual', :s:, 'none', true, :n:, CAST(:k: AS uuid), :at:, :u:) RETURNING id",
            ['i' => $invoiceId, 's' => $sentAt, 'n' => $note, 'k' => $requestKey, 'at' => $createdAt, 'u' => $userId],
        )->getRow()->id;

        $this->audit->record('invoice_send', $id, 'create', null, [
            'invoice_id' => $invoiceId,
            'kind'       => 'manual',
            'sent_at'    => $sentAt,
            'note'       => $note,
        ]);

        return $id;
    }

    private function lock(int $invoiceId): void
    {
        $this->db->query("SELECT pg_advisory_xact_lock(hashtext('invoice_sends'), :id:)", ['id' => $invoiceId]);
    }

    private function replayOf(string $requestKey): ?int
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $requestKey) !== 1) {
            return null;
        }

        $row = $this->db->query('SELECT id FROM invoice_sends WHERE request_key = CAST(:k: AS uuid)', ['k' => $requestKey])->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }
}
