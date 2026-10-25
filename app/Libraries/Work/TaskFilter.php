<?php

declare(strict_types=1);

namespace App\Libraries\Work;

/**
 * Filter zoznamu uloh zo URL. Vsetko prechadza whitelistom, neplatne hodnoty sa ticho nahradia predvolenymi.
 */
final readonly class TaskFilter
{
    public const STATES        = ['current', 'in_progress', 'done', 'partial', 'billed', 'all'];
    public const DEFAULT_STATE = 'current';
    private const MAX_Q        = 100;

    public function __construct(
        public ?int $clientId = null,
        public string $state = self::DEFAULT_STATE,
        public ?string $month = null,
        public ?string $q = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $get
     */
    public static function fromQuery(array $get): self
    {
        $client = $get['client'] ?? null;
        $state  = $get['state'] ?? null;
        $month  = $get['month'] ?? null;
        $q      = $get['q'] ?? null;

        $clientId = is_string($client) && preg_match('/^\d{1,18}$/D', $client) === 1 ? (int) $client : null;
        $state    = is_string($state) && in_array($state, self::STATES, true) ? $state : self::DEFAULT_STATE;
        $month    = is_string($month) ? BillingMonth::parse($month) : null;
        $text     = null;

        if (is_string($q)) {
            $text = mb_substr(trim($q), 0, self::MAX_Q);
            $text = $text === '' ? null : $text;
        }

        return new self($clientId, $state, $month, $text);
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $out = [];

        if ($this->clientId !== null) {
            $out['client'] = (string) $this->clientId;
        }

        if ($this->state !== self::DEFAULT_STATE) {
            $out['state'] = $this->state;
        }

        if ($this->month !== null) {
            $out['month'] = $this->month;
        }

        if ($this->q !== null) {
            $out['q'] = $this->q;
        }

        return $out;
    }

    public function activeCount(): int
    {
        return count($this->toQuery());
    }
}
