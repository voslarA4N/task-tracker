<?php

declare(strict_types=1);

namespace App\Libraries\Audit;

/**
 * Filter denníka zmien zo URL; všetko prechádza whitelistom, neplatné hodnoty sa ticho zahodia (T-11-16).
 */
final readonly class AuditFilter
{
    public const MAX_PAGE = 10000;

    public function __construct(
        public ?string $entityType = null,
        public ?string $action = null,
        public ?int $userId = null,
        public ?string $from = null,
        public ?string $to = null,
        public int $page = 1,
    ) {
    }

    /**
     * @param array<array-key, mixed> $get
     * @param list<string>            $entityTypes
     * @param list<string>            $actions
     */
    public static function fromQuery(array $get, array $entityTypes, array $actions): self
    {
        $entity = $get['entity'] ?? null;
        $action = $get['action'] ?? null;
        $page   = $get['page'] ?? null;
        $n      = is_string($page) && preg_match('/^\d{1,5}$/D', $page) === 1 ? (int) $page : 1;

        return new self(
            is_string($entity) && in_array($entity, $entityTypes, true) ? $entity : null,
            is_string($action) && in_array($action, $actions, true) ? $action : null,
            self::id($get['user'] ?? null),
            self::date($get['from'] ?? null),
            self::date($get['to'] ?? null),
            max(1, min(self::MAX_PAGE, $n)),
        );
    }

    /**
     * Query bez čísla strany.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $out = [];

        if ($this->entityType !== null) {
            $out['entity'] = $this->entityType;
        }

        if ($this->action !== null) {
            $out['action'] = $this->action;
        }

        if ($this->userId !== null) {
            $out['user'] = (string) $this->userId;
        }

        if ($this->from !== null) {
            $out['from'] = $this->from;
        }

        if ($this->to !== null) {
            $out['to'] = $this->to;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function withPage(int $page): array
    {
        $page = max(1, min(self::MAX_PAGE, $page));

        return $this->toQuery() + ($page > 1 ? ['page' => (string) $page] : []);
    }

    public function isActive(): bool
    {
        return $this->toQuery() !== [];
    }

    private static function id(mixed $v): ?int
    {
        return is_string($v) && preg_match('/^\d{1,9}$/D', $v) === 1 ? (int) $v : null;
    }

    private static function date(mixed $v): ?string
    {
        if (! is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
            return null;
        }

        $d = service('clock')->parseDate($v);

        return $d !== null && $d->format('Y-m-d') === $v ? $v : null;
    }
}
