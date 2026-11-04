<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Platform\Money;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Jediny zdroj sum prehladov (NFR-04, D-05). Cita iba denormalizovane stlpce faktur zapisane pri
 * vystaveni (total_entity_ccy, turnover_date, counts_toward_limit, origin). Doklady sa zaradia podla
 * datumu dodania (D-01), externe faktury sa nezapocitavaju. Sucty vznikaju iba cez Money, nikdy druhym SQL.
 */
final class ReportCalculator
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

    public function forPeriod(int $entityId, ReportPeriod $period): ReportResult
    {
        $currencyRow = $this->db->query('SELECT currency FROM entities WHERE id = :e:', ['e' => $entityId])->getRowArray();
        $currency    = trim((string) ($currencyRow['currency'] ?? ''));

        $rows = $this->db->query(
            "SELECT i.client_id, c.name AS client_name, to_char(i.turnover_date, 'YYYY-MM') AS month,
                    COALESCE(SUM(i.total_entity_ccy), 0) AS invoiced,
                    COALESCE(SUM(i.total_entity_ccy) FILTER (WHERE i.counts_toward_limit), 0) AS counted,
                    COUNT(*) FILTER (WHERE i.type = 'invoice') AS invoice_count,
                    COUNT(*) FILTER (WHERE i.type = 'credit_note') AS credit_note_count
             FROM invoices i
             JOIN clients c ON c.id = i.client_id
             WHERE i.entity_id = :e: AND i.origin = 'issued'
               AND i.turnover_date >= :from: AND i.turnover_date < :until:
             GROUP BY i.client_id, c.name, 3",
            ['e' => $entityId, 'from' => $period->from, 'until' => $period->until],
        )->getResultArray();

        /** @var array<int, array{name: string, invoiced: Money, counted: Money, paid: Money}> $clients */
        $clients = [];
        /** @var array<string, array{invoiced: Money, counted: Money, paid: Money}> $months */
        $months = [];

        foreach ($period->months() as $m) {
            $months[$m] = ['invoiced' => Money::zero(), 'counted' => Money::zero(), 'paid' => Money::zero()];
        }

        $invoiceCount    = 0;
        $creditNoteCount = 0;

        foreach ($rows as $row) {
            $clientId = (int) $row['client_id'];
            $invoiced = Money::of((string) $row['invoiced']);
            $counted  = Money::of((string) $row['counted']);
            $month    = (string) $row['month'];

            $clients[$clientId] ??= ['name' => (string) $row['client_name'], 'invoiced' => Money::zero(), 'counted' => Money::zero(), 'paid' => Money::zero()];
            $clients[$clientId]['invoiced'] = $clients[$clientId]['invoiced']->plus($invoiced);
            $clients[$clientId]['counted']  = $clients[$clientId]['counted']->plus($counted);

            if (isset($months[$month])) {
                $months[$month]['invoiced'] = $months[$month]['invoiced']->plus($invoiced);
                $months[$month]['counted']  = $months[$month]['counted']->plus($counted);
            }

            $invoiceCount += (int) $row['invoice_count'];
            $creditNoteCount += (int) $row['credit_note_count'];
        }

        $paidTotal = $this->addPayments($entityId, $period, $clients, $months);

        uasort($clients, static function (array $a, array $b): int {
            $cmp = $b['invoiced']->compare($a['invoiced']);

            return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
        });

        $clientRows = [];

        foreach ($clients as $id => $c) {
            $clientRows[] = [
                'client_id'   => $id,
                'client_name' => $c['name'],
                'invoiced'    => $c['invoiced']->toDecimalString(),
                'counted'     => $c['counted']->toDecimalString(),
                'paid'        => $c['paid']->toDecimalString(),
            ];
        }

        $monthRows = [];

        foreach ($months as $m => $v) {
            $monthRows[] = ['month' => $m, 'invoiced' => $v['invoiced']->toDecimalString(), 'counted' => $v['counted']->toDecimalString(), 'paid' => $v['paid']->toDecimalString()];
        }

        $invoicedTotal = Money::sum(...array_map(static fn (array $c): Money => Money::of($c['invoiced']), $clientRows));
        $countedTotal  = Money::sum(...array_map(static fn (array $c): Money => Money::of($c['counted']), $clientRows));

        return new ReportResult($entityId, $currency, $period, $clientRows, $monthRows, $invoicedTotal->toDecimalString(), $countedTotal->toDecimalString(), $invoiceCount, $creditNoteCount, $paidTotal->toDecimalString());
    }

    /**
     * Uhrady podla paid_on (D-02), bez zrusenych. Suma v mene subjektu = rozdiel kumulativnych podielov
     * ReportMath::paidShare z ulozenych total a total_entity_ccy faktury (nikdy aktualny kurz, CUR-04).
     * Nacitaju sa vsetky platby faktur, ktore maju aspon jednu platbu v obdobi, aby kumulacia bola presna.
     *
     * @param array<int, array{name: string, invoiced: Money, counted: Money, paid: Money}> $clients
     * @param array<string, array{invoiced: Money, counted: Money, paid: Money}>            $months
     */
    private function addPayments(int $entityId, ReportPeriod $period, array &$clients, array &$months): Money
    {
        $rows = $this->db->query(
            "SELECT p.invoice_id, p.paid_on, p.amount, i.client_id, c.name AS client_name, i.total, i.total_entity_ccy,
                    SUM(p.amount) OVER (PARTITION BY p.invoice_id ORDER BY p.paid_on, p.id) AS cum_amount
             FROM payments p
             JOIN invoices i ON i.id = p.invoice_id
             JOIN clients c ON c.id = i.client_id
             WHERE i.entity_id = :e: AND i.origin = 'issued'
               AND NOT EXISTS (SELECT 1 FROM payment_voids v WHERE v.payment_id = p.id)
               AND p.invoice_id IN (SELECT p2.invoice_id FROM payments p2 WHERE p2.paid_on >= :from: AND p2.paid_on < :until:)
             ORDER BY p.invoice_id, p.paid_on, p.id",
            ['e' => $entityId, 'from' => $period->from, 'until' => $period->until],
        )->getResultArray();

        $total     = Money::zero();
        $prevShare = Money::zero();
        $prevInv   = null;

        foreach ($rows as $row) {
            $invoiceId = (int) $row['invoice_id'];

            if ($prevInv !== $invoiceId) {
                $prevInv   = $invoiceId;
                $prevShare = Money::zero();
            }

            $share     = Money::of(ReportMath::paidShare((string) $row['cum_amount'], (string) $row['total'], (string) $row['total_entity_ccy']));
            $part      = $share->minus($prevShare);
            $prevShare = $share;
            $paidOn    = (string) $row['paid_on'];

            if ($paidOn < $period->from || $paidOn >= $period->until) {
                continue;
            }

            $clientId = (int) $row['client_id'];
            $clients[$clientId] ??= ['name' => (string) $row['client_name'], 'invoiced' => Money::zero(), 'counted' => Money::zero(), 'paid' => Money::zero()];
            $clients[$clientId]['paid'] = $clients[$clientId]['paid']->plus($part);

            $month = substr($paidOn, 0, 7);

            if (isset($months[$month])) {
                $months[$month]['paid'] = $months[$month]['paid']->plus($part);
            }

            $total = $total->plus($part);
        }

        return $total;
    }
}
