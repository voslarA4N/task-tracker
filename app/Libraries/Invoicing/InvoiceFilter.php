<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use DateTimeImmutable;

/**
 * Filter a triedenie zoznamu faktur zo URL. Vsetko prechadza whitelistom, neplatne hodnoty sa ticho nahradia predvolenymi.
 */
final readonly class InvoiceFilter
{
    public const STATUSES     = ['issued', 'sent', 'partially_paid', 'paid', 'overdue', 'cancelled', 'credit_note'];
    public const SORTS        = ['number', 'client', 'issue_date', 'turnover_date', 'due_date', 'currency', 'total', 'total_entity_ccy'];
    public const DEFAULT_SORT = 'issue_date';
    public const DEFAULT_DIR  = 'desc';

    /**
     * Textove stlpce sa predvolene triedia vzostupne, datumy a sumy zostupne.
     */
    private const TEXT_SORTS = ['number', 'client', 'currency'];

    private const MAX_Q = 50;

    public function __construct(
        public ?string $status = null,
        public ?int $entityId = null,
        public ?int $clientId = null,
        public ?string $q = null,
        public ?string $from = null,
        public ?string $to = null,
        public string $sort = self::DEFAULT_SORT,
        public string $dir = self::DEFAULT_DIR,
    ) {
    }

    /**
     * @param array<array-key, mixed> $get
     */
    public static function fromQuery(array $get): self
    {
        $status = $get['status'] ?? null;
        $q      = $get['q'] ?? null;
        $sort   = $get['sort'] ?? null;
        $dir    = $get['dir'] ?? null;
        $text   = null;

        if (is_string($q)) {
            $text = mb_substr(trim($q), 0, self::MAX_Q);
            $text = $text === '' ? null : $text;
        }

        return new self(
            is_string($status) && in_array($status, self::STATUSES, true) ? $status : null,
            self::id($get['entity'] ?? null),
            self::id($get['client'] ?? null),
            $text,
            self::date($get['from'] ?? null),
            self::date($get['to'] ?? null),
            is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT,
            $dir === 'asc' ? 'asc' : self::DEFAULT_DIR,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $out = $this->filterQuery();

        if (! $this->isDefaultSort()) {
            $out['sort'] = $this->sort;
            $out['dir']  = $this->dir;
        }

        return $out;
    }

    /**
     * Query pre odkaz hlavicky stlpca: filtre ostavaju, aktivny stlpec prepne smer.
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

    public function activeCount(): int
    {
        return count($this->filterQuery());
    }

    public function isDefaultSort(): bool
    {
        return $this->sort === self::DEFAULT_SORT && $this->dir === self::DEFAULT_DIR;
    }

    /**
     * @return array<string, string>
     */
    private function filterQuery(): array
    {
        $out = [];

        if ($this->status !== null) {
            $out['status'] = $this->status;
        }

        if ($this->entityId !== null) {
            $out['entity'] = (string) $this->entityId;
        }

        if ($this->clientId !== null) {
            $out['client'] = (string) $this->clientId;
        }

        if ($this->q !== null) {
            $out['q'] = $this->q;
        }

        if ($this->from !== null) {
            $out['from'] = $this->from;
        }

        if ($this->to !== null) {
            $out['to'] = $this->to;
        }

        return $out;
    }

    private static function id(mixed $v): ?int
    {
        return is_string($v) && preg_match('/^\d{1,18}$/D', $v) === 1 ? (int) $v : null;
    }

    private static function date(mixed $v): ?string
    {
        if (! is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
            return null;
        }

        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
    }
}
