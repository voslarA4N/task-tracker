<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Fixtures obratu (Phase 10): faktury s lubovolnym datumom obratu, sumou v mene subjektu a priznakom,
 * dobropisy, limity a pociatocny obrat. Pouzivajuci test musi pouzivat aj MasterFixtures,
 * InvoiceFixtures a CreditNoteFixtures.
 *
 * @mixin PgTestCase
 */
trait TurnoverFixtures
{
    private int $turnoverSeq = 0;

    /**
     * Vlozi vystavenu fakturu s dany datumom obratu. $amount je suma faktury (v mene faktury),
     * total_entity_ccy sa da prepisat cez $overrides.
     *
     * @param array<string, mixed> $overrides
     */
    protected function turnoverInvoice(int $entityId, int $clientId, string $turnoverDate, string $amount, array $overrides = []): int
    {
        $this->turnoverSeq++;
        $month = substr($turnoverDate, 0, 7) . '-01';
        $task  = $this->createTask($clientId, ['rate' => '100.00', 'hours' => bcdiv($amount, '100', 2)]);
        $alloc = $this->createAllocation($task, bcdiv($amount, '100', 2), $month, ['rate' => '100.00']);

        return $this->insertIssuedInvoiceRaw($clientId, $entityId, $month, [$alloc], '%PDF-1.4 test', $overrides + [
            'number'        => 'T' . str_pad((string) (900000 + $this->turnoverSeq), 8, '0', STR_PAD_LEFT) . $entityId,
            'turnover_date' => $turnoverDate,
            'delivery_date' => $turnoverDate,
            'issue_date'    => $turnoverDate,
        ]);
    }

    protected function turnoverCreditNote(int $originalId, string $issueDate): int
    {
        return $this->insertRawCreditNote($originalId, ['issue_date' => $issueDate]);
    }

    protected function insertLimit(string $country, int $year, string $name, string $amount, string $currency, string $method, string $thresholdsCsv = '80,100', bool $isSeed = false): int
    {
        $this->owner()->query(
            "INSERT INTO turnover_limits (country, year, name, amount, currency, calc_method, thresholds, is_seed, created_at, updated_at)
             VALUES (:c:, :y:, :n:, :a:, :cur:, :m:, CAST(string_to_array(:t:, ',') AS integer[]), :seed:, now(), now())",
            ['c' => $country, 'y' => $year, 'n' => $name, 'a' => $amount, 'cur' => $currency, 'm' => $method, 't' => $thresholdsCsv, 'seed' => $isSeed ? 'true' : 'false'],
        );

        return (int) $this->owner()->query('SELECT max(id) AS id FROM turnover_limits')->getRow()->id;
    }

    protected function insertOpening(int $entityId, int $year, string $amount): void
    {
        $this->owner()->query(
            'INSERT INTO entity_opening_turnover (entity_id, year, amount, created_at, updated_at) VALUES (:e:, :y:, :a:, now(), now())',
            ['e' => $entityId, 'y' => $year, 'a' => $amount],
        );
    }
}
