<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

final readonly class HttpResult
{
    public function __construct(public int $status, public string $body)
    {
    }
}
