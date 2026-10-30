<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use RuntimeException;
use Throwable;

/**
 * API nie je dostupne alebo vratilo nepouzitelnu odpoved (D-12). Sprava nesie iba dovod a status, nikdy telo (T-08-11).
 */
final class RateUnavailable extends RuntimeException
{
    public const REASONS = ['http_error', 'http_status', 'parse_error'];

    public function __construct(public readonly string $reason, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct('Rate source unavailable: ' . $reason . ($status !== null ? ' (' . $status . ')' : ''), 0, $previous);
    }
}
