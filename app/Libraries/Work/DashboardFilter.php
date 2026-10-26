<?php

declare(strict_types=1);

namespace App\Libraries\Work;

/**
 * Filter prehladu zo URL (mesiac, klient). Vsetko prechadza whitelistom, neplatne hodnoty nahradi predvolene.
 */
final readonly class DashboardFilter
{
    public function __construct(
        public string $month,
        public ?int $clientId,
    ) {
    }

    /**
     * @param array<array-key, mixed> $get
     */
    public static function fromQuery(array $get, ?int $defaultClient): self
    {
        $month  = $get['month'] ?? null;
        $client = $get['client'] ?? null;

        $month = (is_string($month) ? BillingMonth::parse($month) : null) ?? BillingMonth::current();

        if ($client === 'all') {
            $clientId = null;
        } elseif (is_string($client) && preg_match('/^\d{1,18}$/D', $client) === 1) {
            $clientId = (int) $client;
        } else {
            $clientId = $defaultClient;
        }

        return new self($month, $clientId);
    }

    /**
     * @return array{month: string, client: string}
     */
    public function toQuery(): array
    {
        return ['month' => $this->month, 'client' => $this->clientId === null ? 'all' : (string) $this->clientId];
    }
}
