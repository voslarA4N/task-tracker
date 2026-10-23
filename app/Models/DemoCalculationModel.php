<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Platform\Money;
use CodeIgniter\Model;
use DateTimeImmutable;

final class DemoCalculationModel extends Model
{
    protected $table            = 'demo_calculations';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;
    protected $allowedFields    = ['hours', 'rate', 'amount', 'created_at'];

    public function record(string $hours, string $rate, Money $amount, DateTimeImmutable $at): int
    {
        $this->insert([
            'hours'      => $hours,
            'rate'       => $rate,
            'amount'     => $amount->toDecimalString(),
            'created_at' => $at->format('Y-m-d H:i:sP'),
        ]);

        return (int) $this->getInsertID();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit = 20): array
    {
        return $this->orderBy('id', 'DESC')->findAll($limit);
    }

    public function totalAmount(): Money
    {
        $amounts = $this->select('amount')->findColumn('amount') ?? [];

        return Money::sum(...array_map(Money::of(...), $amounts));
    }

    public function sqlTotal(): string
    {
        return (string) $this->db->query('SELECT COALESCE(SUM(amount), 0)::numeric(12,2)::text AS total FROM demo_calculations')->getRow()->total;
    }
}
