<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

use Closure;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Spolocny stav jedneho behu seedu: pripojenie, hodiny, plan a register kluc => id v DB (D-04).
 */
final class SeedContext
{
    /**
     * @var array<string, array<string, int>>
     */
    private array $ids = [];

    /**
     * @var array<string, mixed>
     */
    public array $notes = [];

    /**
     * @param BaseConnection<mixed, mixed> $db
     * @param Closure(string): void        $log
     */
    public function __construct(
        public readonly BaseConnection $db,
        public readonly SeedClock $clock,
        public readonly HistoryPlan $plan,
        public readonly Profile $profile,
        public readonly SeedAdminCredentials $credentials,
        public readonly Closure $log,
    ) {
    }

    public function put(string $kind, string $key, int $id): void
    {
        $this->ids[$kind][$key] = $id;
    }

    public function id(string $kind, string $key): int
    {
        return $this->ids[$kind][$key] ?? throw new RuntimeException('Seed registry has no ' . $kind . ' ' . $key);
    }

    public function log(string $message): void
    {
        ($this->log)($message);
    }
}
