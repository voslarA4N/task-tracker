<?php

declare(strict_types=1);

namespace App\Libraries\Audit;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\CLIRequest;
use Config\Database;
use InvalidArgumentException;

/**
 * Denník zmien master dát (AUD-01, D-18). Zapisuje cez spojenie volajúcej služby,
 * takže zápis je v tej istej transakcii ako zmena.
 */
final class AuditLogger
{
    private const NAME_PATTERN      = '/^[a-z_]{3,40}$/';
    private const SENSITIVE_PATTERN = '/(^|_)(password|secret|token)|^(code_hash|hash)$/i';

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
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(string $entityType, int $entityId, string $action, ?array $before, ?array $after): void
    {
        if (preg_match(self::NAME_PATTERN, $entityType) !== 1) {
            throw new InvalidArgumentException('Invalid entity type: ' . $entityType);
        }

        if (preg_match(self::NAME_PATTERN, $action) !== 1) {
            throw new InvalidArgumentException('Invalid action: ' . $action);
        }

        $request = service('request');
        $cli     = $request instanceof CLIRequest;
        $userId  = auth()->id();

        $this->db->table('audit_log')->insert([
            'occurred_at' => service('clock')->now()->format('Y-m-d H:i:sP'),
            'user_id'     => is_numeric($userId) ? (int) $userId : null,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'before_data' => $before === null ? null : self::encode(self::scrub($before)),
            'after_data'  => $after === null ? null : self::encode(self::scrub($after)),
            'ip'          => $cli ? 'cli' : $request->getIPAddress(),
        ]);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function recordChange(string $entityType, int $entityId, string $action, array $before, array $after): bool
    {
        [$b, $a] = self::diff($before, $after);

        if ($b === [] && $a === []) {
            return false;
        }

        $this->record($entityType, $entityId, $action, $b, $a);

        return true;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $b = [];
        $a = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old !== $new) {
                $b[$key] = $old;
                $a[$key] = $new;
            }
        }

        return [$b, $a];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function scrub(array $data): array
    {
        return array_filter(
            $data,
            static fn ($key): bool => preg_match(self::SENSITIVE_PATTERN, (string) $key) !== 1,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
