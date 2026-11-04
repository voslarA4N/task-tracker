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

    /**
     * WR-02: prechod z dlazdice alebo skupiny dashboardu. Zoznam pouzije presne tu istu podmienku ako dashboard
     * (TaskQueries::groupCondition), preto pocet a mnozina uloh sedia s dlazdicou aj so skupinou.
     */
    public const GROUPS = ['due', 'done_due', 'partial', 'in_progress'];

    /**
     * Skupiny, ktorych podmienka zavisi od vybraneho mesiaca (bez mesiaca sa skupina ignoruje).
     */
    public const MONTH_GROUPS = ['due', 'done_due', 'partial'];

    public const SORTS        = ['default', 'title', 'client', 'status', 'hours', 'amount', 'done'];
    public const DEFAULT_SORT = 'default';

    /**
     * Textove stlpce sa pri prvom kliku radia vzostupne, ostatne zostupne.
     */
    private const TEXT_SORTS = ['title', 'client', 'status'];

    private const MAX_Q = 100;

    public function __construct(
        public ?int $clientId = null,
        public string $state = self::DEFAULT_STATE,
        public ?string $month = null,
        public ?string $q = null,
        public ?string $group = null,
        public string $sort = self::DEFAULT_SORT,
        public string $dir = 'desc',
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
        $group  = $get['group'] ?? null;
        $sort   = $get['sort'] ?? null;
        $dir    = $get['dir'] ?? null;

        $clientId = is_string($client) && preg_match('/^\d{1,18}$/D', $client) === 1 ? (int) $client : null;
        $state    = is_string($state) && in_array($state, self::STATES, true) ? $state : self::DEFAULT_STATE;
        $month    = is_string($month) ? BillingMonth::parse($month) : null;
        $text     = null;

        if (is_string($q)) {
            $text = mb_substr(trim($q), 0, self::MAX_Q);
            $text = $text === '' ? null : $text;
        }

        $group = is_string($group) && in_array($group, self::GROUPS, true) ? $group : null;

        if ($group !== null && $month === null && in_array($group, self::MONTH_GROUPS, true)) {
            $group = null;
        }

        $sort = is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT;
        $dir  = $dir === 'asc' ? 'asc' : 'desc';

        return new self($clientId, $group === null ? $state : self::DEFAULT_STATE, $month, $text, $group, $sort, $dir);
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $out = $this->filterQuery();

        if ($this->sort !== self::DEFAULT_SORT) {
            $out['sort'] = $this->sort;
            $out['dir']  = $this->dir;
        }

        return $out;
    }

    /**
     * Odkaz na triedenie podla stlpca (zachova filtre, opakovany klik otoci smer).
     *
     * @return array<string, string>
     */
    public function withSort(string $col): array
    {
        $col = in_array($col, self::SORTS, true) ? $col : self::DEFAULT_SORT;

        if ($col === $this->sort) {
            $dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $dir = in_array($col, self::TEXT_SORTS, true) ? 'asc' : 'desc';
        }

        return $this->filterQuery() + ['sort' => $col, 'dir' => $dir];
    }

    /**
     * @return array<string, string>
     */
    private function filterQuery(): array
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

        if ($this->group !== null) {
            $out['group'] = $this->group;
        }

        if ($this->q !== null) {
            $out['q'] = $this->q;
        }

        return $out;
    }

    public function activeCount(): int
    {
        return count($this->filterQuery());
    }
}
