<?php

declare(strict_types=1);

namespace App\Libraries\Receivables;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Platform\Money;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;

/**
 * Zapis a storno uhrad (Phase 7, D-18, D-19, D-20, D-28). Peniaze iba ako retazce, aritmetika cez Money (bcmath s mierkou 2).
 * Suberne zapisy tej istej faktury radi pg_advisory_xact_lock (app_rw nema UPDATE, takze FOR UPDATE na invoices nejde).
 * Uhrady sa nikdy nemenia ani nemazu: storno je novy zaznam v payment_voids.
 */
final class PaymentService
{
    public const REQUEST_KEY_CONSTRAINT = 'payments_request_key_key';
    private const VOID_CONSTRAINT       = 'payment_voids_payment_id_key';
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
     * Prijme `1 080,50`, `1080.5`, aj s NBSP. Vrati retazec s 2 desatinnymi miestami alebo null.
     */
    public static function parseAmount(string $raw): ?string
    {
        $s = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim($raw));
        $s = str_replace(',', '.', $s);

        if (preg_match('/^-?\d{1,10}(?:\.\d{1,2})?$/D', $s) !== 1) {
            return null;
        }

        return Money::of($s)->toDecimalString();
    }

    /**
     * @throws PaymentRejected
     */
    public function record(int $invoiceId, ?string $amount, string $paidOn, ?string $note, string $requestKey, ?int $userId): RecordedPayment
    {
        $replay = $this->replayOf($requestKey);

        if ($replay !== null) {
            return $replay;
        }

        $clock = service('clock');

        if ($clock->parseDate($paidOn) === null) {
            throw new PaymentRejected('invalid_date');
        }

        if ($paidOn > $clock->today()) {
            throw new PaymentRejected('future_date');
        }

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > self::MAX_TEXT) {
            throw new PaymentRejected('note_too_long');
        }

        $explicit = null;

        if ($amount !== null && trim($amount) !== '') {
            $explicit = self::parseAmount($amount);

            if ($explicit === null) {
                throw new PaymentRejected('invalid_amount');
            }

            if (Money::of($explicit)->compare(Money::zero()) <= 0) {
                throw new PaymentRejected('non_positive');
            }
        }

        try {
            return Transaction::run($this->db, fn (): RecordedPayment => $this->recordInTransaction($invoiceId, $explicit, $paidOn, $note, $requestKey, $userId));
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
     * Storno omylom zadanej uhrady s povinnym dovodom. Vrati id faktury.
     *
     * @throws PaymentRejected
     */
    public function void(int $paymentId, string $reason, ?int $userId): int
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new PaymentRejected('empty_reason');
        }

        if (mb_strlen($reason) > self::MAX_TEXT) {
            throw new PaymentRejected('note_too_long');
        }

        try {
            return Transaction::run($this->db, function () use ($paymentId, $reason, $userId): int {
                $payment = $this->db->query('SELECT invoice_id, amount FROM payments WHERE id = :id:', ['id' => $paymentId])->getRowArray();

                if ($payment === null) {
                    throw new PaymentRejected('not_found');
                }

                $invoiceId = (int) $payment['invoice_id'];
                $this->lock($invoiceId);

                if ($this->db->query('SELECT 1 FROM payment_voids WHERE payment_id = :id:', ['id' => $paymentId])->getRowArray() !== null) {
                    throw new PaymentRejected('already_voided');
                }

                $voidId = (int) $this->db->query(
                    'INSERT INTO payment_voids (payment_id, reason, voided_at, voided_by) VALUES (:p:, :r:, :at:, :u:) RETURNING id',
                    ['p' => $paymentId, 'r' => $reason, 'at' => service('clock')->now()->format('Y-m-d H:i:sP'), 'u' => $userId],
                )->getRow()->id;

                $this->audit->record('payment_void', $voidId, 'create', null, [
                    'payment_id' => $paymentId,
                    'invoice_id' => $invoiceId,
                    'amount'     => (string) $payment['amount'],
                    'reason'     => $reason,
                ]);

                return $invoiceId;
            });
        } catch (DatabaseException $e) {
            if (InvoiceIssuer::isUniqueViolation($e, self::VOID_CONSTRAINT)) {
                throw new PaymentRejected('already_voided');
            }

            throw $e;
        }
    }

    /**
     * Nahlad dopadu uhrady bez zapisu. Pri neplatnej alebo prilis vysokej sume vrati sumu 0.00 a aktualny zostatok.
     *
     * @return array{amount: string, balance_after: string, status_after: string}
     */
    public function impact(int $invoiceId, ?string $amount): array
    {
        $row = $this->db->query('SELECT status, total, paid_amount, balance FROM invoice_status_v WHERE id = :id:', ['id' => $invoiceId])->getRowArray();

        if ($row === null) {
            return ['amount' => '0.00', 'balance_after' => '0.00', 'status_after' => 'issued'];
        }

        $balance = (string) $row['balance'];
        $current = ['amount' => '0.00', 'balance_after' => $balance, 'status_after' => (string) $row['status']];
        $parsed  = $amount === null || trim($amount) === '' ? $balance : self::parseAmount($amount);

        if ($parsed === null || Money::of($parsed)->compare(Money::zero()) <= 0 || Money::of($parsed)->compare(Money::of($balance)) > 0) {
            return $current;
        }

        $after = Money::of($balance)->minus(Money::of($parsed))->toDecimalString();
        $paid  = Money::of((string) $row['paid_amount'])->plus(Money::of($parsed))->toDecimalString();

        return [
            'amount'        => $parsed,
            'balance_after' => $after,
            'status_after'  => self::statusAfter((string) $row['total'], $paid, (string) $row['status']),
        ];
    }

    private function recordInTransaction(int $invoiceId, ?string $explicit, string $paidOn, ?string $note, string $requestKey, ?int $userId): RecordedPayment
    {
        $this->lock($invoiceId);

        // Subezny dvojklik pocka na zamok a po commite prveho uz vidi jeho zapis.
        $replay = $this->replayOf($requestKey);

        if ($replay !== null) {
            return $replay;
        }

        $row = $this->db->query('SELECT origin, type, balance FROM invoice_status_v WHERE id = :id:', ['id' => $invoiceId])->getRowArray();

        if ($row === null) {
            throw new PaymentRejected('not_found');
        }

        if ($row['origin'] !== 'issued' || $row['type'] !== 'invoice') {
            throw new PaymentRejected('not_payable');
        }

        $balance = (string) $row['balance'];

        if (Money::of($balance)->compare(Money::zero()) <= 0) {
            throw new PaymentRejected('already_paid', $balance);
        }

        $amount = $explicit ?? $balance;

        if (Money::of($amount)->compare(Money::of($balance)) > 0) {
            throw new PaymentRejected('overpaid', $balance);
        }

        $id = (int) $this->db->query(
            'INSERT INTO payments (invoice_id, paid_on, amount, note, request_key, created_at, created_by)
             VALUES (:i:, :d:, :a:, :n:, CAST(:k: AS uuid), :at:, :u:) RETURNING id',
            ['i' => $invoiceId, 'd' => $paidOn, 'a' => $amount, 'n' => $note, 'k' => $requestKey, 'at' => service('clock')->now()->format('Y-m-d H:i:sP'), 'u' => $userId],
        )->getRow()->id;

        $this->audit->record('payment', $id, 'create', null, [
            'invoice_id' => $invoiceId,
            'paid_on'    => $paidOn,
            'amount'     => $amount,
            'note'       => $note,
        ]);

        $after = $this->db->query('SELECT status, balance FROM invoice_status_v WHERE id = :id:', ['id' => $invoiceId])->getRowArray() ?? [];

        return new RecordedPayment($id, $amount, (string) ($after['balance'] ?? '0.00'), (string) ($after['status'] ?? 'issued'), false);
    }

    private function lock(int $invoiceId): void
    {
        $this->db->query("SELECT pg_advisory_xact_lock(hashtext('payments'), :id:)", ['id' => $invoiceId]);
    }

    /**
     * Predchadzajuci vysledok zapisu pod tym istym request_key pre tuto fakturu (opakovany POST, WR-02).
     * Kontrolor ho vola pred kontrolou stavu, aby opakovanie po plnej uhrade nevratilo 404.
     */
    public function replayFor(int $invoiceId, string $requestKey): ?RecordedPayment
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $requestKey) !== 1) {
            return null;
        }

        $p = $this->db->query(
            'SELECT p.id, p.amount, v.status, v.balance
             FROM payments p JOIN invoice_status_v v ON v.id = p.invoice_id
             WHERE p.request_key = CAST(:k: AS uuid) AND p.invoice_id = :i:',
            ['k' => $requestKey, 'i' => $invoiceId],
        )->getRowArray();

        return $p === null ? null : new RecordedPayment((int) $p['id'], (string) $p['amount'], (string) $p['balance'], (string) $p['status'], true);
    }

    private function replayOf(string $requestKey): ?RecordedPayment
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $requestKey) !== 1) {
            return null;
        }

        $p = $this->db->query(
            'SELECT p.id, p.amount, v.status, v.balance
             FROM payments p JOIN invoice_status_v v ON v.id = p.invoice_id
             WHERE p.request_key = CAST(:k: AS uuid)',
            ['k' => $requestKey],
        )->getRowArray();

        return $p === null ? null : new RecordedPayment((int) $p['id'], (string) $p['amount'], (string) $p['balance'], (string) $p['status'], true);
    }

    private static function statusAfter(string $total, string $paid, string $current): string
    {
        $paidMoney = Money::of($paid);

        if (Money::of($total)->compare(Money::zero()) > 0 && $paidMoney->compare(Money::of($total)) >= 0) {
            return 'paid';
        }

        return $paidMoney->compare(Money::zero()) > 0 ? 'partially_paid' : $current;
    }
}
